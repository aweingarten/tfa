<?php

namespace Drupal\tfa\Form;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\tfa\TfaDataTrait;
use Drupal\tfa\TfaSetup;
use Drupal\user\Entity\User;
use Drupal\user\UserDataInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * TFA setup form router.
 */
class BasicSetup extends FormBase {
  use TfaDataTrait;

  /**
   * The TfaSetupPluginManager.
   *
   * @var \Drupal\Component\Plugin\PluginManagerInterface
   */
  protected $manager;

  /**
   * Provides the user data service object.
   *
   * @var \Drupal\user\UserDataInterface
   */
  protected $userData;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.manager.tfa.setup'),
      $container->get('user.data')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function __construct(PluginManagerInterface $manager, UserDataInterface $user_data) {
    $this->manager = $manager;
    $this->userData = $user_data;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'tfa_setup';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, User $user = NULL, $method = 'tfa_totp') {
    $plugin_definitions = $this->manager->getDefinitions();
    $account = User::load($this->currentUser()->id());

    $form['account'] = array(
      '#type' => 'value',
      '#value' => $user,
    );
    $tfa_data = $this->tfaGetTfaData($user->id(), $this->userData);
    $enabled = isset($tfa_data['status']) && $tfa_data['status'] ? TRUE : FALSE;

    $storage = $form_state->getStorage();
    // Always require a password on the first time through.
    if (empty($storage)) {
      // Allow administrators to change TFA settings for another account.
      if ($account->id() == $user->id() && $account->hasPermission('administer users')) {
        $current_pass_description = t('Enter your current password to alter TFA settings for account %name.', array('%name' => $user->getUsername()));
      }
      else {
        $current_pass_description = t('Enter your current password to continue.');
      }

      $form['current_pass'] = array(
        '#type' => 'password',
        '#title' => t('Current password'),
        '#size' => 25,
        '#required' => TRUE,
        '#description' => $current_pass_description,
        '#attributes' => array('autocomplete' => 'off'),
      );

      $form['submit'] = array(
        '#type' => 'submit',
        '#value' => t('Confirm'),
      );

      $form['cancel'] = array(
        '#type' => 'submit',
        '#value' => t('Cancel'),
        '#limit_validation_errors' => array(),
        '#submit' => array('::cancelForm'),
      );
    }
    else {
      // @todo Needs to be fixed. Use case would be multiple fallbacks.
      // if (!$enabled && empty($storage['steps'])) {
      // $storage['full_setup'] = TRUE;
      // $steps = $this->_tfa_basic_full_setup_steps($method);
      // $storage['steps_left'] = $steps;
      // $storage['steps_skipped'] = array();
      // }
      // Override provided method if operating under multi-step.
      // Deprecate
      // if (isset($storage['step_method'])) {
      // $method = $storage['step_method'];
      // }
      // Record methods progressed.
      // Deprecate
      // $storage['steps'][] = $method;.
      // $context = array('uid' => $account->id());
      $plugin_id = $method . '_setup';
      $validation_inst = \Drupal::service('plugin.manager.tfa.setup');
      $setup_plugin = $validation_inst->createInstance($plugin_id, ['uid' => $account->id()]);
      $tfa_setup = new TfaSetup($setup_plugin);
      $form = $tfa_setup->getForm($form, $form_state);
      $storage[$method] = $tfa_setup;
      // Deprecate this approach. Left here for future reference
      // switch ($method) {
      //
      //  case 'tfa_basic_trusted_browser':
      //    $context['setup_context'] = ['plugin_definition' => $plugin_definition];
      //    $form['#title'] = t('TFA setup - Trusted browsers');
      //    $setup_plugin = new TfaTrustedBrowserSetup($context);
      //    $tfa_setup = new TfaSetup($setup_plugin);
      //    $form = $tfa_setup->getForm($form, $form_state);
      //    $storage[$method] = $tfa_setup;
      //    break;
      //
      //  case 'tfa_basic_sms':
      //    $context['setup_context'] = ['plugin_definition' => $plugin_definition];
      //    $form['#title'] = t('TFA setup - SMS');
      //    // SMS itself has multiple steps. Begin with phone number entry.
      //    if (empty($storage[$method])) {
      //      $default_number = tfa_basic_get_mobile_number($account);
      //      $form['sms_number'] = array(
      //        '#type' => 'textfield',
      //        '#title' => t('Mobile phone number'),
      //        '#required' => TRUE,
      //        '#description' => t('Enter your mobile phone number that can receive SMS codes. A code will be sent to this number for validation.'),
      //        '#default_value' => $default_number ?: '',
      //      );
      //      // @todo what is this? looks like it should be user specific
      //      $phone_field = variable_get('tfa_basic_phone_field', '');
      //      if (!empty($phone_field)) {
      //        // Report that this is an account field.
      //        $field = field_info_instance('user', $phone_field, 'user');
      //        $form['sms_number']['#description'] .= ' ' . t('This number is stored on your account under field %label.', array('%label' => $field['label']));
      //      }
      //      $form['send'] = array(
      //        '#type' => 'submit',
      //        '#value' => t('Send SMS'),
      //      );
      //      if (!empty($tfa_data['data']['sms'])) {
      //        // Provide disable SMS option.
      //        $form['actions']['sms_disable'] = array(
      //          '#type' => 'submit',
      //          '#value' => t('Disable SMS delivery'),
      //          '#limit_validation_errors' => array(),
      //          '#submit' => array('::cancelForm'),
      //        );
      //      }
      //    }
      //    // Then validate by sending an SMS.
      //    else {
      //      $number = tfa_basic_format_number($storage['sms_number']);
      //      drupal_set_message(t("A code was sent to @number. It may take up to a minute for its arrival.", array('@number' => $number)));
      //      $tfa_setup = $storage[$method];
      //      $form = $tfa_setup->getForm($form, $form_state);
      //      if (isset($storage['full_setup'])) {
      //        drupal_set_message(t("If the code does not arrive or you entered the wrong number skip this step to continue without SMS delivery. You can enable it after completing the rest of TFA setup."));
      //      }
      //      else {
      //        $form['sms_code']['#description'] .= ' ' . l(t('If the code does not arrive or you entered the wrong number click here to start over.'), 'user/' . $account->uid . '/security/tfa/sms-setup');
      //      }
      //
      //      $storage[$method] = $tfa_setup;
      //    }
      //    break;
      //
      //  // List previously saved recovery codes. Note, this is not a plugin.
      //  case 'recovery_codes_list':
      //    $recovery = new TfaBasicRecoveryCodeSetup(array('uid' => $account->id()));
      //    $codes = $recovery->getCodes();
      //
      //    $output = ['#theme' => 'item_list', '#items' => $codes];
      //    $output = \Drupal::service('renderer')->render($output);
      //    $output .= Link::fromTextAndUrl(t('Return to account TFA overview'), Url::fromRoute('tfa.overview', ['user' => $account->id()]))->toString();
      //    $form['output'] = array(
      //      '#type' => 'markup',
      //      '#markup' => $output,
      //    );
      //    // Return early.
      //    return $form;
      //
      //  default:
      //    break;
      // }
      // Provide skip button under full setup.
      if (isset($storage['full_setup']) && count($storage['steps']) > 1) {
        $count = count($storage['steps_left']);
        $form['actions']['skip'] = array(
          '#type' => 'submit',
          '#value' => $count > 0 ? t('Skip') : t('Skip and finish'),
          '#limit_validation_errors' => array(),
          '#submit' => array('::cancelForm'),
        );
      }
      // Provide cancel button on first step or single steps.
      else {
        $form['actions']['cancel'] = array(
          '#type' => 'submit',
          '#value' => t('Cancel'),
          '#limit_validation_errors' => array(),
          '#submit' => array('::cancelForm'),
        );
      }
      // Record the method in progress regardless of whether in full setup.
      $storage['step_method'] = $method;
    }
    $form_state->setStorage($storage);
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $user = User::load($this->currentUser()->id());
    $storage = $form_state->getStorage();
    $values = $form_state->getValues();
    $account = $form['account']['#value'];
    if (isset($values['current_pass'])) {
      // Allow administrators to change TFA settings for another account.
      if ($account->id() != $user->id() && $user->hasPermission('administer users')) {
        $account = $user;
      }
      $current_pass = \Drupal::service('password')->check(trim($form_state->getValue('current_pass')), $account->getPassword());
      if (!$current_pass) {
        $form_state->setErrorByName('current_pass', t("Incorrect password."));
      }

      // Check password. (from user.module user_validate_current_pass()).
      //      require_once DRUPAL_ROOT . '/' . variable_get('password_inc', 'includes/password.inc');
      //      $current_pass = user_check_password($values['current_pass'], $account);
      //      if (!$current_pass) {
      //        form_set_error('current_pass', t("Incorrect password."));
      //      }.
      return;
    }
    // Handle first step of SMS setup.
    elseif (isset($values['sms_number'])) {
      // Validate number.
      $number = $values['sms_number'];
      $number_errors = tfa_basic_valid_number($number);
      if (!empty($number_errors)) {
        foreach ($number_errors as $error) {
          $form_state->setErrorByName('number', $error);
        }
      }
      return;
    }
    // Validate plugin form.
    elseif (!empty($storage['step_method'])) {
      $method = $storage['step_method'];
      $tfa_setup = $storage[$method];
      if (!$tfa_setup->validateForm($form, $form_state)) {
        foreach ($tfa_setup->getErrorMessages() as $element => $message) {
          $form_state->setErrorByName($element, $message);
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function cancelForm(array &$form, FormStateInterface $form_state) {
    $account = $form['account']['#value'];
    drupal_set_message('TFA setup canceled.', 'warning');
    $form_state->setRedirect('tfa.overview', ['user' => $account->id()]);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $account = $form['account']['#value'];
    $storage = $form_state->getStorage();
    $values = $form_state->getValues();

    // Password validation.
    if (isset($values['current_pass'])) {
      $storage['pass_confirmed'] = TRUE;
      $form_state->setRebuild();
      $form_state->setStorage($storage);
      return;
    }
    // Submitting mobile number step.
    elseif (!empty($values['sms_number'])) {
      // Send code to number.
      $storage['sms_number'] = $values['sms_number'];
      $context = array('uid' => $account->id(), 'mobile_number' => $storage['sms_number']);
      $setup_plugin = new TfaBasicSmsSetup($context, $storage['sms_number']);
      $tfa_setup = new TfaSetup($setup_plugin, $context);
      $tfa_setup->begin();
      $errors = $tfa_setup->getErrorMessages();
      if (!empty($errors)) {
        foreach ($errors as $error) {
          $form_state->setErrorByName('number', $error);
        }
      }
      else {
        // No errors so store setup.
        $storage['tfa_basic_sms'] = $tfa_setup;
      }
      $form_state->setRebuild();
      $form_state->setStorage($storage);
      return;
    }
    // Disabling SMS delivery.
    if (isset($values['sms_disable']) && $values['op'] === $values['sms_disable']) {
      $this->tfaSaveTfaData($account->id(), $this->userData, array('sms' => FALSE));
      drupal_set_message(t('TFA SMS delivery disabled.'));
      $form_state['redirect'] = 'user/' . $account->id() . '/security/tfa';
      \Drupal::logger('tfa_basic')->info('TFA SMS disabled for user @name UID @uid', array(
        '@name' => $account->name,
        '@uid' => $account->id(),
      ));
      return;
    }
    // Submitting a plugin form.
    elseif (!empty($storage['step_method'])) {
      $method = $storage['step_method'];
      $skipped_method = FALSE;

      // Support skipping optional steps when in full setup.
      if (isset($values['skip']) && $values['op'] === $values['skip']) {
        $skipped_method = $method;
        $storage['steps_skipped'][] = $method;
        unset($storage[$method]);
      }

      // Trigger multi-step if in full setup.
      if (!empty($storage['full_setup'])) {
        $this->tfaNextSetupStep($form_state, $method, $skipped_method);
      }

      // Plugin form submit.
      if (!empty($storage[$method])) {
        $setup_class = $storage[$method];
        if (!$setup_class->submitForm($form, $form_state)) {
          drupal_set_message(t('There was an error during TFA setup. Your settings have not been saved.'), 'error');
          $form_state->setRedirect('tfa.overview', ['user' => $account->id()]);
          return;
        }
      }

      // Save user TFA settings for relevant plugins that weren't skipped.
      if (empty($skipped_method) && $method == 'tfa_basic_sms' &&
        isset($storage['sms_number']) &&
        in_array('tfa_basic_sms', $storage['steps'])) {

        // Update mobile number if different than stored.
        if ($storage['sms_number'] !== tfa_basic_get_mobile_number($account)) {
          tfa_basic_set_mobile_number($account, $storage['sms_number']);
        }
        $this->tfaSaveTfaData($account->id(), $this->userData, array('sms' => TRUE));
      }

      // Return if multi-step.
      if ($form_state->getRebuildInfo()) {
        return;
      }
      // Else, setup complete and return to overview page.
      drupal_set_message(t('TFA setup complete.'));
      $form_state->setRedirect('tfa.overview', ['user' => $account->id()]);

      // Log and notify if this was full setup.
      if (!empty($storage['step_method'])) {
        // $data = array(
        //  'plugins' => array_diff($storage['steps'], $storage['steps_skipped']),
        // );.
        $data = ['plugins' => $storage['step_method']];
        $this->tfaSaveTfaData($account->id(), $this->userData, $data);
        \Drupal::logger('tfa_basic')->info('TFA enabled for user @name UID @uid',
          array(
            '@name' => $account->getUsername(),
            '@uid' => $account->id(),
          ));

        // @todo Not working, not sure why though.
        // $params = array('account' => $account);
        // \Drupal::service('plugin.manager.mail')->mail('tfa_basic', 'tfa_basic_tfa_enabled', $account->getEmail(), $account->getPreferredLangcode(), $params);
      }
    }
  }

  /**
   * Steps eligble for TFA setup.
   */
  private function tfaFullSetupSteps() {
    $steps = array();
    $plugins = array(
      'tfa_totp',
      'tfa_basic_sms',
      'tfa_basic_trusted_browser',
      'tfa_recovery_code',
    );
    $config = $this->config('tfa_basic.settings');
    foreach ($plugins as $plugin) {
      if ($plugin === $config->get('tfa_validate_plugin', '') ||
        in_array($plugin, $config->get('fallback_plugins', array())) ||
        in_array($plugin, $config->get('login_plugins', array()))) {
        $steps[] = $plugin;
      }
    }
    return $steps;
  }

  /**
   * Set form rebuild, next step, and message if any plugin steps left.
   */
  private function tfaNextSetupStep(FormStateInterface &$form_state, $this_step, $skipped_step = FALSE) {
    // @todo This needs to be refactored to make it dynamic.
    $storage = $form_state->getStorage();
    // Remove this step from steps left.
    $storage['steps_left'] = array_diff($storage['steps_left'], array($this_step));
    if (!empty($storage['steps_left'])) {
      // Contextual reporting.
      $output = FALSE;
      switch ($this_step) {
        case 'tfa_totp':
          $output = $skipped_step ? t('Application codes not enabled.') : t('Application code verified.');
          break;

        case 'tfa_basic_sms':
          $output = $skipped_step ? t('SMS code delivery not enabled.') : t('SMS code verified.');
          break;

        case 'tfa_basic_trusted_browser':
          // Handle whether the checkbox was unchecked.
          if ($skipped_step || empty($form_state['values']['trust'])) {
            $output = t('Browser not saved.');
          }
          else {
            $output = t('Browser saved.');
          }
          break;

        case 'tfa_recovery_code':
          $output = $skipped_step ? t('Recovery codes not saved.') : t('Saved recovery codes.');
          break;
      }
      $count = count($storage['steps_left']);
      $output .= ' ' . \Drupal::translation()->formatPlural($count, 'One setup step remaining.', '@count TFA setup steps remain.', array('@count' => $count));
      if ($output) {
        drupal_set_message($output);
      }

      // Set next step and mark form for rebuild.
      $next_step = array_shift($storage['steps_left']);
      $storage['step_method'] = $next_step;
      $form_state->setRebuild();
    }
    $form_state->setStorage($storage);
  }

}
