<?php

declare(strict_types=1);

namespace Drupal\bos_homepage\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Office-editable homepage copy. The high-churn text (hero, the seasonal
 * secondary button, band intros, careers) lives here; the structured lists
 * (service cards, the four steps, the four stats) stay in config
 * (bos_homepage.settings) and are edited there when they rarely change.
 */
class HomepageSettingsForm extends ConfigFormBase {

  protected function getEditableConfigNames(): array {
    return ['bos_homepage.settings'];
  }

  public function getFormId(): string {
    return 'bos_homepage_settings';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $c = $this->config('bos_homepage.settings');
    $t = (array) $c->get('trust');

    $form['hero'] = ['#type' => 'details', '#title' => $this->t('Hero'), '#open' => TRUE];
    $form['hero']['hero_eyebrow'] = ['#type' => 'textfield', '#title' => $this->t('Eyebrow'), '#default_value' => $c->get('hero_eyebrow')];
    $form['hero']['hero_headline'] = ['#type' => 'textfield', '#title' => $this->t('Headline (H1)'), '#default_value' => $c->get('hero_headline')];
    $form['hero']['hero_subhead'] = ['#type' => 'textarea', '#title' => $this->t('Subhead'), '#rows' => 2, '#default_value' => $c->get('hero_subhead')];
    $form['hero']['hero_primary_label'] = ['#type' => 'textfield', '#title' => $this->t('Primary button label'), '#default_value' => $c->get('hero_primary_label')];
    $form['hero']['hero_primary_url'] = ['#type' => 'textfield', '#title' => $this->t('Primary button link'), '#default_value' => $c->get('hero_primary_url')];
    $form['hero']['hero_secondary_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Secondary button label (SEASONAL)'),
      '#description' => $this->t('Changes with the season: "Book Sprinkler Winterization" (Sep–Nov), "Snow Removal Contracts" (Dec–Feb), "Schedule Spring Turn-On" (Mar–May).'),
      '#default_value' => $c->get('hero_secondary_label'),
    ];
    $form['hero']['hero_secondary_url'] = ['#type' => 'textfield', '#title' => $this->t('Secondary button link'), '#default_value' => $c->get('hero_secondary_url')];

    $form['promo'] = ['#type' => 'details', '#title' => $this->t('Seasonal promo banner (under the hero)'), '#open' => TRUE];
    $form['promo']['promo_enabled'] = ['#type' => 'checkbox', '#title' => $this->t('Show the promo banner'), '#default_value' => (bool) $c->get('promo_enabled')];
    $form['promo']['promo_eyebrow'] = ['#type' => 'textfield', '#title' => $this->t('Eyebrow'), '#default_value' => $c->get('promo_eyebrow'), '#description' => $this->t('e.g. BOOKING NOW')];
    $form['promo']['promo_text'] = ['#type' => 'textfield', '#title' => $this->t('Message'), '#maxlength' => 255, '#default_value' => $c->get('promo_text')];
    $form['promo']['promo_cta_label'] = ['#type' => 'textfield', '#title' => $this->t('Link label'), '#default_value' => $c->get('promo_cta_label')];
    $form['promo']['promo_cta_url'] = ['#type' => 'textfield', '#title' => $this->t('Link'), '#default_value' => $c->get('promo_cta_url')];

    $form['trust'] = ['#type' => 'details', '#title' => $this->t('Trust strip (3 items)'), '#open' => FALSE];
    for ($i = 0; $i < 3; $i++) {
      $form['trust']['trust_' . $i] = ['#type' => 'textfield', '#title' => $this->t('Item @n', ['@n' => $i + 1]), '#default_value' => $t[$i] ?? ''];
    }

    $form['bands'] = ['#type' => 'details', '#title' => $this->t('Band headings & intros'), '#open' => FALSE];
    foreach ([
      'services_h2' => 'Services — heading', 'services_intro' => 'Services — intro',
      'proof_h2' => 'Proof — heading', 'proof_intro' => 'Proof — intro',
      'steps_h2' => 'How it works — heading', 'steps_intro' => 'How it works — intro',
      'steps_cta_label' => 'How it works — button label',
      'who_h2' => 'Who we are — heading',
    ] as $key => $label) {
      $isText = str_ends_with($key, 'intro');
      $form['bands'][$key] = [
        '#type' => $isText ? 'textarea' : 'textfield',
        '#title' => $this->t($label), '#rows' => 2, '#default_value' => $c->get($key),
      ];
    }
    $form['bands']['who_body'] = ['#type' => 'textarea', '#title' => $this->t('Who we are — body (blank line between paragraphs)'), '#rows' => 6, '#default_value' => $c->get('who_body')];
    $form['bands']['service_area'] = ['#type' => 'textfield', '#title' => $this->t('Service-area line'), '#default_value' => $c->get('service_area')];

    $form['careers'] = ['#type' => 'details', '#title' => $this->t('Careers'), '#open' => FALSE];
    $form['careers']['careers_h2'] = ['#type' => 'textfield', '#title' => $this->t('Heading'), '#default_value' => $c->get('careers_h2')];
    $form['careers']['careers_body'] = ['#type' => 'textarea', '#title' => $this->t('Body'), '#rows' => 3, '#default_value' => $c->get('careers_body')];
    $form['careers']['careers_button_label'] = ['#type' => 'textfield', '#title' => $this->t('Button label'), '#default_value' => $c->get('careers_button_label')];
    $form['careers']['careers_url'] = ['#type' => 'textfield', '#title' => $this->t('Button link'), '#default_value' => $c->get('careers_url')];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $c = $this->config('bos_homepage.settings');
    $scalars = [
      'hero_eyebrow', 'hero_headline', 'hero_subhead', 'hero_primary_label', 'hero_primary_url',
      'hero_secondary_label', 'hero_secondary_url', 'services_h2', 'services_intro', 'proof_h2',
      'proof_intro', 'steps_h2', 'steps_intro', 'steps_cta_label', 'who_h2', 'who_body',
      'service_area', 'careers_h2', 'careers_body', 'careers_button_label', 'careers_url',
    ];
    $scalars = array_merge($scalars, ['promo_eyebrow', 'promo_text', 'promo_cta_label', 'promo_cta_url']);
    foreach ($scalars as $k) {
      $c->set($k, $form_state->getValue($k));
    }
    $c->set('promo_enabled', (bool) $form_state->getValue('promo_enabled'));
    $c->set('trust', array_values(array_filter([
      $form_state->getValue('trust_0'),
      $form_state->getValue('trust_1'),
      $form_state->getValue('trust_2'),
    ], fn($v) => $v !== '' && $v !== NULL)));
    $c->save();
    parent::submitForm($form, $form_state);
  }

}
