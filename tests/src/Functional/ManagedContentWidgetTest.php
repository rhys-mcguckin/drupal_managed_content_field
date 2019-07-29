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
class ManagedContentWidgetTest extends BrowserTestBase {

  use LoginAdminTrait;
  use ManagedContentTrait;

  /**
   * Modules to enable.
   *
   * @var string[]
   */
  public static $modules = [
    'node',
    'managed_content_field',
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

  }

  /**
   * Test classes exist on managed content field.
   */
  public function testContentClass() {
    // Login with the appropriate permissions.
    $this->loginAsAdmin([
      'create managed_test content',
      'edit any managed_test content',
      'create test_content_type content',
      'edit any test_content_type content',
      'delete any test_content_type content',
      'create text content',
      'edit any text content',
    ]);

    // Add content type to the managed_test content type and check if their type is present as a class.
    $this->drupalGet('node/add/managed_test');
    $this->getSession()
      ->getPage()
      ->findButton('managed_test_content_type_add_more')
      ->press();
    $this->assertSession()
      ->responseContains('managed-content-type--test-content-type');
    $this->getSession()
      ->getPage()
      ->findButton('managed_text_add_more')
      ->press();
    $this->assertSession()->responseContains('managed-content-type--text');
    $this->getSession()->getPage()->findButton('managed_0_remove')->press();
    $this->assertSession()->responseContains('managed-content-type--text');

    // Assert the remove button exists even though we don't have access to delete the text content type.
    $button = $this->getSession()->getPage()->findButton('managed_1_remove');
    $this->assertNotNull($button);
  }

  /**
   * Check not having the appropriate permission denies being able to add the content type.
   */
  public function testCreateContentAccess() {
    // Login with the appropriate permissions.
    $this->loginAsAdmin([
      'create managed_test content',
      'edit any managed_test content',
      'create test_content_type content',
      'edit any test_content_type content',
      'delete any test_content_type content',
      'edit any text content',
    ]);

    // Add content type to the managed_test content type and check if their type is present as a class.
    $this->drupalGet('node/add/managed_test');
    $this->getSession()
      ->getPage()
      ->findButton('managed_test_content_type_add_more')
      ->press();
    $this->assertNull($this->getSession()
      ->getPage()
      ->findButton('managed_text_add_more'));
  }

  /**
   * Test that we create the content based on the appropriate value.
   */
  public function testCreateContentSuccess() {
    // Login with the appropriate permissions.
    $this->loginAsAdmin([
      'create managed_test content',
      'edit any managed_test content',
      'create test_content_type content',
      'edit any test_content_type content',
      'delete any test_content_type content',
    ]);

    // Add content type to the managed_test content type and check if their type is present as a class.
    $this->drupalGet('node/add/managed_test');
    $this->getSession()
      ->getPage()
      ->findButton('managed_test_content_type_add_more')
      ->press();

    // Save the content form using the success.
    $edit = [
      'title[0][value]' => $this->randomString(),
      'managed[0][subform][title][0][value]' => $this->randomString(),
    ];
    $this->drupalPostForm(NULL, $edit, t('Save'));

    // Check there is a created node.
    $managed_node = $this->drupalGetNodeByTitle($edit['managed[0][subform][title][0][value]']);
    $this->assertNotNull($managed_node);
    $this->assertEquals($edit['managed[0][subform][title][0][value]'], $managed_node->label());

    // Get the node itself.
    $node = $this->drupalGetNodeByTitle($edit['title[0][value]']);

    // Check that the managed_node matches when loading the original node.
    $this->assertEquals($managed_node->id(), $node->get('managed')
      ->get(0)
      ->getValue()['target_id']);
  }

