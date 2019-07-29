<?php

namespace Drupal\Tests\managed_content_field\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Tests \Drupal\managed_content_field\Plugin\RulesAction\ContentSet.
 *
 * @group managed_content_field
 * @group legacy
 */
class PluginContentSetTest extends KernelTestBase {

  /**
   * @var array
   */
  protected static $modules = [
    'rules',
    'managed_content_field'
  ];

  /**
   * The action to be tested.
   *
   * @var \Drupal\rules\Core\RulesActionInterface
   */
  protected $action;

  public function setUp() {
    parent::setUp();
    $this->action = $this->container->get('plugin.manager.rules_action')
      ->createInstance('managed_content_set');
  }

  /**
   * Test the summary field for the action.
   */
  public function testSummary() {
    $this->assertEquals('Set a managed content data value', $this->action->summary());
  }

  /**
   * Tests that primitive values can be set.
   */
  public function testPrimitiveValues() {
    $this->action->setContextValue('data', 'original')
      ->setContextValue('value', 'replacement');
    $this->action->execute();

    $this->assertSame('replacement', $this->action->getContextValue('data'));
    $this->assertSame([], $this->action->autoSaveContext());
  }

  /**
   * Tests that a variable can be set to NULL.
   */
  public function testSetToNull() {
    // We don't need to set the 'value' context, it is NULL by default.
    $this->action->setContextValue('data', 'original');
    $this->action->execute();

    $this->assertNull($this->action->getContextValue('data'));
    $this->assertSame([], $this->action->autoSaveContext());
  }

}
