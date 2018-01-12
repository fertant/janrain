<?php

namespace Drupal\janrain_widgets\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Response;
use janrain\features\FederateWidget;

/**
 * Class XdcommController.
 */
class XdcommController extends ControllerBase {

  /**
   * Render Xdcomm.
   */
  public function render() {
    $data = FederateWidget::renderXdcomm(TRUE);
    $response = new Response();
    $response->setContent($data);

    return $response;
  }

}
