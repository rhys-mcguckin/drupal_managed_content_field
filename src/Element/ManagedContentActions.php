<?php

namespace Drupal\managed_content_field\Element;

use Drupal\Core\Render\Element;
use Drupal\Core\Render\Element\RenderElement;

/**
 * Provides a render element for managed content actions.
 *
 * Managed content actions can have two type of actions
 * - actions - this are default actions that are always visible.
 * - dropdown_actions - actions that are in dropdown sub component.
 *
 * Usage example:
 *
 * @code
 * $form['actions'] = [
 *   '#type' => 'managed_content_actions',
 *   'actions' => $actions,
 *   'dropdown_actions' => $dropdown_actions,
 * ];
 * $dropdown_actions['button'] = array(
 *   '#type' => 'submit',
 * );
 * @endcode
 *
 * @FormElement("managed_content_actions")
 */
class ManagedContentActions extends RenderElement {

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    $class = get_class($this);

    return [
      '#pre_render' => [
        [$class, 'preRenderManagedContentActions'],
      ],
      '#theme' => 'managed_content_actions',
    ];
  }

  /**
   * Pre render callback for #type 'managed_content_actions'.
   *
   * @param array $element
   *   Element array of a #type 'managed_content_actions'.
   *
   * @return array
   *   The processed element.
   */
  public static function preRenderManagedContentActions(array $element) {
    $element['#attached']['library'][] = 'managed_content_field/drupal.managed_content_field.actions';

    if (!empty($element['dropdown_actions'])) {
      foreach (Element::children($element['dropdown_actions']) as $key) {
        $dropdown_action = &$element['dropdown_actions'][$key];
        if (isset($dropdown_action['#ajax'])) {
          $dropdown_action = RenderElement::preRenderAjaxForm($dropdown_action);
        }
        if (empty($dropdown_action['#attributes'])) {
          $dropdown_action['#attributes'] = ['class' => ['managed-content-dropdown-action']];
        }
        else {
          $dropdown_action['#attributes']['class'][] = 'managed-content-dropdown-action';
        }
      }
    }

    return $element;
  }

}