  /**
   * Test that when there is no remove access we cannot remove the item.
   */
  public function testRemoveAccess() {
    // Login with the appropriate permissions.
    $this->loginAsAdmin([
      'create managed_test content',
      'edit any managed_test content',
      'create test_content_type content',
      'edit any test_content_type content',
    ]);

    // Add content type to the managed_test content type and check if their type is present as a class.
    $this->drupalGet('node/add/managed_test');
    $this->getSession()
      ->getPage()
      ->findButton('managed_test_content_type_add_more')
      ->press();

    // Save the content form using the success.
    $edit = [
      'title[0][value]' => $this->randomString(),
      'managed[0][subform][title][0][value]' => $this->randomString(),
    ];
    $this->drupalPostForm(NULL, $edit, t('Save'));

    // Go to node edit page.
    $node = $this->drupalGetNodeByTitle($edit['title[0][value]']);
    $this->drupalGet('node/' . $node->id() . '/edit');

    // Check that the icon for unable to be deleted shows.
    $this->assertSession()
      ->responseContains('managed-content-icon-delete-disabled');

    // Assert that we can't find the remove functionality.
    $button = $this->getSession()->getPage()->findButton('managed_0_remove');
    $this->assertNull($button);
  }

  /**
   * Test the removal of content when all references have been removed.
   */
  public function testRemoveContent() {
    // Login with the appropriate permissions.
    $this->loginAsAdmin([
      'create managed_test content',
      'edit any managed_test content',
      'create test_content_type content',
      'edit any test_content_type content',
      'delete any test_content_type content',
    ]);

    // Add content type to the managed_test content type and check if their type is present as a class.
    $this->drupalGet('node/add/managed_test');
    $this->getSession()
      ->getPage()
      ->findButton('managed_test_content_type_add_more')
      ->press();

    // Save the content form using the success.
    $edit = [
      'title[0][value]' => $this->randomString(),
      'managed[0][subform][title][0][value]' => $this->randomString(),
    ];
    $this->drupalPostForm(NULL, $edit, t('Save'));

    // Get a reference to the managed node.
    $managed_node = $this->drupalGetNodeByTitle($edit['managed[0][subform][title][0][value]']);

    // Get the node itself.
    $node = $this->drupalGetNodeByTitle($edit['title[0][value]']);

    // Get the node edit page for the owning content.
    $this->drupalGet('node/' . $node->id() . '/edit');

    // Remove the managed content.
    $this->getSession()->getPage()->findButton('managed_0_remove')->press();

    // Check there is a restore button
    $button = $this->getSession()->getPage()->findButton('managed_0_restore');
    $this->assertNotNull($button);

    // Post the form.
    $this->drupalPostForm(NULL, [], t('Save'));

    // Check that the content has been removed, by checking the cache is cleared.
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $storage->resetCache();

    $result = $storage->load($managed_node->id());

    // Ensure the content was removed.
    $this->assertEquals(0, count($result));
  }

  /**
   * Test that update denied removes the ability to fill in the details.
   */
  public function testUpdateDenied() {
    // Login with the appropriate permissions.
    $this->loginAsAdmin([
      'create managed_test content',
      'edit any managed_test content',
      'create test_content_type content',
      'delete any test_content_type content',
    ]);

    // Add content type to the managed_test content type and check if their type is present as a class.
    $this->drupalGet('node/add/managed_test');
    $this->getSession()
      ->getPage()
      ->findButton('managed_test_content_type_add_more')
      ->press();

    // Save the content form using the success.
    $edit = [
      'title[0][value]' => $this->randomString(),
      'managed[0][subform][title][0][value]' => $this->randomString(),
    ];
    $this->drupalPostForm(NULL, $edit, t('Save'));

    // Get the node itself.
    $node = $this->drupalGetNodeByTitle($edit['title[0][value]']);

    // Get the node edit page for the owning content.
    $this->drupalGet('node/' . $node->id() . '/edit');

    // Check that the collapse button does not exist.
    $button = $this->getSession()->getPage()->findButton('managed_0_collapse');
    $this->assertNull($button);
  }

