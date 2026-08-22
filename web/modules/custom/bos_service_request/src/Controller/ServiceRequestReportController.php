<?php

declare(strict_types=1);

namespace Drupal\bos_service_request\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Attribution report for public service requests — the funnel measurement (§14).
 *
 * Uses direct GROUP BY aggregate queries (no clean entity-API path for grouped
 * counts). The per-record queue is the Views table; this is only the roll-up.
 */
final class ServiceRequestReportController extends ControllerBase {

  public function __construct(private readonly Connection $database) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('database'));
  }

  public function page(): array {
    $total = (int) $this->database->select('service_request_field_data', 's')->countQuery()->execute()->fetchField();
    $wants = $this->groupCount('service_request__field_wants_recurring', 'field_wants_recurring_value');
    $optIns = (int) ($wants['1'] ?? 0);

    $build = [];
    $build['summary'] = [
      '#markup' => '<h2>Summary</h2><p><strong>' . $total . '</strong> total requests · <strong>' . $optIns
        . '</strong> opted into the automatic winterizing list.</p>',
    ];

    // Postcard variants broken out explicitly — B is the number that justifies
    // next year's spend and must never be pooled with A (P0.1).
    $byCampaign = $this->groupCount('service_request__field_campaign', 'field_campaign_value');
    $variantLabels = [
      'pc26a' => 'Postcard A — "already on our list"',
      'pc26b' => 'Postcard B — "time to schedule"',
      'pc26' => 'Postcard (legacy pc26)',
      'website' => 'Website',
    ];
    $variantRows = [];
    foreach ($variantLabels as $code => $label) {
      if (isset($byCampaign[$code])) {
        $variantRows[$label] = $byCampaign[$code];
      }
    }
    $build['variants'] = $this->table('Postcard variants (A vs B, kept separate)', $variantRows);

    $build['by_source'] = $this->table('By source', $this->groupCount('service_request__field_source', 'field_source_value'));
    $build['by_campaign'] = $this->table('By campaign (all codes)', $byCampaign);
    $build['by_year'] = $this->table('By service year', $this->groupCount('service_request__field_service_year', 'field_service_year_value'));
    $build['by_status'] = $this->table('By status', $this->statusCounts());
    $build['#cache'] = ['max-age' => 0];
    return $build;
  }

  /**
   * GROUP BY count for a single-value field table: [value => count] desc.
   *
   * @return array<string,int>
   */
  private function groupCount(string $table, string $column): array {
    if (!$this->database->schema()->tableExists($table)) {
      return [];
    }
    $q = $this->database->select($table, 't');
    $q->addField('t', $column, 'v');
    $q->addExpression('COUNT(*)', 'c');
    $q->groupBy('t.' . $column);
    $q->orderBy('c', 'DESC');
    $out = [];
    foreach ($q->execute() as $row) {
      $out[(string) $row->v] = (int) $row->c;
    }
    return $out;
  }

  /**
   * Status counts, mapping the term id to its label.
   *
   * @return array<string,int>
   */
  private function statusCounts(): array {
    $raw = $this->groupCount('service_request__field_request_status', 'field_request_status_target_id');
    if (!$raw) {
      return [];
    }
    $terms = $this->entityTypeManager()->getStorage('taxonomy_term')->loadMultiple(array_map('intval', array_keys($raw)));
    $out = [];
    foreach ($raw as $tid => $count) {
      $label = isset($terms[$tid]) ? $terms[$tid]->label() : ('tid ' . $tid);
      $out[$label] = $count;
    }
    return $out;
  }

  /**
   * Render a labelled two-column count table.
   */
  private function table(string $title, array $counts): array {
    $rows = [];
    foreach ($counts as $label => $count) {
      $rows[] = [$label === '' ? '(none)' : $label, $count];
    }
    return [
      '#type' => 'table',
      '#caption' => $title,
      '#header' => [$this->t('Value'), $this->t('Requests')],
      '#rows' => $rows,
      '#empty' => $this->t('No data yet.'),
      '#attributes' => ['style' => 'max-width:520px;margin-bottom:1.5rem'],
    ];
  }

}
