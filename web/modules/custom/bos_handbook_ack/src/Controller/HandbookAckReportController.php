<?php

declare(strict_types=1);

namespace Drupal\bos_handbook_ack\Controller;

use Drupal\bos_handbook_ack\Service\HandbookAckService;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handbook acknowledgment report: who has / hasn't acknowledged a version.
 */
final class HandbookAckReportController extends ControllerBase {

  public function __construct(private readonly HandbookAckService $svc) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('bos_handbook_ack.service'));
  }

  public function report(Request $request): array {
    $current = $this->svc->currentVersion();
    $versions = $this->svc->allVersions();
    $requested = (string) $request->query->get('version', '');
    $version = ($requested !== '' && in_array($requested, $versions, TRUE)) ? $requested : $current;

    $rows = $this->svc->reportRows($version);
    $acked = $rows['acked'];
    $gap = $rows['gap'];
    $total = count($acked) + count($gap);

    return [
      '#theme' => 'bos_handbook_ack_report',
      '#acked' => $acked,
      '#gap' => $gap,
      '#version' => $version,
      '#versions' => $versions,
      '#stats' => [
        'acked' => count($acked),
        'gap' => count($gap),
        'total' => $total,
        'pct' => $total ? round(count($acked) / $total * 100) : 0,
        'is_current' => ($version === $current),
      ],
      '#attached' => ['library' => ['bos_handbook_ack/report']],
      '#cache' => ['max-age' => 0],
    ];
  }

}
