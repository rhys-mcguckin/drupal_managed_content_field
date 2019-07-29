<?php

namespace Drupal\managed_content_field\Plugin\Field\FieldWidget;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\managed_content_field\Plugin\Field\FieldType\ManagedContentItem;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldFilteredMarkup;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\TypedData\TranslationStatusInterface;
use Symfony\Component\Validator\ConstraintViolationInterface;

/**
 * Plugin implementation of the 'managed_content' widget.
 *
 * @FieldWidget(
 *   id = "managed_content",
 *   label = @Translation("Managed Content"),
 *   field_types = {
 *     "managed_content"
 *   }
 * )
 */
class ManagedContentWidget extends WidgetBase {

  /**
   * Action position is in the add content place.
   */
  const ACTION_POSITION_BASE = 1;

  /**
   * Action position is in the table header section.
   */
  const ACTION_POSITION_HEADER = 2;

  /**
   * Action position is in the actions section of the widget.
   */
  const ACTION_POSITION_ACTIONS = 3;

  /**
   * @var \Drupal\content_moderation\ModerationInformationInterface
   */
  protected $moderationInformation = NULL;

  /**
   * Indicates whether the current widget instance is in translation.
   *
   * @var bool
   */
  protected $isTranslating;

  /**
   * Id to name ajax buttons that includes field parents and field name.
   *
   * @var string
   */
  protected $fieldIdPrefix;

  /**
   * Wrapper id to identify the managed content.
   *
   * @var string
   */
  protected $fieldWrapperId;

  /**
   * Id for the delta.
   *
   * @var string
   */
  protected $deltaId;

  /**
   * @var string
   */
  protected $deltaWrapperId;

  /**
   * Number of managed content items on form.
   *
   * @var int
   */
  protected $realItemCount;

  /**
   * Parents for the current managed content.
   *
   * @var array
   */
  protected $fieldParents;

  /**
   * Accessible managed content types.
   *
   * @var array
   */
  protected $accessOptions = NULL;

