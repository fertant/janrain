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

/**
 * Provides a resource to post access token.
 *
 * @RestResource(
 *   id = "janrain_token_resource",
 *   label = @Translation("Push Janrain identity credentials into user session"),
 *   uri_paths = {
 *     "canonical" = "/janrain/login/token",
 *   }
 * )
 */
class LoginTokenResource extends ResourceBase implements DependentPluginInterface {

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * Constructs a LoginTokenResource object.
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
   *    'token'
   *  ]
   *
   * @param array $data
   *   Data array.
   *
   * @return \Drupal\rest\ModifiedResourceResponse
   *   Register user post result data.
   */
  public function post(array $data = []) {
    $sdk = JanrainSdk::instance();

    try {
      // Trade token for profile.
      $profile = $sdk->EngageApi->fetchProfileByToken($data['token']);
    }
    catch (Exception $e) {
      // Engage call failed for login, this is superbad.
      $this->logger->emergency($e->getMessage());
      // Also log the full stack dump to syslog.
      error_log($e->getTraceAsString());
      // Return response.
      return new ModifiedResourceResponse($e->getMessage());
    }

    // Notify listeners.
    $this->moduleHandler->invokeAll('janrain_profile_received', $profile);

    // Set session data.
    DrupalAdapter::setSessionItem('identifiers', $profile->getIdentifiers());
    DrupalAdapter::setSessionItem('name', $profile->getFirst("$.profile.displayName"));
    DrupalAdapter::setSessionItem('profile', $profile->__toString());
    $result = 'Session enhanced with social login data! Proceed to login';

    // Return response.
    return new ModifiedResourceResponse($result);
  }

}
