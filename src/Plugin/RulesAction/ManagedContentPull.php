<?php

namespace Drupal\managed_content_field\Plugin\RulesAction;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Provides a 'Managed Content Pull Value' action.
 *
 * @RulesAction(
 *   id = "managed_content_pull",
 *   label = @Translation("Copy field from owning entity."),
 *   category = @Translation("Managed Content"),
 *   context = {
 *     "entity" = @ContextDefinition("entity",
 *       label = @Translation("Entity"),
 *       description = @Translation("Specify the content that will be receiving the value."),
 *       required = TRUE
 *     ),
 *     "source_field_name" = @ContextDefinition("string",
 *       label = @Translation("Source Field name"),
 *       description = @Translation("Name of the field that is providing the value."),
 *       required = TRUE
 *     ),
 *     "dest_field_name" = @ContextDefinition("string",
 *       label = @Translation("Destination Field name"),
 *       description = @Translation("Name of the destination field that is having it's value updated."),
 *       required = TRUE
 *     ),
 *     "forced" = @ContextDefinition("boolean",
 *       label = @Translation("Force"),
 *       description = @Translation("Forces a value to be updated if the value is not already set."),
 *       default_value = TRUE,
 *       required = TRUE
 *     )
 *   }
 * )
 */
class ManagedContentPull extends ManagedContentBase {

  /**
   * Execute the action within the given context.
   */
  protected function doExecute(ContentEntityInterface $entity, $source_field, $dest_field, $forced = TRUE) {
    // TODO: Convert this to use the specific type for looking up the appropriate field definition.
    // TODO: i.e. entity_type, field_name combo.

    // Save storage for entity.
    $storage = $this->entityTypeManager
      ->getStorage('abs_release');

    // Get the latest entity that is managing the content.
    $entities = $storage
      ->getQuery()
      ->condition('content', $entity->id(), '=')
      ->sort('release_date', 'DESC')
      ->execute();

    // There is no owning entity.
    if (count($entities) < 1) {
      return;
    }

    // Load the entity.
    $entity = $storage->load(reset($entities));
    if (!$entity) {
      return;
    }

    // Load the value from the entity.
    $value = $this->getFieldValue($entity, $source_field);
    if (is_null($value)) {
      return;
    }

    // Set the field value on the content.
    $this->setFieldValue($entity, $dest_field, $value, $forced);
  }

  /**
   * {@inheritdoc}
   */
  public function autoSaveContext() {
    return [];
  }

}
