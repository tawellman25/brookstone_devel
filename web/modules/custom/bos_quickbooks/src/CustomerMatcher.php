<?php

declare(strict_types=1);

namespace Drupal\bos_quickbooks;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Name → ListID: links mirrored QuickBooks customers to BOS clients.
 *
 * QuickBooks names carry the office's status flags as suffixes ("Smith, John
 * & Jane EMAIL", "… NO SERVICES", "… DECEASED"); BOS holds the same people as
 * first / last / spouse / company on the customer profile and a user name.
 * Both sides are reduced to a handful of normalised keys ("john smith",
 * "smith john", "john & jane smith", the company) and compared. Exactly one
 * BOS client → linked automatically; two or more → Ambiguous (the note lists
 * them); none → Unmatched; a client another QuickBooks customer already
 * points at → Duplicate. Only the office changes a Manual or Ignored row.
 *
 * Measured on the 2026-11-15 lists export: 1,494 of 1,734 active customers
 * resolve to exactly one client, 22 ambiguous, 218 unmatched.
 */
final class CustomerMatcher {

  public const SUFFIXES = ['EMAIL', 'NO EMAIL', 'NO SERVICES', 'COLLECTIONS', 'BAD DEBT', 'PREPAY', 'COD', 'CASH', 'HOLD', 'DECEASED', 'MOVED', 'SOLD', 'PP', 'HOA', 'SEE NOTES'];
  public const RETRY_STATUSES = ['unmatched', 'ambiguous', 'duplicate'];
  public const HUMAN_STATUSES = ['manual', 'ignored'];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Runs the pass over every active, present, unlinked customer.
   *
   * @param bool $dryRun
   *   Count and report, write nothing.
   * @param bool $rematch
   *   Also re-evaluate rows the pass itself matched earlier (never Manual /
   *   Ignored, never a row with a client the office set).
   *
   * @return array{scanned:int,auto:int,ambiguous:int,unmatched:int,duplicate:int,unchanged:int,dry_run:bool,samples:array}
   */
  public function run(bool $dryRun = FALSE, bool $rematch = FALSE): array {
    $index = $this->bosIndex();
    $linked = $this->linkedClients();
    $storage = $this->entityTypeManager->getStorage(QbListMirror::ENTITY_TYPE);
    $query = $storage->getQuery()->accessCheck(FALSE)
      ->condition('type', 'customer')
      ->condition('status', 1)
      ->condition('field_missing', 1, '<>')
      ->condition('field_match_status', self::HUMAN_STATUSES, 'NOT IN')
      ->sort('id');
    if (!$rematch) {
      $query->condition('field_match_status', self::RETRY_STATUSES, 'IN');
      $query->notExists('field_bos_client');
    }
    $ids = $query->execute();
    $stats = ['scanned' => 0, 'auto' => 0, 'ambiguous' => 0, 'unmatched' => 0, 'duplicate' => 0, 'unchanged' => 0, 'dry_run' => $dryRun, 'samples' => ['ambiguous' => [], 'unmatched' => [], 'duplicate' => []]];

    foreach (array_chunk($ids, 100) as $chunk) {
      foreach ($storage->loadMultiple($chunk) as $customer) {
        $stats['scanned']++;
        $id = (int) $customer->id();
        $current_uid = $customer->get('field_bos_client')->isEmpty() ? NULL : (int) $customer->get('field_bos_client')->target_id;
        if ($rematch && $current_uid && (string) $customer->get('field_match_status')->value !== 'auto') {
          // A client the office set stays, whatever the status says.
          $stats['unchanged']++;
          continue;
        }
        $hits = [];
        $hit_key = '';
        foreach ($this->candidateKeys($customer) as $key) {
          if (isset($index[$key])) {
            foreach ($index[$key] as $uid => $name) {
              if (!isset($hits[$uid])) {
                $hits[$uid] = $name;
                $hit_key = $hit_key ?: $key;
              }
            }
          }
        }
        $status = 'unmatched';
        $note = '';
        $uid = NULL;
        if (count($hits) === 1) {
          $uid = (int) array_key_first($hits);
          $other = $linked[$uid] ?? NULL;
          if ($other && $other['id'] !== $id) {
            $status = 'duplicate';
            $note = sprintf('%s (uid %d) is already linked by QuickBooks customer "%s" (ListID %s). A merge in QuickBooks BOS has not heard about, or two records for one client — resolve in QuickBooks, then re-run.', $hits[$uid], $uid, $other['title'], $other['list_id']);
            $uid = NULL;
          }
          else {
            $status = 'auto';
            $note = sprintf('Matched on "%s" → %s (uid %d).', $hit_key, $hits[$uid], $uid);
          }
        }
        elseif (count($hits) > 1) {
          $status = 'ambiguous';
          $list = [];
          foreach (array_slice($hits, 0, 6, TRUE) as $h_uid => $h_name) {
            $list[] = sprintf('%s (uid %d)', $h_name, $h_uid);
          }
          $note = 'Candidates: ' . implode(' | ', $list) . (count($hits) > 6 ? sprintf(' | +%d more', count($hits) - 6) : '') . '. Compare the Bill To address with each client\'s address, pick one, save.';
        }

        $old_status = (string) $customer->get('field_match_status')->value;
        $old_note = (string) $customer->get('field_match_note')->value;
        if ($old_status === $status && $old_note === $note && $current_uid === $uid) {
          $stats['unchanged']++;
          continue;
        }
        $stats[$status]++;
        if (count($stats['samples'][$status] ?? [-1]) < 10 && isset($stats['samples'][$status])) {
          $stats['samples'][$status][] = $customer->label();
        }
        if ($uid) {
          $linked[$uid] = ['id' => $id, 'title' => $customer->label(), 'list_id' => (string) $customer->get('field_list_id')->value];
        }
        if ($dryRun) {
          continue;
        }
        $customer->set('field_bos_client', $uid);
        $customer->set('field_match_status', $status);
        $customer->set('field_match_note', $note);
        $customer->_bos_qb_matcher = TRUE;
        $customer->save();
      }
    }
    $this->logger->notice('Customer matching pass@dry: @scanned scanned — @auto matched, @ambiguous ambiguous, @unmatched unmatched, @duplicate duplicate, @unchanged unchanged.', [
      '@dry' => $dryRun ? ' (dry run)' : '',
      '@scanned' => $stats['scanned'],
      '@auto' => $stats['auto'],
      '@ambiguous' => $stats['ambiguous'],
      '@unmatched' => $stats['unmatched'],
      '@duplicate' => $stats['duplicate'],
      '@unchanged' => $stats['unchanged'],
    ]);
    return $stats;
  }

