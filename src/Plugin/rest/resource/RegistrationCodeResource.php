<?php

namespace Drupal\janrain\Plugin\rest\resource;

use Drupal\Component\Plugin\DependentPluginInterface;
use Drupal\rest\ModifiedResourceResponse;
use Drupal\rest\Plugin\ResourceBase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use janrain\Sdk as JanrainSdk;
use Drupal\janrain\DrupalAdapter;
use Symfony\Component\Config\Definition\Exception\Exception;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Component\Utility\Timer;
use Drupal\Core\Logger\RfcLogLevel;

/**
 * Provides a resource to post registration code.
 *
 * @RestResource(
 *   id = "janrain_code_resource",
 *   label = @Translation("Push Janrain identity credentials into user session"),
 *   uri_paths = {
 *     "canonical" = "/janrain/registration/code",
 *     "https://www.drupal.org/link-relations/create" = "/janrain/registration/session_token",
 *   }
 * )
 */
class RegistrationCodeResource extends ResourceBase implements DependentPluginInterface {

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * Constructs a RegistrationCodeResource object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param array $serializer_formats
   *   The available serialization formats.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, array $serializer_formats, LoggerInterface $logger, ModuleHandlerInterface $module_handler) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger);
    $this->moduleHandler = $module_handler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->getParameter('serializer.formats'),
      $container->get('logger.factory')->get('janrain'),
      $container->get('module_handler')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {}

  /**
   * Responds to POST requests.
   *
   * POST data should be structured in next way:
   *  $data = [
   *    'code'
   *  ]
   *
   * @param array $data
   *   Data array.
   *
   * @return \Drupal\rest\ModifiedResourceResponse
   *   Register user post result data.
   */
  public function post(array $data = []) {
    Timer::start('janrain_registration_code');
    global $base_root;
    $sdk = JanrainSdk::instance();

    try {
      // Where did the login start?
      $login_url = $_SERVER['HTTP_REFERER'];
      // Check referrer is 1st party.
      $login_host_matches = FALSE !== stripos($login_url, $base_root);
      // Sanity check AJAX origin is also 1st party.
      $login_origin_matches = FALSE !== stripos($base_root, $_SERVER['HTTP_ORIGIN']);
      $good_login_url = $login_host_matches && $login_origin_matches;
      if (!$good_login_url) {
        // Bad referer, fallback to session based login_url.
        $login_url = $sdk->getSessionItem('capture.currentUri');
      }
      // Check for login_url.
      if (empty($login_url)) {
        // Something is fishy here, blow up.
        $this->logger->emergency('Login URL is not verifiable, aborting login.');
        return new ModifiedResourceResponse('');
      }
      // Everything looks okay, fetch tokens.
      $tokens = $sdk->CaptureApi->fetchTokensFromCode($data['code'], $login_url);
    }
    catch (Exception $e) {
      // Capture call failed for login, this is superbad.
      watchdog_exception('janrain', $e, NULL, [], RfcLogLevel::EMERGENCY);
      // Services_error rethrows.
      $this->logger->emergency('Invalid OAuth code!');
    }

    DrupalAdapter::setSessionItem('accessToken', $tokens['access_token']);
    DrupalAdapter::setSessionItem('refreshToken', $tokens['refresh_token']);
    // Set the token expiration timestamp to be 10 min less than the timeout.
    DrupalAdapter::setSessionItem('tokenExpires', time() + intval($tokens['expires_in']) - 60 * 10);
    $info = Timer::stop('janrain_registration_code');
    \Drupal::state()->set('janrain_perf', sprintf("%fms", $info['time']));
    $result = 'Session enhanced by Janrain Registration! Proceed to login';

    // Return response.
    return new ModifiedResourceResponse($result);
  }

}