  /**
   * Test that update passes the appropriate content changes through.
   */
  public function testUpdateContent() {
    // Login with the appropriate permissions.
    $this->loginAsAdmin([
      'create managed_test content',
      'edit any managed_test content',
      'create test_content_type content',
      'edit any test_content_type content',
      'delete any test_content_type content',
    ]);

    // Add content type to the managed_test content type and check if their type is present as a class.
    $this->drupalGet('node/add/managed_test');
    $this->getSession()
      ->getPage()
      ->findButton('managed_test_content_type_add_more')
      ->press();

    // Save the content form using the success.
    $edit = [
      'title[0][value]' => $this->randomString(),
      'managed[0][subform][title][0][value]' => $this->randomString(),
    ];
    $this->drupalPostForm(NULL, $edit, t('Save'));

    // Check there is a created node.
    $original_node = $this->drupalGetNodeByTitle($edit['managed[0][subform][title][0][value]']);

    // Get the node itself.
    $node = $this->drupalGetNodeByTitle($edit['title[0][value]']);

    // Go to the node edit page again.
    $this->drupalGet('node/' . $node->id() . '/edit');

    // Save the content form using the success.
    $edit = [
      'managed[0][subform][title][0][value]' => $this->randomString(),
    ];
    $this->drupalPostForm(NULL, $edit, t('Save'));

    // Get new version of the node, check they match.
    $managed_node = $this->drupalGetNodeByTitle($edit['managed[0][subform][title][0][value]'], TRUE);

    // Confirm that we loaded the same node (but with the different title).
    $this->assertEquals($original_node->id(), $managed_node->id());
  }

  /**
   * Test that update passes after collapse.
   */
  public function testUpdateCollapse() {
    // Login with the appropriate permissions.
    $this->loginAsAdmin([
      'create managed_test content',
      'edit any managed_test content',
      'create test_content_type content',
      'edit any test_content_type content',
      'delete any test_content_type content',
    ]);

    // Add content type to the managed_test content type and check if their type is present as a class.
    $this->drupalGet('node/add/managed_test');
    $this->getSession()
      ->getPage()
      ->findButton('managed_test_content_type_add_more')
      ->press();

    // Save the content form using the success.
    $edit = [
      'title[0][value]' => $this->randomString(),
      'managed[0][subform][title][0][value]' => $this->randomString(),
    ];
    $this->drupalPostForm(NULL, $edit, t('Save'));

    // Check there is a created node.
    $original_node = $this->drupalGetNodeByTitle($edit['managed[0][subform][title][0][value]']);

    // Get the node itself.
    $node = $this->drupalGetNodeByTitle($edit['title[0][value]']);

    // Go to the node edit page again.
    $this->drupalGet('node/' . $node->id() . '/edit');

    // Set a field value before collapse.
    $new_label = $this->randomString();
    $this->getSession()
      ->getPage()
      ->findField('managed[0][subform][title][0][value]')
      ->setValue($new_label);

    // Press the collapse button.
    $this->getSession()->getPage()->findButton('managed_0_collapse')->press();

    // Check there is an edit button.
    $button = $this->getSession()->getPage()->findButton('managed_0_edit');
    $this->assertNotNull($button);

    // Save the content form using the success.
    $edit = [];
    $this->drupalPostForm(NULL, $edit, t('Save'));

    // Get new version of the node, check they match.
    $managed_node = $this->drupalGetNodeByTitle($new_label, TRUE);

    // Confirm that we loaded the same node (but with the different title).
    $this->assertEquals($original_node->id(), $managed_node->id());
  }

  /**
   * Test that the clone is denied to content without the proper create permissions.
   */
  public function testCloneAccess() {
    $node = $this->drupalCreateNode([
      'type' => 'test_content_type',
      'title' => $this->randomString(),
    ]);

    // Login with the appropriate permissions.
    $this->loginAsAdmin([
      'create managed_test content',
      'edit any managed_test content',
      'edit any test_content_type content',
      'delete any test_content_type content',
    ]);

    // Add content type to the managed_test content type and check if their type is present as a class.
    $this->drupalGet('node/add/managed_test');
    $this->getSession()->getPage()->findButton('managed_clone')->press();

    // Save the content form using the success.
    $edit = [
      'title[0][value]' => $this->randomString(),
      'managed[0][subform][entity_id]' => $node->label() . ' (' . $node->id() . ')',
    ];
    $this->drupalPostForm(NULL, $edit, t('Save'));

    // There needs to be an error message for cloning content that is not creatable by the user.
    $this->assertSession()
      ->responseContains('You do not have access to clone the content.');
  }

