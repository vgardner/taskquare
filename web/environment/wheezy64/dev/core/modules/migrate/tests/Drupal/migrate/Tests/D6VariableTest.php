<?php

/**
 * @file
 * Contains \Drupal\migrate\Tests\D6VariableSourceTest.
 */

namespace Drupal\migrate\Tests;

/**
 * @group migrate
 * @group Drupal
 */
class D6VariableTest extends MigrateSqlSourceTestCase {

  const PLUGIN_CLASS = 'Drupal\migrate\Plugin\migrate\source\D6Variable';

  protected $migrationConfiguration = array(
    'id' => 'test',
    'highwaterProperty' => array('field' => 'test'),
    'idlist' => array(),
    'source' => array(
      'plugin' => 'drupal6_variable',
      'variables' => array(
        'foo',
        'bar',
      ),
    ),
    'sourceIds' => array(),
    'destinationIds' => array(),
  );

  protected $mapJoinable = FALSE;

  protected $expectedResults = array(
    array(
      'foo' => 1,
      'bar' => FALSE,
    ),
  );

  protected $databaseContents = array(
    'variable' => array(
      array('name' => 'foo', 'value' => 'i:1;'),
      array('name' => 'bar', 'value' => 'b:0;'),
    ),
  );

  /**
   * {@inheritdoc}
   */
  public static function getInfo() {
    return array(
      'name' => 'D6 variable source functionality',
      'description' => 'Tests D6 variable source plugin.',
      'group' => 'Migrate',
    );
  }

}

namespace Drupal\migrate\Tests\source;

use Drupal\Core\Database\Connection;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\migrate\Plugin\migrate\source\D6Variable;

class TestD6Variable extends D6Variable {
  function setDatabase(Connection $database) {
    $this->database = $database;
  }
  function setModuleHandler(ModuleHandlerInterface $module_handler) {
    $this->moduleHandler = $module_handler;
  }
}
