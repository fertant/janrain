<?php

namespace Drupal\janrain;

use janrain\Adapter;
use Symfony\Component\HttpFoundation\Request;

/**
 * DrupalAdapter enables PHPSDK to talk to Drupal.
 *
 * @todo-3.1 Rewrite this to be an adaptor implementation maybe move adaptors
 * into sdk so we can avoid this horrible code convention?
 */
class DrupalAdapter implements Adapter {

  const SKU_SOCIAL_LOGIN = 0;
  const SKU_STANDARD = 1;

  protected $settings;

  /**
   * Drupal "Function" comment.
   */
  public function __construct($data = []) {
    $this->settings = new \ArrayObject($data, \ArrayObject::ARRAY_AS_PROPS);
  }

  /**
   * Drupal "Function" comment.
   */
  public function getItem($key) {
    return isset($this->settings->$key) ? $this->settings->$key : NULL;
  }

  /**
   * Drupal "Function" comment.
   */
  public function setItem($key, $value) {
    $this->settings->$key = $value;
  }

  /**
   * Drupal "Function" comment.
   */
  public function toJson() {
    return json_encode($this->settings->getArrayCopy());
  }

  /**
   * Drupal "Function" comment.
   */
  public function getIterator() {
    return $this->settings->getIterator();
  }

  /**
   * Drupal "Function" comment.
   */
  public function save() {
    \Drupal::configFactory()->getEditable('janrain.settings')
      ->set('general_settings', $this->toJson())
      ->save();
  }

  /**
   * Drupal "Function" comment.
   */
  public static function fromDrupal() {
    // Load config from drupal if it exists.
    $settings = \Drupal::config('janrain.settings')->get('general_settings');
    $settings = empty($settings) ? '{}' : $settings;
    $out = self::fromJson($settings);
    return $out;
  }

  /**
   * Drupal "Function" comment.
   */
  public static function fromJson($json) {
    $data = json_decode($json);
    return new self($data);
  }

  /**
   * Drupal "Function" comment.
   */
  public static function getSessionItem($key) {
    return isset($_SESSION['janrain'][$key]) ? $_SESSION['janrain'][$key] : NULL;
  }

  /**
   * Drupal "Function" comment.
   */
  public static function setSessionItem($key, $value) {
    if (!isset($_SESSION['janrain'])) {
      $_SESSION['janrain'] = [];
    }
    $_SESSION['janrain'][$key] = $value;
  }

  /**
   * Drupal "Function" comment.
   */
  public static function dropSessionItem($key = NULL) {
    if ($key) {
      unset($_SESSION['janrain'][$key]);
    }
    else {
      $_SESSION['janrain'] = [];
    }
  }

  /**
   * Drupal "Function" comment.
   */
  public function getLocale() {
    global $language;
    if (isset($language->language)) {
      return str_replace('_', '-', $language->language);
    }
    // Return sensible default if language->language missing.
    return 'en-US';
  }

  /**
   * Drupal "Function" comment.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   Current request.
   */
  public function setLoginPage(Request $request) {
    // Shortcut session activation if referer is first party.
    global $base_root;
    if (FALSE !== stripos($_SERVER['HTTP_REFERER'], $base_root)) {
      return;
    }
    // Browser agent isn't sending referer headers so fire up a session to
    // remember where the login started.
    $ruri = $request->getRequestUri();
    // Don't set for requests against service endpoints.
    if (0 === stripos($ruri, '/services/') || 0 === stripos($ruri, '/janrain/registration/')) {
      // Widgets wont be rendered on service endpoints.
      return;
    }
    // Not a service endpoint, set the current page.
    self::setSessionItem('capture.currentUri', $base_root . $ruri);
  }

}