  /**
   * Test the clone content works when expected.
   */
  public function testCloneContent() {
    $node = $this->drupalCreateNode([
      'type' => 'test_content_type',
      'title' => $this->randomString(),
      'status' => TRUE,
    ]);

    // Login with the appropriate permissions.
    $this->loginAsAdmin([
      'create managed_test content',
      'edit any managed_test content',
      'create test_content_type content',
      'edit any test_content_type content',
    ]);

    // Add content type to the managed_test content type and check if their type is present as a class.
    $this->drupalGet('node/add/managed_test');
    $this->getSession()->getPage()->findButton('managed_clone')->press();

    // Save the content form using the success.
    $edit = [
      'title[0][value]' => $this->randomString(),
      'managed[0][subform][entity_id]' => $node->label() . ' (' . $node->id() . ')',
    ];
    $this->drupalPostForm(NULL, $edit, t('Save'));

    // Aiming to check revision of node.
    $storage = \Drupal::entityTypeManager()->getStorage($node->getEntityTypeId());

    // Ensure the storage has been updated
    $storage->resetCache([$node->id()]);

    // Get the node itself.
    $nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties(['title' => $node->label()]);

    // Check that the managed_node matches when loading the original node id.
    $this->assertEquals(2, count($nodes));
  }

  /**
   * Test the clone content works when expected, when collapsed.
   */
  public function testCloneCollapse() {
    $node = $this->drupalCreateNode([
      'type' => 'test_content_type',
      'title' => $this->randomString(),
      'status' => TRUE,
    ]);

    // Login with the appropriate permissions.
    $this->loginAsAdmin([
      'create managed_test content',
      'edit any managed_test content',
      'create test_content_type content',
      'edit any test_content_type content',
    ]);

    // Add content type to the managed_test content type and check if their type is present as a class.
    $this->drupalGet('node/add/managed_test');
    $this->getSession()->getPage()->findButton('managed_clone')->press();

    // Set a field value before collapse.
    $this->getSession()
      ->getPage()
      ->findField('managed[0][subform][entity_id]')
      ->setValue($node->label() . ' (' . $node->id() . ')');

    // Press the collapse button.
    $this->getSession()->getPage()->findButton('managed_0_collapse')->press();

    // Check there is an edit button.
    $button = $this->getSession()->getPage()->findButton('managed_0_edit');
    $this->assertNotNull($button);

    // Save the content form using the success.
    $edit = [
      'title[0][value]' => $this->randomString(),
    ];
    $this->drupalPostForm(NULL, $edit, t('Save'));

    // Aiming to check revision of node.
    $storage = \Drupal::entityTypeManager()->getStorage($node->getEntityTypeId());

    // Ensure the storage has been updated
    $storage->resetCache([$node->id()]);

    // Get the node itself.
    $nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties(['title' => $node->label()]);

    // Check that the managed_node matches when loading the original node id.
    $this->assertEquals(2, count($nodes));
  }

  /**
   * Test the revision access for content.
   */
  public function testReviseAccess() {
    $node = $this->drupalCreateNode([
      'type' => 'test_content_type',
      'title' => $this->randomString(),
      'status' => TRUE,
    ]);

    // Login with the appropriate permissions.
    $this->loginAsAdmin([
      'create managed_test content',
      'edit any managed_test content',
    ]);

    // Add content type to the managed_test content type and check if their type is present as a class.
    $this->drupalGet('node/add/managed_test');
    $this->getSession()->getPage()->findButton('managed_revise')->press();

    // Save the content form using the success.
    $edit = [
      'title[0][value]' => $this->randomString(),
      'managed[0][subform][entity_id]' => $node->label() . ' (' . $node->id() . ')',
    ];
    $this->drupalPostForm(NULL, $edit, t('Save'));

    // There needs to be an error message for cloning content that is not creatable by the user.
    $this->assertSession()
      ->responseContains('You do not have access to revise the content.');
  }

