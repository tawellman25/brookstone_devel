<?php

declare(strict_types=1);

namespace Drupal\bos_wo_intake\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountInterface;
use Psr\Log\LoggerInterface;

/**
 * Transport-agnostic Work Order intake logic.
 *
 * Two entry points share the create/access rules:
 *  - createBareWorkOrder(propertyId, serviceTermId) — Gate 1 (REST, IDs in).
 *  - createFromText(text, actor, options) — Gate 2A: DETERMINISTIC (no LLM) parse +
 *    resolution of a spoken/typed command into a WO + complaint note.
 *
 * Result shape (both): ['success'|'status' => ..., ... , 'http' => N]. createFromText uses
 * status: created | ambiguous | blocked | error (see the Gate 2A as-built in work_order_api.md).
 */
final class WorkOrderIntakeService {

  private const STATUS_OPEN_TID = 1089;
  private const ACTIVE_TIDS = [1089, 1090, 1091, 1092];
  private const TERMINAL_TIDS = [1097, 1281, 1283, 1504];
  private const FORBIDDEN_BUNDLE = 'estimate';
  private const ACCOUNT_NAME = 'cowork-connect';

  /** @var array<int,string>|null Cached [termId => normalized name] for WO-service terms. */
  private ?array $woServiceTerms = NULL;

  private $settings;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {
    $this->settings = $this->configFactory->get('bos_wo_intake.settings');
  }

  // ==========================================================================
  // Gate 1 — bare create from IDs (unchanged behavior).
  // ==========================================================================

  public function createBareWorkOrder(int $propertyId, int $serviceTermId): array {
    $svc = $this->resolveServiceByTermId($serviceTermId);
    if ($svc['status'] !== 'ok') {
      return $this->error($svc['code'], $svc['message'], 422);
    }
    if (!$this->loadProperty($propertyId)) {
      return $this->error('property_not_found', 'Property not found.', 422);
    }
    $account = $this->loadServiceAccount();
    if (!$account) {
      return $this->error('access_denied', 'Service account unavailable.', 403);
    }
    if (!$this->canCreate($svc['bundle'], $account)) {
      return $this->error('access_denied', 'Not permitted to create this work order.', 403);
    }
    $wo = $this->persistWorkOrder($svc['bundle'], $propertyId, $serviceTermId);
    if (!$wo) {
      return $this->error('create_failed', 'Work order could not be created.', 500);
    }
    return ['success' => TRUE, 'work_order' => $this->woSummary($wo), 'http' => 201];
  }

  // ==========================================================================
  // Gate 2A — deterministic text resolution.
  // ==========================================================================

