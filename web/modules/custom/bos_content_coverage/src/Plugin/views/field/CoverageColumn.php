<?php

declare(strict_types=1);

namespace Drupal\bos_content_coverage\Plugin\views\field;

use Drupal\bos_content_coverage\ContentCoverage;
use Drupal\Core\Form\FormStateInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * One computed column of the content coverage report.
 *
 * Covers the columns Views cannot express natively: the live URL alias, the
 * parent term, and per-role copy state where "not applicable" has to be
 * distinguished from "empty" — a vocabulary that never had a teaser slot is not
 * a page missing its teaser, and conflating the two would invent work.
 */
#[ViewsField("bos_coverage_column")]
final class CoverageColumn extends FieldPluginBase {

  protected ContentCoverage $coverage;

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->coverage = $container->get('bos_content_coverage.coverage');
    return $instance;
  }

  /**
   * Computed from the loaded term; nothing to add to the query.
   */
  public function query() {}

  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['coverage_column'] = ['default' => 'body'];
    return $options;
  }

  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    $form['coverage_column'] = [
      '#type' => 'select',
      '#title' => $this->t('Column'),
      '#options' => [
        'alias' => $this->t('Live URL alias'),
        'parent' => $this->t('Parent term'),
        'flag' => $this->t('Boilerplate flag'),
      ] + array_map(fn($label) => $this->t('State: @l', ['@l' => $label]), ContentCoverage::ROLES),
      '#default_value' => $this->options['coverage_column'],
    ];
    parent::buildOptionsForm($form, $form_state);
  }

  public function render(ResultRow $values) {
    $term = $values->_entity ?? NULL;
    if (!$term instanceof TermInterface) {
      return '';
    }
    $column = $this->options['coverage_column'];

    if ($column === 'alias') {
      $alias = $this->coverage->alias($term);
      // A term with no alias is worth seeing: it has no public URL.
      return $alias === '/taxonomy/term/' . $term->id() ? $this->t('— no alias —') : $alias;
    }

    if ($column === 'parent') {
      $pid = (int) $term->get('parent')->target_id;
      if (!$pid) {
        return $this->t('(root)');
      }
      $parent = $this->getEntityTypeManager()->getStorage('taxonomy_term')->load($pid);
      return $parent ? $parent->label() : (string) $pid;
    }

    if ($column === 'flag') {
      return $this->coverage->termIsFlagged($term) ? $this->t('⚠ boilerplate') : '';
    }

    // A copy role.
    $state = $this->coverage->state($term, $column);
    if (!$state['applicable']) {
      // The vocabulary has no such field — not a gap.
      return $this->t('n/a');
    }
    if (!$state['populated']) {
      return $this->t('EMPTY');
    }
    return $state['boilerplate']
      ? $this->t('@n chars ⚠', ['@n' => number_format($state['chars'])])
      : $this->t('@n chars', ['@n' => number_format($state['chars'])]);
  }

  private function getEntityTypeManager() {
    return \Drupal::entityTypeManager();
  }

}
