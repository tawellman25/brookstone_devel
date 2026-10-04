<?php

declare(strict_types=1);

namespace Drupal\bos_content_coverage;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\taxonomy\TermInterface;

/**
 * What copy is actually live on the public taxonomy terms.
 *
 * WHY THIS EXISTS
 * ---------------
 * Twice in two days, marketing and Code each asserted the wrong thing about
 * live content, in opposite directions. Marketing's copy files say what was
 * WRITTEN; they carried cross-links to a path that had been renamed, which
 * would have 404'd. Code's build reports say what was BUILT; working from an
 * extract it concluded the /material view header was not live when it was.
 * Both inferences were reasonable from the artifact in hand and both were
 * wrong, because neither artifact is the database. This class is the database
 * answer, and the report built on it is queryable by both sides.
 *
 * THE THREE THINGS WORTH EXTENDING ARE ALL CONSTANTS HERE.
 */
final class ContentCoverage {

  /**
   * The copy ROLES a public term page can carry, keyed by column.
   *
   * These are roles, not field names, on purpose — see FIELD_OVERRIDES.
   */
  public const ROLES = [
    'teaser' => 'Teaser (card)',
    'body' => 'Public description',
    'cta' => 'Call to action',
    'crew' => 'Crew description',
  ];

  /**
   * The field that normally holds each role.
   */
  public const DEFAULT_FIELDS = [
    'teaser' => 'field_short_description',
    'body' => 'field_public_description',
    'cta' => 'field_call_to_action',
    'crew' => 'field_teammate_description',
  ];

  /**
   * Vocabularies that hold a role in a DIFFERENTLY NAMED field.
   *
   * `services` is the live case and the reason this indirection exists: its
   * public and crew copy live in field_service_public_desc (59 of 60 terms) and
   * field_service_crew_desc (55 of 60). A report that only knew the four default
   * field names would show all 60 service pages as having no copy at all —
   * precisely the false reading this report is meant to prevent. Add a
   * vocabulary here when it stores a role somewhere else.
   */
  public const FIELD_OVERRIDES = [
    'services' => [
      'body' => 'field_service_public_desc',
      'crew' => 'field_service_crew_desc',
    ],
  ];

  /**
   * Generic agency-register copy being replaced across the catalogue.
   *
   * Matched case-insensitively anywhere in a field's value. Extend this list as
   * more patterns of placeholder copy turn up; everything else keys off it.
   */
  public const BOILERPLATE_PHRASES = [
    'At Brookstone Outdoors',
    'Brookstone Outdoors carries',
    'Brookstone Outdoors offers',
    'category brings',
    'category is dedicated to',
  ];

  /**
   * Vocabularies covered, beyond the public allowlist bos_breadcrumbs defines.
   *
   * Deliberately empty: the repo already has ONE considered answer to "which
   * vocabularies render a public page" — the bos_breadcrumbs allowlist, which is
   * an allowlist precisely because ~1,300 operational term pages are publicly
   * indexed and must not be swept in. Reusing it keeps a single source of truth,
   * so the report cannot drift from what the site treats as public.
   */
  public const ADDITIONAL_VIDS = [];

  /**
   * Used only if bos_breadcrumbs is ever removed, so the report still works.
   */
  private const FALLBACK_VIDS = [
    'services', 'material_types', 'backflow_device_types', 'backflow_uses',
    'plant_characteristics', 'plant_character_categories',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $etm,
    private readonly EntityFieldManagerInterface $efm,
    private readonly AliasManagerInterface $aliasManager,
  ) {}

  /**
   * The vocabularies this report covers.
   */
  public function coveredVids(): array {
    $vids = class_exists('Drupal\bos_breadcrumbs\BosTermAliasBreadcrumbBuilder')
      && defined('Drupal\bos_breadcrumbs\BosTermAliasBreadcrumbBuilder::ALLOWED_VIDS')
      ? \Drupal\bos_breadcrumbs\BosTermAliasBreadcrumbBuilder::ALLOWED_VIDS
      : self::FALLBACK_VIDS;
    $vids = array_values(array_unique(array_merge($vids, self::ADDITIONAL_VIDS)));
    sort($vids);
    return $vids;
  }

