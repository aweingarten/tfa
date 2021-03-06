<?php

namespace Drupal\tfa\Plugin\TfaValidation;

use Base32\Base32;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Site\Settings;
use Drupal\tfa\Plugin\TfaBasePlugin;
use Drupal\tfa\Plugin\TfaValidationInterface;
use Drupal\tfa\TfaDataTrait;
use Drupal\user\UserDataInterface;
use Otp\GoogleAuthenticator;
use Otp\Otp;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * HOTP validation class for performing HOTP validation.
 *
 * @TfaValidation(
 *   id = "tfa_hotp",
 *   label = @Translation("TFA Hotp"),
 *   description = @Translation("TFA Hotp Validation Plugin"),
 *   fallbacks = {
 *    "tfa_recovery_code"
 *   }
 * )
 */
class TfaHotp extends TfaBasePlugin implements TfaValidationInterface {
  use DependencySerializationTrait;
  use TfaDataTrait;

  /**
   * Object containing the external validation library.
   *
   * @var GoogleAuthenticator
   */
  protected $auth;

  /**
   * The time-window in which the validation should be done.
   *
   * @var int
   */
  protected $timeSkew;

  /**
   * Whether the code has already been used or not.
   *
   * @var bool
   */
  protected $alreadyAccepted;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, UserDataInterface $user_data) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $user_data);
    $this->auth      = new \StdClass();
    $this->auth->otp = new Otp();
    $this->auth->ga  = new GoogleAuthenticator();
    // Allow codes within tolerance range of 3 * 30 second units.
    $this->timeSkew = \Drupal::config('tfa.settings')->get('time_skew');
    // Recommended: set variable tfa_totp_secret_key in settings.php.
    $this->encryptionKey   = \Drupal::config('tfa.settings')->get('secret_key');
    $this->alreadyAccepted = FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('user.data')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function ready() {
    return ($this->getSeed() !== FALSE);
  }

  /**
   * {@inheritdoc}
   */
  public function getForm(array $form, FormStateInterface $form_state) {
    $message = 'Verification code is application generated and @length digits long.';
    if ($this->getUserData('tfa', 'tfa_recovery_code', $this->uid, $this->userData) && $this->getFallbacks()) {
      $message .= '<br/>Can not access your account? Use one of your recovery codes.';
    }
    $form['code']             = array(
      '#type'        => 'textfield',
      '#title'       => t('Application verification code'),
      '#description' => t($message, array('@length' => $this->codeLength)),
      '#required'    => TRUE,
      '#attributes'  => array('autocomplete' => 'off'),
    );
    $form['actions']['#type'] = 'actions';
    $form['actions']['login'] = array(
      '#type'  => 'submit',
      '#value' => t('Verify'),
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array $form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    // dpm($values);
    if (!$this->validate($values['code'])) {
      $form_state->setErrorByName('code', t('Invalid application code. Please try again.'));
      if ($this->alreadyAccepted) {
        $form_state->setErrorByName('code', t('Invalid code, it was recently used for a login. Please wait for the application to generate a new code.'));
      }
      return FALSE;
    }
    else {
      // Store accepted code to prevent replay attacks.
      $this->storeAcceptedCode($values['code']);
      return TRUE;
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function validate($code) {
    // Strip whitespace.
    $code = preg_replace('/\s+/', '', $code);
    if ($this->alreadyAcceptedCode($code)) {
      $this->isValid = FALSE;
    }
    else {
      // Get OTP seed.
      $seed          = $this->getSeed();
      $counter       = $this->getHotpCounter();
      $this->isValid = ($seed && ($counter = $this->auth->otp->checkHotpResync(Base32::decode($seed), ++$counter, $code)));
      $this->setUserData('tfa', ['tfa_hotp_counter' => $counter], $this->uid, $this->userData);
    }
    return $this->isValid;
  }

  /**
   * Store validated code to prevent replay attack.
   *
   * @param string $code
   *    The validated code.
   */
  protected function storeAcceptedCode($code) {
    $code = preg_replace('/\s+/', '', $code);
    $hash = hash('sha1', Settings::getHashSalt() . $code);

    // Store the hash made using the code in users_data.
    // @todo Use the request time to say something like 'code was requested at ..'?
    $store_data = ['tfa_accepted_code_' . $hash => REQUEST_TIME];
    $this->setUserData('tfa', $store_data, $this->uid, $this->userData);
  }

  /**
   * Whether code has already been used.
   *
   * @param string $code
   *    The code to be checked.
   *
   * @return bool
   *    TRUE if already used otherwise FALSE
   */
  protected function alreadyAcceptedCode($code) {
    $hash = hash('sha1', Settings::getHashSalt() . $code);

    // Check if the code has already been used or not.
    $key    = 'tfa_accepted_code_' . $hash;
    $result = $this->getUserData('tfa', $key, $this->uid, $this->userData);

    if (!empty($result)) {
      $this->alreadyAccepted = TRUE;
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Get seed for this account.
   *
   * @return string
   *    Decrypted account OTP seed or FALSE if none exists.
   */
  protected function getSeed() {
    // Lookup seed for account and decrypt.
    $result = $this->getUserData('tfa', 'tfa_hotp_seed', $this->uid, $this->userData);

    if (!empty($result)) {
      $encrypted = base64_decode($result['seed']);
      $seed      = $this->decrypt($encrypted);
      if (!empty($seed)) {
        return $seed;
      }
    }
    return FALSE;
  }

  /**
   * Save seed for account.
   *
   * @param string $seed
   *   Un-encrypted seed.
   */
  public function storeSeed($seed) {
    // Encrypt seed for storage.
    $encrypted = $this->encrypt($seed);

    $record = [
      'tfa_hotp_seed' => [
        'seed' => base64_encode($encrypted),
        'created' => REQUEST_TIME,
      ],
    ];
    $this->setUserData('tfa', $record, $this->uid, $this->userData);
  }

  /**
   * Delete the seed of the current validated user.
   */
  public function deleteSeed() {
    $this->deleteUserData('tfa', 'tfa_hotp_seed', $this->uid, $this->userData);
  }

  /**
   * {@inheritdoc}
   */
  public function getFallbacks() {
    return ($this->pluginDefinition['fallbacks']) ?: '';
  }

  /**
   * Get the HOTP counter.
   *
   * @return int
   *   The current value of the HOTP counter, or 1 if no value was found
   */
  public function getHotpCounter() {
    $result = ($this->getUserData('tfa', 'tfa_hotp_counter', $this->uid, $this->userData)) ?: -1;

    return $result;
  }

}