  /**
   * @param array $options  allow_duplicate (bool), property_id (int), service_term_id (int).
   */
  public function createFromText(string $text, AccountInterface $actor, array $options = []): array {
    $allowDuplicate = !empty($options['allow_duplicate']);
    $frag = $this->extractFragments($text);

    // --- SERVICE ---
    if (!empty($options['service_term_id'])) {
      $svc = $this->resolveServiceByTermId((int) $options['service_term_id']);
      if ($svc['status'] !== 'ok') {
        return $this->err($svc['code'], $svc['message'], 422, $frag);
      }
    }
    else {
      $svc = $this->resolveServiceByPhrase($frag['service_phrase']);
      if ($svc['status'] === 'ambiguous') {
        return $this->ambiguous('service', $svc['candidates'], $frag);
      }
      if ($svc['status'] !== 'ok') {
        return $this->err($svc['code'], $svc['message'], 422, $frag);
      }
    }
    $bundle = $svc['bundle'];
    $termId = $svc['term_id'];

    // --- PROPERTY ---
    if (!empty($options['property_id'])) {
      $property = $this->loadProperty((int) $options['property_id']);
      if (!$property) {
        return $this->err('property_not_found', 'Property not found.', 422, $frag);
      }
    }
    else {
      $prop = $this->resolvePropertyByFragments($frag['name'], $frag['street'], $frag['town']);
      if ($prop['status'] !== 'resolved') {
        return $this->ambiguous('property', $prop['candidates'], $frag, $prop['conflict'] ?? NULL);
      }
      $property = $this->loadProperty($prop['property_id']);
    }
    $propertyId = (int) $property->id();

    // --- ACCESS ---
    if (!$this->canCreate($bundle, $actor)) {
      return $this->err('access_denied', 'Not permitted to create this work order.', 403, $frag);
    }

    // --- DUPLICATE TIERS ---
    $dupFlag = NULL;
    if (!$allowDuplicate) {
      $dup = $this->duplicateCheck($propertyId, $termId, $bundle);
      if ($dup['status'] === 'blocked') {
        return [
          'status' => 'blocked',
          'reason' => $dup['reason'],
          'existing' => $this->woSummary($dup['existing']),
          'extracted' => $frag,
          'http' => 409,
        ];
      }
      if (!empty($dup['flag'])) {
        $dupFlag = $dup;
      }
    }

    // --- CREATE ---
    $wo = $this->persistWorkOrder($bundle, $propertyId, $termId);
    if (!$wo) {
      return $this->err('create_failed', 'Work order could not be created.', 500, $frag);
    }
    $noteIds = [];
    $flags = [];

    // The spoken description (+ any recent-terminal system flag) is appended to
    // the WO's "Work To Be Done" (field_work_todo_description), on new lines
    // after the per-service default — NOT a wo_notes entity. wo_notes are for
    // things noted AFTER creation. (Falls back to a note only if a bundle lacks
    // the description field.)
    $descLines = [];
    if (trim((string) $frag['complaint']) !== '') {
      $descLines[] = trim((string) $frag['complaint']);
    }
    if ($dupFlag) {
      $prior = $dupFlag['prior'];
      $priorId = $prior->hasField('field_work_order_id') && !$prior->get('field_work_order_id')->isEmpty()
        ? $prior->get('field_work_order_id')->value : $prior->id();
      $when = $this->fmtDate($prior->getChangedTime());
      $statusLbl = $this->statusLabel($prior);
      $descLines[] = "⚠ System: WO {$priorId} ({$svc['term_name']}) for this property was {$statusLbl} on {$when} — verify this is a new issue, not a callback.";
      $flags[] = 'recent_terminal_noted';
    }
    if ($descLines) {
      if (!$this->appendToDescription($wo, $descLines)) {
        // Bundle has no description field — fall back to a note so nothing is lost.
        foreach ($descLines as $line) {
          $n = $this->createNote($wo, $actor, $line, str_starts_with($line, '⚠ System:'), 'manual');
          if ($n) {
            $noteIds[] = (int) $n->id();
          }
        }
      }
    }

    return [
      'status' => 'created',
      'work_order' => $this->woSummary($wo),
      'note_ids' => $noteIds,
      'flags' => $flags,
      'extracted' => $frag,
      'http' => 201,
    ];
  }

  // ==========================================================================
  // Extraction (deterministic).
  // ==========================================================================

