<?php

namespace Drupal\contract_residential\Plugin\Action;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Entity\EntityInterface;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;

/**
 * Sets Contract Section "Do you want" to No (2).
 *
 * @Action(
 *   id = "contract_section_set_do_you_want_no",
 *   label = @Translation("Section: Set Do You Want = No"),
 *   category = @Translation("Contract Sections"),
 *   confirm = TRUE
 * )
 */
class ContractSectionSetDoYouWantNoAction extends ViewsBulkOperationsActionBase {
  use MessengerTrait;

  public function execute(EntityInterface $entity = NULL) {
    if (!$entity || $entity->getEntityTypeId() !== 'contract_sections') {
      return;
    }

    if (!$entity->hasField('field_do_you_want')) {
      $this->messenger()->addError($this->t('Section @id is missing field_do_you_want.', [
        '@id' => $entity->id(),
      ]));
      return;
    }

    $new_value = '2';

    $current = !$entity->get('field_do_you_want')->isEmpty()
      ? (string) $entity->get('field_do_you_want')->value
      : '';

    if ($current === $new_value) {
      return;
    }

    $entity->set('field_do_you_want', $new_value);
    $entity->save();

    $label = $entity->label() ?: $this->t('Section @id', ['@id' => $entity->id()]);

    $this->messenger()->addMessage($this->t(
      '@label updated: Do You Want = No.',
      ['@label' => $label]
    ));
  }

  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    return $object->access('update', $account, $return_as_object);
  }

}
