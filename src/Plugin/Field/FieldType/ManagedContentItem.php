<?php

namespace Drupal\managed_content_field\Plugin\Field\FieldType;

use Drupal\content_moderation\ContentModerationState;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\TypedDataInterface;
use Drupal\paragraphs\ParagraphInterface;

/**
 * Defines the 'managed_content' entity field type.
 *
 * @FieldType(
 *   id = "managed_content",
 *   label = @Translation("Managed Content"),
 *   description = @Translation("Moderates content via entity references."),
 *   category = @Translation("Managed Content"),
 *   default_widget = "managed_content",
 *   default_formatter = "entity_reference_label",
 *   list_class = "\Drupal\Core\Field\EntityReferenceFieldItemList",
 * )
 */
class ManagedContentItem extends EntityReferenceItem {

  /**
   * @var \Drupal\content_moderation\ModerationInformationInterface
   */
  protected $moderationInformation = NULL;

  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The action to be performed pre-save.
   *
   * @var string
   */
  protected $action;

  /**
   * The entity has been modified.
   *
   * @var bool
   */
  protected $modified = FALSE;

  /**
   * ManagedContentItem constructor.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   * @param null $name
   * @param \Drupal\Core\TypedData\TypedDataInterface|NULL $parent
   */
  public function __construct(DataDefinitionInterface $definition, $name = NULL, TypedDataInterface $parent = NULL) {
    parent::__construct($definition, $name, $parent);

    $this->entityTypeManager = \Drupal::entityTypeManager();

    // Only load the moderation information if module is enabled.
    if (\Drupal::moduleHandler()->moduleExists('content_moderation')) {
      $this->moderationInformation = \Drupal::service('content_moderation.moderation_information');
    }
  }

  /**
   * Get the save action to be performed on the referenced entity.
   *
   * @return string
   *   The entity save action.
   */
  public function getSaveAction() {
    return $this->action;
  }

  /**
   * Set the save action to be performed on the referenced entity.
   *
   * @param string $action
   *   The entity save action.
   *
   * @return static
   *   The object.
   */
  public function setSaveAction($action) {
    $this->action = $action;
    return $this;
  }

  /**
   * Confirm entity has been modified.
   *
   * @return bool
   *   The result.
   */
  public function isModified() {
    return $this->modified;
  }