  /**
   * Test the revising unpublished content.
   */
  public function testReviseUnpublished() {
    $node = $this->drupalCreateNode([
      'type' => 'test_content_type',
      'title' => $this->randomString(),
      'status' => FALSE,
    ]);

    // Login with the appropriate permissions.
    $this->loginAsAdmin([
      'create managed_test content',
      'edit any managed_test content',
      'edit any test_content_type content',
    ]);

    // Add content type to the managed_test content type and check if their type is present as a class.
    $this->drupalGet('node/add/managed_test');
    $this->getSession()->getPage()->findButton('managed_revise')->press();

    // Save the content form using the success.
    $edit = [
      'title[0][value]' => $this->randomString(),
      'managed[0][subform][entity_id]' => $node->label() . ' (' . $node->id() . ')',
    ];
    $this->drupalPostForm(NULL, $edit, t('Save'));

    // There needs to be an error message for cloning content that is not creatable by the user.
    $this->assertSession()
      ->responseContains('The referenced entity (<em class="placeholder">node</em>: <em class="placeholder">' . $node->id() . '</em>) does not exist.');
  }

  /**
   * Test the revising published content.
   */
  public function testRevisePublished() {
    $node = $this->drupalCreateNode([
      'type' => 'test_content_type',
      'title' => $this->randomString(),
      'status' => TRUE,
    ]);

    // Login with the appropriate permissions.
    $this->loginAsAdmin([
      'create managed_test content',
      'edit any managed_test content',
      'edit any test_content_type content',
    ]);

    // Add content type to the managed_test content type and check if their type is present as a class.
    $this->drupalGet('node/add/managed_test');
    $this->getSession()->getPage()->findButton('managed_revise')->press();

    // Save the content form using the success.
    $edit = [
      'title[0][value]' => $this->randomString(),
      'managed[0][subform][entity_id]' => $node->label() . ' (' . $node->id() . ')',
    ];
    $this->drupalPostForm(NULL, $edit, t('Save'));

    // Aiming to check revision of node.
    $storage = \Drupal::entityTypeManager()->getStorage($node->getEntityTypeId());

    // Ensure the storage has been updated
    $storage->resetCache([$node->id()]);

    // Get the node itself.
    $owner = $this->drupalGetNodeByTitle($edit['title[0][value]']);

    // Check that the managed_node matches when loading the original node id.
    $this->assertEquals($node->id(), $owner->get('managed')
      ->get(0)
      ->getValue()['target_id']);

    // Check the node has been moved to unpublished we revisions.
    /** @var \Drupal\node\NodeInterface $revised_node */
    $revised_node = $storage->load($node->id());
    $this->assertTrue($revised_node->isPublished());

    $revisions = $storage->getQuery()->allRevisions()
      ->condition($revised_node->getEntityType()->getKey('id'), $revised_node->id())
      ->execute();

    $this->assertEquals(2, count($revisions));
  }