  /**
   * The field holding a role on a vocabulary, or NULL when it has none.
   */
  public function fieldFor(string $vid, string $role): ?string {
    $name = self::FIELD_OVERRIDES[$vid][$role] ?? (self::DEFAULT_FIELDS[$role] ?? NULL);
    if (!$name) {
      return NULL;
    }
    $defs = $this->efm->getFieldDefinitions('taxonomy_term', $vid);
    return isset($defs[$name]) ? $name : NULL;
  }

  /**
   * The state of one role on one term.
   *
   * @return array
   *   applicable: does this vocabulary have the field at all (vs empty).
   *   populated:  does it hold anything.
   *   chars:      length of the plain text, so a count means readable words
   *               rather than markup.
   *   boilerplate: matches a phrase in BOILERPLATE_PHRASES.
   */
  public function state(TermInterface $term, string $role): array {
    $field = $this->fieldFor($term->bundle(), $role);
    if (!$field || !$term->hasField($field)) {
      return ['applicable' => FALSE, 'populated' => FALSE, 'chars' => 0, 'boilerplate' => FALSE];
    }
    $item = $term->get($field);
    if ($item->isEmpty()) {
      return ['applicable' => TRUE, 'populated' => FALSE, 'chars' => 0, 'boilerplate' => FALSE];
    }
    $raw = (string) ($item->first()->getValue()['value'] ?? '');
    // Count the words a reader sees, not the markup around them.
    $plain = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    return [
      'applicable' => TRUE,
      'populated' => $plain !== '',
      'chars' => mb_strlen($plain),
      'boilerplate' => $this->isBoilerplate($raw),
    ];
  }

  /**
   * Does this value carry generic placeholder copy?
   */
  public function isBoilerplate(string $value): bool {
    foreach (self::BOILERPLATE_PHRASES as $phrase) {
      if (stripos($value, $phrase) !== FALSE) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Is any role on this term carrying boilerplate?
   */
  public function termIsFlagged(TermInterface $term): bool {
    foreach (array_keys(self::ROLES) as $role) {
      if ($this->state($term, $role)['boilerplate']) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * The term's live URL alias — the column that stops copy being written
   * against a path from a design document.
   */
  public function alias(TermInterface $term): string {
    return $this->aliasManager->getAliasByPath('/taxonomy/term/' . $term->id());
  }

  /**
   * Term ids in the covered vocabularies matching a report question.
   *
   * Resolved in PHP rather than SQL because the field holding a role varies by
   * vocabulary (see FIELD_OVERRIDES), which is not one predicate. The covered
   * set is a few hundred terms, so this is cheap, and it keeps the answer
   * identical to what the columns display — a filter that disagreed with the
   * column beside it would be worse than no filter.
   *
   * @param string $question
   *   'boilerplate', 'missing_body' or 'missing_teaser'.
   */
  public function matchingTids(string $question): array {
    $storage = $this->etm->getStorage('taxonomy_term');
    $tids = [];
    foreach ($storage->loadByProperties(['vid' => $this->coveredVids()]) as $term) {
      // An UNPUBLISHED term is not a public page, so missing copy on it is not
      // a public gap. Without this the report flags work that does not exist:
      // on 2026-10-03 it listed "In House Task" as a public page with an empty
      // body, marketing wrote a recommendation to unpublish it, and the term
      // had been unpublished the whole time — /in-house-task was already a 404
      // to anonymous visitors and absent from both the services listing and the
      // sitemap. Publication status is part of whether something is public.
      if ($term instanceof EntityPublishedInterface && !$term->isPublished()) {
        continue;
      }
      $match = match ($question) {
        'boilerplate' => $this->termIsFlagged($term),
        // "Missing" means the vocabulary HAS the field and it is empty. A
        // vocabulary without the field is not missing copy; it never had the
        // slot, and reporting it as a gap would invent work.
        'missing_body' => (function () use ($term) {
          $s = $this->state($term, 'body');
          return $s['applicable'] && !$s['populated'];
        })(),
        'missing_teaser' => (function () use ($term) {
          $s = $this->state($term, 'teaser');
          return $s['applicable'] && !$s['populated'];
        })(),
        default => FALSE,
      };
      if ($match) {
        $tids[] = (int) $term->id();
      }
    }
    return $tids;
  }

}
