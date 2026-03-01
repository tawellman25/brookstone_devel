<?php

declare(strict_types=1);

namespace Drupal\estimate\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Links an existing Contact to an Estimate Request.
 */
final class EstimateRequestContactController extends ControllerBase {

  public function link(EntityInterface $estimate_request, EntityInterface $contact): RedirectResponse {
    // Safety checks.
    if (!$estimate_request->hasField('field_contact')) {
      $this->messenger()->addError('Estimate Request is missing field_contact.');
      return new RedirectResponse($estimate_request->toUrl('canonical')->toString());
    }

    // Only allow linking if currently empty (prevents accidental overwrite).
    if (!$estimate_request->get('field_contact')->isEmpty()) {
      $this->messenger()->addWarning('Estimate Request already has a Contact linked. No changes made.');
      return new RedirectResponse($estimate_request->toUrl('canonical')->toString());
    }

    $estimate_request->set('field_contact', ['target_id' => (int) $contact->id()]);
    $estimate_request->save();

    $this->messenger()->addStatus($this->t('Linked Contact @cid to Estimate Request @rid.', [
      '@cid' => (int) $contact->id(),
      '@rid' => (int) $estimate_request->id(),
    ]));

    return new RedirectResponse($estimate_request->toUrl('canonical')->toString());
  }

}