  /**
   * Test the revising works when collapsed.
   */
  public function testReviseCollapse() {
      $node = $this->drupalCreateNode([
        'type' => 'test_content_type',
        'title' => $this->randomString(),
        'status' => TRUE,
      ]);

      // Login with the appropriate permissions.
      $this->loginAsAdmin([
        'create managed_test content',
        'edit any managed_test content',
        'edit any test_content_type content',
      ]);

      // Add content type to the managed_test content type and check if their type is present as a class.
      $this->drupalGet('node/add/managed_test');
      $this->getSession()->getPage()->findButton('managed_revise')->press();

      $this->getSession()
          ->getPage()
          ->findField('managed[0][subform][entity_id]')
          ->setValue($node->label() . ' (' . $node->id() . ')');

      // Press the collapse button.
      $this->getSession()->getPage()->findButton('managed_0_collapse')->press();

      // Check there is an edit button.
      $button = $this->getSession()->getPage()->findButton('managed_0_edit');
      $this->assertNotNull($button);

      // Save the content form using the success.
      $edit = [
        'title[0][value]' => $this->randomString(),
      ];
      $this->drupalPostForm(NULL, $edit, t('Save'));

      // Aiming to check revision of node.
      $storage = \Drupal::entityTypeManager()->getStorage($node->getEntityTypeId());

      // Ensure the storage has been updated
      $storage->resetCache([$node->id()]);

      // Get the node itself.
      $owner = $this->drupalGetNodeByTitle($edit['title[0][value]']);

      // Check that the managed_node matches when loading the original node id.
      $this->assertEquals($node->id(), $owner->get('managed')
        ->get(0)
        ->getValue()['target_id']);

      // Check the node has been moved to unpublished we revisions.
      /** @var \Drupal\node\NodeInterface $revised_node */
      $revised_node = $storage->load($node->id());
      $this->assertTrue($revised_node->isPublished());

      $revisions = $storage->getQuery()->allRevisions()
        ->condition($revised_node->getEntityType()->getKey('id'), $revised_node->id())
        ->execute();

      $this->assertEquals(2, count($revisions));
    }

  /**
   * Test there is no restore when creating content.
   */
  public function testNoRestore() {
    // Login with the appropriate permissions.
    $this->loginAsAdmin([
      'create managed_test content',
      'edit any managed_test content',
      'create test_content_type content',
      'edit any test_content_type content',
      'delete any test_content_type content',
    ]);

    // Add content type to the managed_test content type and check if their type is present as a class.
    $this->drupalGet('node/add/managed_test');
    $this->getSession()
      ->getPage()
      ->findButton('managed_test_content_type_add_more')
      ->press();

    $new_label = $this->randomString();
    $this->getSession()
        ->getPage()
        ->findField('managed[0][subform][title][0][value]')
        ->setValue($new_label);

    // Press the collapse button.
    $this->getSession()->getPage()->findButton('managed_0_remove')->press();

    // Check there is no restore button.
    $button = $this->getSession()->getPage()->findButton('managed_0_restore');
    $this->assertNull($button);
  }

  /**
   * Test there is no restore when creating content.
   */
  public function testRestore() {
    // Login with the appropriate permissions.
    $this->loginAsAdmin([
      'create managed_test content',
      'edit any managed_test content',
      'create test_content_type content',
      'edit any test_content_type content',
      'delete any test_content_type content',
    ]);

    // Add content type to the managed_test content type and check if their type is present as a class.
    $this->drupalGet('node/add/managed_test');
    $this->getSession()
      ->getPage()
      ->findButton('managed_test_content_type_add_more')
      ->press();

    // Save the content form using the success.
    $edit = [
      'title[0][value]' => $this->randomString(),
      'managed[0][subform][title][0][value]' => $this->randomString(),
    ];
    $this->drupalPostForm(NULL, $edit, t('Save'));

    // Grab the created node.
    $node = $this->drupalGetNodeByTitle($edit['title[0][value]']);

    // Load the form page.
    $this->drupalGet('node/' . $node->id() . '/edit');

    // Press the remove button.
    $this->getSession()->getPage()->findButton('managed_0_remove')->press();

    // Check there is no restore button.
    $button = $this->getSession()->getPage()->findButton('managed_0_restore');
    $this->assertNotNull($button);

    // Press the restore button.
    $this->getSession()->getPage()->findButton('managed_0_restore')->press();

    // Check there is a remove button.
    $button = $this->getSession()->getPage()->findButton('managed_0_remove');
    $this->assertNotNull($button);
  }

}
