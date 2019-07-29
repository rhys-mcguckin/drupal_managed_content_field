<?php

namespace Drupal\managed_content_field\Element;

use Drupal\Core\Render\Element\Operations;
use Drupal\Core\Render\Element\RenderElement;

/**
 * {@inheritdoc}
 *
 * @RenderElement("managed_content_operations")
 */
class ManagedContentOperations extends Operations {

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    return ['#theme' => 'links__dropbutton__operations__managed_content'] + parent::getInfo();
  }

  /**
   * {@inheritdoc}
   */
  public static function preRenderDropbutton($element) {
    $element = parent::preRenderDropbutton($element);

    // Attach #ajax events if title is a render array.
    foreach ($element['#links'] as &$link) {
      if (isset($link['title']['#ajax'])) {
        $link['title'] = RenderElement::preRenderAjaxForm($link['title']);
      }
    }

    return $element;
  }

}
