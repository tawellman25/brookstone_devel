<?php

namespace Drupal\system_readiness;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Link;

final class SystemReadinessListBuilder extends EntityListBuilder {

  public function buildHeader(): array {
    $header['title'] = $this->t('Title');
    $header['entity_type'] = $this->t('Entity Type');
    $header['bundle'] = $this->t('Bundle');
    $header['environment'] = $this->t('Environment');
    $header['status'] = $this->t('Status');
    $header['priority'] = $this->t('Priority');
    $header['ready'] = $this->t('Production Ready');
    $header['changed'] = $this->t('Updated');
    return $header + parent::buildHeader();
  }

  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\system_readiness\Entity\SystemReadiness $entity */
    $row['title'] = Link::createFromRoute(
      $entity->label(),
      'entity.system_readiness.edit_form',
      ['system_readiness' => $entity->id()]
    );

    $row['entity_type'] = $entity->get('field_entity_type')->value ?? '';
    $row['machine_name'] = $entity->get('field_machine_name')->value ?? '';
    $row['bundle'] = $entity->get('field_bundle')->value ?? '';
    $row['environment'] = $entity->get('field_environment')->value ?? '';
    $row['status'] = $entity->get('field_status')->value ?? '';
    $row['priority'] = (string) ($entity->get('field_priority')->value ?? '');
    $row['ready'] = !empty($entity->get('field_live_ready')->value) ? $this->t('Yes') : $this->t('No');
    $row['changed'] = $entity->getChangedTime() ? \Drupal::service('date.formatter')->format($entity->getChangedTime(), 'short') : '';
    return $row + parent::buildRow($entity);
  }

}
