<?php
$fd = \Drupal\Core\Entity\Entity\EntityFormDisplay::load('properties.hoa.default');
if (!$fd) { print "no hoa form display\n"; return; }
$c = $fd->getComponent('field_boundary') ?: ['weight' => 4, 'region' => 'content'];
$c['type'] = 'hoa_boundary_map';
$c['settings'] = [];
$c['third_party_settings'] = [];
$fd->setComponent('field_boundary', $c)->save();
print "field_boundary widget set to hoa_boundary_map\n";
