<?php

namespace Drupal\janrain\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use janrain\Sdk as JanrainSdk;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\janrain\Identity;

/**
 * Load Janrain settings.
 */
class JanrainLoadSubscriber implements EventSubscriberInterface {

  /**
   * Constructs a JanrainLoadSubscriber object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   A config factory for retrieving required config objects.
   * @param \Drupal\janrain\Identity $identity
   *   A config factory for retrieving required config objects.
   */
  public function __construct(ConfigFactoryInterface $config_factory, Identity $identity) {
    $this->config = $config_factory;
    $this->identity = $identity;
  }

  /**
   * Load Janrain settings.
   *
   * @param \Symfony\Component\HttpKernel\Event\GetResponseEvent $event
   *   The response event.
   */
  public function janrainLoad(GetResponseEvent $event) {
    // Load SDK.
    $sdk = JanrainSdk::instance();

    if ($this->identity->isLoginOnly()) {
      $sdk->addFeatureByName('EngageApi');
    }
    else {
      // Disable visitor-initiated account creation via Drupal
      // (admins may still create accounts, but they should do so in Capture).
      if ($this->config->get('janrain.settings')->get('user_register') != USER_REGISTER_ADMINISTRATORS_ONLY) {
        $this->config->getEditable('janrain.settings')->set('user_register', USER_REGISTER_ADMINISTRATORS_ONLY)->save();
      }
      $sdk->addFeatureByName('CaptureApi');
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[KernelEvents::REQUEST][] = ['janrainLoad'];
    return $events;
  }

}
