<?php

/**
 * @file
 * Contains \Drupal\dblog\Controller\DbLogController.
 */

namespace Drupal\dblog\Controller;

use Drupal\Component\Utility\Unicode;
use Drupal\Component\Utility\String;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\Date;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\user\UserStorageControllerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Returns responses for dblog routes.
 */
class DbLogController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * The database service.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The date service.
   *
   * @var \Drupal\Core\Datetime\Date
   */
  protected $date;
  /**
   * @var \Drupal\user\UserStorageControllerInterface
   */
  protected $userStorage;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('module_handler'),
      $container->get('date'),
      $container->get('entity.manager')->getStorageController('user')
    );
  }

  /**
   * Constructs a DbLogController object.
   *
   * @param Connection $database
   *   A database connection.
   * @param ModuleHandlerInterface $module_handler
   *   A module handler.
   */
  public function __construct(Connection $database, ModuleHandlerInterface $module_handler, Date $date, UserStorageControllerInterface $user_storage) {
    $this->database = $database;
    $this->moduleHandler = $module_handler;
    $this->date = $date;
    $this->userStorage = $user_storage;
  }

  /**
   * Gets an array of log level classes.
   *
   * @return array
   *   An array of log level classes.
   */
  public static function getLogLevelClassMap() {
    return array(
      WATCHDOG_DEBUG => 'dblog-debug',
      WATCHDOG_INFO => 'dblog-info',
      WATCHDOG_NOTICE => 'dblog-notice',
      WATCHDOG_WARNING => 'dblog-warning',
      WATCHDOG_ERROR => 'dblog-error',
      WATCHDOG_CRITICAL => 'dblog-critical',
      WATCHDOG_ALERT => 'dblog-alert',
      WATCHDOG_EMERGENCY => 'dblog-emergency',
    );
  }

  /**
   * Displays a listing of database log messages.
   *
   * Messages are truncated at 56 chars.
   * Full-length messages can be viewed on the message details page.
   *
   * @return array
   *   A render array as expected by drupal_render().
   *
   * @see dblog_clear_log_form()
   * @see dblog_event()
   * @see dblog_filter_form()
   */
  public function overview() {

    $filter = $this->buildFilterQuery();
    $rows = array();

    $classes = static::getLogLevelClassMap();

    $this->moduleHandler->loadInclude('dblog', 'admin.inc');

    $build['dblog_filter_form'] = drupal_get_form('dblog_filter_form');
    $build['dblog_clear_log_form'] = drupal_get_form('dblog_clear_log_form');

    $header = array(
      // Icon column.
      '',
      array(
        'data' => t('Type'),
        'field' => 'w.type',
        'class' => array(RESPONSIVE_PRIORITY_MEDIUM)),
      array(
        'data' => t('Date'),
        'field' => 'w.wid',
        'sort' => 'desc',
        'class' => array(RESPONSIVE_PRIORITY_LOW)),
      t('Message'),
      array(
        'data' => t('User'),
        'field' => 'u.name',
        'class' => array(RESPONSIVE_PRIORITY_MEDIUM)),
      array(
        'data' => t('Operations'),
        'class' => array(RESPONSIVE_PRIORITY_LOW)),
    );

    $query = $this->database->select('watchdog', 'w')
      ->extend('Drupal\Core\Database\Query\PagerSelectExtender')
      ->extend('Drupal\Core\Database\Query\TableSortExtender');
    $query->fields('w', array(
      'wid',
      'uid',
      'severity',
      'type',
      'timestamp',
      'message',
      'variables',
      'link',
    ));

    if (!empty($filter['where'])) {
      $query->where($filter['where'], $filter['args']);
    }
    $result = $query
      ->limit(50)
      ->orderByHeader($header)
      ->execute();

    foreach ($result as $dblog) {
      // Check for required properties.
      if (isset($dblog->message) && isset($dblog->variables)) {
        // Messages without variables or user specified text.
        if ($dblog->variables === 'N;') {
          $message = $dblog->message;
        }
        // Message to translate with injected variables.
        else {
          $message = t($dblog->message, unserialize($dblog->variables));
        }
        if (isset($dblog->wid)) {
          // Truncate link_text to 56 chars of message.
          $log_text = Unicode::truncate(filter_xss($message, array()), 56, TRUE, TRUE);
          $message = $this->l($log_text, 'dblog.event',  array('event_id' => $dblog->wid), array('html' => TRUE));
        }
      }
      $username = array(
        '#theme' => 'username',
        '#account' => user_load($dblog->uid),
      );
      $rows[] = array(
        'data' => array(
          // Cells.
          array('class' => array('icon')),
          t($dblog->type),
          $this->date->format($dblog->timestamp, 'short'),
          $message,
          array('data' => $username),
          Xss::filter($dblog->link),
        ),
        // Attributes for table row.
        'class' => array(drupal_html_class('dblog-' . $dblog->type), $classes[$dblog->severity]),
      );
    }

    $build['dblog_table'] = array(
      '#theme' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#attributes' => array('id' => 'admin-dblog', 'class' => array('admin-dblog')),
      '#empty' => t('No log messages available.'),
    );
    $build['dblog_pager'] = array('#theme' => 'pager');

    return $build;

  }

  /**
   * Displays details about a specific database log message.
   *
   * @param int $event_id
   *   Unique ID of the database log message.
   *
   * @return array
   *   If the ID is located in the Database Logging table, a build array in the
   *   format expected by drupal_render();
   *
   */
  public function eventDetails($event_id) {
    $build = array();
    if ($dblog = $this->database->query('SELECT w.*, u.name, u.uid FROM {watchdog} w INNER JOIN {users} u ON w.uid = u.uid WHERE w.wid = :id', array(':id' => $event_id))->fetchObject()) {
      $severity = watchdog_severity_levels();
      // Check for required properties.
      if (isset($dblog->message) && isset($dblog->variables)) {
        // Inject variables into the message if required.
        $message = $dblog->variables === 'N;' ? $dblog->message : t($dblog->message, unserialize($dblog->variables));
      }
      $username = array(
        '#theme' => 'username',
        '#account' => user_load($dblog->uid),
      );
      $rows = array(
        array(
          array('data' => t('Type'), 'header' => TRUE),
          t($dblog->type),
        ),
        array(
          array('data' => t('Date'), 'header' => TRUE),
          $this->date->format($dblog->timestamp, 'long'),
        ),
        array(
          array('data' => t('User'), 'header' => TRUE),
          array('data' => $username),
        ),
        array(
          array('data' => t('Location'), 'header' => TRUE),
          l($dblog->location, $dblog->location),
        ),
        array(
          array('data' => t('Referrer'), 'header' => TRUE),
          l($dblog->referer, $dblog->referer),
        ),
        array(
          array('data' => t('Message'), 'header' => TRUE),
          $message,
        ),
        array(
          array('data' => t('Severity'), 'header' => TRUE),
          $severity[$dblog->severity],
        ),
        array(
          array('data' => t('Hostname'), 'header' => TRUE),
          String::checkPlain($dblog->hostname),
        ),
        array(
          array('data' => t('Operations'), 'header' => TRUE),
          $dblog->link,
        ),
      );
      $build['dblog_table'] = array(
        '#theme' => 'table',
        '#rows' => $rows,
        '#attributes' => array('class' => array('dblog-event')),
      );
    }

    return $build;
  }

  /**
   * Builds a query for database log administration filters based on session.
   *
   * @return array
   *   An associative array with keys 'where' and 'args'.
   */
  protected function buildFilterQuery() {
    if (empty($_SESSION['dblog_overview_filter'])) {
      return;
    }

    $this->moduleHandler->loadInclude('dblog', 'admin.inc');

    $filters = dblog_filters();

    // Build query.
    $where = $args = array();
    foreach ($_SESSION['dblog_overview_filter'] as $key => $filter) {
      $filter_where = array();
      foreach ($filter as $value) {
        $filter_where[] = $filters[$key]['where'];
        $args[] = $value;
      }
      if (!empty($filter_where)) {
        $where[] = '(' . implode(' OR ', $filter_where) . ')';
      }
    }
    $where = !empty($where) ? implode(' AND ', $where) : '';

    return array(
      'where' => $where,
      'args' => $args,
    );
  }

  /**
   * @todo Remove dblog_top().
   */
  public function pageNotFound() {
    module_load_include('admin.inc', 'dblog');
    return dblog_top('page not found');
  }

  /**
   * @todo Remove dblog_top().
   */
  public function accessDenied() {
    module_load_include('admin.inc', 'dblog');
    return dblog_top('access denied');
  }

  /**
   * @todo Remove dblog_top().
   */
  public function search() {
    module_load_include('admin.inc', 'dblog');
    return dblog_top('search');
  }

}
