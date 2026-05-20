<?php
$ctrl = \Drupal\admin_calendar\Controller\AdminCalendarEventsController::create(\Drupal::getContainer());
$r = new \ReflectionMethod($ctrl, 'buildEvents');
$r->setAccessible(true);
$events = $r->invoke($ctrl, '2026-05-01', '2026-06-01', null, null, null, [1089,1099,1095,1503,1091,1090,1092,1093,1094,1096]);
echo "Raw event array count: " . count($events) . "\n";

$bad = [];
foreach ($events as $i => $e) {
  $j = @json_encode($e);
  if ($j === false) {
    $bad[] = $i;
  }
}
echo "Events that fail json_encode individually: " . count($bad) . "\n";

foreach (array_slice($bad, 0, 5) as $i) {
  $e = $events[$i];
  echo "\n-- BAD ROW idx={$i} --\n";
  echo "  scheduling_id : {$e['id']}\n";
  echo "  wo            : " . $e['extendedProps']['woEntityId'] . "\n";
  echo "  title          : " . $e['title'] . "\n";
  echo "  nickname       : " . $e['extendedProps']['propertyNickname'] . "\n";
  echo "  note           : " . $e['extendedProps']['note'] . "\n";
  $checks = ['title' => $e['title'], 'nickname' => $e['extendedProps']['propertyNickname'], 'serviceName' => $e['extendedProps']['serviceName'], 'note' => $e['extendedProps']['note'], 'description' => $e['extendedProps']['description']];
  foreach ($checks as $k => $v) {
    if ($v !== '' && @json_encode($v) === false) {
      echo "  >>> '{$k}' is the malformed-UTF8 field\n";
      echo "      raw hex: " . bin2hex(substr($v, 0, 120)) . "\n";
    }
  }
}