  private function extractFragments(string $text): array {
    $original = trim($text);

    // Complaint (verbatim, original case) = the trailing clause after the first
    // sentence period, OR the LAST comma-segment when it reads like a problem
    // report. The indicator list keeps a name-comma ("Smith, John") from being
    // mistaken for a complaint while still catching "…, the pump isn't working".
    $complaint = '';
    $command = $original;
    $indicators = '/(isn.?t|aren.?t|wasn.?t|weren.?t|won.?t|can.?t|couldn.?t|doesn.?t|don.?t|didn.?t|\bnot\b|no longer|broken|\bbroke\b|leak|leaking|working|stopped|stuck|dead|\bdown\b|\bout\b|needs?\b|need to|problem|issue|damaged|cracked|clogged|won.?t work)/i';
    if (preg_match('/^(.*?[a-z0-9\)])\.\s+(\S.*)$/is', $original, $m)) {
      $command = $m[1];
      $complaint = trim($m[2]);
    }
    else {
      $segments = preg_split('/\s*,\s*/', $original);
      if (count($segments) > 1) {
        $last = trim((string) end($segments));
        if ($last !== '' && preg_match($indicators, $last)) {
          $complaint = $last;
          array_pop($segments);
          $command = implode(', ', $segments);
        }
      }
    }

    // Normalize the command for structural parsing; strip command filler.
    $norm = $this->normalizeText($command);
    $norm = preg_replace('/\b(create|make|new|open|please|generate|add)\b/', ' ', $norm);
    $norm = preg_replace('/\bwork order\b/', ' ', $norm);
    $norm = preg_replace('/\bwo\b/', ' ', $norm);
    $norm = preg_replace('/\b(properties|property|place)\b/', ' ', $norm);
    $norm = preg_replace('/\b(an?|the)\b/', ' ', $norm);
    $norm = trim(preg_replace('/\s+/', ' ', $norm));

    // Pluck the longest known service phrase out of the command.
    [$servicePhrase, $rest] = $this->pluckService($norm);

    // Parse name / street / town from what remains.
    [$name, $street, $town] = $this->pluckLocation($rest);

    return [
      'service_phrase' => $servicePhrase,
      'name' => $name,
      'street' => $street,
      'town' => $town,
      'complaint' => $complaint,
    ];
  }

  /**
   * Find the longest known service phrase (synonym key or WO-service term name)
   * present as whole words, and return [phrase, command-with-phrase-removed].
   */
  private function pluckService(string $norm): array {
    $phrases = array_keys($this->settings->get('synonym_map') ?? []);
    foreach ($this->woServiceTerms() as $name) {
      $phrases[] = $name;
    }
    $phrases = array_unique($phrases);
    // Longest first (most specific wins).
    usort($phrases, fn($a, $b) => strlen($b) <=> strlen($a));

    $hay = ' ' . $norm . ' ';
    foreach ($phrases as $p) {
      if ($p === '') {
        continue;
      }
      $needle = ' ' . $p . ' ';
      $pos = strpos($hay, $needle);
      if ($pos !== FALSE) {
        $rest = trim(substr($hay, 0, $pos) . ' ' . substr($hay, $pos + strlen($needle)));
        return [$p, preg_replace('/\s+/', ' ', $rest)];
      }
    }
    return ['', $norm];
  }

  /**
   * Split remaining command text into name / street / town using prepositions,
   * with a no-preposition fallback that carves a street off the tail.
   */
  private function pluckLocation(string $s): array {
    $tokens = array_values(array_filter(explode(' ', trim($s)), fn($t) => $t !== ''));
    $slots = ['name' => [], 'street' => [], 'town' => []];
    $slot = 'name';
    foreach ($tokens as $tok) {
      if ($tok === 'for') { $slot = 'name'; continue; }
      if ($tok === 'on' || $tok === 'at') { $slot = 'street'; continue; }
      if ($tok === 'in') { $slot = 'town'; continue; }
      $slots[$slot][] = $tok;
    }
    $name = implode(' ', $slots['name']);
    $street = implode(' ', $slots['street']);
    $town = implode(' ', $slots['town']);

    if ($street === '' && $slots['name']) {
      [$name, $street] = $this->splitNameStreet($slots['name']);
    }
    return [$name, $street, $town];
  }

  /**
   * No-preposition split: a house number starts the street, else a trailing
   * street-suffix token pulls itself + one preceding word into the street.
   */
  private function splitNameStreet(array $tokens): array {
    foreach ($tokens as $i => $t) {
      if (ctype_digit($t)) {
        return [implode(' ', array_slice($tokens, 0, $i)), implode(' ', array_slice($tokens, $i))];
      }
    }
    $suffixes = $this->suffixSet();
    $n = count($tokens);
    if ($n >= 2 && in_array($tokens[$n - 1], $suffixes, TRUE)) {
      return [implode(' ', array_slice($tokens, 0, $n - 2)), implode(' ', array_slice($tokens, $n - 2))];
    }
    return [implode(' ', $tokens), ''];
  }

