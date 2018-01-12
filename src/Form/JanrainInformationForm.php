<?php

namespace Drupal\janrain\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use janrain\Sdk as JanrainSdk;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\janrain\Identity;
use Drupal\Component\Utility\Xss;

/**
 * Janrain info forms.
 *
 * @ingroup janrain
 */
class JanrainInformationForm extends FormBase {

  /**
   * The identity service.
   *
   * @var \Drupal\janrain\Identity
   */
  protected $identity;

  /**
   * {@inheritdoc}
   */
  public function __construct(Identity $identity) {
    $this->identity = $identity;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('janrain.identity'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'janrain-info-form';
  }

  /**
   * Build info form.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Get CMS Adapter.
    $settings = JanrainSdk::instance()->getConfig();
    $form['drupal_info'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Drupal Information'),
      '#weight' => 0,
      '#collapsible' => FALSE,
    ];
    $f = &$form['drupal_info'];

    // Expose server config.
    $f['os'] = [
      '#markup' => '<p><strong>OS:</strong>&nbsp;' . PHP_OS . '</p>',
    ];
    $f['php'] = [
      '#markup' => '<p><strong>PHP Version:</strong>&nbsp;' . PHP_VERSION . '</p>',
    ];
    $f['drupal'] = [
      '#markup' => '<p><strong>Drupal Version:</strong>&nbsp;' . \Drupal::VERSION . '</p>',
    ];
    $f['module'] = [
      '#markup' => '<p><strong>Module Version:</strong>&nbsp;' . $this->identity->janrainVersion() . '</p>',
    ];

    $form['janrain_info'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Janrain SDK Settings'),
      '#description' => $this->t('Use this information to check Janrain configuration settings.  These settings are provided by the Janrain platform to assist in troubleshooting and configuration.'),
      '#collapsible' => FALSE,
      '#collapsed' => FALSE,
    ];
    // Expose Janrain config.
    foreach ($settings as $k => $v) {
      // Anonymize secrets.
      if (in_array($k, ['apiKey', 'capture.clientSecret'])) {
        $v = preg_replace('|[0-9a-zA-Z]|', '*', $v);
      }

      // Clean up the json-encoded value for legibility.
      $v = str_replace('\\/', '/', json_encode($v));
      $form['janrain_info'][strtolower($k)] = [
        '#markup' => sprintf('<p><strong>%s:</strong>&nbsp;%s</p>', Xss::filter($k), $v),
      ];
    }

    return $form;
  }

  /**
   * Form submit and save new estimation.
   *
   * @param array $form
   *   From render array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
  }

}
