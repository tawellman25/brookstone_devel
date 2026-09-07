<?php

declare(strict_types=1);

namespace Drupal\bos_hoa\Commands;

use Drupal\bos_hoa\HoaMembershipService;
use Drush\Commands\DrushCommands;

/**
 * HOA GPS membership sync.
 */
final class BosHoaCommands extends DrushCommands {

  public function __construct(
    private readonly HoaMembershipService $membership,
  ) {
    parent::__construct();
  }

  /**
   * Scan discounted HOA boundaries and auto-flag member homes for the discount.
   *
   * @command bos:hoa:sync-members
   * @option apply Write changes (default is a dry run).
   * @option hoa Limit to a single HOA property ID.
   * @usage drush bos:hoa:sync-members
   *   Dry run — report homes that would be added/removed per HOA.
   * @usage drush bos:hoa:sync-members --apply
   *   Apply: link + flag homes inside discounted HOA boundaries.
   */
  public function syncMembers(array $options = ['apply' => FALSE, 'hoa' => NULL]): void {
    $apply = (bool) $options['apply'];
    $this->io()->writeln($apply ? '*** APPLY ***' : '--- DRY RUN (use --apply to write) ---');

    $ids = $options['hoa'] ? [(int) $options['hoa']] : $this->membership->allHoaIds();
    $totAdd = 0; $totRemove = 0; $totInside = 0;
    foreach ($ids as $hoaId) {
      $r = $this->membership->syncHoa((int) $hoaId, $apply);
      $flag = $r['discounted'] ? 'discounted' : 'NOT discounted';
      $this->io()->writeln(sprintf(
        '  HOA %d "%s" [%s]: inside %d | +%d added | -%d removed',
        $r['hoa'], $r['name'], $flag, $r['inside'], $r['added'], $r['removed']
      ));
      $totInside += $r['inside']; $totAdd += $r['added']; $totRemove += $r['removed'];
    }
    $this->io()->success(sprintf(
      '%s — inside total %d, added %d, removed %d.',
      $apply ? 'Applied' : 'Dry run', $totInside, $totAdd, $totRemove
    ));
  }

}