  // ==========================================================================
  // Service resolution.
  // ==========================================================================

  private function resolveServiceByPhrase(string $phrase): array {
    if ($phrase === '') {
      return ['status' => 'ambiguous', 'candidates' => []];
    }
    $p = $this->normalizeText($phrase);
    $syn = $this->settings->get('synonym_map') ?? [];
    $termIds = [];
    if (isset($syn[$p])) {
      $termIds = [(int) $syn[$p]];
    }
    else {
      foreach ($this->woServiceTerms() as $tid => $name) {
        if ($name === $p) {
          $termIds[] = $tid;
        }
      }
      if (!$termIds) {
        // looser: term name contains the phrase (or vice versa)
        foreach ($this->woServiceTerms() as $tid => $name) {
          if (str_contains($name, $p) || str_contains($p, $name)) {
            $termIds[] = $tid;
          }
        }
      }
    }
    $termIds = array_values(array_unique($termIds));
    if (count($termIds) === 0) {
      return ['status' => 'ambiguous', 'candidates' => $this->serviceCandidates(array_keys($this->woServiceTerms()))];
    }
    if (count($termIds) > 1) {
      return ['status' => 'ambiguous', 'candidates' => $this->serviceCandidates($termIds)];
    }
    $svc = $this->resolveServiceByTermId($termIds[0]);
    if ($svc['status'] !== 'ok') {
      return $svc;
    }
    return $svc;
  }

  private function resolveServiceByTermId(int $termId): array {
    $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($termId);
    if (!$term || $term->bundle() !== 'services') {
      return ['status' => 'error', 'code' => 'service_not_work_order', 'message' => 'Service term not found or not a service.'];
    }
    $isWo = $term->hasField('field_work_order_service') && !$term->get('field_work_order_service')->isEmpty()
      && (bool) $term->get('field_work_order_service')->value;
    if (!$isWo) {
      return ['status' => 'error', 'code' => 'service_not_work_order', 'message' => 'Service is not flagged as a work-order service.'];
    }
    $bundle = $term->hasField('field_service_bundle') && !$term->get('field_service_bundle')->isEmpty()
      ? trim((string) $term->get('field_service_bundle')->value) : '';
    if ($bundle === '') {
      return ['status' => 'error', 'code' => 'service_bundle_missing', 'message' => 'Service has no work-order bundle mapping.'];
    }
    if ($bundle === self::FORBIDDEN_BUNDLE) {
      return ['status' => 'error', 'code' => 'estimate_bundle_forbidden', 'message' => 'The estimate bundle cannot be created via the API.'];
    }
    return ['status' => 'ok', 'term_id' => $termId, 'term_name' => $term->label(), 'bundle' => $bundle];
  }

  private function serviceCandidates(array $termIds): array {
    $out = [];
    foreach (array_slice($termIds, 0, 8) as $tid) {
      $t = $this->entityTypeManager->getStorage('taxonomy_term')->load($tid);
      if ($t) {
        $out[] = ['term_id' => (int) $tid, 'name' => $t->label(), 'bundle' => $t->get('field_service_bundle')->value];
      }
    }
    return $out;
  }

  /** @return array<int,string> [termId => normalized name] for all WO-service terms. */
  private function woServiceTerms(): array {
    if ($this->woServiceTerms !== NULL) {
      return $this->woServiceTerms;
    }
    $ts = $this->entityTypeManager->getStorage('taxonomy_term');
    $ids = $ts->getQuery()->accessCheck(FALSE)->condition('vid', 'services')
      ->condition('field_work_order_service', 1)->execute();
    $map = [];
    foreach ($ts->loadMultiple($ids) as $t) {
      $map[(int) $t->id()] = $this->normalizeText($t->label());
    }
    return $this->woServiceTerms = $map;
  }

