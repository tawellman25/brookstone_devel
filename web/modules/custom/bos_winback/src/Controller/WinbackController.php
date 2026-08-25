<?php

declare(strict_types=1);

namespace Drupal\bos_winback\Controller;

use Drupal\bos_winback\Service\WinbackListService;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Winterize win-back call list + call-outcome recorder.
 */
final class WinbackController extends ControllerBase {

  public function __construct(private readonly WinbackListService $winback) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('bos_winback.list'));
  }

  /**
   * The call list page.
   */
  public function list(): array {
    $rows = $this->winback->getRows();

    $no_phone = count(array_filter($rows, fn($r) => $r['phone'] === ''));
    $canceled = count(array_filter($rows, fn($r) => $r['was_canceled']));
    $revenue = array_sum(array_map(fn($r) => (float) $r['last_total'], $rows));
    $worked = count(array_filter($rows, fn($r) => !empty($r['state'])));

    return [
      '#theme' => 'bos_winback_list',
      '#rows' => $rows,
      '#declined' => $this->winback->getDeclined(),
      '#reasons' => WinbackListService::DECLINE_REASONS,
      '#target_year' => $this->winback->targetYear(),
      '#stats' => [
        'total' => count($rows),
        'no_phone' => $no_phone,
        'canceled' => $canceled,
        'worked' => $worked,
        'revenue' => number_format($revenue, 2),
      ],
      '#attached' => [
        'library' => ['bos_winback/winback'],
        'drupalSettings' => [
          'bosWinback' => [
            'markUrlBase' => '/admin/office/winterize/win-back/mark/',
          ],
        ],
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Record (or clear) a call outcome for one property. AJAX endpoint.
   */
  public function mark(Request $request, int $property): JsonResponse {
    $outcome = (string) $request->request->get('outcome', '');
    $reason = (string) $request->request->get('reason', '');
    $note = (string) $request->request->get('note', '');
    $by = (string) $this->currentUser()->getDisplayName();

    if ($outcome === 'clear') {
      $this->winback->clearState($property);
      return new JsonResponse(['status' => 'ok', 'cleared' => TRUE]);
    }

    try {
      $rec = $this->winback->mark($property, $outcome, $by, $reason, $note);
    }
    catch (\InvalidArgumentException $e) {
      return new JsonResponse(['status' => 'error', 'message' => $e->getMessage()], 400);
    }

    return new JsonResponse([
      'status' => 'ok',
      'outcome' => $outcome,
      'by' => $by,
      'time' => date('m/d/Y', $rec['time_ts']),
      // Declined removes the customer from the list.
      'suppress' => $outcome === 'declined',
    ]);
  }

}
