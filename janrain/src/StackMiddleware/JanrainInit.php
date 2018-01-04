<?php

namespace Drupal\janrain\StackMiddleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Config\Definition\Exception\Exception;
use Drupal\janrain\DrupalAdapter;
use janrain\Sdk as JanrainSdk;

/**
 * Load Janrain API.
 */
class JanrainInit implements HttpKernelInterface {

  /**
   * The wrapped HTTP kernel.
   *
   * @var \Symfony\Component\HttpKernel\HttpKernelInterface
   */
  protected $httpKernel;

  /**
   * Creates a HTTP middleware handler.
   *
   * @param \Symfony\Component\HttpKernel\HttpKernelInterface $kernel
   *   The HTTP kernel.
   */
  public function __construct(HttpKernelInterface $kernel) {
    $this->httpKernel = $kernel;
  }

  /**
   * {@inheritdoc}
   */
  public function handle(Request $request, $type = self::MASTER_REQUEST, $catch = TRUE) {
    $module_path = drupal_get_path('module', 'janrain');
    // Early load resources since Drupal's module loading semantics are wack.
    if (!class_exists('JanrainSdk')) {
      try {
        require_once $module_path . '/src/lib/JanrainPhpSdk.phar';
      }
      catch (Exception $e) {
        // This really can only be triggered by invalid file permissions or
        // unsupported PHP versions.
        \Drupal::logger('janrain')->emergency($e->getMessage(), []);
      }
    }
    if (!class_exists('Guzzle\\Http\\Client')) {
      require_once $module_path . '/src/lib/guzzle.phar';
    }

    $this->initJanrainSdk($request);

    return $this->httpKernel->handle($request, $type, $catch);
  }

  /**
   * Initialize the SDK.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   Current request.
   */
  protected function initJanrainSdk(Request $request) {
    $adapter = DrupalAdapter::fromDrupal();
    $sdk = JanrainSdk::forAdapter($adapter);
    $sdk->getConfig()->setLoginPage($request);
  }

}
