<?php

declare(strict_types=1);

namespace Drupal\bos_service_request\Holiday;

use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * The one implementation of holiday lighting pricing.
 *
 * Both estimators — the public one on /holiday-lights and the on-site one a
 * teammate uses at the curb — call this. That is the point: a customer who
 * builds an estimate on the website and then gets a walkthrough must not be
 * shown two different numbers for the same scope.
 *
 * Every rate comes from config_pages:business_setting (see
 * web/scripts/setup_holiday_price_book.php). Nothing is hardcoded here, so the
 * office changes a price in one place with no deploy.
 *
 * Deliberately returns NO cost or margin. Section 12 of the Rev 2 field sheet
 * lists the burdened labor rate and the material costs as unverified, so a
 * margin figure out of this class would read as fact while resting on a guess.
 * When those inputs are real, margin belongs here — once — not in either form.
 */
final class HolidayEstimator {

  use StringTranslationTrait;

  /** Plan keys. The value is the settings field holding that plan's rate. */
  public const PLANS = [
    'two_year' => ['field' => 'field_holiday_light_per_foot', 'label' => 'Full Season · 2-year', 'full_service' => TRUE],
    'one_year' => ['field' => 'field_holiday_rate_1yr', 'label' => 'Full Season · 1-year', 'full_service' => TRUE],
    'hang' => ['field' => 'field_holiday_rate_hang', 'label' => 'Hang & Remove', 'full_service' => FALSE],
  ];

  /**
   * Counted add-ons: key => [settings field, label, helper].
   *
   * Order is the order both forms render, and it follows the field sheet:
   * trees first because they are the biggest ticket after the roofline.
   */
  public const ADDONS = [
    'dec_sm' => ['field_holiday_tree_dec_sm', 'Deciduous tree — small', 'Trunk thinner than a basketball'],
    'dec_md' => ['field_holiday_tree_dec_md', 'Deciduous tree — medium', "Trunk about one arm's reach around, 10–16 in"],
    'dec_lg' => ['field_holiday_tree_dec_lg', 'Deciduous tree — large', 'Trunk 16–24 in'],
    'eg_6' => ['field_holiday_tree_eg_6', 'Evergreen — up to 6 ft', 'Shorter than a person and a half'],
    'eg_9' => ['field_holiday_tree_eg_9', 'Evergreen — 6 to 9 ft', 'Up to about the gutter line on a single story'],
    'eg_12' => ['field_holiday_tree_eg_12', 'Evergreen — 9 to 12 ft', 'Clears the roofline on a single story'],
    'wreath_24' => ['field_holiday_wreath_24', 'Wreath — 24 in', 'Each, hung and lit'],
    'wreath_36' => ['field_holiday_wreath_36', 'Wreath — 36 in', 'Each'],
    'wreath_48' => ['field_holiday_wreath_48', 'Wreath — 48 in', 'Each'],
    'shrub' => ['field_holiday_shrub', 'Shrub or bush', 'Three minimum'],
    'column' => ['field_holiday_column_wrap', 'Porch column', 'Each, wrapped to 10 ft'],
    'window' => ['field_holiday_window', 'Lit window', 'Each window'],
    'walkway' => ['field_holiday_walkway', 'Walkway or bed border', 'Per 25 ft section'],
  ];

  public function __construct(
    private readonly \Drupal\config_pages\ConfigPagesLoaderServiceInterface $configPages,
  ) {}

  /**
   * A rate from the price book, or NULL when the office has not set it.
   *
   * NULL rather than 0: a missing price must stop the line appearing, never
   * quote it free.
   */
  public function rate(string $field): ?float {
    $cp = $this->configPages->load('business_setting');
    if (!$cp || !$cp->hasField($field)) {
      return NULL;
    }
    $v = $cp->get($field)->value;
    return ($v === NULL || $v === '') ? NULL : (float) $v;
  }

