<?php

namespace Drupal\managed_content_field\Plugin\RulesAction;

use Drupal\rules\Core\RulesActionBase;

/**
 * Provides a 'Content Data set' action.
 *
 * @RulesAction(
 *   id = "managed_content_set",
 *   label = @Translation("Set a managed content data value"),
 *   category = @Translation("Managed Content"),
 *   context = {
 *     "data" = @ContextDefinition("any",
 *       label = @Translation("Data"),
 *       description = @Translation("Specifies the data to be modified using a data selector, e.g. 'node:author:name'."),
 *       allow_null = TRUE,
 *       assignment_restriction = "selector"
 *     ),
 *     "value" = @ContextDefinition("any",
 *       label = @Translation("Value"),
 *       description = @Translation("The new value to set for the specified data."),
 *       default_value = NULL,
 *       required = FALSE
 *     )
 *   }
 * )
 */
class ContentSet extends RulesActionBase {

  /**
   * Executes the Plugin.
   *
   * @param mixed $data
   *   Original value of an element which is being updated.
   * @param mixed $value
   *   A new value which is being set to an element identified by data selector.
   */
  protected function doExecute($data, $value) {
    $typed_data = $this->getContext('data')->getContextData();
    $typed_data->setValue($value);
  }

  /**
   * {@inheritdoc}
   */
  public function autoSaveContext() {
    return [];
  }

}
