<?php


namespace Drupal\Tests\managed_content_field\Traits;


use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\workflows\Entity\Workflow;

/**
 * Trait ManagedContentTrait
 *
 * @package Drupal\Tests\managed_content_field\Functional
 */
trait ManagedContentTrait {

  use ContentTypeCreationTrait;

  /**
   * Create a content type, clearing the entity bundle information.
   *
   * @param $type
   *
   * @return \Drupal\node\Entity\NodeType
   */
  protected function addContentType($type) {
    $content_type = NodeType::create([
      'type' => $type,
      'name' => $type,
    ]);
    $content_type->save();
    return $content_type;
  }

  /**
   * Adds a field to a given entity type.
   *
   * @param string $entity_type
   *   The entity type.
   * @param string $bundle
   *   The entity bundle.
   * @param string $field_name
   *   Field name to be used.
   * @param string $field_type
   *   Type of the field.
   * @param array $storage_settings
   *   Settings for the field storage.
   */
  protected function addFieldtoEntity($entity_type, $bundle, $field_name, $field_type, array $storage_settings = []) {
    $field_type_definition = \Drupal::service('plugin.manager.field.field_type')->getDefinition($field_type);

    $widget = $field_type_definition['default_widget'];
    $formatter = $field_type_definition['default_formatter'];

    $this->addManagedField(
      $entity_type,
      $bundle,
      $field_name,
      ['type' => $field_type],
      $storage_settings,
      ['widget' => $widget, 'formatter' => $formatter],
      []
    );
  }

  /**
   * Adds a content type with a managed content field.
   *
   * @param string $content_type_name
   *   Content type name to be used.
   * @param string $managed_field_name
   *   (optional) Field name to be used. Defaults to 'field_managed'.
   */
  protected function addManagedContentType($content_type_name, $managed_field_name = 'field_managed') {
    // Create the content type.
    $this->addContentType($content_type_name);
    $this->addManagedField('node', $content_type_name, $managed_field_name);
  }

  /**
   * Update the entity form/view display using a particular widget.
   *
   * @param $target_storage
   *   The storage used for managing the displays.
   * @param $entity_type
   *   The entity type.
   * @param $bundle
   *   The entity bundle.
   * @param $view_mode
   *   The form view mode.
   * @param $field_name
   *   The field name.
   * @param $widget_name
   *   The widget used.
   */
  protected function updateEntityDisplay($target_storage, $entity_type, $bundle, $view_mode, $field_name, $widget_name) {
    // Setup the form display.
    $storage = \Drupal::entityTypeManager()->getStorage($target_storage);

    $form_entity = $storage->load($entity_type . '.' . $bundle . '.' . $view_mode);
    if (!$form_entity) {
      $values = array(
        'targetEntityType' => $entity_type,
        'bundle' => $bundle,
        'mode' => $view_mode,
        'status' => TRUE,
      );
      $form_entity = $storage->create($values);
    }

    $form_entity
      ->setComponent($field_name, ['type' => $widget_name])
      ->save();
  }

  /**
   * Adds a managed content field to a given entity type.
   *
   * @param string $entity_type
   *   The entity type.
   * @param string $bundle
   *   The entity bundle.
   * @param string $field_name
   *   Field name to be used.
   * @param array $storage
   *   Storage override values ('type' and 'cardinality').
   * @param array $storage_settings
   *   Storage settings override values.
   * @param array $instance
   *   Instance override values ('widget' and 'formatter').
   * @param array $instance_settings
   *   Instance settings override values.
   */
  protected function addManagedField($entity_type, $bundle, $field_name, array $storage = [], array $storage_settings = [], array $instance = [], array $instance_settings = []) {
    $field_storage = FieldStorageConfig::loadByName($entity_type, $field_name);
    if (!$field_storage) {
      // Add a node field.
      $field_storage = FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => $entity_type,
        'type' => !empty($storage['type']) ? $storage['type'] : 'managed_content',
        'cardinality' => !empty($storage['cardinality']) ? $storage['cardinality'] : '-1',
        'settings' => $storage_settings + [
            'target_type' => 'node',
          ],
      ]);
      $field_storage->save();
    }
    $field = FieldConfig::create([
      'field_storage' => $field_storage,
      'bundle' => $bundle,
      'settings' => $instance_settings + [
          'handler' => 'default:node',
          'handler_settings' => ['target_bundles' => NULL],
        ],
    ]);
    $field->save();

    $widget = !empty($instance['widget']) ? $instance['widget'] : 'managed_content';
    $formatter = !empty($instance['formatter']) ? $instance['formatter'] : 'entity_reference_label';
    $this->updateEntityDisplay('entity_form_display', $entity_type, $bundle, 'default', $field_name, $widget);
    $this->updateEntityDisplay('entity_view_display', $entity_type, $bundle, 'default', $field_name, $formatter);
  }

  /**
   * Creates a workflow entity.
   *
   * @param string $bundle
   *   The node bundle.
   */
  protected function createEditorialWorkflow($bundle) {
    if (!isset($this->workflow)) {
      $this->workflow = Workflow::create([
        'type' => 'content_moderation',
        'id' => $this->randomMachineName(),
        'label' => 'Editorial',
        'type_settings' => [
          'states' => [
            'archived' => [
              'label' => 'Archived',
              'weight' => 5,
              'published' => FALSE,
              'default_revision' => TRUE,
            ],
            'draft' => [
              'label' => 'Draft',
              'published' => FALSE,
              'default_revision' => FALSE,
              'weight' => -5,
            ],
            'published' => [
              'label' => 'Published',
              'published' => TRUE,
              'default_revision' => TRUE,
              'weight' => 0,
            ],
          ],
          'transitions' => [
            'archive' => [
              'label' => 'Archive',
              'from' => ['published'],
              'to' => 'archived',
              'weight' => 2,
            ],
            'archived_draft' => [
              'label' => 'Restore to Draft',
              'from' => ['archived'],
              'to' => 'draft',
              'weight' => 3,
            ],
            'archived_published' => [
              'label' => 'Restore',
              'from' => ['archived'],
              'to' => 'published',
              'weight' => 4,
            ],
            'create_new_draft' => [
              'label' => 'Create New Draft',
              'to' => 'draft',
              'weight' => 0,
              'from' => [
                'draft',
                'published',
              ],
            ],
            'publish' => [
              'label' => 'Publish',
              'to' => 'published',
              'weight' => 1,
              'from' => [
                'draft',
                'published',
              ],
            ],
          ],
        ],
      ]);
    }

    $this->workflow->getTypePlugin()->addEntityTypeAndBundle('node', $bundle);
    $this->workflow->save();
  }

}
