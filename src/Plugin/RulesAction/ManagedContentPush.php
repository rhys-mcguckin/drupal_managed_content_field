<?php

namespace Drupal\managed_content_field\Plugin\RulesAction;

/**
 * Provides a 'Managed Content Push Value' action.
 *
 * @RulesAction(
 *   id = "managed_content_push",
 *   label = @Translation("Copy field from entity to managed content."),
 *   category = @Translation("Managed Content"),
 *   context = {
 *     "entity" = @ContextDefinition("entity",
 *       label = @Translation("Entity"),
 *       description = @Translation("Specifies the entity moderating the content."),
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
class ManagedContentPush extends ManagedContentBase {

  /**
   * Execute the action within the given context.
   */
  protected function doExecute($entity, $source_field, $dest_field, $forced = TRUE) {
    $value = $this->getFieldValue($entity, $source_field);
    if (is_null($value)) {
      return;
    }

    // TODO: Define the owning field name for the updating the entity.

    // Cycle through each piece of content.
    foreach ($entity->get('content') as $content) {
      /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
      $entity = $content->get('entity')->getValue();

      $this->setFieldValue($entity, $dest_field, $value, $forced);
    }
  }

}