  /**
   * Plans the office has priced, optionally only the ones we can price unseen.
   *
   * @param bool|null $fullServiceOnly
   *   TRUE returns only plans where we supply the lights. That is the PUBLIC
   *   set: Hang & Remove uses the customer's own lights, and its price depends
   *   on what condition those lights are in — tangled, missing clips, half the
   *   strands dead — which cannot be judged from a web form. Offering it as a
   *   selectable option would also let a price-shopper self-select into the
   *   lowest-margin plan with no salesperson present; the field sheet calls it
   *   a door opener, to be used when they will not commit. It is described in
   *   copy on the public page instead, with an inspection required.
   *   NULL returns everything, which is what the on-site form wants — the
   *   teammate is standing there looking at the lights.
   *
   * @return array
   *   plan key => ['label' => string, 'rate' => float, 'minimum' => float,
   *   'full_service' => bool]
   */
  public function pricedPlans(?bool $fullServiceOnly = NULL): array {
    $out = [];
    foreach (self::PLANS as $key => $spec) {
      if ($fullServiceOnly === TRUE && !$spec['full_service']) {
        continue;
      }
      $rate = $this->rate($spec['field']);
      if ($rate === NULL || $rate <= 0) {
        // An unpriced plan is not offered. Quoting a season at $0 is worse than
        // not listing it.
        continue;
      }
      $out[$key] = [
        'label' => $spec['label'],
        'rate' => $rate,
        'full_service' => $spec['full_service'],
        'minimum' => (float) ($this->rate($spec['full_service'] ? 'field_holiday_min_job' : 'field_holiday_min_hang') ?? 0),
      ];
    }
    return $out;
  }

  /**
   * Every add-on the office has priced, in render order.
   *
   * @return array
   *   key => ['label' => string, 'help' => string, 'price' => float]
   */
  public function pricedAddons(): array {
    $out = [];
    foreach (self::ADDONS as $key => [$field, $label, $help]) {
      $price = $this->rate($field);
      if ($price !== NULL && $price > 0) {
        $out[$key] = ['label' => $label, 'help' => $help, 'price' => $price];
      }
    }
    return $out;
  }

  /**
   * Price a scope.
   *
   * @param string $plan
   *   A key of self::PLANS.
   * @param float $feet
   *   Measured roofline feet.
   * @param array $quantities
   *   Add-on key => quantity.
   * @param bool $steep
   *   Steep roof premium applies.
   * @param bool $tall
   *   Work above 25 ft applies.
   *
   * @return array
   *   lines[] (each label/amount/note), total, deposit, minimum_applied,
   *   notes[]. Money is returned as float; formatting is the caller's job.
   */
  public function price(string $plan, float $feet, array $quantities = [], bool $steep = FALSE, bool $tall = FALSE): array {
    $plan = isset(self::PLANS[$plan]) ? $plan : 'two_year';
    $spec = self::PLANS[$plan];
    $rate = $this->rate($spec['field']);
    $fullService = $spec['full_service'];

    $lines = [];
    $notes = [];
    $total = 0.0;
    $minApplied = FALSE;

    $feet = max(0.0, $feet);

    // --- roofline ---------------------------------------------------------
    if ($feet > 0 && $rate !== NULL && $rate > 0) {
      $raw = $feet * $rate;
      $minField = $fullService ? 'field_holiday_min_job' : 'field_holiday_min_hang';
      $minimum = $this->rate($minField);
      $roof = $raw;
      if ($minimum !== NULL && $raw < $minimum) {
        $roof = $minimum;
        $minApplied = TRUE;
      }
      $lines[] = [
        'label' => sprintf('Roofline — %s ft at $%s/ft', rtrim(rtrim(number_format($feet, 1, '.', ','), '0'), '.'), number_format($rate, 2)),
        'amount' => $roof,
      ];
      if ($minApplied) {
        $lines[] = [
          'label' => sprintf('Minimum job applied ($%s)', number_format((float) $minimum, 0)),
          'amount' => NULL,
          'note' => TRUE,
        ];
      }
      $total += $roof;

      // Steep applies to the roofline portion only, after the minimum.
      if ($steep) {
        $pct = $this->rate('field_holiday_steep_pct');
        if ($pct !== NULL && $pct > 0) {
          $premium = $roof * ($pct / 100);
          $lines[] = ['label' => sprintf('Steep roof — %s%%', rtrim(rtrim(number_format($pct, 1, '.', ''), '0'), '.')), 'amount' => $premium];
          $total += $premium;
        }
      }
    }
    elseif ($feet > 0 && ($rate === NULL || $rate <= 0)) {
      // The office has not priced this plan. Say so rather than quote $0.
      $notes[] = (string) $this->t('This plan has no price set yet — ask the office before quoting it.');
    }

    // --- counted add-ons --------------------------------------------------
    $priced = $this->pricedAddons();
    $shrubMin = (int) ($this->rate('field_holiday_shrub_min_qty') ?? 0);
    foreach ($priced as $key => $info) {
      $qty = (int) ($quantities[$key] ?? 0);
      if ($qty <= 0) {
        continue;
      }
      if ($key === 'shrub' && $shrubMin > 0 && $qty < $shrubMin) {
        // Nudge up rather than reject — the field sheet is explicit about this.
        $notes[] = (string) $this->t('Shrubs have a @n-unit minimum, so @q was priced as @n.', ['@n' => $shrubMin, '@q' => $qty]);
        $qty = $shrubMin;
      }
      $amount = $info['price'] * $qty;
      $lines[] = [
        'label' => $info['label'] . ($qty > 1 ? ' × ' . $qty : ''),
        'amount' => $amount,
      ];
      $total += $amount;
    }

    // --- height adder -----------------------------------------------------
    if ($tall) {
      $adder = $this->rate('field_holiday_tall_adder');
      if ($adder !== NULL && $adder > 0) {
        $lines[] = ['label' => (string) $this->t('Work above 25 ft'), 'amount' => $adder];
        $total += $adder;
      }
    }

    // --- closing notes ----------------------------------------------------
    if ($minApplied) {
      $notes[] = (string) $this->t('Roofline came in under the minimum. Add scope rather than discounting.');
    }
    $notes[] = $fullService
      ? (string) $this->t('Includes our lights, install, service all season, takedown and storage.')
      : (string) $this->t("Uses the customer's own lights. No service guarantee and no storage.");

    return [
      'plan' => $plan,
      'plan_label' => $spec['label'],
      'rate' => $rate,
      'feet' => $feet,
      'lines' => $lines,
      'total' => $total,
      // 50% at signing buys the material; it does not apply where we supply none.
      'deposit' => $fullService ? $total * 0.5 : NULL,
      'minimum_applied' => $minApplied,
      'notes' => $notes,
    ];
  }