  /**
   * Constructs a ManagedContentWidget object.
   *
   * @param string $plugin_id
   *   The plugin_id for the widget.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The definition of the field to which the widget is associated.
   * @param array $settings
   *   The widget settings.
   * @param array $third_party_settings
   *   Any third party settings.
   */
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, array $third_party_settings) {
    // Modify settings that were set before https://www.drupal.org/node/2896115.
    if (isset($settings['edit_mode']) && $settings['edit_mode'] === 'preview') {
      $settings['edit_mode'] = 'closed';
    }

    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $third_party_settings);

    // Only load the moderation information if module is enabled.
    if (\Drupal::moduleHandler()->moduleExists('content_moderation')) {
      $this->moderationInformation = \Drupal::service('content_moderation.moderation_information');
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'edit_mode' => 'open',
      'autocollapse' => 'none',
      'form_display_mode' => 'default',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $elements = [];

    $elements['edit_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Edit mode'),
      '#description' => $this->t('The mode the content is in by default.'),
      '#options' => $this->getSettingOptions('edit_mode'),
      '#default_value' => $this->getSetting('edit_mode'),
      '#required' => TRUE,
    ];

    $elements['autocollapse'] = [
      '#type' => 'select',
      '#title' => $this->t('Autocollapse'),
      '#description' => $this->t('When content is opened for editing, close others.'),
      '#options' => $this->getSettingOptions('autocollapse'),
      '#default_value' => $this->getSetting('autocollapse'),
      '#required' => TRUE,
      '#states' => [
        'visible' => [
          'select[name="fields[' . $this->fieldDefinition->getName() . '][settings_edit_form][settings][edit_mode]"]' => ['value' => 'closed'],
        ],
      ],
    ];

    $elements['form_display_mode'] = [
      '#type' => 'select',
      '#options' => \Drupal::service('entity_display.repository')
        ->getFormModeOptions($this->getFieldSetting('target_type')),
      '#description' => $this->t('The form display mode to use when rendering the content form.'),
      '#title' => $this->t('Form display mode'),
      '#default_value' => $this->getSetting('form_display_mode'),
      '#required' => TRUE,
    ];

    return $elements;
  }

  /**
   * Returns select options for a plugin setting.
   *
   * This is done to allow
   * \Drupal\managed_content_field\Plugin\Field\FieldWidget\static::settingsSummary()
   * to access option labels. Not all plugin setting are available.
   *
   * @param string $setting_name
   *   The name of the widget setting. Supported settings:
   *   - "edit_mode"
   *   - "autocollapse"
   *
   * @return array|null
   *   An array of setting option usable as a value for a "#options" key.
   */
  protected function getSettingOptions($setting_name) {
    switch ($setting_name) {
      case 'edit_mode':
        $options = [
          'open' => $this->t('Open'),
          'closed' => $this->t('Closed'),
        ];
        break;
      case 'autocollapse':
        $options = [
          'none' => $this->t('None'),
          'all' => $this->t('All'),
        ];
        break;
    }

    return isset($options) ? $options : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = [];

    $edit_mode = $this->getSettingOptions('edit_mode')[$this->getSetting('edit_mode')];

    $summary[] = $this->t('Edit mode: @edit_mode', ['@edit_mode' => $edit_mode]);
    if ($this->getSetting('edit_mode') == 'closed') {
      $autocollapse = $this->getSettingOptions('autocollapse')[$this->getSetting('autocollapse')];
      $summary[] = $this->t('Autocollapse: @autocollapse', ['@autocollapse' => $autocollapse]);
    }

    $summary[] = $this->t('Form display mode: @form_display_mode', [
      '@form_display_mode' => $this->getSetting('form_display_mode'),
    ]);

    return $summary;
  }

  /**
   * {@inheritdoc}
   *
   * @see \Drupal\content_translation\Controller\ContentTranslationController::prepareTranslation()
   *   Uses a similar approach to populate a new translation.
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $field_name = $this->fieldDefinition->getName();
    $parents = $element['#field_parents'];

    /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
    $entity = NULL;
    $widget_state = static::getWidgetState($parents, $field_name, $form_state);

    $default_item_mode = $this->getSetting('edit_mode') == 'open' ? 'edit' : 'closed';
    $item_type = isset($widget_state['content'][$delta]['type']) ? $widget_state['content'][$delta]['type'] : '';
    $item_mode = isset($widget_state['content'][$delta]['mode']) ? $widget_state['content'][$delta]['mode'] : $default_item_mode;

    // Get the entity information for the field delta.
    if (isset($widget_state['content'][$delta]['entity'])) {
      $entity = $widget_state['content'][$delta]['entity'];
    }
    elseif (isset($items[$delta]->entity)) {
      $entity = $items[$delta]->entity;
    }

    // An entity is defined, but no item type defined.
    if ($entity && !$item_type) {
      $item_type = 'item';
    }

    // Item type is undefined, so this is a creation behaviour.
    if (!$item_type) {
      $action = isset($widget_state['action']) ? $widget_state['action'] : '';
      switch ($action) {
        case 'revise':
        case 'clone':
          $item_type = $action;
          $item_mode = 'edit';

          // Allow for the field to perform an action on the referenced item.
          if ($items[$delta] instanceof ManagedContentItem) {
            $items[$delta]->setSaveAction($item_type);
          }
          break;

        default:
          if (isset($widget_state['selected_bundle'])) {
            $item_type = 'item';
            $item_mode = 'edit';

            $entity_type_manager = \Drupal::entityTypeManager();
            $target_type = $this->getFieldSetting('target_type');

            $entity_type = $entity_type_manager->getDefinition($target_type);
            $bundle_key = $entity_type->getKey('bundle');

            $entity = $entity_type_manager->getStorage($target_type)->create([
              $bundle_key => $widget_state['selected_bundle'],
            ]);
          }
          break;
      }
    }

    // We have no specific item_type and item_mode.
    if (!$item_type) {
      $element['#access'] = FALSE;
    }
    elseif ($item_mode === 'remove') {
      // Existing entities that have been removed require a restore behaviour.
      if (!$entity->isNew()) {
        $this->prepareForm($element, $parents, $field_name, $delta);
        $this->formRemoveEntity($items, $delta, $item_type, $item_mode, $element, $entity, $widget_state, $form, $form_state);
      }
      // New entities that have been removed should be completely ignored.
      else {
        $element['#access'] = FALSE;
      }
    }
    // But we have recorded the item_type and item_mode.
    else {
      $this->prepareForm($element, $parents, $field_name, $delta);

      // Provide a different form based
      if ($item_type != 'item') {
        $this->formReferenceEntity($items, $delta, $item_type, $item_mode, $element, $entity, $widget_state, $form, $form_state);
      }
      else {
        $this->formEditEntity($items, $delta, $item_mode, $element, $entity, $widget_state, $form, $form_state);
      }

      // Ensure that the subform has an appropriate class.
      $element['subform']['#attributes']['class'][] = 'managed-content-subform';
    }

    $widget_state['content'][$delta]['entity'] = $entity;
    $widget_state['content'][$delta]['mode'] = $item_mode;
    $widget_state['content'][$delta]['type'] = $item_type;
    $widget_state['autocollapse'] = isset($widget_state['autocollapse']) ?
      $widget_state['autocollapse'] :
      $this->getSetting('autocollapse');
    $widget_state['autocollapse_default'] = $this->getSetting('autocollapse');

    static::setWidgetState($parents, $field_name, $form_state, $widget_state);

    return $element;
  }

  /**
   * Prepares the form element for the configuration.
   *
   * @param array $element
   * @param array $parents
   * @param $field_name
   * @param $delta
   */
  protected function prepareForm(array &$element, array $parents, $field_name, $delta) {
    $element_parents = $parents;
    $element_parents[] = $field_name;
    $element_parents[] = $delta;

    $this->deltaId = implode('-', $element_parents);
    $this->deltaWrapperId = Html::getUniqueId($this->deltaId . '-item-wrapper');

    $element['#prefix'] = '<div id="' . $this->deltaWrapperId . '">';
    $element['#suffix'] = '</div>';

    $element_parents[] = 'subform';

    $element += [
      '#type' => 'container',
      'subform' => [
        '#type' => 'container',
        '#parents' => $element_parents,
      ],
    ];

    // Create top section structure with all needed subsections.
    $element['top'] = [
      '#type' => 'container',
      '#weight' => -1000,
      '#attributes' => [
        'class' => [
          'managed-content-top',
        ],
      ],
      // Section for managed content type information.
      'type' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['managed-content-type']],
      ],
      // Section for info icons.
      'icons' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['managed-content-info']],
      ],
      // Section for the entity edit link.
      'entity_link' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['managed-content-entity-link']],
      ],
      // Section for the content moderation state.
      'moderation' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['managed-content-moderation']],
      ],
      // Managed content actions element for actions and dropdown actions.
      'actions' => [
        '#type' => 'managed_content_actions',
      ],
    ];
  }

  /**
   * Update the form element for editing a content entity.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   * @param $delta
   * @param $item_mode
   * @param array $element
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   * @param array $widget_state
   * @param array $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   */
  protected function formEditEntity(FieldItemListInterface $items, $delta, $item_mode, array &$element, ContentEntityInterface $entity, array &$widget_state, array &$form, FormStateInterface $form_state) {
    $field_name = $this->fieldDefinition->getName();
    $parents = $element['#field_parents'];

    $element += [
      '#element_validate' => [[$this, 'elementValidate']],
      '#managed_content_type' => $entity->bundle(),
    ];

    $target_type = $this->getFieldSetting('target_type');

    $show_must_be_saved_warning = !empty($widget_state['content'][$delta]['show_warning']);

    /** @var \Drupal\Core\Entity\EntityInterface $host */
    $host = $items->getEntity();

    // Detect if we are translating.
    $this->initIsTranslating($form_state, $host);
    $langcode = $form_state->get('langcode');

    if (!$this->isTranslating) {
      // Set the langcode if we are not translating.
      $langcode_key = $entity->getEntityType()->getKey('langcode');
      if ($entity->get($langcode_key)->value != $langcode) {
        // If a translation in the given language already exists, switch to
        // that. If there is none yet, update the language.
        if ($entity->hasTranslation($langcode)) {
          $entity = $entity->getTranslation($langcode);
        }
        else {
          $entity->set($langcode_key, $langcode);
        }
      }
    }
    else {
      // If the node is being translated, the content should be all open
      // when the form is not being rebuilt (E.g. when clicked on a managed content
      // action) and when the translation is being added.
      if (!$form_state->isRebuilding() &&
        $host->getTranslationStatus($langcode) == TranslationStatusInterface::TRANSLATION_CREATED) {
        $item_mode = 'edit';
      }

      // Add translation if missing for the target language.
      if (!$entity->hasTranslation($langcode)) {
        // Get the selected translation of the content entity.
        $entity_langcode = $entity->language()->getId();
        $source = $form_state->get(['content_translation', 'source']);
        $source_langcode = $source ? $source->getId() : $entity_langcode;
        // Make sure the source language version is used if available. It is a
        // the host and fetching the translation without this check could lead
        // valid scenario to have no content items in the source version of
        // to an exception.
        if ($entity->hasTranslation($source_langcode)) {
          $entity = $entity->getTranslation($source_langcode);
        }
        // The content entity has no content translation source field if
        // no content entity field is translatable, even if the host is.
        if ($entity->hasField('content_translation_source')) {
          // Initialise the translation with source language values.
          $entity->addTranslation($langcode, $entity->toArray());
          $translation = $entity->getTranslation($langcode);
          $manager = \Drupal::service('content_translation.manager');
          $manager->getTranslationMetadata($translation)
            ->setSource($entity->language()->getId());
        }
      }
      // If any content type is translatable do not switch.
      if ($entity->hasField('content_translation_source')) {
        // Switch the content to the translation.
        $entity = $entity->getTranslation($langcode);
      }
    }

    // If untranslatable fields are hidden while translating, we are
    // translating the parent and the Content is open, then close the
    // Content if it does not have translatable fields.
    $translating_force_close = FALSE;
    if (\Drupal::moduleHandler()->moduleExists('content_translation')) {
      $manager = \Drupal::service('content_translation.manager');
      $settings = $manager->getBundleTranslationSettings($entity->getEntityTypeId(), $entity->bundle());
      if (!empty($settings['untranslatable_fields_hide']) && $this->isTranslating) {
        $translating_force_close = TRUE;
        $display = EntityFormDisplay::collectRenderDisplay($entity, $this->getSetting('form_display_mode'));
        // Check if the entity has translatable fields.
        foreach (array_keys($display->get('content')) as $field) {
          if ($entity->hasField($field)) {
            $field_definition = $entity->get($field)->getFieldDefinition();
            if ($field_definition->isTranslatable()) {
              $translating_force_close = FALSE;
              break;
            }
          }
        }

        if ($translating_force_close) {
          $item_mode = 'closed';
        }
      }
    }

    $delete_access = $entity->access('delete') || $entity->isNew();
    $update_access = $entity->access('update');
    $create_access = $entity->access('create') && $entity->isNew();

    $info = [];

    $item_bundles = \Drupal::service('entity_type.bundle.info')
      ->getBundleInfo($target_type);
    if (isset($item_bundles[$entity->bundle()])) {
      $bundle_info = $item_bundles[$entity->bundle()];

      $element['top']['type']['label'] = [
        '#markup' => '<span class="managed-content-type-label">' . $bundle_info['label'] . '</span>',
        '#weight' => 1,
      ];

      // Widget actions.
      $widget_actions = [
        'actions' => [],
        'dropdown_actions' => [],
      ];

      if ($item_mode != 'remove') {
        $widget_actions['dropdown_actions']['remove_button'] = $this->getDeltaRemoveButton(
          $parents,
          $field_name,
          $delta,
          'actionItemSubmit',
          $widget_state['ajax_wrapper_id'],
          $this->removeButtonAccess($entity)
        );
      }

      if ($item_mode == 'edit') {
        if (isset($entity)) {
          $widget_actions['actions']['collapse_button'] = $this->getDeltaCollapseButton(
            $parents,
            $field_name,
            $delta,
            'actionItemSubmit',
            $widget_state['ajax_wrapper_id'],
            ($update_access || $create_access) && !$translating_force_close
          );
        }
      }
      else {
        $widget_actions['actions']['edit_button'] = $this->getDeltaEditButton(
          $parents,
          $field_name,
          $delta,
          'actionItemSubmit',
          $widget_state['ajax_wrapper_id'],
          ($update_access || $create_access) && !$translating_force_close
        );

        if ($show_must_be_saved_warning) {
          $info['changed'] = [
            '#theme' => 'managed_content_info_icon',
            '#message' => $this->t('You have unsaved changes on this @title item.', ['@title' => $this->getSetting('title')]),
            '#icon' => 'changed',
          ];
        }
      }

      // If update is disabled we will show lock icon in actions section.
      if (!$update_access && !$create_access) {
        $widget_actions['actions']['edit_disabled'] = [
          '#theme' => 'managed_content_info_icon',
          '#message' => $this->t('You are not allowed to edit or remove this @title.', ['@title' => $this->getSetting('title')]),
          '#icon' => 'lock',
          '#weight' => 1,
        ];
      }

      if (!$update_access && $delete_access && !$create_access) {
        $info['edit'] = [
          '#theme' => 'managed_content_info_icon',
          '#message' => $this->t('You are not allowed to edit this @title.', ['@title' => $this->getSetting('title')]),
          '#icon' => 'edit-disabled',
        ];
      }
      elseif (!$delete_access && $update_access) {
        $info['remove'] = [
          '#theme' => 'managed_content_info_icon',
          '#message' => $this->t('You are not allowed to remove this @title.', ['@title' => $this->getSetting('title')]),
          '#icon' => 'delete-disabled',
        ];
      }

      $this->getDeltaActions(
        $items,
        $delta,
        $entity,
        $element,
        $parents,
        $field_name,
        $widget_actions,
        $form,
        $form_state
      );
    }

    $display = EntityFormDisplay::collectRenderDisplay($entity, $this->getSetting('form_display_mode'));

    if (!$entity->isNew() && $entity->id()) {
      $element['top']['entity_link']['link'] = [
        '#markup' => $entity->toLink(NULL, 'edit-form')->toString(),
        '#access' => $entity->access('update') || $entity->access('view'),
      ];
    }
    else {
      $element['top']['entity_link']['link'] = [
        '#markup' => $entity->label(),
        '#access' => $entity->access('view'),
      ];
    }

    $element['top']['moderation']['moderation_state'] = [
      '#markup' => $this->getDeltaModerationState($entity),
      '#access' => $update_access || $entity->access('view'),
    ];

    if ($item_mode == 'edit') {
      $display->buildForm($entity, $element['subform'], $form_state);

      // @todo Remove as part of https://www.drupal.org/node/2640056
      if (\Drupal::moduleHandler()->moduleExists('field_group')) {
        $context = [
          'entity_type' => $entity->getEntityTypeId(),
          'bundle' => $entity->bundle(),
          'entity' => $entity,
          'context' => 'form',
          'display_context' => 'form',
          'mode' => $display->getMode(),
        ];

        field_group_attach_groups($element['subform'], $context);
        $element['subform']['#pre_render'][] = 'field_group_form_pre_render';
      }

      // TODO: Remove revision log for entity modifications, as this is not
      // respecting the field behaviour.

    }
    elseif ($item_mode == 'closed') {
      $element['subform'] = [];
    }
    else {
      $element['subform'] = [];
    }

    // If we have any info items lets add them to the top section.
    if (count($info)) {
      foreach ($info as $info_item) {
        if (!isset($info_item['#access']) || $info_item['#access']) {
          $element['top']['icons']['items'] = $info;
          break;
        }
      }
    }

    $element['subform']['#attributes']['class'][] = 'managed-content-subform';
    $element['subform']['#access'] = $update_access || $create_access;

    $widget_state['content'][$delta]['display'] = $display;
  }

  protected function formReferenceEntity(FieldItemListInterface $items, $delta, $item_type, $item_mode, array &$element, $entity, array &$widget_state, array &$form, FormStateInterface $form_state) {
    $field_name = $this->fieldDefinition->getName();
    $parents = $element['#field_parents'];

    $show_must_be_saved_warning = !empty($widget_state['content'][$delta]['show_warning']);
    $info = [];

    $element += [
      '#element_validate' => [[$this, 'elementValidate']],
    ];

    $actions = [
      'revise' => $this->t('Revise'),
      'clone' => $this->t('Clone'),
    ];

    $element['top']['type']['label'] = [
      '#markup' => '<span class="managed-content-type-label">' . $actions[$item_type] . '</span>',
      '#weight' => 1,
    ];

    // Widget actions.
    $widget_actions = [
      'actions' => [],
      'dropdown_actions' => [],
    ];

    if ($item_mode != 'remove') {
      $widget_actions['dropdown_actions']['remove_button'] = $this->getDeltaRemoveButton(
        $parents,
        $field_name,
        $delta,
        'actionItemSubmit',
        $widget_state['ajax_wrapper_id'],
        $entity ? $this->removeButtonAccess($entity) : TRUE
      );
    }

    if ($item_mode == 'edit') {
      $widget_actions['actions']['collapse_button'] = $this->getDeltaCollapseButton(
        $parents,
        $field_name,
        $delta,
        'actionItemSubmit',
        $widget_state['ajax_wrapper_id'],
        $entity ? $entity->access('update') : TRUE
      );
    }
    else {
      $widget_actions['actions']['edit_button'] = $this->getDeltaEditButton(
        $parents,
        $field_name,
        $delta,
        'actionItemSubmit',
        $widget_state['ajax_wrapper_id'],
        $entity ? $entity->access('update') : TRUE
      );
    }

    if ($show_must_be_saved_warning) {
      $info['changed'] = [
        '#theme' => 'managed_content_info_icon',
        '#message' => $this->t('You have unsaved changes on this @title item.', ['@title' => $this->getSetting('title')]),
        '#icon' => 'changed',
      ];
    }

    $this->getDeltaActions(
      $items,
      $delta,
      $entity,
      $element,
      $parents,
      $field_name,
      $widget_actions,
      $form,
      $form_state
    );

    if ($entity) {
      if (!$entity->isNew()) {
        $element['top']['entity_link']['link'] = [
          '#markup' => $entity->toLink(NULL, 'edit-form')->toString(),
          '#access' => $entity->access('update') || $entity->access('view'),
        ];
      }
      else {
        $element['top']['entity_link']['link'] = [
          '#markup' => $entity->label(),
          '#access' => $entity->access('view'),
        ];
      }

      $element['top']['moderation']['moderation_state'] = [
        '#markup' => $this->getDeltaModerationState($entity),
        '#access' => $entity->access('update') || $entity->access('view'),
      ];
    }

    if ($item_mode == 'edit') {
      $element['subform']['entity_id'] = [
        '#title' => $this->t('Content'),
        '#type' => 'entity_autocomplete',
        '#target_type' => $this->getFieldSetting('target_type'),
        '#selection_handler' => $this->getFieldSetting('handler'),
        '#validate_reference' => TRUE,
        '#maxlength' => 1024,
        '#default_value' => $entity ? $entity : NULL,
        '#size' => 120,
        '#required' => TRUE,
      ];
    }

    // If we have any info items lets add them to the top section.
    if (count($info)) {
      foreach ($info as $info_item) {
        if (!isset($info_item['#access']) || $info_item['#access']) {
          $element['top']['icons']['items'] = $info;
          break;
        }
      }
    }

    if ($item_mode == 'remove') {
      $element['#access'] = FALSE;
    }
  }

  protected function formRemoveEntity(FieldItemListInterface $items, $delta, $item_type, $item_mode, array &$element, $entity, array &$widget_state, array &$form, FormStateInterface $form_state) {
    $field_name = $this->fieldDefinition->getName();
    $parents = $element['#field_parents'];

    $element += [
      '#element_validate' => [[$this, 'elementValidate']],
    ];

    $element['top']['type']['label'] = [
      '#markup' => '<span class="managed-content-type-label managed-content-type-remove">' . $this->t('Remove') . '</span>',
      '#weight' => 1,
    ];

    // Widget actions.
    $widget_actions = [
      'actions' => [],
      'dropdown_actions' => [],
    ];

    $widget_actions['actions']['restore_button'] = $this->getDeltaRestoreButton(
      $parents,
      $field_name,
      $delta,
      'actionItemSubmit',
      $widget_state['ajax_wrapper_id'],
      $entity ? $entity->access('update') : TRUE
    );

    $this->getDeltaActions(
      $items,
      $delta,
      $entity,
      $element,
      $parents,
      $field_name,
      $widget_actions,
      $form,
      $form_state
    );

    $element['top']['entity_link']['link'] = [
      '#markup' => $entity->toLink(NULL, 'edit-form')->toString(),
      '#access' => $entity->access('update') || $entity->access('view'),
    ];

    $element['subform']['entity_id'] = [
      '#type' => 'value',
      '#default_value' => $entity->id(),
    ];
  }

  /**
   * @param $entity
   *
   * @return string
   */
  protected function getDeltaModerationState($entity) {
    $moderation_state = '';
    if ($this->moderationInformation) {
      // Workflow is consistent per node.
      $workflow = $this->moderationInformation->getWorkflowForEntity($entity);

      // Handle the condition whereby content moderation does not apply to the node type.
      if ($workflow) {
        $state = $entity->get('moderation_state')->value;
        $moderation_state = $workflow->getTypePlugin()
          ->getState($state)
          ->label();
      }
    }
    elseif ($entity instanceof EntityPublishedInterface) {
      $moderation_state = $entity->isPublished() ? $this->t('Published') : $this->t('Unpublished');
    }

    return $moderation_state;
  }

  /**
   * Update the element actions.
   *
   * @param $items
   * @param $delta
   * @param $entity
   * @param array $element
   * @param array $parents
   * @param $field_name
   * @param array $widget_actions
   * @param array $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   */
  protected function getDeltaActions(
    $items,
    $delta,
    $entity,
    array &$element,
    array $parents,
    $field_name,
    array &$widget_actions,
    array $form,
    FormStateInterface $form_state
  ) {
    $context = [
      'form' => $form,
      'widget' => self::getWidgetState($parents, $field_name, $form_state),
      'items' => $items,
      'delta' => $delta,
      'element' => $element,
      'form_state' => $form_state,
      'content_entity' => $entity,
      'is_translating' => $this->isTranslating,
      'allow_reference_changes' => $this->allowReferenceChanges(),
    ];

    // Allow modules to alter widget actions.
    \Drupal::moduleHandler()
      ->alter('managed_content_widget_actions', $widget_actions, $context);

    if (count($widget_actions['actions'])) {
      // Expand all actions to proper submit elements and add it to top
      // actions sub component.
      $element['top']['actions']['actions'] = array_map([
        $this,
        'expandButton',
      ], $widget_actions['actions']);
    }

    if (count($widget_actions['dropdown_actions'])) {
      // Expand all dropdown actions to proper submit elements and add
      // them to top dropdown actions sub component.
      $element['top']['actions']['dropdown_actions'] = array_map([
        $this,
        'expandButton',
      ], $widget_actions['dropdown_actions']);
    }
  }

  /**
   * Get the restore button for a field delta.
   *
   * @param $parents
   * @param $field_name
   * @param $delta
   * @param $callback
   * @param $wrapper_id
   * @param $accessible
   *
   * @return array
   */
  protected function getDeltaRestoreButton($parents, $field_name, $delta, $callback, $wrapper_id, $accessible) {
    return $this->expandButton([
      '#type' => 'submit',
      '#value' => $this->t('Restore'),
      '#name' => $this->deltaId . '_restore',
      '#weight' => 501,
      '#submit' => [[get_class($this), $callback]],
      // Ignore all validation errors because deleting invalid entity
      // is allowed.
      '#limit_validation_errors' => [],
      '#delta' => $delta,
      '#ajax' => [
        'callback' => [get_class($this), 'itemAjax'],
        'wrapper' => $wrapper_id,
      ],
      '#access' => $accessible,
      '#content_mode' => 'closed',
    ]);
  }

  /**
   * Get the remove button for a field delta.
   *
   * @param $parents
   * @param $field_name
   * @param $delta
   * @param $callback
   * @param $wrapper_id
   * @param $accessible
   *
   * @return array
   */
  protected function getDeltaRemoveButton($parents, $field_name, $delta, $callback, $wrapper_id, $accessible) {
    return $this->expandButton([
      '#type' => 'submit',
      '#value' => $this->t('Remove'),
      '#name' => $this->deltaId . '_remove',
      '#weight' => 501,
      '#submit' => [[get_class($this), $callback]],
      // Ignore all validation errors because deleting invalid entities
      // is allowed.
      '#limit_validation_errors' => [],
      '#delta' => $delta,
      '#ajax' => [
        'callback' => [get_class($this), 'itemAjax'],
        'wrapper' => $wrapper_id,
      ],
      '#access' => $accessible,
      '#content_mode' => 'remove',
      '#content_show_warning' => TRUE,
    ]);
  }

  /**
   * Get the collapse button for a field delta.
   *
   * @param $parents
   * @param $field_name
   * @param $delta
   * @param $callback
   * @param $wrapper_id
   * @param $accessible
   *
   * @return array
   */
  protected function getDeltaCollapseButton($parents, $field_name, $delta, $callback, $wrapper_id, $accessible) {
    return $this->expandButton([
      '#value' => $this->t('Collapse'),
      '#name' => $this->deltaId . '_collapse',
      '#weight' => 1,
      '#submit' => [[get_class($this), $callback]],
      '#limit_validation_errors' => [
        array_merge($parents, [$field_name, $delta]),
      ],
      '#delta' => $delta,
      '#ajax' => [
        'callback' => [get_class($this), 'itemAjax'],
        'wrapper' => $wrapper_id,
      ],
      '#access' => $accessible,
      '#content_mode' => 'closed',
      '#content_show_warning' => TRUE,
      '#attributes' => [
        'class' => [
          'managed-content-icon-button',
          'managed-content-icon-button-collapse',
        ],
        'title' => $this->t('Collapse'),
      ],
    ]);
  }

  /**
   * Get the edit button for a field delta.
   *
   * @param $parents
   * @param $field_name
   * @param $delta
   * @param $callback
   * @param $wrapper_id
   * @param $accessible
   *
   * @return array
   */
  protected function getDeltaEditButton($parents, $field_name, $delta, $callback, $wrapper_id, $accessible) {
    return $this->expandButton([
      '#type' => 'submit',
      '#value' => $this->t('Edit'),
      '#name' => $this->deltaId . '_edit',
      '#weight' => 1,
      '#submit' => [[get_class($this), $callback]],
      '#limit_validation_errors' => [
        array_merge($parents, [$field_name, $delta]),
      ],
      '#delta' => $delta,
      '#ajax' => [
        'callback' => [get_class($this), 'itemAjax'],
        'wrapper' => $wrapper_id,
      ],
      '#access' => $accessible,
      '#content_mode' => 'edit',
      '#attributes' => [
        'class' => ['managed-content-icon-button', 'managed-content-icon-button-edit'],
        'title' => $this->t('Edit'),
      ],
    ]);
  }

  /**
   * Returns the sorted allowed types for a entity reference field.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *  (optional) The field definition for which the allowed types should be
   *  returned, defaults to the current field.
   *
   * @return array
   *   A list of arrays keyed by the entity type machine name with the
   *   following properties.
   *     - label: The label of the entity type.
   *     - weight: The weight of the entity type.
   */
  public function getAllowedTypes(FieldDefinitionInterface $field_definition = NULL) {
    $return_bundles = [];

    /** @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface $bundles */
    $bundles = \Drupal::service('entity_type.bundle.info')
      ->getBundleInfo($field_definition ? $field_definition->getSetting('target_type') : $this->fieldDefinition->getSetting('target_type'));
    $weight = 0;
    foreach ($bundles as $machine_name => $bundle) {
      if (!count($this->getSelectionHandlerSetting('target_bundles'))
        || in_array($machine_name, $this->getSelectionHandlerSetting('target_bundles'))) {

        $return_bundles[$machine_name] = [
          'label' => $bundle['label'],
          'weight' => $weight,
        ];

        $weight++;
      }
    }

    return $return_bundles;
  }

  /**
   * {@inheritdoc}
   */
  public function formMultipleElements(FieldItemListInterface $items, array &$form, FormStateInterface $form_state) {
    $field_name = $this->fieldDefinition->getName();
    $cardinality = $this->fieldDefinition->getFieldStorageDefinition()
      ->getCardinality();
    $this->fieldParents = $form['#parents'];
    $field_state = static::getWidgetState($this->fieldParents, $field_name, $form_state);

    $max = $field_state['items_count'];

    $this->realItemCount = $max;
    $is_multiple = $this->fieldDefinition->getFieldStorageDefinition()
      ->isMultiple();

    $field_title = $this->fieldDefinition->getLabel();
    $description = FieldFilteredMarkup::create(\Drupal::token()
      ->replace($this->fieldDefinition->getDescription()));

    $elements = [];
    $tabs = '';
    $this->fieldIdPrefix = implode('-', array_merge($this->fieldParents, [$field_name]));
    $this->fieldWrapperId = Html::getId($this->fieldIdPrefix . '-add-more-wrapper');

    $elements['#prefix'] = '<div class="is-horizontal managed-content-tabs-wrapper" id="' . $this->fieldWrapperId . '">' . $tabs;
    $elements['#suffix'] = '</div>';

    $field_state['ajax_wrapper_id'] = $this->fieldWrapperId;

    // Persist the widget state so formElement() can access it.
    static::setWidgetState($this->fieldParents, $field_name, $form_state, $field_state);

    if ($max > 0) {
      for ($delta = 0; $delta < $max; $delta++) {

        // Add a new empty item if it doesn't exist yet at this delta.
        if (!isset($items[$delta])) {
          $items->appendItem();
        }

        // For multiple fields, title and description are handled by the wrapping
        // table.
        $element = [
          '#title' => $is_multiple ? '' : $field_title,
          '#description' => $is_multiple ? '' : $description,
        ];
        $element = $this->formSingleElement($items, $delta, $element, $form, $form_state);

        if ($element) {
          // Input field for the delta (drag-n-drop reordering).
          if ($is_multiple) {
            // We name the element '_weight' to avoid clashing with elements
            // defined by widget.
            $element['_weight'] = [
              '#type' => 'weight',
              '#title' => $this->t('Weight for row @number', ['@number' => $delta + 1]),
              '#title_display' => 'invisible',
              // Note: this 'delta' is the FAPI #type 'weight' element's property.
              '#delta' => $max,
              '#default_value' => $items[$delta]->_weight ?: $delta,
              '#weight' => 100,
            ];
          }

          // Access for the top element is set to FALSE only when the entity
          // was removed. An entity that a user can not edit has access on
          // lower level.
          if (isset($element['#access']) && !$element['#access']) {
            $this->realItemCount--;
          }
          else {
            $elements[$delta] = $element;
          }
        }
      }
    }

    $field_state = static::getWidgetState($this->fieldParents, $field_name, $form_state);
    $field_state['real_item_count'] = $this->realItemCount;
    static::setWidgetState($this->fieldParents, $field_name, $form_state, $field_state);

    $elements += [
      '#element_validate' => [[$this, 'multipleElementValidate']],
      '#theme' => 'field_multiple_value_form',
      '#field_name' => $field_name,
      '#cardinality' => $cardinality,
      '#cardinality_multiple' => TRUE,
      '#required' => $this->fieldDefinition->isRequired(),
      '#title' => $field_title,
      '#description' => $description,
      '#max_delta' => $max - 1,
    ];

    $host = $items->getEntity();
    $this->initIsTranslating($form_state, $host);

    $header_actions = $this->buildHeaderActions($field_state, $form_state);
    if ($header_actions) {
      $elements['header_actions'] = $header_actions;
      // Add a weight element so we guarantee that header actions will stay in
      // first row. We will use this later in
      // managed_content_preprocess_field_multiple_value_form().
      $elements['header_actions']['_weight'] = [
        '#type' => 'weight',
        '#default_value' => -100,
      ];
    }

    if (($this->realItemCount < $cardinality || $cardinality == FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED) && !$form_state->isProgrammed() && $this->allowReferenceChanges()) {
      $elements['add_more'] = $this->buildAddActions();
    }

    $elements['#allow_reference_changes'] = $this->allowReferenceChanges();
    $elements['#managed_content_widget'] = TRUE;
    $elements['#attached']['library'][] = 'managed_content_field/drupal.managed_content_field.widget';

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function form(FieldItemListInterface $items, array &$form, FormStateInterface $form_state, $get_delta = NULL) {
    $parents = $form['#parents'];

    // Identify the manage field settings default value form.
    if (in_array('default_value_input', $parents, TRUE)) {
      // Since the entity is not reusable neither cloneable, having a default
      // value is not supported.
      return [
        '#markup' => $this->t('No widget available for: %label.', [
          '%label' => $items->getFieldDefinition()
            ->getLabel(),
        ]),
      ];
    }

    $elements = parent::form($items, $form, $form_state, $get_delta);

    // Signal to content_translation that this field should be treated as
    // multilingual and not be hidden, see
    // \Drupal\content_translation\ContentTranslationHandler::entityFormSharedElements().
    $elements['#multilingual'] = TRUE;
    return $elements;
  }

  /**
   * Add 'add more' button, if not working with a programmed form.
   *
   * @return array
   *    The form element array.
   */
  protected function buildAddActions() {
    if (count($this->getAccessibleOptions()) === 0) {
      if (count($this->getAllowedTypes()) === 0) {
        $add_more_elements['icons'] = $this->createMessage($this->t('You are not allowed to add any of the @title types.', ['@title' => $this->getSetting('title')]));
      }
      else {
        $add_more_elements['icons'] = $this->createMessage($this->t('You did not add any @title types yet.', ['@title' => $this->getSetting('title')]));
      }

      return $add_more_elements;
    }

    return $this->buildButtonsAddMode();
  }

  /**
   * Returns the available entity type.
   *
   * @return array
   *   Available entity types.
   */
  protected function getAccessibleOptions() {
    if ($this->accessOptions !== NULL) {
      return $this->accessOptions;
    }

    $this->accessOptions = [];

    $entity_type_manager = \Drupal::entityTypeManager();
    $target_type = $this->getFieldSetting('target_type');
    $bundles = $this->getAllowedTypes();
    $access_control_handler = $entity_type_manager->getAccessControlHandler($target_type);

    foreach ($bundles as $machine_name => $bundle) {
      if ((empty($this->getSelectionHandlerSetting('target_bundles'))
        || in_array($machine_name, $this->getSelectionHandlerSetting('target_bundles')))) {
        if ($access_control_handler->createAccess($machine_name)) {
          $this->accessOptions[$machine_name] = $bundle['label'];
        }
      }
    }

    return $this->accessOptions;
  }

  /**
   * Helper to create a entity UI message.
   *
   * @param string $message
   *   Message text.
   * @param string $type
   *   Message type.
   *
   * @return array
   *   Render array of message.
   */
  public function createMessage($message, $type = 'warning') {
    return [
      '#type' => 'container',
      '#markup' => $message,
      '#attributes' => ['class' => ['messages', 'messages--' . $type]],
    ];
  }

  /**
   * Expand button base array into a managed content widget action button.
   *
   * @param array $button_base
   *   Button base render array.
   *
   * @return array
   *   Button render array.
   */
  public static function expandButton(array $button_base) {
    // Do not expand elements that do not have submit handler.
    if (empty($button_base['#submit'])) {
      return $button_base;
    }

    $button = $button_base + [
        '#type' => 'submit',
        '#theme_wrappers' => ['input__submit__managed_content_action'],
      ];

    // Html::getId will give us '-' char in name but we want '_' for now so
    // we use strtr to search&replace '-' to '_'.
    $button['#name'] = strtr(Html::getId($button_base['#name']), '-', '_');
    $button['#id'] = Html::getUniqueId($button['#name']);

    if (isset($button['#ajax'])) {
      $button['#ajax'] += [
        'effect' => 'fade',
        // Since a normal throbber is added inline, this has the potential to
        // break a layout if the button is located in dropbuttons. Instead,
        // it's safer to just show the fullscreen progress element instead.
        'progress' => ['type' => 'fullscreen'],
      ];
    }

    return $button;
  }

  /**
   * Get common submit element information for processing ajax submit handlers.
   *
   * @param array $form
   *   Form array.
   * @param FormStateInterface $form_state
   *   Form state object.
   * @param int $position
   *   Position of triggering element.
   *
   * @return array
   *   Submit element information.
   */
  public static function getSubmitElementInfo(array $form, FormStateInterface $form_state, $position = self::ACTION_POSITION_BASE) {
    $submit['button'] = $form_state->getTriggeringElement();

    // Go up in the form, to the widgets container.
    if ($position == static::ACTION_POSITION_BASE) {
      $submit['element'] = NestedArray::getValue($form, array_slice($submit['button']['#array_parents'], 0, -2));
    }
    if ($position == static::ACTION_POSITION_HEADER) {
      $submit['element'] = NestedArray::getValue($form, array_slice($submit['button']['#array_parents'], 0, -3));
    }
    elseif ($position == static::ACTION_POSITION_ACTIONS) {
      $submit['element'] = NestedArray::getValue($form, array_slice($submit['button']['#array_parents'], 0, -5));
      $delta = array_slice($submit['button']['#array_parents'], -5, -4);
      $submit['delta'] = $delta[0];
    }

    $submit['field_name'] = $submit['element']['#field_name'];
    $submit['parents'] = $submit['element']['#field_parents'];

    // Get widget state.
    $submit['widget_state'] = static::getWidgetState($submit['parents'], $submit['field_name'], $form_state);

    return $submit;
  }

  /**
   * Build drop button.
   *
   * @param array $elements
   *   Elements for drop button.
   *
   * @return array
   *   Drop button array.
   */
  protected function buildDropbutton(array $elements = []) {
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['managed-content-dropbutton-wrapper']],
    ];

    $operations = [];
    // Because we are cloning the elements into title sub element we need to
    // sort children first.
    foreach (Element::children($elements, TRUE) as $child) {
      // Clone the element as an operation.
      $operations[$child] = ['title' => $elements[$child]];

      // Flag the original element as printed so it doesn't render twice.
      $elements[$child]['#printed'] = TRUE;
    }

    $build['operations'] = [
      '#type' => 'managed_content_operations',
      // Even though operations are run through the "links" element type, the
      // theme system will render any render array passed as a link "title".
      '#links' => $operations,
    ];

    return $build + $elements;
  }

  /**
   * Builds dropdown button for adding new entity.
   *
   * @return array
   *   The form element array.
   */
  protected function buildButtonsAddMode() {
    $options = $this->getAccessibleOptions();

    // Build the buttons.
    $add_buttons = [];
    foreach ($options as $machine_name => $label) {
      $button_key = 'add_more_button_' . $machine_name;
      $add_buttons[$button_key] = $this->expandButton([
        '#type' => 'submit',
        '#name' => $this->fieldIdPrefix . '_' . $machine_name . '_add_more',
        '#value' => $this->t('Add @type', ['@type' => $label]),
        '#attributes' => ['class' => ['field-add-more-submit']],
        '#limit_validation_errors' => [
          array_merge($this->fieldParents, [
            $this->fieldDefinition->getName(),
            'add_more',
          ]),
        ],
        '#submit' => [[get_class($this), 'addMoreSubmit']],
        '#ajax' => [
          'callback' => [get_class($this), 'addMoreAjax'],
          'wrapper' => $this->fieldWrapperId,
        ],
        '#bundle_machine_name' => $machine_name,
      ]);
    }

    // Determine if buttons should be rendered as dropbuttons.
    if (count($options) > 1) {
      $add_buttons = $this->buildDropbutton($add_buttons);
    }

    $revise_button = $this->expandButton([
      '#type' => 'submit',
      '#name' => $this->fieldIdPrefix . '_revise',
      '#value' => $this->t('Revise Content'),
      '#limit_validation_errors' => [
        array_merge($this->fieldParents, [
          $this->fieldDefinition->getName(),
          'add_more',
        ]),
      ],
      '#submit' => [[get_class($this), 'addReviseSubmit']],
      '#ajax' => [
        'callback' => [get_class($this), 'addReferenceAjax'],
        'wrapper' => $this->fieldWrapperId,
      ],
    ]);

    $clone_button = $this->expandButton([
      '#type' => 'submit',
      '#name' => $this->fieldIdPrefix . '_clone',
      '#value' => $this->t('Clone Content'),
      '#limit_validation_errors' => [
        array_merge($this->fieldParents, [
          $this->fieldDefinition->getName(),
          'add_more',
        ]),
      ],
      '#submit' => [[get_class($this), 'addCloneSubmit']],
      '#ajax' => [
        'callback' => [get_class($this), 'addReferenceAjax'],
        'wrapper' => $this->fieldWrapperId,
      ],
    ]);


    $elements = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['container-inline', 'managed-content-action-buttons'],
      ],
      '#weight' => 1,
      'add_more' => $add_buttons,
      'revise' => $revise_button,
      'clone' => $clone_button,
    ];

    return $elements;
  }

  /**
   * @param array $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *
   * @return mixed
   */
  public static function addReferenceAjax(array $form, FormStateInterface $form_state) {
    $submit = static::getSubmitElementInfo($form, $form_state);
    $element = $submit['element'];

    // Add a DIV around the delta receiving the Ajax effect.
    $delta = $submit['element']['#max_delta'];
    $element[$delta]['#prefix'] = '<div class="ajax-new-content">' . (isset($element[$delta]['#prefix']) ? $element[$delta]['#prefix'] : '');
    $element[$delta]['#suffix'] = (isset($element[$delta]['#suffix']) ? $element[$delta]['#suffix'] : '') . '</div>';

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public static function addMoreAjax(array $form, FormStateInterface $form_state) {
    $submit = static::getSubmitElementInfo($form, $form_state, self::ACTION_POSITION_HEADER);
    $element = $submit['element'];

    // Add a DIV around the delta receiving the Ajax effect.
    $delta = $submit['element']['#max_delta'];
    $element[$delta]['#prefix'] = '<div class="ajax-new-content">' . (isset($element[$delta]['#prefix']) ? $element[$delta]['#prefix'] : '');
    $element[$delta]['#suffix'] = (isset($element[$delta]['#suffix']) ? $element[$delta]['#suffix'] : '') . '</div>';

    return $element;
  }

  /**
   * Ajax callback for all actions.
   */
  public static function allActionsAjax(array $form, FormStateInterface $form_state) {
    $submit = static::getSubmitElementInfo($form, $form_state, static::ACTION_POSITION_HEADER);
    $element = $submit['element'];

    // Add a DIV around the delta receiving the Ajax effect.
    $delta = $submit['element']['#max_delta'];
    $element[$delta]['#prefix'] = '<div class="ajax-new-content">' . (isset($element[$delta]['#prefix']) ? $element[$delta]['#prefix'] : '');
    $element[$delta]['#suffix'] = (isset($element[$delta]['#suffix']) ? $element[$delta]['#suffix'] : '') . '</div>';

    return $element;
  }

  /**
   * Prepares the widget state to add a new entity at a specific position.
   *
   * In addition to the widget state change, also user input could be modified
   * to handle adding of a new entity at a specific position between existing
   * entities.
   *
   * @param array $widget_state
   *   Widget state as reference, so that it can be updated.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   * @param array $field_path
   *   Path to entity field.
   * @param int|mixed $new_delta
   *   Delta position in list of entities, where new entity will be added.
   */
  protected static function prepareDeltaPosition(array &$widget_state, FormStateInterface $form_state, array $field_path, $new_delta) {
    // Increase number of items to create place for new entity.
    $widget_state['items_count']++;

    // Default behavior is adding to end of list and in case delta is not
    // provided or already at end, we can skip all other steps.
    if (!is_numeric($new_delta) || intval($new_delta) >= $widget_state['real_item_count']) {
      return;
    }

    $widget_state['real_item_count']++;

    // Limit delta between 0 and "number of items" in entities widget.
    $new_delta = max(intval($new_delta), 0);

    // Change user input in order to create new delta position.
    $user_input = NestedArray::getValue($form_state->getUserInput(), $field_path);

    // Rearrange all original deltas to make one place for the new element.
    $new_original_deltas = [];
    foreach ($widget_state['original_deltas'] as $current_delta => $original_delta) {
      $new_current_delta = $current_delta >= $new_delta ? $current_delta + 1 : $current_delta;

      $new_original_deltas[$new_current_delta] = $original_delta;
      $user_input[$original_delta]['_weight'] = $new_current_delta;
    }

    // Add information into delta mapping for the new element.
    $original_deltas_size = count($widget_state['original_deltas']);
    $new_original_deltas[$new_delta] = $original_deltas_size;
    $user_input[$original_deltas_size]['_weight'] = $new_delta;

    $widget_state['original_deltas'] = $new_original_deltas;
    NestedArray::setValue($form_state->getUserInput(), $field_path, $user_input);
  }

  /**
   * Prepares a widget based on the configuration.
   *
   * @param array $widget
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   */
  protected static function prepareWidgetDelta(array &$widget, FormStateInterface $form_state) {
    if ($widget['widget_state']['real_item_count'] < $widget['element']['#cardinality'] || $widget['element']['#cardinality'] == FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED) {
      $field_path = array_merge($widget['element']['#field_parents'], [$widget['element']['#field_name']]);
      $add_more_delta = NestedArray::getValue(
        $widget['element'],
        ['add_more', '#value']
      );

      static::prepareDeltaPosition($widget['widget_state'], $form_state, $field_path, $add_more_delta);
    }
  }

  protected static function addActionSubmit(array $form, FormStateInterface $form_state, $action) {
    $submit = static::getSubmitElementInfo($form, $form_state);

    static::prepareWidgetDelta($submit, $form_state);

    $submit['widget_state']['action'] = $action;

    $submit['widget_state'] = static::autocollapse($submit['widget_state']);

    static::setWidgetState($submit['parents'], $submit['field_name'], $form_state, $submit['widget_state']);

    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public static function addMoreSubmit(array $form, FormStateInterface $form_state) {
    $submit = static::getSubmitElementInfo($form, $form_state, self::ACTION_POSITION_HEADER);

    static::prepareWidgetDelta($submit, $form_state);

    if (isset($submit['button']['#bundle_machine_name'])) {
      $submit['widget_state']['selected_bundle'] = $submit['button']['#bundle_machine_name'];
    }
    else {
      $submit['widget_state']['selected_bundle'] = $submit['element']['add_more']['add_more_select']['#value'];
    }

    $submit['widget_state']['action'] = 'create';
    $submit['widget_state'] = static::autocollapse($submit['widget_state']);

    static::setWidgetState($submit['parents'], $submit['field_name'], $form_state, $submit['widget_state']);

    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public static function addReviseSubmit(array $form, FormStateInterface $form_state) {
    self::addActionSubmit($form, $form_state, 'revise');
  }

  /**
   * {@inheritdoc}
   */
  public static function addCloneSubmit(array $form, FormStateInterface $form_state) {
    self::addActionSubmit($form, $form_state, 'clone');
  }

  /**
   * Submit for content action buttons.
   *
   * @param array $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   */
  public static function actionItemSubmit(array $form, FormStateInterface $form_state) {
    $submit = static::getSubmitElementInfo($form, $form_state, static::ACTION_POSITION_ACTIONS);

    $new_mode = $submit['button']['#content_mode'];

    if ($new_mode === 'edit') {
      $submit['widget_state'] = static::autocollapse($submit['widget_state']);
    }

    $submit['widget_state']['content'][$submit['delta']]['mode'] = $new_mode;

    if (!empty($submit['button']['#content_show_warning'])) {
      $submit['widget_state']['content'][$submit['delta']]['show_warning'] = $submit['button']['#content_show_warning'];
    }

    static::setWidgetState($submit['parents'], $submit['field_name'], $form_state, $submit['widget_state']);

    $form_state->setRebuild();
  }

  /**
   * Ajax support for content action buttons.
   * 
   * @param array $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *
   * @return mixed
   */
  public static function itemAjax(array $form, FormStateInterface $form_state) {
    $submit = static::getSubmitElementInfo($form, $form_state, static::ACTION_POSITION_ACTIONS);

    $submit['element']['#prefix'] = '<div class="ajax-new-content">' . (isset($submit['element']['#prefix']) ? $submit['element']['#prefix'] : '');
    $submit['element']['#suffix'] = (isset($submit['element']['#suffix']) ? $submit['element']['#suffix'] : '') . '</div>';

    return $submit['element'];
  }

  /**
   * Returns the value of a setting for the entity reference selection handler.
   *
   * @param string $setting_name
   *   The setting name.
   *
   * @return mixed
   *   The setting value.
   */
  protected function getSelectionHandlerSetting($setting_name) {
    $settings = $this->getFieldSetting('handler_settings');
    return isset($settings[$setting_name]) ? $settings[$setting_name] : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function elementValidate($element, FormStateInterface $form_state, $form) {
    $field_name = $this->fieldDefinition->getName();
    $widget_state = static::getWidgetState($element['#field_parents'], $field_name, $form_state);
    $delta = $element['#delta'];

    $widget = $widget_state['content'][$delta];

    // Update the widget entity, assuming we have it properly linked.
    if ($widget['mode'] != 'edit' || $widget['type'] != 'item') {
      // Get a value and load new version if entity is unavailable.
      $value = $form_state->getValue($element['subform']['#parents']);
      if ($value && empty($widget['entity'])) {
        $entity_type_manager = \Drupal::entityTypeManager();
        $target_type = $this->getFieldSetting('target_type');

        /** @var \Drupal\Core\Entity\RevisionableStorageInterface $storage */
        $storage = $entity_type_manager->getStorage($target_type);

        // Load the latest revision for the entity.
        $entity = $storage->loadRevision($storage->getLatestRevisionId($value['entity_id']));
        $widget_state['content'][$delta]['entity'] = $entity;

        // Check the behaviour expected for clone and revise.
        if ($widget['type'] === 'revise' && !$entity->access('update')) {
          $form_state->setError($element['subform']['entity_id'], $this->t('You do not have access to revise the content.'));
        }
        elseif ($widget['type'] === 'revise' && !$this->canRevise($entity)) {
          $form_state->setError($element['subform']['entity_id'], $this->t('The content is already being revised or created.'));
        }
        elseif ($widget['type'] === 'clone' && !$entity->access('create')) {
          $form_state->setError($element['subform']['entity_id'], $this->t('You do not have access to clone the content.'));
        }
      }
    }
    // Update the entity values from the subform.
    elseif (isset($widget_state['content'][$delta]['entity']) && $widget['mode'] == 'edit') {
      /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
      $entity = $widget_state['content'][$delta]['entity'];

      /** @var \Drupal\Core\Entity\Display\EntityFormDisplayInterface $display */
      $display = $widget_state['content'][$delta]['display'];

      $display->extractFormValues($entity, $element['subform'], $form_state);
    }

    static::setWidgetState($element['#field_parents'], $field_name, $form_state, $widget_state);
  }

  /**
   * {@inheritdoc}
   */
  public function errorElement(array $element, ConstraintViolationInterface $error, array $form, FormStateInterface $form_state) {
    // Validation errors might be a about a specific (behavior) form element
    // attempt to find a matching element.
    if (!empty($error->arrayPropertyPath) && $sub_element = NestedArray::getValue($element, $error->arrayPropertyPath)) {
      return $sub_element;
    }
    return $element;
  }

  /**
   * Special handling to validate form elements with multiple values.
   *
   * @param array $elements
   *   An associative array containing the substructure of the form to be
   *   validated in this call.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param array $form
   *   The complete form array.
   */
  public function multipleElementValidate(array $elements, FormStateInterface $form_state, array $form) {
    $field_name = $this->fieldDefinition->getName();
    $widget_state = static::getWidgetState($elements['#field_parents'], $field_name, $form_state);

    if ($elements['#required'] && $widget_state['real_item_count'] < 1) {
      $form_state->setError($elements, t('@name field is required.', ['@name' => $this->fieldDefinition->getLabel()]));
    }

    static::setWidgetState($elements['#field_parents'], $field_name, $form_state, $widget_state);
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    $field_name = $this->fieldDefinition->getName();
    $widget_state = static::getWidgetState($form['#parents'], $field_name, $form_state);
    $element = NestedArray::getValue($form_state->getCompleteForm(), $widget_state['array_parents']);

    // Process each of the values for the form.
    foreach ($values as $delta => &$item) {
      $original_delta = $item['_original_delta'];
      $widget = $widget_state['content'][$original_delta];

      // Perform updating of the field state from the values for an entity.
      if (isset($widget['entity']) && $widget['type'] == 'item' && $widget['mode'] != 'remove') {
        /** @var \Drupal\Core\Entity\ContentEntityInterface $content_entity */
        $content_entity = $widget['entity'];

        /** @var \Drupal\Core\Entity\Display\EntityFormDisplayInterface $display */
        $display = $widget['display'];
        if ($widget['mode'] == 'edit') {
          $display->extractFormValues($content_entity, $element[$original_delta]['subform'], $form_state);
          $widget['show_warning'] = $content_entity->isNew() || $this->hasChanged($content_entity);
        }

        // A content entity form saves without any rebuild. It needs to set the
        // language to update it in case of language change.
        $langcode_key = $content_entity->getEntityType()->getKey('langcode');
        if ($content_entity->get($langcode_key)->value != $form_state->get('langcode')) {
          // If a translation in the given language already exists, switch to
          // that. If there is none yet, update the language.
          if ($content_entity->hasTranslation($form_state->get('langcode'))) {
            $content_entity = $content_entity->getTranslation($form_state->get('langcode'));
          }
          else {
            $content_entity->set($langcode_key, $form_state->get('langcode'));
          }
        }

        // We can only use the entity form display to display validation errors
        // if it is in edit mode.
        if ($widget['mode'] === 'edit') {
          $display->validateFormValues($content_entity, $element[$original_delta]['subform'], $form_state);
        }
        // Assume that the entity is being saved/previewed, in this case,
        // validate even the closed entities. If there are validation errors,
        // add them on the parent level. Validation errors do not rebuild the
        // form so it's not possible to auto-uncollapse the form at this point.
        elseif ($form_state->getLimitValidationErrors() === NULL) {
          $violations = $content_entity->validate();
          $violations->filterByFieldAccess();
          if (count($violations)) {
            foreach ($violations as $violation) {
              /** @var \Symfony\Component\Validator\ConstraintViolationInterface $violation */
              $form_state->setError($element[$item['_original_delta']], $violation->getMessage());
            }
          }
        }

        $item['entity'] = $content_entity;
        $item['target_id'] = (int)$content_entity->id();
        $item['modified'] = !empty($widget['show_warning']);
      }
      elseif (($widget['mode'] != 'item' && $widget['mode'] != 'remove') || ($widget['mode'] == 'remove')) {
        /** @var \Drupal\Core\Entity\ContentEntityInterface $content_entity */
        $content_entity = $widget['entity'];
        if ($content_entity) {
          $item['entity'] = $content_entity;
          $item['target_id'] = (int)$content_entity->id();
          $item['action'] = $widget['type'] == 'item' ? $widget['mode'] : $widget['type'];
        }
      }
    }

    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function extractFormValues(FieldItemListInterface $items, array $form, FormStateInterface $form_state) {
    // Filter possible empty items.
    $items->filterEmptyItems();

    // Remove buttons from header actions.
    $field_name = $this->fieldDefinition->getName();
    $path = array_merge($form['#parents'], [$field_name]);
    $form_state_variables = $form_state->getValues();
    $key_exists = NULL;
    $values = NestedArray::getValue($form_state_variables, $path, $key_exists);

    if ($key_exists) {
      unset($values['header_actions']);

      NestedArray::setValue($form_state_variables, $path, $values);
      $form_state->setValues($form_state_variables);
    }

    return parent::extractFormValues($items, $form, $form_state);
  }

  /**
   * Determine if widget is in translation.
   *
   * Initializes $this->isTranslating.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   * @param \Drupal\Core\Entity\ContentEntityInterface $host
   */
  protected function initIsTranslating(FormStateInterface $form_state, ContentEntityInterface $host) {
    if ($this->isTranslating != NULL) {
      return;
    }
    $this->isTranslating = FALSE;
    if (!$host->isTranslatable()) {
      return;
    }
    if (!$host->getEntityType()->hasKey('default_langcode')) {
      return;
    }
    $default_langcode_key = $host->getEntityType()->getKey('default_langcode');
    if (!$host->hasField($default_langcode_key)) {
      return;
    }

    // Supporting \Drupal\content_translation\Controller\ContentTranslationController.
    if (!empty($form_state->get('content_translation'))) {
      // Adding a translation.
      $this->isTranslating = TRUE;
    }
    $langcode = $form_state->get('langcode');
    if ($host->hasTranslation($langcode) && $host->getTranslation($langcode)
        ->get($default_langcode_key)->value == 0) {
      // Editing a translation.
      $this->isTranslating = TRUE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(FieldDefinitionInterface $field_definition) {
    $target_type = $field_definition->getSetting('target_type');
    $entity_type = \Drupal::entityTypeManager()->getDefinition($target_type);
    if ($entity_type) {
      return $entity_type->entityClassImplements(ContentEntityInterface::class);
    }

    return FALSE;
  }

  /**
   * Builds header actions.
   *
   * @param array[] $field_state
   *   Field widget state.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Current form state.
   *
   * @return array[]
   *   The form element array.
   */
  public function buildHeaderActions(array $field_state, FormStateInterface $form_state) {
    $actions = [];
    $field_name = $this->fieldDefinition->getName();
    $id_prefix = implode('-', array_merge($this->fieldParents, [$field_name]));

    // Collapse & expand all.
    if ($this->realItemCount > 1) {
      $collapse_all = $this->expandButton([
        '#type' => 'submit',
        '#value' => $this->t('Collapse all'),
        '#submit' => [[get_class($this), 'changeAllEditModeSubmit']],
        '#name' => $id_prefix . '_collapse_all',
        '#content_mode' => 'closed',
        '#limit_validation_errors' => [
          array_merge($this->fieldParents, [$field_name, 'collapse_all']),
        ],
        '#ajax' => [
          'callback' => [get_class($this), 'allActionsAjax'],
          'wrapper' => $this->fieldWrapperId,
        ],
        '#weight' => -1,
        '#content_show_warning' => TRUE,
      ]);

      $edit_all = $this->expandButton([
        '#type' => 'submit',
        '#value' => $this->t('Edit all'),
        '#submit' => [[get_class($this), 'changeAllEditModeSubmit']],
        '#name' => $id_prefix . '_edit-all',
        '#content_mode' => 'edit',
        '#limit_validation_errors' => [],
        '#ajax' => [
          'callback' => [get_class($this), 'allActionsAjax'],
          'wrapper' => $this->fieldWrapperId,
        ],
      ]);

      // Take the default edit mode if we don't have anything in state.
      $mode = isset($field_state['content'][0]['mode']) ? $field_state['content'][0]['mode'] : $this->settings['edit_mode'];

      // Depending on the state of the widget output close/edit all in the right
      // order and with the right settings.
      if ($mode === 'closed') {
        $edit_all['#attributes'] = [
          'class' => ['managed-content-icon-button', 'managed-content-icon-button-edit'],
          'title' => $this->t('Edit all'),
        ];
        $edit_all['#title'] = $this->t('Edit All');
        $actions['actions']['edit_all'] = $edit_all;
        $actions['dropdown_actions']['collapse_all'] = $collapse_all;
      }
      else {
        $collapse_all['#attributes'] = [
          'class' => ['managed-content-icon-button', 'managed-content-icon-button-collapse'],
          'title' => $this->t('Collapse all'),
        ];
        $actions['actions']['collapse_all'] = $collapse_all;
        $actions['dropdown_actions']['edit_all'] = $edit_all;
      }
    }

    // Add managed_content_header flag which we use later in preprocessor to move
    // header actions to table header.
    if ($actions) {
      // Set actions.
      $actions['#type'] = 'managed_content_actions';
      $actions['#managed_content_header'] = TRUE;
    }

    return $actions;
  }

  /**
   * Loops through all entities and change mode for each entity instance.
   *
   * @param array $form
   *   Current form state.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Current form state.
   */
  public static function changeAllEditModeSubmit(array $form, FormStateInterface $form_state) {
    $submit = static::getSubmitElementInfo($form, $form_state, static::ACTION_POSITION_HEADER);

    // Change edit mode for each entity.
    foreach ($submit['widget_state']['content'] as $delta => &$entity) {
      if ($submit['widget_state']['content'][$delta]['mode'] !== 'remove') {
        $submit['widget_state']['content'][$delta]['mode'] = $submit['button']['#content_mode'];
        if (!empty($submit['button']['#content_show_warning'])) {
          $submit['widget_state']['content'][$delta]['show_warning'] = $submit['button']['#content_show_warning'];
        }
      }
    }

    if ($submit['widget_state']['autocollapse_default'] == 'all') {
      if ($submit['button']['#content_mode'] === 'edit') {
        $submit['widget_state']['autocollapse'] = 'none';
      }
      elseif ($submit['button']['#content_mode'] === 'closed') {
        $submit['widget_state']['autocollapse'] = 'all';
      }
    }

    static::setWidgetState($submit['parents'], $submit['field_name'], $form_state, $submit['widget_state']);
    $form_state->setRebuild();
  }

  /**
   * Returns a state with all entities closed, if autocollapse is enabled.
   *
   * @param array $widget_state
   *   The current widget state.
   *
   * @return array
   *   The widget state altered by closing all entities.
   */
  public static function autocollapse(array $widget_state) {
    if ($widget_state['real_item_count'] > 0 && $widget_state['autocollapse'] !== 'none') {
      foreach ($widget_state['content'] as $delta => $value) {
        if ($widget_state['content'][$delta]['mode'] === 'edit') {
          $widget_state['content'][$delta]['mode'] = 'closed';
        }
      }
    }

    return $widget_state;
  }

  /**
   * Checks if we can allow reference changes.
   *
   * @return bool
   *   TRUE if we can allow reference changes, otherwise FALSE.
   */
  protected function allowReferenceChanges() {
    return !$this->isTranslating;
  }

  /**
   * Check remove button access.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Content entity to check.
   *
   * @return bool
   *   TRUE if we can remove entity, otherwise FALSE.
   */
  protected function removeButtonAccess(ContentEntityInterface $entity) {
    if (!$entity->access('delete') && !$entity->isNew()) {
      return FALSE;
    }

    if (!$this->allowReferenceChanges()) {
      return FALSE;
    }

    $field_required = $this->fieldDefinition->isRequired();
    $allowed_types = $this->getAllowedTypes();
    $cardinality = $this->fieldDefinition->getFieldStorageDefinition()
      ->getCardinality();

    // Hide the button if field is required, cardinality is one and just one
    // entity type is allowed.
    if ($field_required && $cardinality == 1 && (count($allowed_types) == 1)) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Check that the entity has changed.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *
   * @return bool
   */
  protected function hasChanged(EntityInterface $entity) {
    $original = \Drupal::entityTypeManager()->getStorage($entity->getEntityTypeId())
      ->load($entity->id());
    return $original->toArray() != $entity->toArray();
  }

  /**
   * Check that the entity can be revised.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *
   * @return bool
   */
  protected function canRevise(EntityInterface $entity) {
    // Save the storage, as we need this for loading the revisions.
    $storage = \Drupal::entityTypeManager()->getStorage($entity->getEntityTypeId());

    // Get the entity type.
    $entity_type = $entity->getEntityType();

    // Query all revisions for the entity.
    $query = $storage->getQuery()
      ->latestRevision()
      ->condition($entity_type->getKey('id'), $entity->id())
      ->sort($entity_type->getKey('revision'), 'DESC');

    // Get the revisions list.
    $revisions = array_keys($query->execute());

    // Get the specific revision.
    /** @var \Drupal\Core\Entity\RevisionableInterface $revision */
    $revision = $storage->loadRevision(reset($revisions));

    return $revision->wasDefaultRevision();
  }

}
