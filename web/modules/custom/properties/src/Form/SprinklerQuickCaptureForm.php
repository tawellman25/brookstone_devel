<?php

declare(strict_types=1);

namespace Drupal\properties\Form;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * One screen, from an open work order, for the facts a crew needs on site.
 *
 * Recording sprinkler information meant six forms and 86 fields across four
 * entity types nested three deep, and the adoption numbers showed what that
 * costs: against 1,253 sprinkler systems there were 41 water-source records and
 * ONE zone record. The system record gets filled because billing forces it;
 * everything under it was effectively never filled.
 *
 * So this is the short list the crew asked for — shut-off location and key type,
 * hookup location and type, backflow or pump, clock location, zone count — on one
 * screen, reached in one tap from the work order they already have open.
 *
 * It writes to the proper records (sprinkler system, water source, controller) so
 * the property page, billing and the existing forms all still see everything. It
 * is a faster door onto the same data, not a parallel store.
 */
final class SprinklerQuickCaptureForm extends FormBase {

  // Declared, not promoted: the form is cacheable (file upload), so
  // DependencySerializationTrait has to re-inject these on __wakeup().
  protected $etm;

  public function __construct(EntityTypeManagerInterface $etm) {
    $this->etm = $etm;
  }

  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'));
  }

  public function getFormId(): string {
    return 'properties_sprinkler_quick_capture';
  }

  /**
   * The work order gives us the property; the crew never picks one.
   */
  public function buildForm(array $form, FormStateInterface $form_state, $work_order = NULL): array {
    $wo = is_object($work_order) ? $work_order : ($work_order ? $this->etm->getStorage('work_order')->load($work_order) : NULL);
    if (!$wo) {
      $form['err'] = ['#markup' => $this->t('No work order found.')];
      return $form;
    }
    $property = $wo->get('field_property')->entity;
    if (!$property) {
      $form['err'] = ['#markup' => $this->t('This work order has no property on it, so there is nothing to record against.')];
      return $form;
    }
    $form_state->set('wo_id', (int) $wo->id());
    $form_state->set('property_id', (int) $property->id());

    $system = $this->findSystem((int) $property->id());
    $source = $system ? $this->findSource((int) $system->id()) : NULL;
    $controller = $system ? $this->findController((int) $system->id()) : NULL;
    $form_state->set('system_id', $system ? (int) $system->id() : NULL);
    $form_state->set('source_id', $source ? (int) $source->id() : NULL);
    $form_state->set('controller_id', $controller ? (int) $controller->id() : NULL);

    $form['#attributes']['class'][] = 'sprinkler-quick';
    $form['head'] = [
      '#markup' => '<h2>' . $this->t('@property', ['@property' => $property->label()]) . '</h2>'
        . '<p>' . $this->t('Only what you need on site. Everything here is optional — fill what you know and save.') . '</p>',
    ];

    // --- Zones -------------------------------------------------------------
    $form['zones'] = [
      '#type' => 'number',
      '#title' => $this->t('How many zones'),
      '#min' => 0,
      '#default_value' => $system && !$system->get('field_total_zones')->isEmpty()
        ? (int) $system->get('field_total_zones')->value : NULL,
    ];

    // --- Shut off ----------------------------------------------------------
    $form['shut_off'] = ['#type' => 'fieldset', '#title' => $this->t('Shut off')];
    $form['shut_off']['shut_off_location'] = [
      '#type' => 'select',
      '#title' => $this->t('Location'),
      '#options' => $this->options('property_ss_sources', 'domestic_source', 'field_ss_shut_off_location'),
      '#empty_option' => $this->t('- not recorded -'),
      '#default_value' => $this->val($source, 'field_ss_shut_off_location'),
    ];
    $form['shut_off']['shut_off_notes'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Where exactly'),
      '#placeholder' => $this->t('e.g. in the crawlspace, left of the furnace'),
      '#default_value' => $this->val($source, 'field_shut_off_location_descript'),
    ];
    $form['shut_off']['key_needed'] = [
      '#type' => 'select',
      '#title' => $this->t('Key type'),
      '#options' => $this->options('property_ss_sources', 'domestic_source', 'field_ss_key_needed'),
      '#empty_option' => $this->t('- not recorded -'),
      '#default_value' => $this->val($source, 'field_ss_key_needed'),
    ];
    $form['shut_off']['shut_off_photo'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Photo'),
      '#upload_location' => 'public://sprinkler-quick',
      '#upload_validators' => ['FileExtension' => ['extensions' => 'jpg jpeg png heic webp']],
      '#description' => $this->t('Added to the existing shut-off photos. Alt text writes itself.'),
    ];

    // --- Hookup ------------------------------------------------------------
    $form['hookup'] = ['#type' => 'fieldset', '#title' => $this->t('Hookup (where the compressor goes)')];
    $form['hookup']['hookup_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Type'),
      '#options' => $this->options('property_ss_sources', 'domestic_source', 'field_ss_hookup_type'),
      '#empty_option' => $this->t('- not recorded -'),
      '#default_value' => $this->val($source, 'field_ss_hookup_type'),
    ];
    $form['hookup']['hookup_location'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Location'),
      '#placeholder' => $this->t('e.g. NE corner, behind the hose bibb'),
      '#default_value' => $this->val($source, 'field_ss_hookup_location'),
    ];

    // --- Backflow or pump --------------------------------------------------
    $form['bfp'] = ['#type' => 'fieldset', '#title' => $this->t('Backflow or pump')];
    $form['bfp']['which'] = [
      '#type' => 'radios',
      '#title' => $this->t('Which does it have'),
      '#options' => [
        '' => $this->t('- not recorded -'),
        'backflow' => $this->t('Backflow'),
        'pump' => $this->t('Pump'),
        'neither' => $this->t('Neither'),
      ],
      '#default_value' => $this->backflowOrPumpDefault($source, $system),
    ];
    $form['bfp']['bfp_location'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Where is it'),
      '#default_value' => $this->val($source, 'field_ss_backflow_location'),
    ];

    // --- Clock -------------------------------------------------------------
    $form['clock'] = ['#type' => 'fieldset', '#title' => $this->t('Clock')];
    $form['clock']['clock_location'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Location'),
      '#placeholder' => $this->t('e.g. garage, north wall by the door'),
      '#default_value' => $this->val($controller, 'field_controller_location'),
    ];
    $form['clock']['clock_photo'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Photo'),
      '#upload_location' => 'public://sprinkler-quick',
      '#upload_validators' => ['FileExtension' => ['extensions' => 'jpg jpeg png heic webp']],
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
      '#button_type' => 'primary',
    ];
    $form['actions']['back'] = [
      '#type' => 'link',
      '#title' => $this->t('Back to the work order'),
      '#url' => $wo->toUrl(),
      '#attributes' => ['class' => ['button']],
    ];
    $form['#attached']['library'][] = 'properties/sprinkler_quick';
    return $form;
  }

  /**
   * Write everything in one pass, creating records only where a value was given.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $propertyId = (int) $form_state->get('property_id');
    $v = fn(string $k) => $form_state->getValue($k);
    $written = [];

    // --- System: zones -----------------------------------------------------
    $zones = $v('zones');
    if ($zones !== '' && $zones !== NULL) {
      $system = $this->findSystem($propertyId) ?: NULL;
      if (!$system) {
        // A system needs a type, which this screen does not ask for. Rather than
        // invent one, say so — the system record is created by the existing
        // "Add sprinkler system" button on the work order.
        $this->messenger()->addWarning($this->t('Zone count not saved: this property has no sprinkler system record yet. Use "Add Sprinkler System" on the work order first.'));
      }
      else {
        $system->set('field_total_zones', (int) $zones);
        $system->save();
        $written[] = (string) $this->t('zones');
      }
    }

    $system = $this->findSystem($propertyId);

    // --- Source: shut off, key, hookup, backflow ---------------------------
    $sourceValues = [
      'field_ss_shut_off_location' => $v('shut_off_location'),
      'field_shut_off_location_descript' => $v('shut_off_notes'),
      'field_ss_key_needed' => $v('key_needed'),
      'field_ss_hookup_type' => $v('hookup_type'),
      'field_ss_hookup_location' => $v('hookup_location'),
      'field_ss_backflow_location' => $v('bfp_location'),
    ];
    $hasSourceValue = (bool) array_filter($sourceValues, static fn($x) => $x !== '' && $x !== NULL);
    $photoFids = array_filter((array) $v('shut_off_photo'));
    if (($hasSourceValue || $photoFids) && $system) {
      $source = $this->findSource((int) $system->id());
      if (!$source) {
        $source = $this->etm->getStorage('property_ss_sources')->create([
          'type' => $this->sourceBundleForSystem($system),
          'field_property_ss_system' => $system->id(),
          'field_ss_source_name' => 'Primary',
        ]);
      }
      foreach ($sourceValues as $field => $value) {
        if ($value !== '' && $value !== NULL && $source->hasField($field)) {
          $source->set($field, $value);
        }
      }
      foreach ($photoFids as $fid) {
        // Append, never replace: a second photo is extra information.
        $source->get('field_ss_shut_off_location_pic')->appendItem(['target_id' => (int) $fid]);
      }
      $this->saveRecord($source);
      $written[] = (string) $this->t('shut off / hookup');
    }

    // --- Controller: clock location ----------------------------------------
    $clock = $v('clock_location');
    $clockFids = array_filter((array) $v('clock_photo'));
    if (($clock !== '' || $clockFids) && $system) {
      $controller = $this->findController((int) $system->id());
      if (!$controller) {
        // field_controller_number and field_controller_type are required on this
        // entity, so a new one gets the obvious defaults rather than blocking.
        $controller = $this->etm->getStorage('property_system_controller')->create([
          'type' => 'controller',
          'field_property_ss_system' => $system->id(),
          'field_controller_number' => 1,
          'field_controller_type' => 1,
        ]);
      }
      if ($clock !== '') {
        $controller->set('field_controller_location', $clock);
      }
      foreach ($clockFids as $fid) {
        $controller->get('field_controller_photos')->appendItem(['target_id' => (int) $fid]);
      }
      $this->saveRecord($controller);
      $written[] = (string) $this->t('clock');
    }

    // --- Backflow / pump choice, recorded where it is visible --------------
    $which = $v('which');
    if ($which !== '' && $which !== NULL && $system) {
      $note = $which === 'backflow' ? 'Has a backflow.' : ($which === 'pump' ? 'Has a pump.' : 'No backflow or pump.');
      $source = $this->findSource((int) $system->id());
      if ($source && $source->hasField('field_ss_shut_off_notes')) {
        $existing = trim((string) $source->get('field_ss_shut_off_notes')->value);
        if (!str_contains($existing, $note)) {
          $source->set('field_ss_shut_off_notes', trim($existing . ' ' . $note));
          $this->saveRecord($source);
        }
      }
    }

    if ($written) {
      $this->messenger()->addStatus($this->t('Saved: @what.', ['@what' => implode(', ', $written)]));
    }
    else {
      $this->messenger()->addWarning($this->t('Nothing to save — no values were entered.'));
    }

    $woId = (int) $form_state->get('wo_id');
    if ($woId && ($wo = $this->etm->getStorage('work_order')->load($woId))) {
      $form_state->setRedirectUrl($wo->toUrl());
    }
  }

  /**
   * Save a record, and never leave a crew looking at a label placeholder.
   *
   * auto_entitylabel builds some labels on a SECOND save, which it runs in a PHP
   * shutdown function — after the response has already been sent. A record
   * created here therefore renders with its raw placeholder
   * (`%AutoEntityLabel: <uuid>%`) on the very page the crew is redirected to.
   * That is what WO#53970 showed on 2026-10-02: the Controller(s) heading was
   * the placeholder, because this was the one sprinkler bundle set to defer its
   * label. Its pattern needs nothing that only exists after the save, so it now
   * builds on the first save and this method is a no-op — it is here so that
   * flipping that setting back in the UI cannot put the placeholder in front of
   * a crew a second time.
   *
   * Same shape as wo_shared_work_order_insert(), deliberately scoped to the
   * records this form writes rather than to every insert in BOS: the ~100 other
   * deferred-label bundles (work orders and estimates, whose patterns genuinely
   * need the entity id) are already re-saved by contrib, and a site-wide heal
   * would add a third save to all of them to fix a timing problem that is better
   * fixed per bundle at the source.
   */
  private function saveRecord(EntityInterface $record): void {
    $isNew = $record->isNew();
    $record->save();
    if (!$isNew || !$record->hasField('title')) {
      return;
    }

    // Only bundles configured to label AFTER the first save can carry the
    // placeholder, and that is a cached config read rather than a query.
    $cfg = $this->config('auto_entitylabel.settings.' . $record->getEntityTypeId() . '.' . $record->bundle());
    if ((int) $cfg->get('new_content_behavior') !== 1) {
      return;
    }

    // Re-read the STORED title. auto_entitylabel repairs the label on the
    // in-memory entity during hook_entity_insert but leaves the database row
    // carrying the placeholder until its shutdown re-save, so $record->label()
    // reads correctly here while the row the next page renders is still wrong.
    $stored = $this->etm->getStorage($record->getEntityTypeId())->loadUnchanged($record->id());
    if (!$stored || !str_contains((string) $stored->get('title')->value, '%AutoEntityLabel')) {
      return;
    }

    // status:2 bundles only fill an empty title, so clear it before re-saving.
    $stored->set('title', '');
    $stored->save();
    \Drupal::logger('properties')->notice('Healed a stuck label placeholder on @type @id -> %label', [
      '@type' => $stored->getEntityTypeId(),
      '@id' => $stored->id(),
      '%label' => $stored->label(),
    ]);
  }

  /**
   * The property's sprinkler system, by either link path (both are partially
   * populated — 1,147 of 1,253 carry field_property, 1,246 the info reference).
   */
  protected function findSystem(int $propertyId): ?EntityInterface {
    $storage = $this->etm->getStorage('property_sprinkler_system');
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('field_property', $propertyId)->sort('id')->range(0, 1)->execute();
    if ($ids) {
      return $storage->load(reset($ids));
    }
    $infoIds = $this->etm->getStorage('property_sprinkler_info')->getQuery()->accessCheck(FALSE)
      ->condition('field_property', $propertyId)->execute();
    if (!$infoIds) {
      return NULL;
    }
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('field_property_system_info', array_values($infoIds), 'IN')->sort('id')->range(0, 1)->execute();
    return $ids ? $storage->load(reset($ids)) : NULL;
  }

  protected function findSource(int $systemId): ?EntityInterface {
    $storage = $this->etm->getStorage('property_ss_sources');
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('field_property_ss_system', $systemId)->sort('id')->range(0, 1)->execute();
    return $ids ? $storage->load(reset($ids)) : NULL;
  }

  protected function findController(int $systemId): ?EntityInterface {
    $storage = $this->etm->getStorage('property_system_controller');
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('field_property_ss_system', $systemId)->sort('id')->range(0, 1)->execute();
    return $ids ? $storage->load(reset($ids)) : NULL;
  }

  /**
   * Match the source bundle to the system type, so a well system does not get a
   * domestic source record.
   */
  protected function sourceBundleForSystem(EntityInterface $system): string {
    $label = strtolower((string) ($system->get('field_system_type')->entity?->label() ?? ''));
    if (str_contains($label, 'well')) {
      return 'well_water_source';
    }
    if (str_contains($label, 'dirty') || str_contains($label, 'duel')) {
      return 'dirty_water_source';
    }
    return 'domestic_source';
  }

  protected function options(string $entityType, string $bundle, string $field): array {
    $defs = \Drupal::service('entity_field.manager')->getFieldDefinitions($entityType, $bundle);
    $allowed = isset($defs[$field]) ? ($defs[$field]->getSetting('allowed_values') ?? []) : [];
    return $allowed;
  }

  protected function val(?EntityInterface $entity, string $field) {
    if (!$entity || !$entity->hasField($field) || $entity->get($field)->isEmpty()) {
      return NULL;
    }
    return $entity->get($field)->value;
  }

  protected function backflowOrPumpDefault(?EntityInterface $source, ?EntityInterface $system) {
    if ($source && $source->hasField('field_ss_backflow') && !$source->get('field_ss_backflow')->isEmpty()) {
      return 'backflow';
    }
    if ($system) {
      $label = strtolower((string) ($system->get('field_system_type')->entity?->label() ?? ''));
      if (str_contains($label, 'well') || str_contains($label, 'dirty') || str_contains($label, 'duel')) {
        return 'pump';
      }
    }
    return '';
  }

}
