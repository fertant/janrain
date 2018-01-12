<?php

namespace Drupal\janrain_widgets\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use janrain\Sdk as JanrainSdk;
use Drupal\Component\Utility\Xss;

/**
 * Class WidgetsSettingsForm.
 */
class WidgetsSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'janrain_widgets.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'widgets_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = JanrainSdk::instance()->getConfig();
    // SSO Settings.
    $form['sso'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Single Sign On'),
      '#weight' => 0,
      '#collapsible' => FALSE,
      '#collapsed' => FALSE,
    ];
    $form['sso']['sso_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Single Sign On'),
      '#description' => $this->t('Check to enable Janrain Single Sign On.'),
      '#default_value' => (int) in_array('FederateWidget', $config->getItem('features')),
    ];
    $form['sso']['sso_segment'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Your SSO segment name'),
      '#description' => $this->t('The SSO segment name from your Janrain SSO configuration (http://developers.janrain.com/how-to/single-sign-on/segments/)'),
      '#default_value' => Xss::filter($config->getItem('sso.segment')),
      '#states' => [
        'visible' => [
          ':input[name="sso_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];
    $segments = $config->getItem('sso.supportedSegments') ?: [];
    $form['sso']['sso_supported_segments'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Your SSO supported segment names'),
      '#description' => $this->t('The SSO supported segment names from your Janrain SSO configuration (http://developers.janrain.com/how-to/single-sign-on/segments/)'),
      '#default_value' => Xss::filter(implode(', ', $segments)),
      '#states' => [
        'visible' => [
          ':input[name="sso_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Backplane Settings.
    $form['bp'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Backplane'),
      '#weight' => 1,
      '#collapsible' => FALSE,
      '#collapsed' => FALSE,
    ];
    $form['bp']['bp_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Backplane'),
      '#description' => $this->t('Check to enable Backplane on your Drupal site.'),
      '#default_value' => (int) in_array('BackplaneWidget', $config->getItem('features')),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    $sdk = JanrainSdk::instance();
    $config = $sdk->getConfig();

    // Federate.
    if (1 === $form_state->getValue('sso_enabled')) {
      // Activate federate settings for widgets.
      $sdk->addFeatureByName('FederateWidget');

      // Process federate segment.
      $segment = trim($form_state->getValue('sso_segment'));
      $segment = ctype_alnum($segment);
      if ($segment && !ctype_alnum($segment)) {
        // Invalid characters in segment.
        $form_state->setError($form['sso']['sso_segment'], $this->t('Invalid segment name'));
      }
      else {
        // No error.
        $config->setItem('sso.segment', $segment);
      }

      // Process federate segments.
      $supported = trim($form_state->getValue('sso_supported_segments'));
      // Get unique segment list from user input.
      $segments = array_unique(preg_split('|[,\\s]+|', $supported, NULL, PREG_SPLIT_NO_EMPTY));
      // Reduce to simple everything is good or something is bad.
      $allgood = array_reduce($segments, function ($carry, $item) {
        // Check for valid segment names.
        return $carry && ctype_alnum($item);
      }, TRUE);
      if ($allgood) {
        $config->setItem('sso.supportedSegments', $segments);
      }
      else {
        $form_state->setError($form['sso']['sso_supported_segments'], $this->t('Invalid supported segments name'));
      }
    }
    else {
      unset($sdk->FederateWidget);
    }

    // Backplane.
    if (1 === $form_state->getValue('bp_enabled')) {
      // Check that the app is configured to support BP.
      $bpserver = $config->getItem('backplane.serverBaseUrl');
      $bpbus = $config->getItem('backplane.busName');
      if (filter_var($bpserver, FILTER_VALIDATE_URL, FILTER_FLAG_HOST_REQUIRED)
        && !empty($bpbus)) {
        $sdk->addFeatureByName('BackplaneWidget');
      }
      else {
        $form_state->setError($form['bp']['bp_enabled'], $this->t('Backplane server is invalid.'));
      }
    }
    else {
      unset($sdk->BackplaneWidget);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);
    // Save Janrain settings.
    JanrainSdk::instance()->getConfig()->save();
  }

}
