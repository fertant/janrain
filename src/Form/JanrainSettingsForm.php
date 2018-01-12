<?php

namespace Drupal\janrain\Form;

use Drupal\Core\Form\ConfigFormBase;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Form\FormStateInterface;
use janrain\Sdk as JanrainSdk;
use Drupal\janrain\DrupalAdapter;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\janrain\Identity;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Component\Utility\Xss;
use Symfony\Component\Config\Definition\Exception\Exception;
use Drupal\Component\Utility\Html;

/**
 * Defines a form that configures forms module settings.
 */
class JanrainSettingsForm extends ConfigFormBase {

  /**
   * The identity service.
   *
   * @var \Drupal\janrain\Identity
   */
  protected $identity;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * Constructs a \Drupal\system\ConfigFormBase object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\janrain\Identity $identity
   *   A config factory for retrieving required config objects.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler service.
   */
  public function __construct(ConfigFactoryInterface $config_factory, Identity $identity, LoggerChannelFactoryInterface $logger_factory, ModuleHandlerInterface $module_handler) {
    parent::__construct($config_factory);
    $this->identity = $identity;
    $this->loggerFactory = $logger_factory;
    $this->moduleHandler = $module_handler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('janrain.identity'),
      $container->get('logger.factory'),
      $container->get('module_handler')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'janrain-settings-form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'janrain.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, Request $request = NULL) {
    $config = JanrainSdk::instance()->getConfig();

    // Core settings.
    $form['janrain_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Janrain Product'),
      '#weight' => 0,
      '#collapsible' => TRUE,
      '#collapsed' => FALSE,
    ];

    $janrain_product = $this->configFactory->get('janrain.settings')->get('janrain_product');
    $janrain_product = !empty($janrain_product) ? $janrain_product : DrupalAdapter::SKU_SOCIAL_LOGIN;
    $form['janrain_settings']['janrain_product'] = [
      '#type' => 'radios',
      '#title' => $this->t('Product'),
      '#default_value' => $janrain_product,
      '#options' => [
        DrupalAdapter::SKU_SOCIAL_LOGIN => $this->t('Social Login'),
        DrupalAdapter::SKU_STANDARD => $this->t('Social Login + Registration'),
      ],
      '#description' => $this->t('Select the Janrain Product to be used for this integration.'),
    ];

