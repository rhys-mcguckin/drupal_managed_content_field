<?php

namespace Drupal\managed_content_field\Plugin\RulesAction;


use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\TypedData\ListInterface;
use Drupal\rules\Core\RulesActionBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class ManagedContentBase
 *
 * @package Drupal\managed_content_field\Plugin\RulesAction
 */
class ManagedContentBase extends RulesActionBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a ManagedContentBase object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function refineContextDefinitions(array $selected_data) {
  }

  /**
   * Get a field value from an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity object.
   * @param string $field_name
   *   The field name.
   *
   * @return mixed|null
   *   The value, or NULL if not found.
   */
  protected function getFieldValue(EntityInterface $entity, $field_name) {
    $parts = explode(':', $field_name);
    $target = $entity;
    foreach ($parts as $part) {
      if ($target instanceof ListInterface && !is_numeric($part)) {
        $target = $target->get(0);
      }

      $target = $target ? $target->get($part) : NULL;
      // We are unable to locate the source value.
      if (!$target) {
        return NULL;
      }
    }

    // Get the value used by the target.
    return $target ? $target->getValue() : NULL;
  }

  /**
   * Set a field value for an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity object.
   * @param string $field_name
   *   The field name.
   * @param mixed $value
   *   The field value.
   * @param bool $force
   *   Flag to force value to be updated.
   */
  protected function setFieldValue(EntityInterface $entity, $field_name, $value, $force = TRUE) {
    drupal_set_message("setFieldValue($field_name, $value)");
    $parts = explode(':', $field_name);
    $target = $entity;
    foreach ($parts as $part) {
      if ($target instanceof ListInterface && !is_numeric($part)) {
        // Create a value when there is none for a list.
        if (!$target->offsetExists(0)) {
          $target->appendItem();
        }

        $target = $target->get(0);
      }

      $target = $target ? $target->get($part) : NULL;

      // We are unable to locate the source value.
      if (!$target) {
        return;
      }
    }

    // Only update the value if empty or being forced.
    if (!$target->getValue() || $force) {
      // There is no guarantee that the target field supports the same data
      // type as the incoming value.
      try {
        // Set the value
        $target->setValue($value);
      }
      catch (\Exception $e) {
        // Ignore as any error makes the entity invalid.
      }
    }
  }

}