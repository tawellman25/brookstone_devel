<?php

namespace Drupal\work_order_notes_migration\Plugin\migrate\process;

use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Combines comment subject and body into a formatted note.
 *
 * @MigrateProcessPlugin(
 *   id = "subject_body_formatter"
 * )
 */
class SubjectBodyFormatter extends ProcessPluginBase {

  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    $subject = $row->getSourceProperty('subject');
    $body = $row->getSourceProperty('comment_body_value');

    $subject_value = '';
    if (is_array($subject) && isset($subject[0]['value'])) {
      $subject_value = $subject[0]['value'];
    } elseif (is_string($subject)) {
      $subject_value = $subject;
    }

    $body_value = '';
    if (is_array($body) && isset($body['value'])) {
      $body_value = $body['value'];
    } elseif (is_string($body)) {
      $body_value = $body;
    }

    if (!empty($subject_value)) {
      return rtrim($subject_value, '.') . '. ' . $body_value;
    }

    return $body_value;
  }

}