  /**
   * Set the modified flag.
   *
   * @param bool $modified
   *   The modified flag.
   *
   * @return static
   *   The object.
   */
  public function setModified($modified) {
    $this->modified = (bool)$modified;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function hasNewEntity() {
    return parent::hasNewEntity() || ($this->action && $this->entity);
  }

  /**
   * {@inheritdoc}
   */
  public function preSave() {
    // Perform an action relative to the entity
    if ($this->action) {
      $method = 'action' . ucfirst($this->action);
      if (method_exists($this, $method)) {
        $this->$method();
      }
    }
    // Perform an update for the entity as it is modified.
    elseif ($this->modified && $this->entity) {
      $this->entity->save();
      $this->target_id = $this->entity->id();
    }

    parent::preSave();
  }

  /**
   * {@inheritdoc}
   */
  public function delete() {
    $this->actionRemove();
  }

  /**
   * {@inheritdoc}
   */
  public function getValue() {
    $value = parent::getValue();

    // This ensures we keep the action behaviour between form edits.
    if ($this->action) {
      $value['action'] = $this->action;
    }
    if ($this->modified) {
      $value['modified'] = $this->modified;
    }

    return $value;
  }

  /**
   * {@inheritdoc}
   */
  public function setValue($values, $notify = TRUE) {
    parent::setValue($values, $notify);

    // Set the action if saved.
    if (isset($values['action'])) {
      $this->action = $values['action'];
    }
    if (!empty($values['modified'])) {
      $this->modified = $values['modified'];
    }
  }

  /**
   * Gets the entity revision, or NULL if already being revised.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface|NULL
   */
  protected function getRevisionEntity(ContentEntityInterface $entity) {
    /** @var \Drupal\workflows\StateInterface $state */
    $state = NULL;
    $workflow = $this->moderationInformation->getWorkflowForEntity($entity);
    if (!$workflow) {
      return NULL;
    }

    $workflow_type = $workflow->getTypePlugin();
    if ($entity->isNew() || $this->isFirstTimeModeration($entity)) {
      return NULL;
    }

    $latest = $this->moderationInformation->getLatestRevision($entity->getEntityTypeId(), $entity->id());

    if (!$entity->isDefaultTranslation() && $latest->hasTranslation($latest->language()->getId())) {
      $latest = $latest->getTranslation($entity->language()->getId());
    }
    if ($workflow_type->hasState($latest->moderation_state->value)) {
      $state = $workflow_type->getState($latest->moderation_state->value);
    }

    // We need to have a state that is published to return the entity.
    if (!$state || !($state instanceof ContentModerationState) || !($state->isPublishedState())) {
      return NULL;
    }

    // Content moderation does not provide the default moderation state for existing entities.
    $definition = $this->entityTypeManager->getDefinition($entity->getEntityTypeId());
    $dummy = $this->entityTypeManager->getStorage($entity->getEntityTypeId())
      ->create([$definition->getKey('bundle') => $entity->bundle(), 'title' => 'Dummy']);

    // Reset the entity back to initial state.
    $new_state = $workflow_type->getInitialState($dummy);

    $entity->set('moderation_state', $new_state->id());

    return $entity;
  }

  /**
   * Determines if this entity is being managed for the first time.
   *
   * If the previous version of the entity has no moderation state, we assume
   * that means it predates the presence of moderation states.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being managed.
   *
   * @return bool
   *   TRUE if this is the entity's first time being managed, FALSE otherwise.
   */
  protected function isFirstTimeModeration(EntityInterface $entity) {
    $original_entity = $this->moderationInformation->getLatestRevision($entity->getEntityTypeId(), $entity->id());

    if ($original_entity) {
      $original_id = $original_entity->moderation_state;
    }

    return !($entity->moderation_state && $original_entity && $original_id);
  }

  /**
   * Revises the specific entity.
   */
  protected function actionRevise() {
    if ($this->entity) {
      // Moderation behaves slightly differently.
      if ($this->hasContentModeration($this->entity)) {
        /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
        $entity = $this->getRevisionEntity($this->entity);
      }
      // Entity is required to be published to be revised.
      elseif ($this->entity instanceof EntityPublishedInterface) {
        /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
        $entity = $this->entity->isPublished() ? $this->entity : NULL;
      }

      if (!empty($entity)) {
        $entity->setNewRevision(TRUE);
        $entity->save();

        $this->entity = $entity;
        $this->target_id = $entity->id();
      }
      else {
        $this->entity = NULL;
        $this->target_id = NULL;
      }
    }
  }

  /**
   * Check that the entity supports content moderation.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to check has content moderation.
   *
   * @return bool
   */
  protected function hasContentModeration(ContentEntityInterface $entity) {
    // Content moderation needs to be enabled.
    if (!\Drupal::moduleHandler()->moduleExists('content_moderation')) {
      return FALSE;
    }

    $workflow = $this->moderationInformation->getWorkflowForEntity($entity);
    return !!$workflow;
  }

  /**
   * Clones the specific entity.
   */
  protected function actionClone() {
    if ($this->entity) {
      /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
      $entity = $this->entity->createDuplicate();

      // Get the entity definition, to skip key fields.
      $entity_definition = $this->entityTypeManager->getDefinition($entity->getEntityTypeId());

      // Process all the additional field values.
      foreach ($entity->getFields() as $name => $field) {
        // Skip any entity index fields, as these should already be set correctly.
        if ($entity_definition->hasKey($name)) {
          continue;
        }

        // Special clone of paragraph field items.
        $definition = $field->getFieldDefinition();
        if ($definition->getType() == 'entity_reference_revisions' && $definition->getSetting('target_type') == 'paragraph') {
          $storage = $this->entityTypeManager->getStorage('paragraph');

          $value = [];
          foreach ($field as $key => $item) {
            /** @var ParagraphInterface $paragraph */
            $paragraph = $storage->loadRevision($item->getValue()['target_revision_id']);
            if ($paragraph) {
              $duplicate = $paragraph->createDuplicate();
              $duplicate->save();
              $value[$key] = [
                'target_id' => $duplicate->id(),
                'target_revision_id' => $duplicate->getRevisionId()
              ];
            }
            else {
              $value[$key] = [
                'target_id' => NULL,
                'target_revision_id' => NULL
              ];
            }
          }
        }
        else {
          $value = $field->getValue();
        }

        // Set updated value.
        $entity->set($name, $value);
      }

      // Create a new dummy entity to get the appropriate initial state for the entity.
      $definition = $this->entityTypeManager->getDefinition($entity->getEntityTypeId());
      $dummy = $this->entityTypeManager->getStorage($entity->getEntityTypeId())
        ->create([$definition->getKey('bundle') => $entity->bundle(), 'title' => 'Dummy']);

      // Reset the entity back to initial state.
      if ($this->hasContentModeration($entity)) {
        $workflow = $this->moderationInformation->getWorkflowForEntity($entity);
        $workflow_type = $workflow->getTypePlugin();
        $new_state = $workflow_type->getInitialState($dummy);

        // Set the new moderation state.
        $entity->moderation_state->value = $new_state->id();
      }

      // Save the entity
      $entity->save();

      // Ensure the field details are updated for the entity.
      $this->entity = $entity;
      $this->target_id = $entity->id();
    }
  }

  /**
   * Perform the delete action for the referenced entity.
   */
  protected function actionRemove() {
    if (!$this->entity) {
      return;
    }
    // Get the field definition for this field.
    $field_definition = $this->getFieldDefinition();

    /** @var \Drupal\Core\Entity\EntityInterface $entity */
    $entity = $this->entity;
    $entity_type = $entity->getEntityType();

    // Get information about the owner of the entity.
    $owner = $this->getEntity();
    $owner_type = $owner->getEntityType();

    // Generate the count of other references to the referenced entity that
    // are not on this entity's field.
    $field_name = $field_definition->getName();
    $query = \Drupal::entityQuery($owner->getEntityTypeId())
      ->condition($owner_type->getKey('id'), $owner->id(), '<>')
      ->condition($field_name . '.entity', $entity->id());

    $count = $query->count()->execute();
    if ($count > 0) {
      // Save the storage, as we need this for loading the revisions.
      $storage = $this->entityTypeManager->getStorage($entity->getEntityTypeId());

      // Query all revisions for the entity.
      $query = $storage->getQuery()
        ->allRevisions()
        ->condition($entity_type->getKey('id'), $entity->id())
        ->sort($entity_type->getKey('revision'), 'DESC');

      // Delete revisions up to last published revision.
      foreach (array_keys($query->execute()) as $vid) {
        /** @var \Drupal\Core\Entity\RevisionableInterface $revision */
        $revision = $storage->loadRevision($vid);
        if ($revision && $revision->wasDefaultRevision()) {
          break;
        }

        $storage->deleteRevision($vid);
      }
    }
    // Delete content as there is going to no longer be a reference to the entity.
    else {
      $entity->delete();
    }

    // Save nothing as the result of the remove action.
    $this->entity = NULL;
    $this->target_id = NULL;
  }

}