  // ==========================================================================
  // Property resolution — nickname-primary, compounding filters, conflict-aware.
  // ==========================================================================

  private function resolvePropertyByFragments(string $name, string $street, string $town): array {
    // Drop 1-char stray tokens (the "s" left by "Bynum's" → "bynum s", etc.).
    $nameTokens = array_values(array_filter(explode(' ', $this->normalizeText($name)), fn($t) => strlen($t) >= 2));
    $streetN = $street === '' ? '' : $this->normalizeStreet($street);
    $townN = $town === '' ? '' : $this->normalizeText($town);

    if (!$nameTokens && $streetN === '' && $townN === '') {
      return ['status' => 'ambiguous', 'candidates' => []];
    }

    // Coarse prefilter to bound the working set (2.5k props): nickname CONTAINS the
    // longest name token; if name-less, street CONTAINS.
    $storage = $this->entityTypeManager->getStorage('properties');
    $q = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'property');
    if ($nameTokens) {
      // Prefilter on the TWO longest tokens (stemmed), OR'd — one plural/typo or a
      // single common word can't poison the whole net.
      $byLen = $nameTokens;
      usort($byLen, fn($a, $b) => strlen($b) <=> strlen($a));
      $seed = array_slice(array_values(array_unique(array_map([$this, 'stem'], $byLen))), 0, 2);
      $or = $q->orConditionGroup();
      foreach ($seed as $tok) {
        $or->condition('field_nickname', $tok, 'CONTAINS');
      }
      $q->condition($or);
    }
    elseif ($streetN !== '') {
      $first = explode(' ', $streetN)[0];
      $q->condition('field_street_address', $first, 'CONTAINS');
    }
    $ids = $q->range(0, 800)->execute();
    if (!$ids) {
      return ['status' => 'ambiguous', 'candidates' => []];
    }

    $tokenCount = count($nameTokens);
    $scored = [];
    foreach ($storage->loadMultiple($ids) as $p) {
      $nick = $this->normalizeText((string) ($p->get('field_nickname')->value ?? ''));
      $matched = 0;
      foreach ($nameTokens as $t) {
        if ($this->tokenMatches($nick, $t)) {
          $matched++;
        }
      }
      // A name was given but this row shares none of it → not a candidate.
      if ($tokenCount > 0 && $matched === 0) {
        continue;
      }
      $nameAll = ($tokenCount === 0) ? NULL : ($matched === $tokenCount);
      $streetVal = $this->normalizeStreet((string) ($p->get('field_street_address')->value ?? ''));
      $streetHit = $streetN === '' ? NULL : str_contains($streetVal, $streetN);
      $townVal = $this->propertyTown($p);
      $townHit = $townN === '' ? NULL : str_contains($townVal, $townN);

      $scored[] = [
        'p' => $p,
        'matched' => $matched,
        'name_all' => $nameAll,
        'street_hit' => $streetHit,
        'town_hit' => $townHit,
        'town_val' => $townVal,
        'score' => $matched * 100 + ($streetHit === TRUE ? 10 : 0) + ($townHit === TRUE ? 1 : 0),
      ];
    }
    if (!$scored) {
      return ['status' => 'ambiguous', 'candidates' => []];
    }

    // Full-match set: ALL name tokens matched AND street/town not contradicted.
    $full = array_values(array_filter($scored, function ($r) {
      return ($r['name_all'] !== FALSE) && ($r['street_hit'] !== FALSE) && ($r['town_hit'] !== FALSE);
    }));
    if (count($full) === 1) {
      return ['status' => 'resolved', 'property_id' => (int) $full[0]['p']->id()];
    }
    if (count($full) > 1) {
      usort($full, fn($a, $b) => $b['score'] <=> $a['score']);
      return ['status' => 'ambiguous', 'candidates' => $this->propertyCandidates($full)];
    }

