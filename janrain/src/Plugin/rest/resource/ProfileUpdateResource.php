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
 * Provides a resource to post profile update.
 *
 * @RestResource(
 *   id = "janrain_profile_resource",
 *   label = @Translation("Updates the profile of the logged in user when user submits Janrain profile form"),
 *   uri_paths = {
 *     "canonical" = "/janrain/registration/profile_update",
 *   }
 * )
 */
class ProfileUpdateResource extends ResourceBase implements DependentPluginInterface {

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * Constructs a ProfileUpdateResource object.
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
   * @return \Drupal\rest\ModifiedResourceResponse
   *   Register user post result data.
   */
  public function post() {
    $sdk = JanrainSdk::instance();
    $access_token = DrupalAdapter::getSessionItem('accessToken');
    $profile = $sdk->CaptureApi->fetchProfileByToken($access_token);
    if ($this->moduleHandler->moduleExists('rules')) {
      rules_invoke_event_by_args('janrain_data_profile_updated', ['profile' => $profile]);
    }
    $this->moduleHandler->invokeAll('janrain_profile_updated', $profile, \Drupal::currentUser()->getAccount());
    $result = $this->t("Drupal's user data updated successfully!");

    // Return response.
    return new ModifiedResourceResponse($result);
  }

}
