<?php

namespace Drupal\janrain\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;

/**
 * Add custom headers.
 */
class HeaderResponseSubscriber implements EventSubscriberInterface {

  /**
   * Add data to header for response.
   *
   * @param \Symfony\Component\HttpKernel\Event\FilterResponseEvent $event
   *   Response event object.
   */
  public function onRespond(FilterResponseEvent $event) {
    if ($janrain_perf = \Drupal::state()->get('janrain_perf', FALSE)) {
      $response = $event->getResponse();
      $response->headers->set('X-Janrain-Perf', 'some value');
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[KernelEvents::RESPONSE][] = ['onRespond'];
    return $events;
  }

}
