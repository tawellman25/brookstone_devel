<?php

namespace Drupal\system_readiness\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

final class SystemReadinessForm extends ContentEntityForm {

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildForm($form, $form_state);

    // Keep the important stuff near the top even if display config changes.
    if (isset($form['title'])) {
      $form['title']['#weight'] = 0;
    }
    if (isset($form['field_entity_type'])) {
      $form['field_entity_type']['#weight'] = 1;
    }
    if (isset($form['field_bundle'])) {
      $form['field_bundle']['#weight'] = 2;
    }
    if (isset($form['field_environment'])) {
      $form['field_environment']['#weight'] = 3;
    }

    return $form;
  }

  public function save(array $form, FormStateInterface $form_state): int {
    $status = parent::save($form, $form_state);
    $entity = $this->getEntity();

    $this->messenger()->addStatus($this->t('Saved: @label', ['@label' => $entity->label()]));
    $form_state->setRedirect('entity.system_readiness.collection');

    return $status;
  }

}
