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

  /**
   * What a new system record is born with.
   *
   * Name: 1,245 of the 1,253 existing systems are called "Primary", which the
   * auto_entitylabel pattern turns into the title "Primary System".
   * Operation: 1,119 of 1,252 are Automatic. It drives no billing and the
   * office can change it, so the crew is not asked at the curb.
   * Complexity Level is deliberately left unset: the field is marked required
   * but has no data table at all — not one of the 1,253 systems carries it —
   * and `required` is a FORM constraint that a programmatic save does not
   * enforce, so setting it here would invent a convention rather than match one.
   */
  private const SYSTEM_NAME = 'Primary';
  private const OPERATION_AUTOMATIC = 1279;

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

    // The real records behind the Backflow/Pump question. A backflow belongs to
    // the PROPERTY (same record the testing and inspection side uses), a pump
    // hangs off the water source.
    $devices = $this->findDevices((int) $property->id());
    $pumps = $source ? $this->findPumps((int) $source->id()) : [];
    $device = count($devices) === 1 ? reset($devices) : NULL;
    $pump = count($pumps) === 1 ? reset($pumps) : NULL;

    $form['#attributes']['class'][] = 'sprinkler-quick';
    $form['head'] = [
      '#markup' => '<h2>' . $this->t('@property', ['@property' => $property->label()]) . '</h2>'
        . '<p>' . $this->t('Only what you need on site. Everything here is optional — fill what you know and save.') . '</p>',
    ];

    // --- No system yet: ask for the one fact we cannot infer ----------------
    // A system record requires a type, and the type decides the winterizing
    // charge (base, or base + pump fee for Dirty/Duel/Well). The work order
    // carries one often enough to be worth pre-filling from, but measured on
    // the properties that actually land here it almost never does: of the 43
    // winterizing WOs this season whose property has no system, exactly 1 has
    // a type on the work order and 42 have neither. So expect the crew to
    // answer this, and keep it to one tap.
    if (!$system) {
      $woType = $wo->hasField('field_system_type') && !$wo->get('field_system_type')->isEmpty()
        ? (int) $wo->get('field_system_type')->target_id : NULL;
      $form['system_type'] = [
        '#type' => 'select',
        '#title' => $this->t('System Type'),
        '#options' => $this->systemTypeOptions(),
        '#empty_option' => $this->t('- select -'),
        '#default_value' => $woType,
        '#description' => $this->t('This property has no sprinkler system record yet — one will be created from this. It also sets the winterizing charge, so a pump system is not billed as a domestic one.'),
      ];
    }

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
      '#attributes' => ['accept' => 'image/*'],
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
      '#default_value' => $device ? $this->val($device, 'field_physical_location')
        : ($pump ? $this->val($pump, 'field_pump_location') : $this->val($source, 'field_ss_backflow_location')),
      '#states' => ['invisible' => [':input[name="which"]' => ['value' => 'neither']]],
    ];

    // --- Backflow: a real device record, the same one testing uses ---------
    // All 23 devices on the system carry a serial number, so this asks for one
    // too rather than creating the first record without.
    $form['bfp']['bf_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Backflow type'),
      '#options' => $this->deviceTypeOptions(),
      '#empty_option' => $this->t('- not sure -'),
      '#default_value' => $device && !$device->get('field_device_type')->isEmpty()
        ? (int) $device->get('field_device_type')->target_id : NULL,
      '#states' => ['visible' => [':input[name="which"]' => ['value' => 'backflow']]],
    ];
    $form['bfp']['bf_serial'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Serial number'),
      '#default_value' => $this->val($device, 'field_serial_number'),
      '#description' => $this->t('Off the tag on the device.'),
      '#states' => ['visible' => [':input[name="which"]' => ['value' => 'backflow']]],
    ];

    // --- Pump: horse power, which is the fact that gets asked for later ----
    $form['bfp']['pump_hp'] = [
      '#type' => 'select',
      '#title' => $this->t('Pump size'),
      '#options' => $this->options('property_sprinkler_pumps', 'pump', 'field_pump_size'),
      '#empty_option' => $this->t('- not sure -'),
      '#default_value' => $this->val($pump, 'field_pump_size'),
      '#states' => ['visible' => [':input[name="which"]' => ['value' => 'pump']]],
    ];

    // More than one of either is a real situation (a commercial property can
    // carry several). This screen will not guess which one is meant, so it
    // reports and writes nothing rather than editing the wrong record.
    $many = [];
    if (count($devices) > 1) { $many[] = $this->t('@n backflow devices', ['@n' => count($devices)]); }
    if (count($pumps) > 1) { $many[] = $this->t('@n pumps', ['@n' => count($pumps)]); }
    if ($many) {
      $form['bfp']['many'] = [
        '#markup' => '<p class="sprinkler-quick__note">'
          . $this->t('This property already has @what on file, so nothing here will change them — edit them on the property page.',
            ['@what' => implode(' ' . $this->t('and') . ' ', $many)])
          . '</p>',
      ];
      $form['bfp']['bf_type']['#disabled'] = count($devices) > 1;
      $form['bfp']['bf_serial']['#disabled'] = count($devices) > 1;
      $form['bfp']['pump_hp']['#disabled'] = count($pumps) > 1;
    }

    // --- Clock -------------------------------------------------------------
    $form['clock'] = ['#type' => 'fieldset', '#title' => $this->t('Clock')];
    $form['clock']['clock_location'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Location'),
      '#placeholder' => $this->t('e.g. garage, north wall by the door'),
      '#default_value' => $this->val($controller, 'field_controller_location'),
    ];
    // Three shots, because that is what the crew actually takes: where the
    // clock is, the face with the door open, and the wiring — the last one is
    // how the zone count gets settled later, since what is wired and what the
    // clock is programmed for are not always the same number.
    // field_controller_photos is unlimited and the submit handler already
    // appends every fid, so this only had to stop accepting one at a time.
    $form['clock']['clock_photo'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Photos'),
      '#multiple' => TRUE,
      '#upload_location' => 'public://sprinkler-quick',
      '#attributes' => ['accept' => 'image/*'],
      '#upload_validators' => ['FileExtension' => ['extensions' => 'jpg jpeg png heic webp']],
      '#description' => $this->t('You can pick more than one — the location, the face with the door open, and the wiring. Added to any photos already on the clock.'),
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
    // Shrink before upload. 2048 here, against 3200 for work order photos:
    // these are reference shots -- where the shut-off is, what the wiring looks
    // like -- not pictures that might end up in an advertisement, and speed at
    // the curb matters more. TRUE asks for thumbnails because managed_file
    // renders only a filename.
    bos_photo_shrink_attach($form, 2048, 85, TRUE);
    return $form;
  }

  /**
   * Only one thing can be demanded here, and only when it is actually needed.
   *
   * The screen's promise is that everything is optional — so an empty save is
   * still allowed. But a value cannot be stored without a system record, and a
   * system record cannot be created without a type, so if the crew has entered
   * something on a property that has no system, that is the moment to ask.
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (!isset($form['system_type'])) {
      return;
    }
    if ($this->needsSystem($form_state) && !$form_state->getValue('system_type')) {
      $form_state->setError(
        $form['system_type'],
        $this->t('Choose the system type so a sprinkler system record can be created for this property.')
      );
    }
  }

  /**
   * Did the crew type anything at all?
   */
  private function enteredAnything(FormStateInterface $form_state): bool {
    foreach (['zones', 'shut_off_location', 'shut_off_notes', 'key_needed', 'hookup_type',
      'hookup_location', 'which', 'bfp_location', 'clock_location', 'bf_type', 'bf_serial',
      'pump_hp'] as $key) {
      $value = $form_state->getValue($key);
      if ($value !== '' && $value !== NULL) {
        return TRUE;
      }
    }
    foreach (['shut_off_photo', 'clock_photo'] as $key) {
      if (array_filter((array) $form_state->getValue($key))) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Does what they entered actually need a sprinkler system record?
   *
   * A BACKFLOW does not: the device belongs to the property, the same way the
   * testing side holds it, so a crew recording a serial number off a tag should
   * not be made to answer what the water supply is first. Everything else here
   * hangs off the system -- a pump through its water source, zones on the
   * system itself, the clock through the controller.
   */
  private function needsSystem(FormStateInterface $form_state): bool {
    foreach (['zones', 'shut_off_location', 'shut_off_notes', 'key_needed', 'hookup_type',
      'hookup_location', 'clock_location', 'pump_hp'] as $key) {
      $value = $form_state->getValue($key);
      if ($value !== '' && $value !== NULL) {
        return TRUE;
      }
    }
    foreach (['shut_off_photo', 'clock_photo'] as $key) {
      if (array_filter((array) $form_state->getValue($key))) {
        return TRUE;
      }
    }
    // A location typed against anything other than the backflow needs one.
    return $form_state->getValue('which') === 'pump';
  }

  /**
   * Create the property's first sprinkler system — visibly to BILLING.
   *
   * There are three link paths between a property and a system, and they are
   * not interchangeable:
   *
   *   1. property_sprinkler_system.field_property  -> the property (1,147)
   *   2. property_sprinkler_system.field_property_system_info -> the info (1,246)
   *   3. property_sprinkler_info.field_systems     -> the systems (1,246)
   *
   * Billing walks the THIRD one: _wo_sw_property_systems() loads the property's
   * property_sprinkler_info record and reads field_systems off it. A system
   * created with only field_property set is therefore invisible to billing, and
   * _wo_sw_resolve_system_type_total() returns 0 when it resolves no type — so
   * the crew would have recorded the system and the winterizing would still
   * have billed nothing.
   *
   * Paths 2 and 3 do NOT have to be written separately, and writing both is a
   * bug: the contrib `cer` module's ss_info_to_system pairing keeps them in
   * step, so setting field_property_system_info here makes cer prepend the
   * system to info.field_systems on save. Appending by hand as well put the
   * system in that list TWICE, which billing counts as a two-system property —
   * it takes the multi-system branch and charged double (caught by
   * test_sprinkler_quick_create_system.php, which is why it asserts the price
   * and not merely that a record exists). That pairing is also why the two
   * counts above are identical at 1,246 with no record carrying one alone.
   */
  private function createSystem(int $propertyId, FormStateInterface $form_state): ?EntityInterface {
    $typeId = (int) ($form_state->getValue('system_type') ?: 0);
    if (!$typeId) {
      // No select rendered (a system existed at build time but not now), or a
      // path that skipped validation — fall back to the work order's own type.
      $woId = (int) $form_state->get('wo_id');
      $wo = $woId ? $this->etm->getStorage('work_order')->load($woId) : NULL;
      if ($wo && $wo->hasField('field_system_type') && !$wo->get('field_system_type')->isEmpty()) {
        $typeId = (int) $wo->get('field_system_type')->target_id;
      }
    }
    if (!$typeId) {
      $this->messenger()->addWarning($this->t('Nothing was saved: a sprinkler system record could not be created without a system type.'));
      return NULL;
    }

    // The info record is the hinge billing walks through. Most properties
    // already have one (2,623 records against 1,253 systems).
    $infoStorage = $this->etm->getStorage('property_sprinkler_info');
    $infoIds = $infoStorage->getQuery()->accessCheck(FALSE)
      ->condition('field_property', $propertyId)->sort('id')->range(0, 1)->execute();
    $info = $infoIds ? $infoStorage->load(reset($infoIds)) : NULL;
    if (!$info) {
      $info = $infoStorage->create([
        'type' => 'general_information',
        'field_property' => $propertyId,
      ]);
      $this->saveRecord($info);
    }

    $values = [
      'type' => 'system',
      'field_system_name' => self::SYSTEM_NAME,
      'field_system_type' => $typeId,
      'field_property' => $propertyId,
      'field_property_system_info' => $info->id(),
    ];
    // Only set the operation if that term is really there; it drives nothing in
    // billing, so a missing term should not cost the crew their entry.
    if ($this->etm->getStorage('taxonomy_term')->load(self::OPERATION_AUTOMATIC)) {
      $values['field_operation'] = self::OPERATION_AUTOMATIC;
    }
    // cer adds this to $info->field_systems; do not append it again.
    $system = $this->etm->getStorage('property_sprinkler_system')->create($values);
    $this->saveRecord($system);

    $this->logger('properties')->notice('Created sprinkler system @sys for property @prop from the quick-capture form (type @type).', [
      '@sys' => $system->id(),
      '@prop' => $propertyId,
      '@type' => $typeId,
    ]);
    return $system;
  }

  /**
   * Write everything in one pass, creating records only where a value was given.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $propertyId = (int) $form_state->get('property_id');
    $v = fn(string $k) => $form_state->getValue($k);
    $written = [];

    // Everything this form writes hangs off a system record, so settle that
    // first. Until 2026-10-09 the form refused to create one and sent the crew
    // away to "Add Sprinkler System" — and because every branch below was gated
    // on a system existing, the shut-off, hookup and clock values they had
    // already typed were dropped on the way out, beneath a message saying
    // nothing had been entered. 43 of this season's 579 winterizing work
    // orders are on a property with no system by either lookup path.
    $system = $this->findSystem($propertyId);
    if (!$system && $this->needsSystem($form_state)) {
      $system = $this->createSystem($propertyId, $form_state);
      if ($system) {
        $written[] = (string) $this->t('sprinkler system');
      }
    }

    // --- System: zones -----------------------------------------------------
    $zones = $v('zones');
    if ($zones !== '' && $zones !== NULL && $system) {
      $system->set('field_total_zones', (int) $zones);
      $system->save();
      $written[] = (string) $this->t('zones');
    }

    // --- Source: shut off, key, hookup, backflow ---------------------------
    $sourceValues = [
      'field_ss_shut_off_location' => $v('shut_off_location'),
      'field_shut_off_location_descript' => $v('shut_off_notes'),
      'field_ss_key_needed' => $v('key_needed'),
      'field_ss_hookup_type' => $v('hookup_type'),
      'field_ss_hookup_location' => $v('hookup_location'),
    ];
    $hasSourceValue = (bool) array_filter($sourceValues, static fn($x) => $x !== '' && $x !== NULL);
    $photoFids = array_filter((array) $v('shut_off_photo'));
    if (($hasSourceValue || $photoFids) && $system) {
      $source = $this->ensureSource($system);
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

    // --- Backflow or pump: a real record, not a sentence in a notes field --
    // Until 2026-10-10 this appended "Has a backflow." to the shut-off NOTES
    // and wrote nothing else, so the answer was unqueryable, it sat in a field
    // about something else, and it did not even survive to the next visit --
    // nothing set field_ss_backflow, which is what the radio read for its own
    // default. A backflow is now the same property_backflow_device record the
    // testing and inspection side uses, and a pump is a real pump record.
    $which = $v('which');
    $location = trim((string) $v('bfp_location'));

    if ($which === 'backflow') {
      $devices = $this->findDevices($propertyId);
      if (count($devices) > 1) {
        $this->messenger()->addWarning($this->t('This property has more than one backflow device, so none of them was changed. Edit them on the property page.'));
      }
      else {
        $device = $devices ? reset($devices) : NULL;
        $isNew = !$device;
        if ($isNew) {
          $device = $this->etm->getStorage('property_backflow_device')->create([
            'type' => 'device',
            'field_property' => $propertyId,
            // A sprinkler work order means irrigation. Resolved by the stable
            // use code rather than a term id, which differs between sites.
            'field_used_for' => $this->useTermId('IRRIGATION'),
            'field_current_status' => 'active',
          ]);
        }
        // Never blank something already on file with an empty box.
        if ($v('bf_type')) { $device->set('field_device_type', (int) $v('bf_type')); }
        if ($v('bf_serial') !== '') { $device->set('field_serial_number', $v('bf_serial')); }
        if ($location !== '') { $device->set('field_physical_location', $location); }
        $device->save();
        $written[] = $isNew
          ? (string) $this->t('backflow device @code', ['@code' => $device->label()])
          : (string) $this->t('backflow device');

        // Keep the legacy source field in step where it exists -- it is only on
        // domestic_source, which is why a pump location filed here used to
        // vanish on a well or dirty system.
        if ($location !== '' && $system && ($src = $this->findSource((int) $system->id()))
          && $src->hasField('field_ss_backflow_location')) {
          $src->set('field_ss_backflow_location', $location);
          $this->saveRecord($src);
        }
      }
    }
    elseif ($which === 'pump' && $system) {
      $source = $this->ensureSource($system);
      $pumps = $this->findPumps((int) $source->id());
      if (count($pumps) > 1) {
        $this->messenger()->addWarning($this->t('This water source has more than one pump, so none of them was changed. Edit them on the property page.'));
      }
      else {
        $pump = $pumps ? reset($pumps) : NULL;
        $isNew = !$pump;
        if ($isNew) {
          $pump = $this->etm->getStorage('property_sprinkler_pumps')->create([
            'type' => 'pump',
            'field_property_ss_source' => $source->id(),
          ]);
        }
        if ($v('pump_hp') !== '' && $v('pump_hp') !== NULL) { $pump->set('field_pump_size', $v('pump_hp')); }
        if ($location !== '') { $pump->set('field_pump_location', $location); }
        $this->saveRecord($pump);
        $written[] = (string) ($isNew ? $this->t('pump') : $this->t('pump details'));
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

  /**
   * The source record, created if the system has none yet.
   */
  protected function ensureSource(EntityInterface $system): EntityInterface {
    $source = $this->findSource((int) $system->id());
    if ($source) {
      return $source;
    }
    return $this->etm->getStorage('property_ss_sources')->create([
      'type' => $this->sourceBundleForSystem($system),
      'field_property_ss_system' => $system->id(),
      'field_ss_source_name' => 'Primary',
    ]);
  }

  /**
   * Every backflow device on the property. A backflow belongs to the PROPERTY,
   * not to the sprinkler system -- the same record the testing side works from.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   */
  protected function findDevices(int $propertyId): array {
    $storage = $this->etm->getStorage('property_backflow_device');
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('field_property', $propertyId)->sort('id')->execute();
    return $ids ? $storage->loadMultiple($ids) : [];
  }

  /**
   * @return \Drupal\Core\Entity\EntityInterface[]
   */
  protected function findPumps(int $sourceId): array {
    $storage = $this->etm->getStorage('property_sprinkler_pumps');
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('field_property_ss_source', $sourceId)->sort('id')->execute();
    return $ids ? $storage->loadMultiple($ids) : [];
  }

  /**
   * The seven device types, as the office maintains them.
   */
  protected function deviceTypeOptions(): array {
    $options = [];
    foreach ($this->etm->getStorage('taxonomy_term')->loadByProperties(['vid' => 'backflow_device_types']) as $term) {
      $options[(int) $term->id()] = $term->label();
    }
    asort($options);
    return $options;
  }

  /**
   * A use term by its stable code, since term ids differ between environments.
   */
  protected function useTermId(string $code): ?int {
    $ids = $this->etm->getStorage('taxonomy_term')->getQuery()->accessCheck(FALSE)
      ->condition('vid', 'backflow_uses')->condition('field_use_code', $code)->range(0, 1)->execute();
    return $ids ? (int) reset($ids) : NULL;
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

  /**
   * The four water supplies, as the office records them.
   */
  protected function systemTypeOptions(): array {
    $options = [];
    foreach ($this->etm->getStorage('sprinkler_system_types')->loadMultiple() as $id => $type) {
      $options[(int) $id] = $type->label();
    }
    asort($options);
    return $options;
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
    // A real record is the best answer there is: if the property has a backflow
    // device on file, it has a backflow. Before these records existed the
    // answer was stored as a sentence in a notes field and the radio came back
    // blank on the next visit.
    if ($system && !$system->get('field_property')->isEmpty()
      && $this->findDevices((int) $system->get('field_property')->target_id)) {
      return 'backflow';
    }
    if ($source && $this->findPumps((int) $source->id())) {
      return 'pump';
    }
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
