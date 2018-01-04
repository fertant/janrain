<?php

namespace Drupal\janrain\Plugin\rest\resource;

use Drupal\Component\Plugin\DependentPluginInterface;
use Drupal\rest\ModifiedResourceResponse;
use Drupal\rest\Plugin\ResourceBase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use janrain\Sdk as JanrainSdk;
use Drupal\janrain\DrupalAdapter;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Provides a resource to post session token.
 *
 * @RestResource(
 *   id = "janrain_session_token_resource",
 *   label = @Translation("Returns current session token for logged in user, refreshes token if necessary"),
 *   uri_paths = {
 *     "canonical" = "/janrain/registration/session_token",
 *   }
 * )
 */
class SessionTokenResource extends ResourceBase implements DependentPluginInterface {

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * Constructs a SessionTokenResource object.
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
   * @param array $data
   *   Data array.
   *
   * @return \Drupal\rest\ModifiedResourceResponse
   *   Register user post result data.
   */
  public function post(array $data = []) {
    $sdk = JanrainSdk::instance();
    $token_expires = DrupalAdapter::getSessionItem('tokenExpires');
    if ($token_expires && (time() > $token_expires)) {
      try {
        $new_tokens = $sdk->CaptureApi->oauthRefreshToken(DrupalAdapter::getSessionItem('refreshToken'));
        DrupalAdapter::setSessionItem('accessToken', $new_tokens->access_token);
        DrupalAdapter::setSessionItem('refreshToken', $new_tokens->refresh_token);
        DrupalAdapter::setSessionItem('tokenExpires', time() + intval($new_tokens->expires_in) - 60 * 10);
      }
      catch (\Exception $e) {
        // Call to Capture failed, this is something that needs attention.
        $this->logger->alert($e->getMessage());
      }
      $result = $new_tokens->access_token;
    }
    else {
      $result = DrupalAdapter::getSessionItem('accessToken');
    }

    // Return response.
    return new ModifiedResourceResponse($result);
  }

}
