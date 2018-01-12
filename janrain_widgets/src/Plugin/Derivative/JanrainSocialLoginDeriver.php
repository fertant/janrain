<?php

namespace Drupal\janrain_widgets\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\janrain\Identity;
use Drupal\janrain_widgets\JanrainPackagesService;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;

class JanrainSocialLoginDeriver extends DeriverBase implements ContainerDeriverInterface {

  /**
   * Drupal\janrain\Identity definition.
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
   * Creates an SelectionBase object.
   *
   * @param \Drupal\janrain\Identity $identity
   *   A config factory for retrieving required config objects.
   * @param \Drupal\janrain_widgets\JanrainPackagesService $janrain_pkg
   *   Package service.
   */
  public function __construct(Identity $janrain_identity, JanrainPackagesService $janrain_pkg) {
    $this->identity = $janrain_identity;
    $this->pkg = $janrain_pkg;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id) {
    return new static(
      $container->get('janrain.identity'),
      $container->get('janrain_widgets.pkgs')
    );
  }

  public function getDerivativeDefinitions($base_plugin_definition) {
    if ($this->identity->isLoginOnly()) {
      $this->derivatives["social_login"] = $base_plugin_definition;
      $this->derivatives["social_login"]['admin_label'] = t('Janrain Social Login Block');
    }
    else {
      // Load packages.
      foreach ($this->pkg->listPkgs() as $uri => $fid) {
        $tag = basename($uri, '.zip');
        $this->derivatives["signIn_$fid"] = $base_plugin_definition;
        $this->derivatives["signIn_$fid"]['admin_label'] = t('Janrain Login (@tag)', array('@tag' => $tag));
        $this->derivatives["verifyEmail_$fid"] = $base_plugin_definition;
        $this->derivatives["verifyEmail_$fid"]['admin_label'] = t('Janrain Email Verify (@tag)', array('@tag' => $tag));
        $this->derivatives["editProfile_$fid"] = $base_plugin_definition;
        $this->derivatives["editProfile_$fid"]['admin_label'] = t('Janrain Profile (@tag)', array('@tag' => $tag));
        $this->derivatives["resetPasswordRequestCode_$fid"] = $base_plugin_definition;
        $this->derivatives["resetPasswordRequestCode_$fid"]['admin_label'] = t('Janrain Password Recover (@tag)', array('@tag' => $tag));
      }
    }

    return $this->derivatives;
  }

}
