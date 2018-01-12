<?php

namespace Drupal\janrain;

use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\externalauth\AuthmapInterface;
use Drupal\Component\Utility\Xss;
use janrain\Profile;
use janrain\Sdk as JanrainSdk;
use Drupal\Core\Config\ConfigFactoryInterface;
use janrain\platform\Renderable as RenderableWidget;

/**
 * Provides an identity helper methods.
 */
class Identity {

  /**
   * The database connection from which to read route information.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The authmap service.
   *
   * @var \Drupal\externalauth\AuthmapInterface
   */
  protected $authmap;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Janrain Identity constructor.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   A database connection object.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   * @param \Drupal\externalauth\AuthmapInterface $authmap
   *   The authmap helper service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(Connection $connection, LoggerChannelFactoryInterface $logger_factory, AuthmapInterface $authmap, ConfigFactoryInterface $config_factory) {
    $this->connection = $connection;
    $this->loggerFactory = $logger_factory;
    $this->authmap = $authmap;
    $this->configFactory = $config_factory;
  }

  /**
   * Ask Drupal for the external identifiers for this user.
   */
  public function userHasCaptureUuid() {
    $authmaps = $this->connection->query("SELECT authname FROM {authmap} WHERE uid = :uid AND module = 'janrain'", [':uid' => $GLOBALS['user']->uid])->fetchAll();
    // Blarg regex validation of uuid :unamused:
    foreach ($authmaps as &$map) {
      if (1 === preg_match('|^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$|', $map->authname)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Extends Drupal's limited "single authmap" adding semantics.
   *
   * @param object $account
   *   User object.
   * @param string $authname
   *   Authentication name.
   */
  public function ensureAuthmap($account, $authname) {
    if (!$this->authmap->getUid($authname, 'janrain')) {
      // This authname has not been used.
      $this->connection->insert('authmap')
        ->fields([
          'uid' => $account->uid,
          'authname' => $authname,
          'module' => 'janrain',
        ])
        ->execute();
      $this->loggerFactory->get('janrain')->notice('Linked {{authname}} to {{user}}.', [
        '{{authname}}' => Xss::filter($authname),
        '{{user}}' => $account->getDisplayName(),
      ]);
    }
    else {
      $this->loggerFactory->get('janrain')->notice('{{user}} already linked to {{authname}}', [
        '{{authname}}' => Xss::filter($authname),
        '{{user}}' => $account->getDisplayName(),
      ]);
    }
  }

  /**
   * Wrapper to access session identifiers.
   *
   * @todo-3.1 move this into sdk
   */
  public function getIdentifiers() {
    return DrupalAdapter::getSessionItem('identifiers') ?: [];
  }

  /**
   * Ensure all identifiers found in the current session are linked.
   *
   * @param object $account
   *   User object.
   * @param \janrain\Profile $profile
   *   Janrain profile class.
   */
  public function linkIdentifiers($account, Profile $profile) {
    $identifiers = $this->isLoginOnly() ? $this->getIdentifiers() : $profile->getIdentifiers();
    foreach ($identifiers as $ext_id) {
      $this->ensureAuthmap($account, $ext_id);
    }
  }

  /**
   * Helper to determine Login-only semantics.
   */
  public function isLoginOnly() {
    $janrain_product = $this->configFactory->get('janrain.settings')->get('janrain_product');
    $janrain_product = empty($janrain_product) ? DrupalAdapter::SKU_SOCIAL_LOGIN : $janrain_product;
    return DrupalAdapter::SKU_SOCIAL_LOGIN == $janrain_product;
  }

  /**
   * Helper to clean up sessions after Janrain login attempts.
   *
   * @todo-3.1 Move to sdk
   */
  public function clearSession($remove_tokens = FALSE) {
    if ($remove_tokens) {
      unset($_SESSION['janrain']);
      return;
    }
    unset(
      $_SESSION['janrain']['identifiers'],
      $_SESSION['janrain']['name'],
      $_SESSION['janrain']['email']
    );
  }

  /**
   * Get the version of the module.
   */
  public function janrainVersion() {
    $module_path = drupal_get_path('module', 'janrain');
    $composer = json_decode(file_get_contents($module_path . '/composer.json'));
    return $composer->version;
  }

  /**
   * Get current list of features.
   *
   * @param \janrain\Sdk $sdk
   *   Janrain class instance.
   */
  public function getEnabledFeatures(JanrainSdk $sdk) {
    $list = [];
    // Pull only the renderable features.
    $features = array_filter(iterator_to_array($sdk->getFeatures()), function ($obj) {
      return $obj instanceof RenderableWidget;
    });
    // Order by render priority need to silence the sort.
    @usort($features, function (RenderableWidget $a, RenderableWidget $b) {
      $pa = $a->getPriority();
      $pb = $b->getPriority();
      if ($pa == $pb) {
        return 0;
      }
      return $pa > $pb ? 1 : -1;
    });
    foreach ($features as $f) {
      $list[$f->getName()] = $f->getName();
    }
    return $list;
  }

}