    $social_login = [
      '#type' => 'fieldset',
      '#title' => $this->t('Social Login Settings'),
      '#collapsible' => TRUE,
      '#collapsed' => FALSE,
      '#tree' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="janrain_product"]' => [
            'value' => DrupalAdapter::SKU_SOCIAL_LOGIN,
          ],
        ],
      ],
    ];

    $social_login['apiKey'] = [
      '#type' => 'password',
      '#title' => $this->t('API Key (Secret)'),
      '#description' => $this->t('Enter the API Key (Secret) that appears in the Janrain Dashboard Social Login (Engage) Application Settings.'),
      // Can't use default_value because Drupal.
      '#attributes' => ['value' => $config->getItem('apiKey')],
    ];

    $social_data = [
      '#type' => 'fieldset',
      '#title' => $this->t('Social Login + Registration Settings'),
      '#collapsible' => TRUE,
      '#collapsed' => FALSE,
      '#tree' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="janrain_product"]' => [
            ['value' => DrupalAdapter::SKU_STANDARD],
          ],
        ],
      ],
    ];
    $social_data['capture.captureServer'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Registration (Capture) Application URL'),
      '#description' => $this->t('Enter the URL (with protocol) of your Registration (Capture) application.  For example: https://myapplication.janraincapture.com'),
      '#default_value' => $config->getItem('capture.captureServer'),
    ];
    $social_data['capture.clientId'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Client ID'),
      '#description' => $this->t('Enter the Registration (Capture) application Client ID from the Janrain Dashboard.'),
      '#default_value' => $config->getItem('capture.clientId'),
      '#element_validate' => [
        '::cleanSettingValidate',
      ],
    ];
    $social_data['capture.clientSecret'] = [
      '#type' => 'password',
      '#title' => $this->t('Client Secret'),
      '#description' => $this->t('Enter the Registration (Capture) application Client Secret from the Janrain Dashboard.'),
      // Can't use default_value because Drupal.
      '#attributes' => ['value' => $config->getItem('capture.clientSecret')],
      '#element_validate' => [
        '::cleanSettingValidate',
      ],
    ];

    // Add Settings to Form.
    $form['janrain_social_login'] = $social_login;

    // Add Settings for Standard and Enterprise.
    $form['janrain_social_data'] = $social_data;

    return parent::buildForm($form, $form_state);
  }

  /**
   * Helper to clean ui settings and remove potential dangerous characters.
   */
  public function cleanSettingValidate($element, &$form_state) {
    if ($element['#value'] !== Html::escape($element['#value'])) {
      $form_state->setError($element, $this->t('Invalid characters detected'));
    }
  }

  /**
   * Validate callback for the settings form.
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    foreach ($form_state->getValue('janrain_social_data') as &$val) {
      $val = trim($val);
    }
    $product = $form_state->getValue('janrain_product');

    // Immediately set mode to ensure later validations against
    // isLoginOnly() don't blow up.
    if (DrupalAdapter::SKU_SOCIAL_LOGIN == $product) {
      $this->config('janrain.settings')->set('janrain_product', DrupalAdapter::SKU_SOCIAL_LOGIN)->save();
    }
    else {
      $this->config('janrain.settings')->set('janrain_product', DrupalAdapter::SKU_STANDARD)->save();
    }

    if ($this->identity->isLoginOnly()) {
      $this->settingsFormValidateLoginOnly($form, $form_state);
    }
    else {
      $this->settingsFormValidateRegistration($form, $form_state);
    }
  }

  /**
   * Validate callback for login settings form submit.
   */
  protected function settingsFormValidateLoginOnly(array $form, FormStateInterface &$form_state) {
    $sdk = JanrainSdk::instance();
    $config = $sdk->getConfig();
    $sdk->addFeatureByName('EngageApi');
    unset($sdk->CaptureApi);
    unset($sdk->CaptureWidget);
    if ($this->moduleHandler->moduleExists('janrain_widgets')) {
      $sdk->addFeatureByName('EngageWidget');
    }
    foreach ($form_state->getValue('janrain_social_login') as $k => $v) {
      $sdk->getConfig()->setItem($k, $v);
    }
    $sdk->getConfig()->setItem('token_uri', $GLOBALS['base_url'] . '/janrain/social-login/token');

    // Collect errors from user inputs.
    $config_errors = $sdk->getValidationErrors();
    if (empty($config_errors['apiKey'])) {
      // No user errors, attempt to load janrain settings.
      try {
        $sdk->EngageApi->fetchSettings($config->getItem('apiKey'));
      }
      catch (Exception $e) {
        $form_state->setError(
          $form['janrain_social_login']['apiKey'],
          $this->t('Unable to fetch Janrain settings: {{message}}.  Details in watchdog.',
            ['{{message}}' => $e->getMessage()]));
        $msg = sprintf("%s\n%s", Xss::filter($e->getMessage()), Xss::filter($e->getTraceAsString()));
        $this->loggerFactory->get('janrain_admin_ui')->critical(nl2br($msg));
      }
    }
    else {
      // User errors, highlight what's bad.
      foreach ($config_errors as $setting_name => $err_codes) {
        $form_state->setError(
          $form['janrain_social_login'][$setting_name],
          $this->t('Setting %name failed validation. (%code)', [
            '%name' => $setting_name,
            '%code' => implode(',', $err_codes),
          ])
        );
      }
      // Bail without validating janrain provided settings.
      return;
    }
    // Perform one last check on janrain settings.
    $config_errors = $sdk->getValidationErrors();
    foreach ($config_errors as $setting_name => $err_codes) {
      $msg = $this->t('Setting %name failed validation. (%codes)', [
        '%name' => Html::escape($setting_name),
        '%codes' => Xss::filter(implode(',', $err_codes)),
      ]);
      drupal_set_message($msg, 'error');
    }
  }

  /**
   * Validate callback for registration settings from submit.
   */
  protected function settingsFormValidateRegistration(array $form, FormStateInterface &$form_state) {
    $sdk = JanrainSdk::instance();
    $settings = $sdk->getConfig();
    $settings->setItem('features', []);
    $sdk->addFeatureByName('EngageApi');
    $sdk->addFeatureByName('CaptureApi');

    $version_string = sprintf('Drupal/%s Janrain/%s ', VERSION, $this->identity->janrainVersion());
    $sdk->EngageApi->getTransport()->setUserAgent($version_string, TRUE);
    $sdk->CaptureApi->getTransport()->setUserAgent($version_string, TRUE);

    unset($sdk->EngageWidget);
    if ($this->moduleHandler->moduleExists('janrain_widgets')) {
      $sdk->addFeatureByName('CaptureWidget');
    }

    foreach ($form_state->getValue('janrain_social_data') as $k => $v) {
      $settings->setItem($k, $v);
    }
    $settings->setItem('capture.redirectUri', $GLOBALS['base_url'] . '/janrain/social-login/token');

    // Collect errors from user inputs.
    $config_errors = $sdk->getValidationErrors();
    if (empty($config_errors['capture.captureServer'])
      && empty($config_errors['capture.clientId'])
      && empty($config_errors['capture.clientSecret'])) {
      // No user errors, try to grab janrain settings.
      try {
        $sdk->CaptureApi->settingsItems();

        // Make sure the schema is collecting email and that it's unique.
        $user_type = $sdk->CaptureApi->entityType();
        $email_attr = array_values(array_filter($user_type['attr_defs'], function (&$val) {
          return 'email' == $val['name'];
        }));
        // Does email exist in schema?
        $email_exists = !empty($email_attr[0]);
        // Does email field contain proper field constraints?
        $email_constraints = ['required', 'unique', 'email-address'];
        $email_constrained = !count(array_diff($email_constraints, $email_attr[0]['constraints']));
        $email_good = $email_exists && $email_constrained;
        if (!$email_good) {
          drupal_set_message($this->t('Drupal requires emails and email uniqueness. Please add the "required" and "unique" constraints to your schema.'), 'warning');
        }
      }
      catch (Exception $e) {
        // Failed call to capture, usually bad app url or invalid client/secret.
        $form_state->setError($form['janrain_social_data'], $this->t("Error talking to Janrain: {{error}}", ['{{error}}' => $e->getMessage()]));
        watchdog_exception('janrain_admin_ui', $e, NULL, [], RfcLogLevel::EMERGENCY);
      }
    }
    else {
      // Errors!
      foreach ($config_errors as $setting_name => $err_codes) {
        $form_state->setError(
          $form['janrain_social_data'][$setting_name],
          $this->t('Setting %name failed validation. (%code)', [
            '%name' => $setting_name,
            '%code' => implode(',', $err_codes),
          ])
        );
      }
      // Bail without validating janrain settings.
      return;
    }
    // Allow show janrain errors, but don't block submit.
    $config_errors = $sdk->getValidationErrors();
    foreach ($config_errors as $setting_name => $err_codes) {
      $msg = $this->t('Setting %name failed validation. (%codes)', [
        '%name' => $setting_name,
        '%codes' => Xss::filter(implode(',', $err_codes)),
      ]);
      drupal_set_message($msg, 'error');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Validate should have populated the settings, just save them.
    JanrainSdk::instance()->getConfig()->save();
    // Need to flush the services cache and the module includes.
    drupal_flush_all_caches();
  }

}
