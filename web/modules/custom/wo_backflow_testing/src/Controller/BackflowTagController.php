<?php

namespace Drupal\wo_backflow_testing\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * On-demand HB25-1077 backflow service tag (PDF), printed per test/device.
 *
 * Draws from the same source as the report (_wo_backflow_testing_report_data),
 * so tag and report cannot drift. Streamed inline, not stored (the report PDF
 * is the retained compliance artifact; the tag is a reprintable label).
 */
class BackflowTagController extends ControllerBase {

  /**
   * Streams the service tag PDF for a backflow test child.
   */
  public function tag(EntityInterface $wo_tasks_list) {
    if ($wo_tasks_list->bundle() !== 'backflow_testing') {
      throw new NotFoundHttpException();
    }

    $data = _wo_backflow_testing_report_data($wo_tasks_list);
    $tag = [
      'device_id' => $data['device_id'],
      'device_type' => $data['device_type'],
      'test_date' => $data['test_date'],
      'passed' => $data['passed'],
      'qr_uri' => $data['qr_uri'],
    ];

    $build = [
      '#theme' => 'backflow_service_tag',
      '#tag' => $tag,
    ];
    $html = (string) \Drupal::service('renderer')->renderInIsolation($build);

    $engine = \Drupal::service('plugin.manager.entity_print.print_engine')->createInstance('dompdf');
    $engine->addPage($html);

    $filename = 'backflow-tag-' . ($data['device_id'] !== '' ? $data['device_id'] : ('test-' . $wo_tasks_list->id())) . '.pdf';
    return new Response($engine->getBlob(), 200, [
      'Content-Type' => 'application/pdf',
      'Content-Disposition' => 'inline; filename="' . $filename . '"',
    ]);
  }

}
