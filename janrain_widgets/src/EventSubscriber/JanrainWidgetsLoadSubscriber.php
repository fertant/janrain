<?php

namespace Drupal\janrain_widgets\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use janrain\Sdk as JanrainSdk;
use Drupal\Core\Config\ConfigFactory;
use Drupal\janrain\Identity;

/**
 * Class JanrainWidgetsLoadSubscriber.
 */
class JanrainWidgetsLoadSubscriber implements EventSubscriberInterface {

  /**
   * Drupal\Core\Config\ConfigFactory definition.
   *
   * @var \Drupal\Core\Config\ConfigFactory
   */
  protected $configFactory;

  /**
   * Drupal\janrain\Identity definition.
   *
   * @var \Drupal\janrain\Identity
   */
  protected $identity;

  /**
   * Constructs a new JanrainWidgetsLoadSubscriber object.
   */
  public function __construct(ConfigFactory $config_factory, Identity $janrain_identity) {
    $this->configFactory = $config_factory;
    $this->identity = $janrain_identity;
  }

  /**
   * Load Janrain settings.
   *
   * @param \Symfony\Component\HttpKernel\Event\GetResponseEvent $event
   *   The response event.
   */
  public function janrainWidgetsLoad(GetResponseEvent $event) {
    global $base_url;

    // Load SDK.
    $sdk = JanrainSdk::instance();
    $config = $sdk->getConfig();

    $xdcomm_path = $base_url . '/janrain/xdcomm.html';
    $logout_uri = $base_url . '/user/logout';
    $config->setItem('sso.xdr', $xdcomm_path);
    $config->setItem('sso.logoutUri', $logout_uri);

    if ($this->identity->isLoginOnly()) {
      $sdk->addFeatureByName('EngageWidget');
    }
    else {
      $sdk->addFeatureByName('CaptureWidget');
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[KernelEvents::REQUEST][] = ['janrainWidgetsLoad'];
    return $events;
  }

}
