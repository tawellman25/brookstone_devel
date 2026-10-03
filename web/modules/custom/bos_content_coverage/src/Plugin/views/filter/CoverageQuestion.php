<?php

declare(strict_types=1);

namespace Drupal\bos_content_coverage\Plugin\views\filter;

use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Attribute\ViewsFilter;
use Drupal\views\Plugin\views\filter\BooleanOperator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Exposed yes/no filter for one coverage question.
 *
 * Resolved by asking ContentCoverage for the matching term ids and constraining
 * on those, rather than by building SQL. The field that holds a copy role varies
 * by vocabulary (services keeps its public copy in field_service_public_desc),
 * so there is no single predicate; and answering through the same code the
 * columns use guarantees the filter agrees with the column beside it. The
 * covered set is a few hundred terms.
 */
#[ViewsFilter("bos_coverage_question")]
final class CoverageQuestion extends BooleanOperator {

  protected $coverage;

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->coverage = $container->get('bos_content_coverage.coverage');
    return $instance;
  }

  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['coverage_question'] = ['default' => 'boilerplate'];
    return $options;
  }

  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    $form['coverage_question'] = [
      '#type' => 'select',
      '#title' => $this->t('Question'),
      '#options' => [
        'boilerplate' => $this->t('Carries boilerplate copy'),
        'missing_body' => $this->t('Missing its public description'),
        'missing_teaser' => $this->t('Missing its teaser'),
      ],
      '#default_value' => $this->options['coverage_question'],
    ];
    parent::buildOptionsForm($form, $form_state);
  }

  public function query() {
    // Unset/"All" on an exposed boolean means do not filter.
    if ($this->value === NULL || $this->value === '' || $this->value === 'All' || $this->value === []) {
      return;
    }
    $want = (bool) (is_array($this->value) ? reset($this->value) : $this->value);
    $tids = $this->coverage->matchingTids($this->options['coverage_question']);

    $this->ensureMyTable();
    $base = $this->tableAlias . '.tid';
    if ($want) {
      // No matches must return nothing, not everything.
      $this->query->addWhereExpression($this->options['group'], $tids ? $base . ' IN (:t[])' : '1 = 0', $tids ? [':t[]' => $tids] : []);
    }
    elseif ($tids) {
      $this->query->addWhereExpression($this->options['group'], $base . ' NOT IN (:t[])', [':t[]' => $tids]);
    }
  }

}