  /**
   * Substitute the holiday price tokens in a body of copy.
   *
   * Office copy carries [holiday-price] and friends rather than typed figures,
   * so a rate change in Business Settings reaches every surface at once. That
   * is the lesson from 2026-10-03, where a published $8 per-foot figure existed
   * only in page copy and had to be hand-edited when it moved.
   *
   * ⚠ bos_services does its own substitution of [holiday-price] when it renders
   * the service page's field. This covers the OTHER consumer: the same body is
   * reused to build the accordions on /holiday-lights, which did not substitute
   * anything — so a visitor there was shown the literal string "[holiday-price]"
   * (found live 2026-10-04). Any new token must be added here.
   */
  public function replaceTokens(string $html): string {
    // Keep real cents. rtrim-ing a trailing zero turns 9.50 into "$9.5" — it is
    // invisible while every rate is whole dollars and wrong the first time one
    // is not, and these are per-foot rates where cents are likely. Taken from
    // bos_services, which had already got this right.
    $money = static fn(float $n): string => '$' . (fmod($n, 1.0) === 0.0
      ? number_format($n, 0)
      : number_format($n, 2));

    $perFoot = $this->rate('field_holiday_light_per_foot');
    $hang = $this->rate('field_holiday_rate_hang');
    $hangMin = $this->rate('field_holiday_min_hang');
    $min = $this->rate('field_holiday_min_job');

    $map = [];
    // [holiday-price] carries its own phrasing INCLUDING the fallback, because
    // existing copy was written around it and a missing rate must never print
    // "$0.00" on a public page.
    $map['[holiday-price]'] = ($perFoot !== NULL && $perFoot > 0)
      ? $money($perFoot) . ' per foot'
      : 'priced per linear foot — call for the current rate';
    // The rest are plain money; the copy supplies the words around them.
    if ($min !== NULL && $min > 0) {
      $map['[holiday-min]'] = $money($min);
    }
    if ($hang !== NULL && $hang > 0) {
      $map['[holiday-hang-rate]'] = $money($hang);
    }
    if ($hangMin !== NULL && $hangMin > 0) {
      $map['[holiday-hang-min]'] = $money($hangMin);
    }
    // A token with no rate behind it is left ALONE rather than resolved to an
    // empty string, so a hole in a sentence is visible to whoever maintains the
    // copy instead of being quietly published.
    return strtr($html, $map);
  }

  /**
   * A plain-text itemisation, for storing with a lead or emailing a customer.
   */
  public function asText(array $result): string {
    $out = [$result['plan_label'] . ':'];
    foreach ($result['lines'] as $line) {
      if ($line['amount'] === NULL) {
        $out[] = '    (' . $line['label'] . ')';
        continue;
      }
      $out[] = sprintf('  %-46s $%s', $line['label'], number_format((float) $line['amount'], 2));
    }
    $out[] = sprintf('  %-46s $%s', 'ESTIMATE TOTAL', number_format((float) $result['total'], 2));
    if ($result['deposit'] !== NULL) {
      $out[] = sprintf('  %-46s $%s', 'Deposit at signing (50%)', number_format((float) $result['deposit'], 2));
    }
    return implode("\n", $out);
  }

}
