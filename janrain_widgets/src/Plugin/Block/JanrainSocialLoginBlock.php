<?php

namespace Drupal\janrain_widgets\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use janrain\Sdk as JanrainSdk;
use Drupal\file\Entity\File;
use Drupal\Core\File\FileSystem;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\janrain\Identity;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\Component\Utility\Html;
use Drupal\Core\Template\TwigEnvironment;
use Drupal\Component\Render\FormattableMarkup;

/**
 * Provides a 'JanrainSocialLoginBlock' block.
 *
 * @Block(
 *  id = "janrain_social_login_block",
 *  admin_label = @Translation("Janrain social login block"),
 *  deriver = "Drupal\janrain_widgets\Plugin\Derivative\JanrainSocialLoginDeriver"
 * )
 */
class JanrainSocialLoginBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The identity service.
   *
   * @var \Drupal\janrain\Identity
   */
  protected $identity;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The current path.
   *
   * @var \Drupal\Core\Path\CurrentPathStack
   */
  protected $currentPath;

  /**
   * Drupal\Core\File\FileSystem definition.
   *
   * @var \Drupal\Core\File\FileSystem
   */
  protected $fileSystem;

  /**
   * Twig environment.
   *
   * @var \Drupal\Core\Template\TwigEnvironment
   */
  private $twig;

  /**
   * Creates a LocalActionsBlock instance.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\janrain\Identity $identity
   *   A config factory for retrieving required config objects.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Path\CurrentPathStack $current_path
   *   The current path.
   * @param \Drupal\Core\File\FileSystem $file_system
   *   File system service.
   * @param \Drupal\Core\Template\TwigEnvironment $twig
   *   Twig environment for Drupal.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, Identity $identity, ConfigFactoryInterface $config_factory, CurrentPathStack $current_path, FileSystem $file_system, TwigEnvironment $twig) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->identity = $identity;
    $this->configFactory = $config_factory->get('janrain_settings');
    $this->currentPath = $current_path;
    $this->fileSystem = $file_system;
    $this->twig = $twig;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('janrain.identity'),
      $container->get('config.factory'),
      $container->get('path.current'),
      $container->get('file_system'),
      $container->get('twig')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $build = [];
    if ($this->pluginId == 'janrain_social_login_block:social_login') {
      $build['content'] = $this->loginBlock();
    }
    else {
      $build['content'] = $this->registerBlocks(substr($this->pluginId, 27));
    }

    return $build;
  }

  /**
   * Helper function render login block.
   */
  protected function loginBlock() {
    $sdk = JanrainSdk::instance();
    if (!$sdk->EngageWidget) {
      return [
        'content' => [
          '#type' => 'markup',
          '#markup' => t('Janrain Social Login not configured.'),
        ],
      ];
    }
    $block = ['content' => ['#type' => 'markup']];
    $inlineScript = $this->twig->render(
      drupal_get_path('module', 'janrain_widgets') . '/templates/login_script.html.twig',
      [
        'dynamicJs' => $sdk->renderJs()
      ]
    );
    $block['content']['scripts'][] = [
      '#type' => 'html_tag',
      '#tag' => 'script',
      '#value' => new FormattableMarkup($inlineScript, []),
      '#attributes' => [
        'type' => 'text/javascript',
      ],
    ];

    foreach ($this->identity->getEnabledFeatures($sdk) as $feature) {
      $block['#attached']['library'][] = 'janrain_widgets/janrain_widget.' . $feature;
    }
    $block['content']['#markup'] = $sdk->EngageWidget->getHtml();

    $clean_url = $this->configFactory->get('clean_url');
    if (!empty($clean_url)) {
      $form_action = $GLOBALS['base_url'] . "/user/login";
    }
    else {
      $form_action = $GLOBALS['base_url'] . "/?q=user/login";
    }
    $block['content']['#markup'] .= '<form action="' . Html::escape($form_action) . '" style="display:none;" method="post" id="user_login"><input name="form_id" value="user_login"/></form>';

    return $block;
  }

  /**
   * Helper function render registration block.
   *
   * @param string $delta
   *   Block name.
   */
  protected function registerBlocks($delta) {
    // Parse out widget file ID.
    list($screen, $fid) = explode('_', $delta);
    $file = File::load($fid);
    if (!$file) {
      return [
        'content' => [
          '#type' => 'markup',
          '#markup' => 'Janrain Registration Widget not found.',
        ],
      ];
    }

    // Render a widget with a legit file.
    $block = [];
    // Filter editProfiles for users without them.
    if ('editProfile' == $screen && !$this->identity->userHasCaptureUuid()) {
      // Different message for admins trying to view other profiles.
      $path = $this->currentPath->getPath();
      $path_args = explode('/', $path);
      if ($path_args[0] == 'user' && ($path_args[1] != $GLOBALS['user']->uid)) {
        $block['content']['#markup'] = t('Janrain profiles are only viewable by their users.');
      }
      else {
        $block['content']['#markup'] = t('You have no capture profile to show.');
      }
      return $block;
    }

    $sdk = JanrainSdk::instance();
    $js = file_get_contents(drupal_get_path('module', 'janrain_widgets') . '/registration.js');
    $js .= $sdk->renderJs();

    $widget_folder_uri = "public://janrain_widgets/" . basename($file->getFilename(), '.zip');
    $widget_folder = $this->fileSystem->realpath($widget_folder_uri);
    if (file_exists("$widget_folder/janrain.css")) {
      $js .= "janrain.settings.capture.stylesheets = ['" . file_create_url("$widget_folder_uri/janrain.css") . "'];\n";
    }
    if (file_exists("$widget_folder/janrain-mobile.css")) {
      $js .= "janrain.settings.capture.mobileStylesheets = ['" . file_create_url("$widget_folder_uri/janrain-mobile.css") . "'];\n";
    }
    if (file_exists("$widget_folder/janrain-ie.css")) {
      $js .= "janrain.settings.capture.conditionalIEStylesheets = ['" . file_create_url("$widget_folder_uri/janrain-ie.css") . "'];\n";
    }
    $js .= sprintf("janrain.settings.capture.screenToRender = '%s';\n", Html::escape($screen));
    if ($this->identity->isLoginOnly()) {
      $js .= "janrain.settings.capture.federate = false;\n";
    }
    $block['content']['scripts'][] = [
      '#type' => 'html_tag',
      '#tag' => 'script',
      '#value' => new FormattableMarkup($js, []),
      '#attributes' => [
        'type' => 'text/javascript',
      ],
    ];
    $block['content']['scripts'][] = [
      '#type' => 'html_tag',
      '#tag' => 'script',
      '#value' => '',
      '#attributes' => [
        'type' => 'text/javascript',
        'src' => "$widget_folder_uri/janrain-init.js",
      ],
    ];
    $block['content']['scripts'][] = [
      '#type' => 'html_tag',
      '#tag' => 'script',
      '#value' => '',
      '#attributes' => [
        'type' => 'text/javascript',
        'src' => "$widget_folder_uri/janrain-utils.js",
      ],
    ];
    $block['content']['#markup'] = file_get_contents("$widget_folder_uri/screens.html");

    $clean_url = $this->configFactory->get('clean_url');
    if (!empty($clean_url)) {
      $form_action = $GLOBALS['base_url'] . "/user/login";
    }
    else {
      $form_action = $GLOBALS['base_url'] . "/?q=user/login";
    }
    // @todo generate form from Forms API to gain security and stuff
    $block['content']['#markup'] .= '<form action="' . Html::escape($form_action) . '"  style="display:none;" method="post" id="user_login"><input name="form_id" value="user_login"/></form>';
    return $block;
  }

}
