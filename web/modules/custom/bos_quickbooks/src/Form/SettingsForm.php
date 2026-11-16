<?php

declare(strict_types=1);

namespace Drupal\bos_quickbooks\Form;

use Drupal\bos_quickbooks\QbwcSession;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * /admin/office/quickbooks/settings.
 *
 * Services are plain protected properties set in create(), not promoted
 * readonly constructor arguments — forms are serialized between requests and
 * promoted properties do not survive it (drupal_bos_gotchas.md).
 */
final class SettingsForm extends ConfigFormBase {

  protected QbwcSession $session;

  public static function create(ContainerInterface $container): static {
    $form = parent::create($container);
    $form->session = $container->get('bos_quickbooks.session');
    return $form;
  }

  protected function getEditableConfigNames(): array {
    return ['bos_quickbooks.settings'];
  }

  public function getFormId(): string {
    return 'bos_quickbooks_settings';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $c = $this->config('bos_quickbooks.settings');
    $form['connector'] = [
      '#type' => 'details',
      '#title' => $this->t('Web Connector credentials'),
      '#open' => TRUE,
    ];
    $form['connector']['username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('User name'),
      '#default_value' => $c->get('username') ?: 'bos',
      '#required' => TRUE,
      '#maxlength' => 64,
      '#description' => $this->t('Written into the .qwc file. Changing it means re-adding the application in the Web Connector.'),
    ];
    $form['connector']['password'] = [
      '#type' => 'password',
      '#title' => $this->session->hasPassword() ? $this->t('New password (leave blank to keep the current one)') : $this->t('Password'),
      '#description' => $this->t('Stored as a hash, never in config. The Web Connector asks for it once when the application is added and keeps it in its own store. Use a long random one.'),
      '#attributes' => ['autocomplete' => 'new-password'],
    ];
    $form['connector']['password_state'] = [
      '#markup' => '<p>' . ($this->session->hasPassword() ? $this->t('A password is set.') : $this->t('<strong>No password set</strong> — the connector cannot authenticate until one is.')) . '</p>',
    ];
    $form['connector']['run_every_minutes'] = [
      '#type' => 'number',
      '#title' => $this->t('Connector polls every (minutes)'),
      '#default_value' => $c->get('run_every_minutes') ?: 60,
      '#min' => 1,
      '#max' => 1440,
      '#description' => $this->t('Written into the .qwc file as the auto-run interval. The user can also change it in the Web Connector window.'),
    ];
    $form['connector']['company_file'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Company file the connector should open'),
      '#default_value' => $c->get('company_file'),
      '#maxlength' => 255,
      '#description' => $this->t('Normally empty: the connector uses whichever company file QuickBooks has open, or the one it was authorized against when QuickBooks is closed. Set the full Windows path only if a specific file must be forced.'),
    ];

    $form['sync'] = [
      '#type' => 'details',
      '#title' => $this->t('Sync and staleness'),
      '#open' => TRUE,
    ];
    $form['sync']['stale_hours'] = [
      '#type' => 'number',
      '#title' => $this->t('Mirror counts as stale after (hours)'),
      '#default_value' => $c->get('stale_hours') ?: 24,
      '#min' => 1,
      '#max' => 720,
      '#description' => $this->t('No complete sync within this window → the status turns STALE, an alert is emailed and invoicing refuses to run.'),
    ];
    $form['sync']['alert_hours'] = [
      '#type' => 'number',
      '#title' => $this->t('Repeat the stale alert every (hours)'),
      '#default_value' => max(1, (int) round(($c->get('alert_interval') ?: 86400) / 3600)),
      '#min' => 1,
      '#max' => 168,
    ];
    $form['sync']['alert_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Alert address'),
      '#default_value' => $c->get('alert_email'),
      '#description' => $this->t('Empty = the site email address.'),
    ];
    $form['sync']['max_returned'] = [
      '#type' => 'number',
      '#title' => $this->t('Records per page'),
      '#default_value' => $c->get('max_returned') ?: 500,
      '#min' => 50,
      '#max' => 2000,
      '#description' => $this->t('Customers and items are pulled in pages of this size (QuickBooks iterators).'),
    ];
    $form['sync']['qbxml_version'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Highest qbXML version to speak'),
      '#default_value' => $c->get('qbxml_version') ?: '16.0',
      '#size' => 6,
      '#pattern' => '\d+\.\d+',
      '#description' => $this->t('QuickBooks Desktop 2024 speaks 16.0. If the connector offers less, the lower version is used automatically.'),
    ];
    $form['sync']['app_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Application name'),
      '#default_value' => $c->get('app_name') ?: 'BOS QuickBooks Sync',
      '#maxlength' => 64,
      '#description' => $this->t('What the Web Connector and QuickBooks\'s authorization dialog call this application.'),
    ];
    $form['qwc'] = [
      '#markup' => '<p>' . $this->t('<a href=":url">Download bos-quickbooks.qwc</a> — the file the Web Connector is configured from (it embeds the endpoint URL, user name, interval and the two application GUIDs).', [':url' => Url::fromRoute('bos_quickbooks.qwc')->toString()]) . '</p>',
    ];
    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('bos_quickbooks.settings')
      ->set('username', trim((string) $form_state->getValue('username')))
      ->set('run_every_minutes', (int) $form_state->getValue('run_every_minutes'))
      ->set('company_file', trim((string) $form_state->getValue('company_file')))
      ->set('stale_hours', (int) $form_state->getValue('stale_hours'))
      ->set('alert_interval', (int) $form_state->getValue('alert_hours') * 3600)
      ->set('alert_email', trim((string) $form_state->getValue('alert_email')))
      ->set('max_returned', (int) $form_state->getValue('max_returned'))
      ->set('qbxml_version', trim((string) $form_state->getValue('qbxml_version')) ?: '16.0')
      ->set('app_name', trim((string) $form_state->getValue('app_name')) ?: 'BOS QuickBooks Sync')
      ->save();
    $password = (string) $form_state->getValue('password');
    if ($password !== '') {
      $this->session->setPassword($password);
      $this->messenger()->addStatus($this->t('Web Connector password set.'));
    }
    parent::submitForm($form, $form_state);
  }

}