  /**
   * The keys a mirrored QuickBooks customer can be found under.
   *
   * @return string[]
   */
  public function candidateKeys($customer): array {
    $title = (string) $customer->label();
    $leaf = (string) ($customer->get('field_name')->value ?: $title);
    $base = $this->stripSuffixes((int) $customer->get('field_sublevel')->value > 0 ? $leaf : $title);
    $keys = [self::norm($base)];
    if (str_contains($base, ',')) {
      [$last, $rest] = array_map('trim', explode(',', $base, 2));
      $keys[] = self::norm("$rest $last");
      $pair = preg_split('/\s*(?:&|\band\b)\s*/i', $rest);
      if (count($pair) === 2) {
        [$a, $b] = array_map('trim', $pair);
        $keys[] = self::norm("$a $last");
        $keys[] = self::norm("$b $last");
        $keys[] = self::norm("$last $b & $a");
        $keys[] = self::norm("$b & $a $last");
      }
    }
    $first = (string) $customer->get('field_first_name')->value;
    $lastname = (string) $customer->get('field_last_name')->value;
    $company = (string) $customer->get('field_company_name')->value;
    if ($first !== '' && $lastname !== '') {
      $keys[] = self::norm("$first $lastname");
      $keys[] = self::norm("$lastname $first");
    }
    if ($company !== '') {
      $keys[] = self::norm($this->stripSuffixes($company));
    }
    return array_values(array_unique(array_filter($keys)));
  }

