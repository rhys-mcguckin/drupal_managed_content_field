<?php

namespace Drupal\Tests\managed_content_field\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\managed_content_field\Traits\LoginAdminTrait;
use Drupal\Tests\managed_content_field\Traits\ManagedContentTrait;

/**
 * Tests the Managed Content widget.
 *
 * @group managed_content_field
 */
class ManagedContentWidgetModeratedTest extends BrowserTestBase {

  use LoginAdminTrait;
  use ManagedContentTrait;

  /**
   * @var \Drupal\workflows\WorkflowInterface
   */
  protected $workflow;

  /**
   * Modules to enable.
   *
   * @var string[]
   */
  public static $modules = [
    'node',
    'managed_content_field',
    'content_moderation',
    'field',
    'field_ui',
    'block',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    // Add some content types.
    $this->addContentType('test_content_type');
    $this->addContentType('text');

    // Add the managed content.
    $this->addManagedContentType('managed_test', 'managed');

    // Create the workflow, and save it.
    $this->createEditorialWorkflow('test_content_type');
    $this->workflow->save();
  }

  /**
   * Test the revision process.
   */
  public function testRevisionDeletion() {
    $session = $this->getSession();
    $page = $session->getPage();
    $assert_session = $this->assertSession();
    $storage = \Drupal::entityTypeManager()->getStorage('node');

    // Login with the appropriate permissions.
    $this->loginAsAdmin([
      'access administration pages',
      'view any unpublished content',
      'view all revisions',
      'revert all revisions',
      'view latest version',
      'view any unpublished content',
      'create managed_test content',
      'edit any managed_test content',
      'create test_content_type content',
      'edit any test_content_type content',
      'delete any test_content_type content',
      'use ' . $this->workflow->id() . ' transition create_new_draft',
      'use ' . $this->workflow->id() . ' transition publish',
      'use ' . $this->workflow->id() . ' transition archived_published',
      'use ' . $this->workflow->id() . ' transition archived_draft',
      'use ' . $this->workflow->id() . ' transition archive',
    ]);

    // Add content type to the managed_test content type and check if their type is present as a class.
    $this->drupalGet('node/add/managed_test');
    $page->findButton('managed_test_content_type_add_more')->press();

    // Create the first iteration of the
    $edit = [
      'title[0][value]' => $this->randomString(),
      'managed[0][subform][title][0][value]' => $this->randomString(),
    ];
    $this->drupalPostForm(NULL, $edit, t('Save'));

    // Get the managed node, and the owner.
    $first_owner = $this->drupalGetNodeByTitle($edit['title[0][value]']);
    $managed_node = $this->drupalGetNodeByTitle($edit['managed[0][subform][title][0][value]']);
    $this->assertNotNull($managed_node);

    // Update the moderation state of the managed node.
    $this->drupalGet('node/' . $managed_node->id() . '/edit');
    $assert_session->optionExists('moderation_state[0][state]', 'draft');
    $edit = [
      'moderation_state[0][state]' => 'published',
    ];
    $this->drupalPostForm(NULL, $edit, t('Save'));

    // Ensure that the managed node has a proper moderation state.
    $storage->resetCache();
    $updated_nodes = $storage->getQuery()
      ->latestRevision()
      ->condition('title', $managed_node->label())
      ->execute();
    $updated_revisions = array_keys($updated_nodes);
    $updated_node = $storage->loadRevision(reset($updated_revisions));
    $this->assertEquals('published', $updated_node->get('moderation_state')->get(0)->getValue()['value']);

    // Add content type to the managed_test content type and check if their type is present as a class.
    $this->drupalGet('node/add/managed_test');
    $page->findButton('managed_revise')->press();

    // Create the second iteration of the managed node.
    $edit = [
      'title[0][value]' => $this->randomString(),
      'managed[0][subform][entity_id]' => $managed_node->label() . ' (' . $managed_node->id() . ')',
    ];
    $this->drupalPostForm(NULL, $edit, t('Save'));

    $second_owner = $this->drupalGetNodeByTitle($edit['title[0][value]']);

    // Check that the revision creates the appropriate node state.
    $storage->resetCache();
    $updated_nodes = $storage->getQuery()
      ->latestRevision()
      ->condition('title', $managed_node->label())
      ->execute();
    $updated_revisions = array_keys($updated_nodes);
    $updated_node = $storage->loadRevision(reset($updated_revisions));
    $this->assertEquals('draft', $updated_node->get('moderation_state')->get(0)->getValue()['value']);

    // Update the moderation state of the managed node.
    $this->drupalGet('node/' . $managed_node->id() . '/edit');
    $assert_session->optionExists('moderation_state[0][state]', 'draft');
    $edit = [
      'moderation_state[0][state]' => 'published',
    ];
    $this->drupalPostForm(NULL, $edit, t('Save'));

    // Add content type to the managed_test content type and check if their type is present as a class.
    $this->drupalGet('node/add/managed_test');
    $page->findButton('managed_revise')->press();

    // Create the second iteration of the managed node.
    $edit = [
      'title[0][value]' => $this->randomString(),
      'managed[0][subform][entity_id]' => $managed_node->label() . ' (' . $managed_node->id() . ')',
    ];
    $this->drupalPostForm(NULL, $edit, t('Save'));

    $third_owner = $this->drupalGetNodeByTitle($edit['title[0][value]']);

    // Check that the revision creates the appropriate node state.
    $storage->resetCache();
    $updated_nodes = $storage->getQuery()
      ->latestRevision()
      ->condition('title', $managed_node->label())
      ->execute();
    $updated_revisions = array_keys($updated_nodes);
    $updated_node = $storage->loadRevision(reset($updated_revisions));
    $this->assertEquals('draft', $updated_node->get('moderation_state')->get(0)->getValue()['value']);

    // Perform a deletion of the first node.
    $first_owner->delete();

    // Check that the revision creates the appropriate node state.
    $storage->resetCache();
    $updated_nodes = $storage->getQuery()
      ->latestRevision()
      ->condition('title', $managed_node->label())
      ->execute();
    $updated_revisions = array_keys($updated_nodes);
    $updated_node = $storage->loadRevision(reset($updated_revisions));
    $this->assertEquals('published', $updated_node->get('moderation_state')->get(0)->getValue()['value']);

    // Perform a deletion of the first node.
    $second_owner->delete();

    // Check that the revision creates the appropriate node state.
    $storage->resetCache();
    $updated_nodes = $storage->getQuery()
      ->latestRevision()
      ->condition('title', $managed_node->label())
      ->execute();
    $updated_revisions = array_keys($updated_nodes);
    $updated_node = $storage->loadRevision(reset($updated_revisions));
    $this->assertEquals('published', $updated_node->get('moderation_state')->get(0)->getValue()['value']);

    // Perform a deletion of the first node.
    $third_owner->delete();

    // Check that the revision creates the appropriate node state.
    $storage->resetCache();
    $updated_nodes = $storage->getQuery()
      ->latestRevision()
      ->condition('title', $managed_node->label())
      ->execute();
    $this->assertEquals(0, count($updated_nodes));
  }

}
