<?php

namespace Drupal\janrain;

use Drupal\user\Entity\User;
use Drupal\Core\Form\FormState;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Component\Utility\Xss;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\externalauth\AuthmapInterface;
use janrain\Sdk as JanrainSdk;
use Symfony\Component\Config\Definition\Exception\Exception;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Component\Utility\Timer;
use Drupal\Core\Extension\ModuleHandlerInterface;
use janrain\Profile;

/**
 * Controller for image style edit form.
 */
class JanrainAccountValidate {

  /**
   * The image effect manager service.
   *
   * @var \Drupal\image\ImageEffectManager
   */
  protected $userStorage;

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
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The authmap service.
   *
   * @var \Drupal\externalauth\AuthmapInterface
   */
  protected $authmap;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * Constructs an ImageStyleEditForm object.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $user_storage
   *   The user storage.
   * @param \Drupal\janrain\Identity $identity
   *   A config factory for retrieving required config objects.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\externalauth\AuthmapInterface $authmap
   *   The authmap helper service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler service.
   */
  public function __construct(EntityStorageInterface $user_storage, Identity $identity, LoggerChannelFactoryInterface $logger_factory, ConfigFactoryInterface $config_factory, AuthmapInterface $authmap, ModuleHandlerInterface $module_handler) {
    $this->userStorage = $user_storage;
    $this->identity = $identity;
    $this->loggerFactory = $logger_factory;
    $this->configFactory = $config_factory;
    $this->authmap = $authmap;
    $this->moduleHandler = $module_handler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')->getStorage('user'),
      $container->get('janrain.identity'),
      $container->get('logger.factory'),
      $container->get('config.factory'),
      $container->get('externalauth.authmap'),
      $container->get('module_handler')
    );
  }

  /**
   * Login validation handler.
   */
  public function loginValidate(&$form, &$form_state) {
    $identifiers = $this->identity->getIdentifiers();

    if (empty($identifiers)) {
      $this->loggerFactory->get('janrain')->info('{{user}} attempting to login without Janrain.', ['{{user}}' => $form_state->getValue('name')]);
      return;
    }

    // Deserialize the Janrain data from the session.
    $profiledata = json_decode(DrupalAdapter::getSessionItem('profile'), TRUE);
    $profile = new Profile($profiledata);

    // Try to find user by social identifier.
    foreach ($identifiers as $external_id) {
      $uid = $this->authmap->getUid($external_id, 'janrain');
      /** @var \Drupal\user\Entity\User $account */
      $account = User::load($uid);
      if ($account) {
        // Found user by identifier.
        $this->identity->clearSession();
        $form_state->setValue('uid', $account->uid);
        $this->loggerFactory->get('janrain')->info('{{user}} logged in by identifier.',
          ['{{user}}' => Html::escape($account->getDisplayName())]);
        return;
      }
    }

    // User not found by external id try to link on secondary credentials.
    $vemail = $profile->getFirst('$.profile.verifiedEmail');
    $vemail = valid_email_address($vemail) ? $vemail : FALSE;
    $email = $profile->getFirst('$.profile.email');
    $email = valid_email_address($email) ? $email : FALSE;
    $strict_email = $this->configFactory->get('janrain_settings')->get('user_email_verification');
    $strict_email = !empty($strict_email) ? $strict_email : TRUE;

    // No users with that identifier, find by verifiedEmail.
    $account = $vemail ? user_load_by_mail($vemail) : FALSE;
    if ($vemail && $account) {
      $this->identity->linkIdentifiers($account, NULL);
      $this->identity->clearSession();
      $form_state->setValue('uid', $account->uid);
      $this->loggerFactory->get('janrain')->info('{{user}} logged in by verified email.',
        ['{{user}}' => Html::escape($account->getDisplayName())]);
      return;
    }

    // No users by identifier or verifiedEmail, try by email.
    $account = $email ? user_load_by_mail($email) : FALSE;
    if ($email && $account) {
      // Honor Drupal email validation.  Admin accounts require verified email.
      if (!$strict_email && ($account->uid != 1)) {
        // Drupal doesn't care about email verification.
        $this->identity->linkIdentifiers($account, NULL);
        $this->identity->clearSession();
        $form_state->setValue('uid', $account->uid);
        $this->loggerFactory->get('janrain')->info('{{user}} logged in by unverified email.',
          ['{{user}}' => Html::escape($account->getDisplayName())]);
        return;
      }
      else {
        // Drupal cares about email verification.
        drupal_set_message(t('User discovered via unverified email, please enter password to prove account ownership and link accounts.'), 'warning');
      }
    }

    // Not found by linked identifier, email, or verifiedEmail do new user.
    $regform = [];
    $form_state = new FormState();
    $regform['name'] = $profile->getFirst('$.profile.preferredUsername');
    $regform['mail'] = $vemail ?: $email;
    $regform['pass']['pass1'] = user_password();
    $regform['pass']['pass2'] = $regform['values']['pass']['pass1'];
    $regform['op'] = t('Create new acount');
    $regform['janrain_identifiers'] = DrupalAdapter::getSessionItem('identifiers');
    $form_state->setValues($regform);
    \Drupal::formBuilder()->submitForm('user_register_form', $form_state);
    if ($errors = $form_state->getErrors()) {
      // couldn't user social login, show message, go to registration.
      $message = t('Unable to log you in with @id', ['@id' => Xss::filter($identifiers[0])]);
      drupal_set_message($message, 'warning', FALSE);
      // Leaves this stack, session is intact.
      $response = new RedirectResponse('user/register');
      $response->send();
    }

    // No errors with the new registration, load by the email provided and login.
    $account = user_load_by_mail($regform->getValue('mail'));
    $form_state->setValue('uid', $account->uid);
    $this->loggerFactory->get('janrain')->info('{{user}} registered.',
      ['{{user}}' => Html::escape($account->getDisplayName())]);

    // Go ahead and login.
    if (empty($account)) {
      /** @var \Drupal\user\Entity\User $account */
      $account = $this->userStorage->load($form_state->get('uid'));
    }
    user_login_finalize($account);
  }

  /**
   * Helper to validate login attempts using Janrain.
   */
  public function registerValidate(&$form, &$form_state) {
    Timer::start('janrain_login_validate');
    $token = DrupalAdapter::getSessionItem('accessToken');
    if (!$token) {
      // Native Drupal login, do nothing.
      $this->loggerFactory->get('janrain')->warning('{{user}} attempting to login without Janrain.', ['{{user}}' => $form_state->getValue('name')]);
      return;
    }

    // Access token found, time for Janrain!
    $sdk = JanrainSdk::instance();
    try {
      $profile = $sdk->CaptureApi->fetchProfileByToken($token);
    }
    catch (Exception $e) {
      // Token found but capture call failed, fail login and log everything.
      $this->loggerFactory->get('janrain')->emergency("%m\n%t", [
        $e->getMessage(),
        $e->getTraceAsString(),
      ]);
      $form_state->setError('janrain', $this->t("Unable to log in."));
      return;
    }

    // Try to login.
    // For capture this should succeed in a single iteration.
    $identifiers = $profile->getIdentifiers();
    foreach ($identifiers as $external_id) {
      $account = $this->authmap->getUid($external_id, 'janrain');
      if ($account) {
        $this->finishLoginHelper($account, $form_state, $profile);
        return;
      }
    }

    // Couldn't find existing linked user. Link or register new user.
    // Load up profile data for link/registration logic.
    // Set uuid.
    $uuid = $profile->getFirst('$.uuid');
    // Set display_name.
    $display_name = $profile->getFirst('$.displayName');
    if (empty($display_name)) {
      // Fall back to uuid for default display_name or Drupal will explode.
      $display_name = $uuid;
    }
    // Set email.
    $email = $profile->getFirst('$.email');
    // Detect verified_email.
    $verified_email = FALSE;
    $verified_string = $profile->getFirst('$.emailVerified');
    if ($verified_string && (FALSE !== strtotime($verified_string))) {
      // Verified date exists and translates to a real timestamp.
      $verified_email = $email;
    }

    // Try to find user by verified email.
    // @todo admin should detect this is required and unique in schema.
    $verified_email = valid_email_address($verified_email) ? $verified_email : FALSE;
    $account = $verified_email ? user_load_by_mail($verified_email) : FALSE;
    if ($verified_email && $account) {
      // Found user by trusted email.
      $this->loggerFactory->get('janrain')->debug('Found @name by verified email @email', [
        '@name' => $account->getDisplayName(),
        '@email' => Xss::filter($verified_email),
      ]);
      // Link them up, log them in, clear the session.
      $this->finishLoginHelper($account, $form_state, $profile);
      return;
    }

    // Fallback to insecure unverified email IF DRUPAL CONFIG ALLOWS IT!
    $strict_email = $this->configFactory->get('janrain_settings')->get('user_email_verification');
    if (!$strict_email) {
      // Drupal doesn't care if email address are verified #sosecure.
      $email = valid_email_address($email) ? $email : FALSE;
      // Lookup user by unverified email.
      $account = user_load_by_mail($email);
      // Try user login/link but skip admins when email unverified.
      if ($account && ($account->uid != 1)) {
        // Found user, link them up, log them in, clear the session.
        $this->loggerFactory->get('janrain')->debug('Found @name by email @email', [
          '@name' => $account->getDisplayName(),
          '@email' => Xss::filter($email),
        ]);
        $this->finishLoginHelper($account, $form_state, $profile);
        return;
      }
    }

    // User not found by identifiers, verified email, or unverified email
    // create new account.
    // Directly save user and then log them in using login_submit so Drupal post-
    // login hooks fire.
    // n.b. capture is authoritative.
    $account_info = [
      'name' => $display_name,
      'init' => $uuid,
      'mail' => $email,
      'access' => REQUEST_TIME,
      // Capture disables drupal based registration, so be sure to activate user.
      'status' => 1,
      'pass' => user_password(),
    ];
    try {
      $new_user = $this->userStorage->create($account_info);
      $new_user->save();
      $this->loggerFactory->get('janrain')->notice('Created user {{user}}', [
        '{{user}}' => $new_user->getDisplayName(),
      ]);
      $this->finishLoginHelper($new_user, $form_state, $profile);
      if (empty($account)) {
        /** @var \Drupal\user\Entity\User $account */
        $account = $this->userStorage->load($form_state->get('uid'));
      }
      user_login_finalize($account);
    }
    catch (\Exception $e) {
      // User save failed.
      // Should only happen if required data is missing (shouldn't happen).
      // Or if there's a uniqueness violation on display name or email.
      drupal_set_message(t('An error occured with your login. Contact your Drupal site admin to resolve.'), 'error');
      watchdog_exception('janrain', $e, NULL, [], RfcLogLevel::EMERGENCY);
    }
  }

  /**
   * Helper function to finish login validation and cleanup.
   */
  protected function finishLoginHelper(&$account, &$form_state, &$profile) {
    // Make sure identifiers are linked so logins are faster next time.
    $this->identity->linkIdentifiers($account, $profile);
    // Tell Drupal who's logging in.
    $form_state->setValue('uid', $account->get('uid'));
    // Store profile for downstream processors (mapping module).
    $form_state->setValue('janrainProfile', $profile);
    // Invoke standard module event API.
    $this->moduleHandler->invokeAll('janrain_profile_updated', [$profile, $account]);
    // Clean up the session data. To reduce session loads on DB.
    $this->identity->clearSession();
    $perf = Timer::stop('janrain_login_validate');
    \Drupal::state()->set('janrain_perf', sprintf("%fms", $perf['time']));
  }

}
