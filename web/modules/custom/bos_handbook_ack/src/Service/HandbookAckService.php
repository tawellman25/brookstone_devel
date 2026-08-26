<?php

declare(strict_types=1);

namespace Drupal\bos_handbook_ack\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Current handbook version, acknowledgment lookups/writes, and the gap report.
 */
final class HandbookAckService {

  /**
   * Roles that must acknowledge (the "all staff" population).
   */
  public const STAFF_ROLES = [
    'teammates', 'supervisor', 'administration', 'site_assistant', 'site_admin', 'administrator',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $etm,
    private readonly TimeInterface $time,
  ) {}

  /**
   * The current handbook version — the value on the ROOT "Team Handbook" cover.
   */
  public function currentVersion(): string {
    $s = $this->etm->getStorage('handbook');
    $ids = $s->getQuery()->accessCheck(FALSE)
      ->condition('type', 'cover')->notExists('field_parent_page')->sort('id')->range(0, 1)->execute();
    if (!$ids) {
      return '';
    }
    $root = $s->load(reset($ids));
    return ($root && $root->hasField('field_handbook_version') && !$root->get('field_handbook_version')->isEmpty())
      ? trim((string) $root->get('field_handbook_version')->value) : '';
  }

  /**
   * The acknowledgment record for a user + version, or NULL.
   */
  public function acknowledgmentFor(int $uid, string $version): ?EntityInterface {
    if ($uid <= 0 || $version === '') {
      return NULL;
    }
    $s = $this->etm->getStorage('handbook_acknowledgment');
    $ids = $s->getQuery()->accessCheck(FALSE)
      ->condition('field_user', $uid)
      ->condition('field_handbook_version', $version)
      ->range(0, 1)->execute();
    return $ids ? $s->load(reset($ids)) : NULL;
  }

  public function hasAcknowledged(int $uid, string $version): bool {
    return (bool) $this->acknowledgmentFor($uid, $version);
  }

  /**
   * Create an acknowledgment (append-only, idempotent per user+version).
   */
  public function record(int $uid, string $version, string $typedName, string $ip): EntityInterface {
    $existing = $this->acknowledgmentFor($uid, $version);
    if ($existing) {
      return $existing;
    }
    $user = $this->etm->getStorage('user')->load($uid);
    $s = $this->etm->getStorage('handbook_acknowledgment');
    $e = $s->create([
      'type' => 'acknowledgment',
      'title' => ($user ? $user->getDisplayName() : ('User ' . $uid)) . ' — ' . $version,
      'uid' => $uid,
      'field_user' => $uid,
      'field_handbook_version' => $version,
      'field_acknowledged_on' => $this->time->getRequestTime(),
      'field_typed_name' => mb_substr($typedName, 0, 255),
      'field_ip' => mb_substr($ip, 0, 45),
    ]);
    $e->save();
    return $e;
  }

  /**
   * UIDs of active staff who must acknowledge.
   */
  public function staffUids(): array {
    $ids = $this->etm->getStorage('user')->getQuery()->accessCheck(FALSE)
      ->condition('status', 1)
      ->condition('roles', self::STAFF_ROLES, 'IN')
      ->execute();
    return array_map('intval', array_values($ids));
  }

  /**
   * All versions seen in acknowledgment records, plus the current one — newest first.
   */
  public function allVersions(): array {
    $s = $this->etm->getStorage('handbook_acknowledgment');
    $vs = [];
    foreach ($s->loadMultiple($s->getQuery()->accessCheck(FALSE)->execute()) as $a) {
      $v = (string) $a->get('field_handbook_version')->value;
      if ($v !== '') {
        $vs[$v] = $v;
      }
    }
    $cur = $this->currentVersion();
    if ($cur !== '') {
      $vs[$cur] = $cur;
    }
    krsort($vs);
    return array_values($vs);
  }

  /**
   * Report rows for a version: acknowledged (with details) + the gap.
   */
  public function reportRows(string $version): array {
    $acked = [];
    $gap = [];
    $tz = new \DateTimeZone(date_default_timezone_get());
    $users = $this->etm->getStorage('user')->loadMultiple($this->staffUids());
    foreach ($users as $u) {
      $ack = $this->acknowledgmentFor((int) $u->id(), $version);
      if ($ack) {
        $ts = $ack->get('field_acknowledged_on')->value;
        $acked[] = [
          'uid' => (int) $u->id(),
          'name' => $u->getDisplayName(),
          'date' => $ts ? (new \DateTime('@' . $ts))->setTimezone($tz)->format('m/d/Y g:i A') : '',
          'typed' => (string) $ack->get('field_typed_name')->value,
          'ip' => (string) $ack->get('field_ip')->value,
        ];
      }
      else {
        $gap[] = ['uid' => (int) $u->id(), 'name' => $u->getDisplayName()];
      }
    }
    usort($acked, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    usort($gap, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    return ['acked' => $acked, 'gap' => $gap];
  }

}
