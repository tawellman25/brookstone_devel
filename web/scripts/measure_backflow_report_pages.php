<?php

/**
 * Render a backflow test report to PDF (in memory, NOT stored) and print its
 * page count. Used to tune the report template to fit one page.
 *
 *   BOS_MEASURE_CHILD=35242 drush php:script web/scripts/measure_backflow_report_pages.php
 *
 * With no child id, measures every backflow test child that has a report and
 * prints the page count for each (so we can see which assemblies overflow).
 */

$only = getenv('BOS_MEASURE_CHILD');

$etm = \Drupal::entityTypeManager();
if ($only) {
  $ids = [(int) $only];
}
else {
  $ids = \Drupal::entityQuery('wo_tasks_list')
    ->accessCheck(FALSE)
    ->condition('type', 'backflow_testing')
    ->exists('field_report_pdf')
    ->execute();
}

$renderer = \Drupal::service('renderer');
$engineMgr = \Drupal::service('plugin.manager.entity_print.print_engine');

foreach ($etm->getStorage('wo_tasks_list')->loadMultiple($ids) as $child) {
  $data = _wo_backflow_testing_report_data($child);
  $build = ['#theme' => 'backflow_test_report', '#report' => $data];
  $html = (string) $renderer->renderInIsolation($build);
  $engine = $engineMgr->createInstance('dompdf');
  $engine->addPage($html);
  $blob = $engine->getBlob();

  // Count pages: decompress any Flate streams, then count /Type /Page (not Pages).
  $pages = 0;
  if (preg_match_all('#stream\r?\n(.*?)\r?\nendstream#s', $blob, $m)) {
    foreach ($m[1] as $s) {
      $dec = @gzuncompress($s);
      if ($dec !== FALSE) {
        $pages += preg_match_all('#/Type\s*/Page[^s]#', $dec);
      }
    }
  }
  $pages += preg_match_all('#/Type\s*/Page[^s]#', $blob);
  $pages = max(1, $pages);

  $dev = $data['device_id'] !== '' ? $data['device_id'] : ('test-' . $child->id());
  $type = $data['device_type'] ?? '';
  $reads = is_array($data['readings'] ?? NULL) ? count($data['readings']) : 0;
  $tags = is_array($data['tag_photos'] ?? NULL) ? count($data['tag_photos']) : 0;
  $gauge = !empty($data['gauge']) ? 'gauge' : 'no-gauge';
  printf("child %-6s %-10s %-45s pages=%d  readings=%d tagphotos=%d %s\n",
    $child->id(), $dev, mb_substr($type, 0, 45), $pages, $reads, $tags, $gauge);
}