    // Zero full matches. Conflict rule: dropping town yields a unique all-name+street
    // match whose town differs — surface it flagged, never silently drop a fragment.
    if ($townN !== '') {
      $nameStreet = array_values(array_filter($scored, fn($r) => $r['name_all'] !== FALSE && $r['street_hit'] !== FALSE));
      if (count($nameStreet) === 1) {
        $c = $this->propertyCandidates($nameStreet)[0];
        $c['conflict'] = ['field' => 'town', 'expected' => $townN, 'actual' => $nameStreet[0]['town_val']];
        return ['status' => 'ambiguous', 'candidates' => [$c], 'conflict' => $c['conflict']];
      }
    }

    // Partial fallback: no full match, but surface the best partial matches (most
    // name tokens hit) so the user can always tap something — never a dead end.
    usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
    $best = $scored[0]['matched'];
    $cands = array_values(array_filter($scored, fn($r) => $r['matched'] >= $best));
    return ['status' => 'ambiguous', 'candidates' => $this->propertyCandidates(array_slice($cands, 0, 8))];
  }

  /**
   * Does a name token appear in the (normalized) nickname? Substring match, plus
   * a possessive/plural stem so "bynums"→"bynum" and "todds"→"todd" still hit.
   */
  private function tokenMatches(string $nick, string $token): bool {
    if (str_contains($nick, $token)) {
      return TRUE;
    }
    $stem = $this->stem($token);
    return $stem !== $token && str_contains($nick, $stem);
  }

  /**
   * Strip a single trailing possessive/plural "s" from a meaningful-length token.
   */
  private function stem(string $t): string {
    return (strlen($t) > 3 && substr($t, -1) === 's') ? substr($t, 0, -1) : $t;
  }

  private function propertyCandidates(array $rows): array {
    usort($rows, fn($a, $b) => $b['score'] <=> $a['score']);
    $out = [];
    foreach (array_slice($rows, 0, 8) as $r) {
      $p = $r['p'];
      $out[] = [
        'id' => (int) $p->id(),
        'name' => (string) ($p->get('field_nickname')->value ?? ''),
        'street' => (string) ($p->get('field_street_address')->value ?? ''),
        'town' => $r['town_val'] ?? $this->propertyTown($p),
      ];
    }
    return $out;
  }

  private function propertyTown(EntityInterface $p): string {
    if ($p->hasField('field_zipcode_reference') && !$p->get('field_zipcode_reference')->isEmpty()) {
      $zip = $p->get('field_zipcode_reference')->entity;
      if ($zip && $zip->hasField('field_city') && !$zip->get('field_city')->isEmpty()) {
        $city = $zip->get('field_city')->entity;
        if ($city) {
          return $this->normalizeText($city->label());
        }
      }
    }
    if ($p->hasField('field_full_address') && !$p->get('field_full_address')->isEmpty()) {
      $parts = explode(',', (string) $p->get('field_full_address')->value);
      if (count($parts) >= 2) {
        return $this->normalizeText(trim($parts[1]));
      }
    }
    return '';
  }

  // ==========================================================================
  // Duplicate tiers.
  // ==========================================================================

  private function duplicateCheck(int $propertyId, int $termId, string $bundle): array {
    // Defer to a bundle's own find-or-create guard where one exists (weed_spraying).
    if ($bundle === 'weed_spraying' && function_exists('_wo_weed_spraying_find_active_open_wo')) {
      $existing = _wo_weed_spraying_find_active_open_wo($propertyId);
      if ($existing) {
        return ['status' => 'blocked', 'reason' => 'active_duplicate', 'existing' => $existing];
      }
      return ['status' => 'ok'];
    }

    $storage = $this->entityTypeManager->getStorage('work_order');
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('field_property', $propertyId)
      ->condition('field_service', $termId)
      ->execute();
    if (!$ids) {
      return ['status' => 'ok'];
    }
    $windowDays = (int) ($this->settings->get('duplicate_window_days') ?? 3);
    $now = \Drupal::time()->getRequestTime();
    $recent = NULL;
    foreach ($storage->loadMultiple($ids) as $wo) {
      $st = $wo->get('field_status')->isEmpty() ? 0 : (int) $wo->get('field_status')->target_id;
      if (in_array($st, self::ACTIVE_TIDS, TRUE)) {
        return ['status' => 'blocked', 'reason' => 'active_duplicate', 'existing' => $wo];
      }
      if (in_array($st, self::TERMINAL_TIDS, TRUE)) {
        if (($now - $wo->getChangedTime()) <= $windowDays * 86400) {
          if (!$recent || $wo->getChangedTime() > $recent->getChangedTime()) {
            $recent = $wo;
          }
        }
      }
      // Canceled (1098) or terminal older than the window: ignore.
    }
    if ($recent) {
      return ['status' => 'ok', 'flag' => 'recent_terminal', 'prior' => $recent];
    }
    return ['status' => 'ok'];
  }

  // ==========================================================================
  // Persistence.
  // ==========================================================================

  private function persistWorkOrder(string $bundle, int $propertyId, int $serviceTermId): ?EntityInterface {
    try {
      $wo = $this->entityTypeManager->getStorage('work_order')->create([
        'type' => $bundle,
        'field_property' => ['target_id' => $propertyId],
        'field_service' => ['target_id' => $serviceTermId],
        'field_status' => ['target_id' => self::STATUS_OPEN_TID],
      ]);
      $wo->save();
    }
    catch (\Throwable $e) {
      $this->logger->error('WO-intake: create failed for bundle @b: @m', ['@b' => $bundle, '@m' => $e->getMessage()]);
      return NULL;
    }
    return $this->entityTypeManager->getStorage('work_order')->loadUnchanged($wo->id());
  }

  /**
   * Append lines to the WO's "Work To Be Done" (field_work_todo_description),
   * each on a new line after any existing/default content. Returns FALSE if the
   * bundle has no such field (caller falls back to a note).
   */
  private function appendToDescription(EntityInterface $wo, array $lines): bool {
    if (!$wo->hasField('field_work_todo_description')) {
      return FALSE;
    }
    $lines = array_values(array_filter(
      array_map(fn($l) => trim((string) $l), $lines),
      fn($l) => $l !== ''
    ));
    if (!$lines) {
      return TRUE;
    }
    $item = $wo->get('field_work_todo_description')->first();
    $existing = $item ? rtrim((string) $item->value) : '';
    $format = ($item && $item->format) ? $item->format : 'basic_html';
    // For HTML formats wrap each line in a paragraph so it renders as a clean new
    // block after the default; otherwise a plain newline.
    if (stripos($format, 'html') !== FALSE) {
      $addition = implode('', array_map(fn($l) => '<p>' . htmlspecialchars($l) . '</p>', $lines));
    }
    else {
      $addition = implode("\n", $lines);
    }
    $value = $existing !== '' ? ($existing . "\n" . $addition) : $addition;
    try {
      $wo->set('field_work_todo_description', ['value' => $value, 'format' => $format]);
      $wo->_skip_invoiced_guard = TRUE;
      $wo->save();
    }
    catch (\Throwable $e) {
      $this->logger->error('WO-intake: description append failed on WO @id: @m', ['@id' => $wo->id(), '@m' => $e->getMessage()]);
      return FALSE;
    }
    return TRUE;
  }

  private function createNote(EntityInterface $wo, AccountInterface $actor, string $body, bool $isSystem, ?string $kind): ?EntityInterface {
    // Explicit access gate — bare save() bypasses the role.
    if (!$this->entityTypeManager->getAccessControlHandler('wo_notes')->createAccess('note', $actor, [], TRUE)->isAllowed()) {
      $this->logger->error('WO-intake: note create denied for actor @u.', ['@u' => $actor->id()]);
      return NULL;
    }
    try {
      $values = [
        'type' => 'note',
        'field_work_order' => ['target_id' => $wo->id()],
        'field_note_text' => ['value' => $body, 'format' => 'basic_html'],
        'field_is_system_note' => $isSystem,
        'uid' => $actor->id(),
      ];
      if ($kind !== NULL) {
        $values['field_note_kind'] = $kind;
      }
      $note = $this->entityTypeManager->getStorage('wo_notes')->create($values);
      $note->save();
      return $note;
    }
    catch (\Throwable $e) {
      $this->logger->error('WO-intake: note create failed: @m', ['@m' => $e->getMessage()]);
      return NULL;
    }
  }

  // ==========================================================================
  // Normalization + small helpers.
  // ==========================================================================

  private function normalizeText(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
  }

  private function normalizeStreet(string $s): string {
    $map = $this->settings->get('street_suffix_map') ?? [];
    $tokens = explode(' ', $this->normalizeText($s));
    foreach ($tokens as &$t) {
      if (isset($map[$t])) {
        $t = $map[$t];
      }
    }
    return trim(implode(' ', $tokens));
  }

  private function suffixSet(): array {
    $map = $this->settings->get('street_suffix_map') ?? [];
    return array_values(array_unique(array_merge(array_keys($map), array_values($map))));
  }

  private function loadProperty(int $id): ?EntityInterface {
    $p = $this->entityTypeManager->getStorage('properties')->load($id);
    return ($p && $p->bundle() === 'property') ? $p : NULL;
  }

  private function loadServiceAccount(): ?EntityInterface {
    $a = $this->entityTypeManager->getStorage('user')->loadByProperties(['name' => self::ACCOUNT_NAME]);
    $a = $a ? reset($a) : NULL;
    return ($a && $a->isActive()) ? $a : NULL;
  }

  private function canCreate(string $bundle, AccountInterface $account): bool {
    return $this->entityTypeManager->getAccessControlHandler('work_order')
      ->createAccess($bundle, $account, [], TRUE)->isAllowed();
  }

  private function woSummary(EntityInterface $wo): array {
    $statusTid = $wo->get('field_status')->isEmpty() ? NULL : (int) $wo->get('field_status')->target_id;
    $woId = $wo->hasField('field_work_order_id') && !$wo->get('field_work_order_id')->isEmpty()
      ? (string) $wo->get('field_work_order_id')->value : NULL;
    return [
      'id' => (int) $wo->id(),
      'work_order_id' => $woId,
      'bundle' => $wo->bundle(),
      'status' => $this->statusLabel($wo),
      'status_tid' => $statusTid,
    ];
  }

  private function statusLabel(EntityInterface $wo): ?string {
    if ($wo->get('field_status')->isEmpty()) {
      return NULL;
    }
    $t = $this->entityTypeManager->getStorage('taxonomy_term')->load($wo->get('field_status')->target_id);
    return $t ? $t->label() : NULL;
  }

  private function fmtDate(int|string $ts): string {
    return \Drupal::service('date.formatter')->format((int) $ts, 'custom', 'm/d/Y');
  }

  private function error(string $code, string $message, int $http): array {
    return ['success' => FALSE, 'error' => ['code' => $code, 'message' => $message], 'http' => $http];
  }

  private function err(string $code, string $message, int $http, array $frag): array {
    return ['status' => 'error', 'error' => ['code' => $code, 'message' => $message], 'extracted' => $frag, 'http' => $http];
  }

  private function ambiguous(string $piece, array $candidates, array $frag, ?array $conflict = NULL): array {
    $r = ['status' => 'ambiguous', 'piece' => $piece, 'candidates' => $candidates, 'extracted' => $frag, 'http' => 200];
    if ($conflict) {
      $r['conflict'] = $conflict;
    }
    return $r;
  }

}
