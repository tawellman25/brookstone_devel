<?php
$ids = \Drupal::entityTypeManager()->getStorage('properties')->getQuery()->accessCheck(FALSE)->condition('type','hoa')->execute();
$made = 0; $unpub = [];
foreach ($ids as $id) {
  $h = \Drupal::entityTypeManager()->getStorage('properties')->load($id);
  if (!$h) { continue; }
  if ($h instanceof \Drupal\Core\Entity\EntityPublishedInterface && !$h->isPublished()) { $unpub[] = $id; }
  _bos_hoa_ensure_public_alias($h);
  $alias = \Drupal::service('path_alias.manager')->getAliasByPath('/hoa/' . $id);
  print "  $id → $alias\n";
  $made++;
}
print "aliases ensured: $made | unpublished (public page would 403 for anon): " . (count($unpub) ? implode(',', $unpub) : 'none') . "\n";
