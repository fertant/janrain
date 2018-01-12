<?php

namespace Drupal\janrain_widgets\Form;

use Drupal\janrain_widgets\JanrainPackagesService;
use Drupal\janrain\Identity;
use Drupal\Component\Utility\Xss;
use Symfony\Component\DependencyInjection\ContainerInterface;
use janrain\Sdk as JanrainSdk;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Controller for image style edit form.
 */
class JanrainSettingsFormAlter implements ContainerInjectionInterface {

  /**
   * The identity service.
   *
   * @var \Drupal\janrain\Identity
   */
  protected $identity;

  /**
   * The janrain package service.
   *
   * @var \Drupal\janrain_widgets\JanrainPackagesService
   */
  protected $pkg;

  /**
   * Constructs an ImageStyleEditForm object.
   *
   * @param \Drupal\janrain\Identity $identity
   *   A config factory for retrieving required config objects.
   * @param \Drupal\janrain_widgets\JanrainPackagesService $janrain_pkg
   *   Package service.
   */
  public function __construct(Identity $identity, JanrainPackagesService $janrain_pkg) {
    $this->identity = $identity;
    $this->pkg = $janrain_pkg;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('janrain.identity'),
      $container->get('janrain_widgets.pkgs')
    );
  }

  /**
   * Login validation handler.
   */
  public function mainSettingsFormAlter(array &$form, FormStateInterface &$form_state) {
    $form['janrain_widgets'] = [
      '#type' => 'fieldset',
      '#title' => t('Registration Packages'),
      '#collapsible' => TRUE,
      '#collapsed' => FALSE,
      '#weight' => 3,
      '#description' => t('Manage your Janrain Registration packages and Drupal block placement.'),
      '#states' => [
        'visible' => [
          ':input[name="janrain_product"]' => ['value' => 1],
        ],
      ],
    ];

    $form['janrain_widgets']['package_upload'] = [
      '#type' => 'fieldset',
      '#title' => t('Upload'),
      '#collapsed' => FALSE,
      '#collapsible' => FALSE,
      '#weight' => 0,
    ];

    $form['janrain_widgets']['package_upload']['new_pkg'] = [
      '#type' => 'managed_file',
      '#title' => t('Upload Janrain Registration Package'),
      '#description' => t('After uploading the file, save this form.'),
      '#size' => '100',
      '#upload_validators' => [
        'file_validate_extensions' => ['zip'],
        'janrain_widgets_file_validator' => [],
      ],
      '#upload_location' => 'public://janrain_widgets',
      '#weight' => 0,
    ];

    $form['janrain_widgets']['packages'] = [
      '#type' => 'fieldset',
      '#title' => t('Manage'),
      '#collapsible' => FALSE,
      '#collapsed' => FALSE,
      '#weight' => 1,
      '#description' => t('Download or remove your Janrain Registration packages.'),
    ];

    // Manage existing widgets.
    foreach ($this->pkg->listPkgs() as $uri => $fid) {
      $basename = basename($uri, '.zip');
      $form['janrain_widgets']['packages']["pkg_{$fid}"] = [
        '#type' => 'managed_file',
        '#title' => Xss::filter($basename),
        '#default_value' => $fid,
        '#process' => array_merge(
          [['Drupal\file\Element\ManagedFile', 'processManagedFile']],
          [[get_class($this), 'mfProcess']]
        ),
        '#description' => t('Janrain blocks created for this package will be tagged with ({{name}})', ['{{name}}' => $basename]),
      ];
    }

    $form['#attributes'] = ['enctype' => "multipart/form-data"];
    $form['#submit'][] = ['Drupal\janrain_widgets\Form\JanrainSettingsFormAlter', 'formSubmitInstance'];
  }

  /**
   * Process the managed file widget for active packages.
   *
   * When a user deletes a managed file they should not immediately be presented
   * with an upload interface in this context. This disables access to upload
   * features and reminds that the form must be submitted to persist the removal.
   */
  public function mfProcess($element, &$form_state, $form) {
    $element['upload_button']['#access'] = FALSE;
    $element['upload']['#access'] = FALSE;
    if ($element['fid']['#value'] == 0) {
      $element['#title'] .= ' will be deleted when you save this form.';
    }
    return $element;
  }

  /**
   * Login validation instance.
   */
  public static function formSubmitInstance(array &$form, FormStateInterface &$form_state) {
    $instance = \Drupal::classResolver()->getInstanceFromDefinition('Drupal\janrain_widgets\Form\JanrainSettingsFormAlter');
    $instance->formSubmit($form, $form_state);
  }

  /**
   * Settings form submit.
   *
   * @param array $form
   *   Form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form setting values.
   */
  protected function formSubmit(array &$form, FormStateInterface &$form_state) {
    $sdk = JanrainSdk::instance();
    $config = $sdk->getConfig();

    // Short-circuit for login-only.
    if ($this->identity->isLoginOnly()) {
      drupal_flush_all_caches();
      unset($sdk->CaptureWidget);
      $config->save();
      return;
    }
    unset($sdk->EngageWidget);

    // Install new package.
    $new_fid = $form_state['values']['new_pkg'];
    if ($new_fid) {
      $new_pkg = $this->pkg->savePkg($new_fid);
      if (!$this->pkg->installPkg($new_pkg)) {
        drupal_set_message(t('An error occured while installing a package, please check watchdog.'), 'error');
      }
    }

    // Purge removed widgets.
    foreach ($form_state->getValues() as $name => $value) {
      sscanf($name, 'pkg_%d', $file_fid);
      // Skip non-file entries.
      if (!$file_fid) {
        continue;
      }

      // Process file.
      if ($file_fid && 0 === $value) {
        if (!$this->pkg->removePkg($file_fid)) {
          drupal_set_message(t('An error occured while deleting a package, please check watchdog.'), 'error');
        }
      }
    }
    // Ensure package state is consistent and clear cache.
    if (!$this->pkg->discoverPkgs()) {
      drupal_set_message(t('An error occured with package discovery, please check watchdog.'), 'error');
    }
    // Flush caches and save.  Needs to clear blocks, pages, themes, when
    // updating available widgets.
    drupal_flush_all_caches();
    $config->save();
  }

}
