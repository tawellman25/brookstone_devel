<?php

declare(strict_types=1);

namespace Drupal\bos_service_request\Form;

use Drupal\bos_service_request\Service\CustomerProvisioningService;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\eck\Entity\EckEntity;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Guided "Create Customer & Property" from a service request: confirm the address
 * on Google, then create the property + client + contact + ownership in one step.
 */
final class CreateCustomerForm extends FormBase {

  // Declared (not promoted) + non-readonly: the form is cacheable (the geocode
  // AJAX button), so DependencySerializationTrait must re-inject these on
  // __wakeup() — which it can't do for constructor-promoted properties.
  protected $prov;
  protected $etm;
  protected $cfg;
  protected $geocoder;

  public function __construct(CustomerProvisioningService $prov, EntityTypeManagerInterface $etm, ConfigFactoryInterface $cfg, $geocoder) {
    $this->prov = $prov;
    $this->etm = $etm;
    $this->cfg = $cfg;
    $this->geocoder = $geocoder;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('bos_service_request.customer_provisioning'),
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
      $container->has('geocoder') ? $container->get('geocoder') : NULL,
    );
  }

  public function getFormId(): string {
    return 'bos_service_request_create_customer_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?EckEntity $service_request = NULL): array {
    if ($service_request) {
      $form_state->set('sr_id', (int) $service_request->id());
    }
    $sr = $service_request;
    $v = fn(string $f) => ($sr && $sr->hasField($f) && !$sr->get($f)->isEmpty()) ? (string) $sr->get($f)->value : '';

    // Split the stored full name into first / last for the client fields.
    $full = $v('field_submitted_name');
    $parts = preg_split('/\s+/', trim($full));
    $last = $parts ? array_pop($parts) : '';
    $first = $parts ? implode(' ', $parts) : '';

    $street = $v('field_submitted_address');
    $zip = $v('field_submitted_zip');

    $form['property'] = ['#type' => 'fieldset', '#title' => $this->t('Property')];
    $form['property']['nickname'] = [
      '#type' => 'textfield', '#title' => $this->t('Nickname'), '#required' => TRUE,
      '#default_value' => trim($last . ($street ? ' — ' . $street : '')),
    ];
    $form['property']['street_address'] = [
      '#type' => 'textfield', '#title' => $this->t('Street address'), '#required' => TRUE, '#default_value' => $street,
    ];
    $form['property']['zip'] = [
      '#type' => 'textfield', '#title' => $this->t('ZIP'), '#size' => 10, '#default_value' => $zip,
    ];
    $form['property']['geocode'] = [
      '#type' => 'button', '#value' => $this->t('Look up on Google'),
      '#ajax' => ['callback' => '::ajaxGeocode', 'wrapper' => 'geo-result'],
      '#limit_validation_errors' => [],
    ];
    $form['property']['geo_result'] = [
      '#type' => 'container', '#attributes' => ['id' => 'geo-result'],
      'msg' => ['#markup' => '<p><em>' . $this->t('Enter the address and click “Look up on Google” to confirm the location.') . '</em></p>'],
    ];
    // Coordinates carried across the AJAX rebuild.
    $form['property']['lat'] = ['#type' => 'hidden', '#default_value' => ''];
    $form['property']['lng'] = ['#type' => 'hidden', '#default_value' => ''];
    $form['property']['full_address'] = ['#type' => 'hidden', '#default_value' => trim($street . ($zip ? ', ' . $zip : ''))];

    $form['client'] = ['#type' => 'fieldset', '#title' => $this->t('Customer')];
    $form['client']['first_name'] = ['#type' => 'textfield', '#title' => $this->t('First name'), '#default_value' => $first];
    $form['client']['last_name'] = ['#type' => 'textfield', '#title' => $this->t('Last name'), '#required' => TRUE, '#default_value' => $last];
    $form['client']['phone'] = ['#type' => 'tel', '#title' => $this->t('Phone'), '#default_value' => $v('field_submitted_phone')];
    $form['client']['email'] = ['#type' => 'email', '#title' => $this->t('Email'), '#default_value' => $v('field_submitted_email')];
    $ctOptions = [];
    foreach ($this->etm->getStorage('client_type')->loadMultiple() as $t) {
      $ctOptions[$t->id()] = $t->label();
    }
    $form['client']['client_type'] = [
      '#type' => 'select', '#title' => $this->t('Client type'), '#options' => $ctOptions, '#default_value' => 1,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Create customer & property'), '#button_type' => 'primary'];
    $form['actions']['cancel'] = [
      '#type' => 'link', '#title' => $this->t('Cancel'),
      '#url' => Url::fromUri('internal:/admin/office/service-requests'),
      '#attributes' => ['class' => ['button']],
    ];
    return $form;
  }

  /**
   * AJAX: geocode the address, show the confirmed location + a static map.
   */
  public function ajaxGeocode(array &$form, FormStateInterface $form_state): array {
    $address = trim($form_state->getValue('street_address') . ', ' . $form_state->getValue('zip'));
    $lat = $lng = NULL;
    $formatted = '';
    if ($this->geocoder && $address !== ',') {
      try {
        $result = $this->geocoder->geocode($address, ['googlemaps']);
        if ($result && ($first = $result->first())) {
          $coords = $first->getCoordinates();
          $lat = $coords?->getLatitude();
          $lng = $coords?->getLongitude();
          $formatted = method_exists($first, 'getFormattedAddress') ? (string) $first->getFormattedAddress() : $address;
        }
      }
      catch (\Throwable $e) {
        // fall through to the not-found message
      }
    }

    if ($lat && $lng) {
      $key = (string) $this->cfg->get('geofield_map.settings')->get('gmap_api_key');
      $map = $key
        ? '<img src="https://maps.googleapis.com/maps/api/staticmap?center=' . $lat . ',' . $lng
          . '&zoom=18&size=460x200&markers=color:red%7C' . $lat . ',' . $lng . '&key=' . $key . '" alt="map" style="max-width:100%;border:1px solid #ccc;border-radius:6px" />'
        : '';
      $form['property']['geo_result']['msg'] = [
        '#markup' => '<p><strong>✓ ' . $this->t('Found:') . '</strong> ' . htmlspecialchars($formatted ?: $address)
          . '<br><small>' . $lat . ', ' . $lng . '</small></p>' . $map,
      ];
      $form['property']['lat']['#value'] = $lat;
      $form['property']['lng']['#value'] = $lng;
      if ($formatted) {
        $form['property']['full_address']['#value'] = $formatted;
      }
    }
    else {
      $form['property']['geo_result']['msg'] = [
        '#markup' => '<p><strong>' . $this->t('Not found.') . '</strong> ' . $this->t('Check the address, or continue — you can set the map pin on the property afterward.') . '</p>',
      ];
    }
    return $form['property']['geo_result'];
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $result = $this->prov->provision([
      'nickname' => $form_state->getValue('nickname'),
      'street_address' => $form_state->getValue('street_address'),
      'full_address' => $form_state->getValue('full_address'),
      'zip' => $form_state->getValue('zip'),
      'lat' => $form_state->getValue('lat'),
      'lng' => $form_state->getValue('lng'),
      'first_name' => $form_state->getValue('first_name'),
      'last_name' => $form_state->getValue('last_name'),
      'email' => $form_state->getValue('email'),
      'phone' => $form_state->getValue('phone'),
      'client_type_id' => (int) $form_state->getValue('client_type'),
    ]);

    // Link the new property back to the service request so "Approve & Create WO"
    // can run against it.
    $srId = (int) $form_state->get('sr_id');
    if ($srId && ($sr = $this->etm->getStorage('service_request')->load($srId))) {
      if ($sr->hasField('field_property')) {
        $sr->set('field_property', ['target_id' => $result['property']->id()]);
        $sr->save();
      }
    }

    $this->messenger()->addStatus($this->t('Created customer %name and property %prop. Now use “Approve & Create Work Order” to schedule the service.', [
      '%name' => trim($form_state->getValue('first_name') . ' ' . $form_state->getValue('last_name')),
      '%prop' => $result['property']->label(),
    ]));
    $form_state->setRedirectUrl(Url::fromUri('internal:/admin/office/service-requests'));
  }

}
