<?php

namespace Drupal\bos_team_apparel\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Url;
use Drupal\field\Entity\FieldStorageConfig;

/**
 * Active-teammate shirt/hat report with per-size order totals.
 */
class ApparelReportController extends ControllerBase {

  /**
   * Build the report.
   */
  public function report() {
    $shirtLabels = $this->allowedValues('field_shirt_size');
    $hatLabels = $this->allowedValues('field_hat_preference');

    // Gather active teammates.
    $storage = $this->entityTypeManager()->getStorage('profile');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'teammate_profile')
      ->execute();

    // Return here after editing a profile.
    $dest = Url::fromRoute('bos_team_apparel.report')->toString();

    $roster = [];
    $shirtTally = array_fill_keys(array_keys($shirtLabels), 0);
    $hatTally = array_fill_keys(array_keys($hatLabels), 0);
    $shirtMissing = 0;
    $hatMissing = 0;

    foreach ($storage->loadMultiple($ids) as $profile) {
      $user = $profile->getOwner();
      if (!$user || !$user->isActive() || !in_array('teammates', $user->getRoles(), TRUE)) {
        continue;
      }
      $shirt = $profile->get('field_shirt_size')->value ?? '';
      $hat = $profile->get('field_hat_preference')->value ?? '';
      if ($shirt !== '' && isset($shirtTally[$shirt])) {
        $shirtTally[$shirt]++;
      }
      else {
        $shirtMissing++;
      }
      if ($hat !== '' && isset($hatTally[$hat])) {
        $hatTally[$hat]++;
      }
      else {
        $hatMissing++;
      }
      $roster[] = [
        'name' => $user->getDisplayName(),
        // Link the name to the teammate profile edit form (with a return here),
        // for anyone allowed to edit it; otherwise plain text.
        'edit_url' => $profile->access('update') ? $profile->toUrl('edit-form', ['query' => ['destination' => $dest]]) : NULL,
        'shirt' => $shirt !== '' ? ($shirtLabels[$shirt] ?? $shirt) : '—',
        'hat' => $hat !== '' ? ($hatLabels[$hat] ?? $hat) : '—',
      ];
    }
    usort($roster, fn($a, $b) => strcasecmp($a['name'], $b['name']));

    $build = [
      '#cache' => ['tags' => ['profile_list', 'user_list'], 'contexts' => ['user.roles']],
    ];
    $build['#attached']['library'][] = 'bos_team_apparel/report';

    $build['intro'] = [
      '#markup' => '<p class="apparel-intro">' . $this->t('@count active team members. Totals below are for ordering; anyone showing "—" or in the "Not recorded" count still needs a size entered on their teammate profile.', ['@count' => count($roster)]) . '</p>',
    ];

    // Order totals — the at-a-glance numbers.
    $build['totals'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['apparel-totals']],
    ];
    $build['totals']['shirts'] = $this->tallyTable(
      $this->t('Shirts to order'),
      $shirtLabels,
      $shirtTally,
      $shirtMissing
    );
    $build['totals']['hats'] = $this->tallyTable(
      $this->t('Hats to order'),
      $hatLabels,
      $hatTally,
      $hatMissing
    );

    // Full roster.
    $rows = [];
    foreach ($roster as $r) {
      $nameCell = $r['edit_url']
        ? ['data' => ['#type' => 'link', '#title' => $r['name'], '#url' => $r['edit_url']]]
        : $r['name'];
      $rows[] = [
        $nameCell,
        ['data' => $r['shirt'], 'class' => $r['shirt'] === '—' ? ['apparel-missing'] : []],
        ['data' => $r['hat'], 'class' => $r['hat'] === '—' ? ['apparel-missing'] : []],
      ];
    }
    $build['roster_heading'] = ['#markup' => '<h2>' . $this->t('Team members') . '</h2>'];
    $build['roster'] = [
      '#type' => 'table',
      '#header' => [$this->t('Team member'), $this->t('Shirt size'), $this->t('Hat preference')],
      '#rows' => $rows,
      '#sticky' => TRUE,
      '#empty' => $this->t('No active team members found.'),
      '#attributes' => ['class' => ['apparel-roster']],
    ];

    return $build;
  }

  /**
   * A totals table: label => count (in the field's defined order), + a
   * "Not recorded" row. Zero-count sizes are shown so the full spread is visible.
   */
  protected function tallyTable($title, array $labels, array $tally, int $missing) {
    $rows = [];
    $total = 0;
    foreach ($labels as $key => $label) {
      $n = $tally[$key] ?? 0;
      $total += $n;
      $rows[] = [
        ['data' => $label],
        ['data' => $n, 'class' => array_merge(['apparel-count'], $n === 0 ? ['apparel-zero'] : [])],
      ];
    }
    // Grand total to order = every recorded size added up.
    $rows[] = [
      'data' => [
        ['data' => $this->t('Total to order')],
        ['data' => $total, 'class' => ['apparel-count']],
      ],
      'class' => ['apparel-total-row'],
    ];
    if ($missing > 0) {
      $rows[] = [
        ['data' => $this->t('Not recorded yet'), 'class' => ['apparel-missing']],
        ['data' => $missing, 'class' => ['apparel-count', 'apparel-missing']],
      ];
    }
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['apparel-tally']],
      'title' => ['#markup' => '<h2>' . $title . '</h2>'],
      'table' => [
        '#type' => 'table',
        '#header' => [$this->t('Size / type'), $this->t('Qty')],
        '#rows' => $rows,
        '#attributes' => ['class' => ['apparel-tally-table']],
      ],
      'note' => ['#markup' => '<p class="apparel-tally-total">' . $this->t('@n of @all team members have a size recorded.', ['@n' => $total, '@all' => $total + $missing]) . '</p>'],
    ];
  }

  /**
   * Allowed values (key => label) for a teammate_profile list field.
   */
  protected function allowedValues(string $field): array {
    $storage = FieldStorageConfig::loadByName('profile', $field);
    if (!$storage instanceof FieldStorageDefinitionInterface) {
      return [];
    }
    return $storage->getSetting('allowed_values') ?: [];
  }

}