  /**
   * "Smith, John & Jane EMAIL NO SERVICES" → "Smith, John & Jane".
   */
  public function stripSuffixes(string $name): string {
    $pattern = '/\s+(' . implode('|', array_map(static fn($s) => preg_quote($s, '/'), self::SUFFIXES)) . ')$/i';
    $name = trim($name);
    while (preg_match($pattern, $name)) {
      $name = trim((string) preg_replace($pattern, '', $name));
    }
    return $name;
  }

  /**
   * Lower-case, "and" → "&", letters/digits/&/space only, single spaces.
   */
  public static function norm(string $s): string {
    $s = mb_strtolower(trim($s));
    $s = preg_replace('/\band\b/', '&', $s);
    $s = preg_replace('/[^a-z0-9& ]+/u', ' ', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    return trim((string) $s);
  }

  /**
   * key → [uid => display name], for every user with the client role.
   */
  private function bosIndex(): array {
    $sql = "SELECT u.uid, u.name AS username, fn.field_first_name_value AS first_name, ln.field_last_name_value AS last_name,
        sp.field_spouse_name_value AS spouse, co.field_company_name_value AS company
      FROM {users_field_data} u
      INNER JOIN {user__roles} r ON r.entity_id = u.uid AND r.roles_target_id = 'client'
      LEFT JOIN {profile} p ON p.uid = u.uid AND p.type = 'customer_profile'
      LEFT JOIN {profile__field_first_name} fn ON fn.entity_id = p.profile_id
      LEFT JOIN {profile__field_last_name} ln ON ln.entity_id = p.profile_id
      LEFT JOIN {profile__field_spouse_name} sp ON sp.entity_id = p.profile_id
      LEFT JOIN {profile__field_company_name} co ON co.entity_id = p.profile_id
      WHERE u.uid > 0";
    $index = [];
    foreach ($this->database->query($sql) as $row) {
      $uid = (int) $row->uid;
      $display = trim(($row->first_name ?? '') . ' ' . ($row->last_name ?? '')) ?: (string) $row->username;
      $keys = [];
      if ($row->username) {
        $keys[] = self::norm($row->username);
      }
      if ($row->company) {
        $keys[] = self::norm($row->company);
      }
      if ($row->first_name && $row->last_name) {
        $f = trim($row->first_name);
        $l = trim($row->last_name);
        $keys[] = self::norm("$f $l");
        $keys[] = self::norm("$l $f");
        if ($row->spouse) {
          $s = trim($row->spouse);
          $keys[] = self::norm("$l $f & $s");
          $keys[] = self::norm("$f & $s $l");
          $keys[] = self::norm("$s & $f $l");
          $keys[] = self::norm("$l $s & $f");
        }
      }
      foreach (array_unique(array_filter($keys)) as $key) {
        $index[$key][$uid] = $display;
      }
    }
    return $index;
  }

  /**
   * uid → the present customer already linked to it.
   */
  private function linkedClients(): array {
    $storage = $this->entityTypeManager->getStorage(QbListMirror::ENTITY_TYPE);
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('type', 'customer')
      ->condition('field_missing', 1, '<>')
      ->exists('field_bos_client')
      ->execute();
    $linked = [];
    foreach (array_chunk($ids, 200) as $chunk) {
      foreach ($storage->loadMultiple($chunk) as $customer) {
        $uid = (int) $customer->get('field_bos_client')->target_id;
        $linked[$uid] = ['id' => (int) $customer->id(), 'title' => $customer->label(), 'list_id' => (string) $customer->get('field_list_id')->value];
      }
    }
    return $linked;
  }

}
