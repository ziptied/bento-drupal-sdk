<?php

namespace Drupal\bento_sdk\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Access\AccessResult;
use Psr\Log\LoggerInterface;
use Drupal\bento_sdk\BentoSanitizationTrait;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Ajax\MessageCommand;

/**
 * Configuration form for Bento SDK settings.
 *
 * Provides an admin interface for configuring Bento API credentials
 * and other module settings.
 */
class BentoSettingsForm extends ConfigFormBase {
  use BentoSanitizationTrait;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected StateInterface $state;

  /**
   * The current user account.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected AccountInterface $currentUser;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * {@inheritdoc}
   */
  public static function create(\Symfony\Component\DependencyInjection\ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->state = $container->get('state');
    $instance->currentUser = $container->get('current_user');
    $instance->logger = $container->get('logger.channel.bento_sdk');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['bento_sdk.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'bento_sdk_settings_form';
  }

  /**
   * Custom access callback for the settings form.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account to check access for.
   *
   * @return \Drupal\Core\Access\AccessResult
   *   The access result.
   */
  public static function access(AccountInterface $account) {
    // Allow access if user has any of the Bento SDK permissions.
    $permissions = [
      'administer bento sdk',
      'view bento sdk settings',
      'edit bento sdk credentials',
      'edit bento sdk mail settings',
      'edit bento sdk validation settings',
      'edit bento sdk performance settings',
    ];

    foreach ($permissions as $permission) {
      if ($account->hasPermission($permission)) {
        return AccessResult::allowed()->cachePerPermissions();
      }
    }

    return AccessResult::forbidden()->cachePerPermissions();
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('bento_sdk.settings');
    
    // Migrate existing secret key from config to secure storage if needed.
    $this->migrateSecretKeyToSecureStorage();

    // Check permissions for different sections.
    $can_edit_credentials = $this->hasCredentialEditAccess();
    $can_edit_mail = $this->hasMailEditAccess();
    $can_edit_validation = $this->hasValidationEditAccess();
    $can_edit_performance = $this->hasPerformanceEditAccess();

    $form['api_credentials'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('API Credentials'),
      '#description' => $this->t('Enter your Bento API credentials. You can find these in your Bento account settings.'),
    ];

    if (!$can_edit_credentials) {
      $form['api_credentials']['#description'] .= ' ' . $this->t('<strong>Note:</strong> You do not have permission to modify credentials.');
    }

    $form['api_credentials']['site_uuid'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Site UUID'),
      '#description' => $this->t('Your Bento site UUID (e.g., 12345678-1234-1234-1234-123456789abc or 2103f23614d9877a6b4ee73d28a5c61d)'),
      '#default_value' => $config->get('site_uuid'),
      '#required' => TRUE,
      '#maxlength' => 36,
      '#disabled' => !$can_edit_credentials,
      '#ajax' => [
        'callback' => '::credentialsChangedCallback',
        'wrapper' => 'authors-dropdown-wrapper',
        'method' => 'replace',
        'effect' => 'fade',
        'event' => 'change',
      ],
    ];

    $form['api_credentials']['publishable_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Publishable Key'),
      '#description' => $this->t('Your Bento publishable key'),
      '#default_value' => $config->get('publishable_key'),
      '#required' => TRUE,
      '#maxlength' => 255,
      '#disabled' => !$can_edit_credentials,
      '#ajax' => [
        'callback' => '::credentialsChangedCallback',
        'wrapper' => 'authors-dropdown-wrapper',
        'method' => 'replace',
        'effect' => 'fade',
        'event' => 'change',
      ],
    ];

    $form['api_credentials']['secret_key'] = [
      '#type' => 'password',
      '#title' => $this->t('Secret Key'),
      '#description' => $this->t('Your Bento secret key. Leave blank to keep existing value. <strong>Security Note:</strong> For production environments, consider setting the BENTO_SECRET_KEY environment variable instead.'),
      '#maxlength' => 255,
      '#disabled' => !$can_edit_credentials,
      '#ajax' => [
        'callback' => '::credentialsChangedCallback',
        'wrapper' => 'authors-dropdown-wrapper',
        'method' => 'replace',
        'effect' => 'fade',
        'event' => 'change',
      ],
    ];

    // Show current secret key status if one exists.
    $secret_key_configured = $this->getSecretKeyStatus();
    if ($secret_key_configured['has_key']) {
      $form['api_credentials']['secret_key']['#description'] .= ' ' . $this->t('A secret key is currently configured via @source.', [
        '@source' => $secret_key_configured['source'],
      ]);
    }

    // Add security warning for production environments.
    $form['api_credentials']['security_warning'] = [
      '#type' => 'markup',
      '#markup' => '<div class="messages messages--warning">' . 
        $this->t('<strong>Security Recommendation:</strong> For production environments, store the secret key in the BENTO_SECRET_KEY environment variable instead of the database. This prevents the key from being exported with configuration or exposed in database backups.') . 
        '</div>',
      '#weight' => -1,
    ];

    $form['mail_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Mail Settings'),
      '#description' => $this->t('Configure how Drupal emails are handled through Bento.'),
    ];

    if (!$can_edit_mail) {
      $form['mail_settings']['#description'] .= ' ' . $this->t('<strong>Note:</strong> You do not have permission to modify mail settings.');
    }

    $form['mail_settings']['enable_mail_routing'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Route Drupal emails through Bento'),
      '#description' => $this->t('When enabled, all Drupal emails will be sent via Bento API. If Bento is unavailable, emails will fallback to the default mail system.'),
      '#default_value' => $config->get('enable_mail_routing'),
      '#disabled' => !$can_edit_mail,
    ];



    // Add author dropdown field
    $form['mail_settings']['default_author_email'] = [
      '#type' => 'select',
      '#title' => $this->t('Default author email'),
      '#description' => $this->t('Select a verified sender email address from your Bento account. This will be used as the default "from" address for emails sent through Bento.'),
      '#options' => $this->getAuthorOptions(),
      '#default_value' => $config->get('default_author_email'),
      '#disabled' => !$can_edit_mail || !$this->isConfigured(),
      '#empty_option' => $this->t('- Select an author -'),
      '#empty_value' => '',
      '#states' => [
        'visible' => [
          ':input[name="enable_mail_routing"]' => ['checked' => TRUE],
        ],
      ],
      '#prefix' => '<div id="authors-dropdown-wrapper">',
      '#suffix' => '</div>',
    ];

    // Add refresh button
    $form['mail_settings']['refresh_authors'] = [
      '#type' => 'button',
      '#value' => $this->t('Refresh Authors'),
      '#disabled' => !$can_edit_mail || !$this->isConfigured(),
      '#states' => [
        'visible' => [
          ':input[name="enable_mail_routing"]' => ['checked' => TRUE],
        ],
      ],
      '#attributes' => [
        'class' => ['button--small'],
      ],
      '#ajax' => [
        'callback' => '::refreshAuthorsCallback',
        'wrapper' => 'authors-dropdown-wrapper',
        'method' => 'replace',
        'effect' => 'fade',
        'progress' => [
          'type' => 'throbber',
          'message' => $this->t('Refreshing authors...'),
        ],
      ],
    ];

    // Add visual separator
    $form['mail_settings']['test_separator'] = [
      '#type' => 'markup',
      '#markup' => '<hr class="test-email-separator">',
      '#weight' => 10,
      '#states' => [
        'visible' => [
          ':input[name="enable_mail_routing"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Add CSS for test email section styling
    $form['mail_settings']['test_email_css'] = [
      '#type' => 'markup',
      '#markup' => '<style>
        .test-email-separator {
          margin: 20px 0;
          border: 0;
          border-top: 1px solid #ccc;
        }
        .test-email-section {
          background: #f8f9fa;
          padding: 15px;
          border-radius: 4px;
          margin-top: 15px;
        }
        #test-email-messages {
          margin-top: 10px;
        }
        #test-email-messages .messages {
          margin: 10px 0;
        }
      </style>',
      '#weight' => -1,
    ];

    // Add test email section
    $form['mail_settings']['test_email_section'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Test Email Configuration'),
      '#description' => $this->t('To test email delivery, ensure API credentials are configured and an author is selected.'),
      '#weight' => 11,
      '#states' => [
        'visible' => [
          ':input[name="enable_mail_routing"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Add test button
    $form['mail_settings']['test_email_section']['send_test_email'] = [
      '#type' => 'button',
      '#value' => $this->t('Send Test Email'),
      '#disabled' => !$this->canSendTestEmail(),
      '#attributes' => [
        'class' => ['button--primary'],
      ],
      '#ajax' => [
        'callback' => '::sendTestEmailCallback',
        'wrapper' => 'test-email-messages',
        'method' => 'replace',
        'effect' => 'fade',
        'progress' => [
          'type' => 'throbber',
          'message' => $this->t('Sending test email...'),
        ],
      ],
    ];

    // Add messages wrapper
    $form['mail_settings']['test_email_section']['test_messages'] = [
      '#type' => 'markup',
      '#markup' => '<div id="test-email-messages"></div>',
      '#weight' => 1,
    ];

    $form['validation_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Email Validation Settings'),
      '#description' => $this->t('Configure email validation using Bento\'s experimental validation API.'),
    ];

    if (!$can_edit_validation) {
      $form['validation_settings']['#description'] .= ' ' . $this->t('<strong>Note:</strong> You do not have permission to modify validation settings.');
    }

    $form['validation_settings']['enable_email_validation'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable email validation'),
      '#description' => $this->t('When enabled, emails will be validated using Bento\'s API before processing. Note: This uses an experimental API endpoint.'),
      '#default_value' => $config->get('enable_email_validation'),
      '#disabled' => !$can_edit_validation,
    ];

    $form['validation_settings']['email_validation_cache_valid_duration'] = [
      '#type' => 'number',
      '#title' => $this->t('Cache duration for valid emails (seconds)'),
      '#description' => $this->t('How long to cache valid email validation results. Default: 86400 (24 hours).'),
      '#default_value' => $config->get('email_validation_cache_valid_duration') ?: 86400,
      '#min' => 300,
      '#max' => 604800,
      '#disabled' => !$can_edit_validation,
      '#states' => [
        'visible' => [
          ':input[name="enable_email_validation"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['validation_settings']['email_validation_cache_invalid_duration'] = [
      '#type' => 'number',
      '#title' => $this->t('Cache duration for invalid emails (seconds)'),
      '#description' => $this->t('How long to cache invalid email validation results. Default: 3600 (1 hour).'),
      '#default_value' => $config->get('email_validation_cache_invalid_duration') ?: 3600,
      '#min' => 300,
      '#max' => 86400,
      '#disabled' => !$can_edit_validation,
      '#states' => [
        'visible' => [
          ':input[name="enable_email_validation"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['rate_limiting'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Rate Limiting & Performance'),
      '#description' => $this->t('Configure API rate limiting and circuit breaker settings to prevent service overload.'),
    ];

    if (!$can_edit_performance) {
      $form['rate_limiting']['#description'] .= ' ' . $this->t('<strong>Note:</strong> You do not have permission to modify performance settings.');
    }

    $form['rate_limiting']['enable_rate_limiting'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable rate limiting'),
      '#description' => $this->t('Limit the number of API requests to prevent quota exhaustion and service degradation.'),
      '#default_value' => $config->get('enable_rate_limiting') ?? TRUE,
      '#disabled' => !$can_edit_performance,
    ];

    $form['rate_limiting']['max_requests_per_minute'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum requests per minute'),
      '#description' => $this->t('Maximum number of API requests allowed per minute. Default: 60.'),
      '#default_value' => $config->get('max_requests_per_minute') ?: 60,
      '#min' => 1,
      '#max' => 300,
      '#disabled' => !$can_edit_performance,
      '#states' => [
        'visible' => [
          ':input[name="enable_rate_limiting"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['rate_limiting']['max_requests_per_hour'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum requests per hour'),
      '#description' => $this->t('Maximum number of API requests allowed per hour. Default: 1000.'),
      '#default_value' => $config->get('max_requests_per_hour') ?: 1000,
      '#min' => 10,
      '#max' => 10000,
      '#disabled' => !$can_edit_performance,
      '#states' => [
        'visible' => [
          ':input[name="enable_rate_limiting"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['rate_limiting']['max_test_emails_per_hour'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum test emails per hour'),
      '#description' => $this->t('Maximum number of test emails allowed per user per hour. Default: 5.'),
      '#default_value' => $config->get('max_test_emails_per_hour') ?: 5,
      '#min' => 1,
      '#max' => 20,
      '#disabled' => !$can_edit_performance,
      '#states' => [
        'visible' => [
          ':input[name="enable_rate_limiting"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['rate_limiting']['enable_circuit_breaker'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable circuit breaker'),
      '#description' => $this->t('Temporarily stop API requests after repeated failures to prevent cascading failures.'),
      '#default_value' => $config->get('enable_circuit_breaker') ?? TRUE,
      '#disabled' => !$can_edit_performance,
    ];

    $form['rate_limiting']['circuit_breaker_failure_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Failure threshold'),
      '#description' => $this->t('Number of consecutive failures before circuit breaker opens. Default: 5.'),
      '#default_value' => $config->get('circuit_breaker_failure_threshold') ?: 5,
      '#min' => 1,
      '#max' => 20,
      '#disabled' => !$can_edit_performance,
      '#states' => [
        'visible' => [
          ':input[name="enable_circuit_breaker"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['rate_limiting']['circuit_breaker_timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Circuit breaker timeout (seconds)'),
      '#description' => $this->t('How long to wait before attempting to close the circuit breaker. Default: 300 (5 minutes).'),
      '#default_value' => $config->get('circuit_breaker_timeout') ?: 300,
      '#min' => 60,
      '#max' => 3600,
      '#disabled' => !$can_edit_performance,
      '#states' => [
        'visible' => [
          ':input[name="enable_circuit_breaker"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['security_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Security Settings'),
      '#description' => $this->t('Configure SSL verification, timeouts, and security headers for API requests.'),
    ];

    if (!$can_edit_performance) {
      $form['security_settings']['#description'] .= ' ' . $this->t('<strong>Note:</strong> You do not have permission to modify security settings.');
    }

    $form['security_settings']['ssl_verification'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable SSL certificate verification'),
      '#description' => $this->t('Verify SSL certificates for API requests. Disable only for development environments with self-signed certificates.'),
      '#default_value' => $config->get('ssl_verification') ?? TRUE,
      '#disabled' => !$can_edit_performance,
    ];

    $form['security_settings']['request_timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Request timeout (seconds)'),
      '#description' => $this->t('Maximum time to wait for API responses. Default: 30 seconds.'),
      '#default_value' => $config->get('request_timeout') ?: 30,
      '#min' => 5,
      '#max' => 300,
      '#disabled' => !$can_edit_performance,
    ];

    $form['security_settings']['connection_timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Connection timeout (seconds)'),
      '#description' => $this->t('Maximum time to wait for initial connection. Default: 10 seconds.'),
      '#default_value' => $config->get('connection_timeout') ?: 10,
      '#min' => 1,
      '#max' => 60,
      '#disabled' => !$can_edit_performance,
    ];

    $form['security_settings']['enable_request_id_tracking'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable request ID tracking'),
      '#description' => $this->t('Add unique request IDs to API calls for audit trails and debugging.'),
      '#default_value' => $config->get('enable_request_id_tracking') ?? TRUE,
      '#disabled' => !$can_edit_performance,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $site_uuid = $form_state->getValue('site_uuid');
    $publishable_key = $form_state->getValue('publishable_key');
    $secret_key = $form_state->getValue('secret_key');

    // Validate Site UUID format.
    if ($site_uuid && !preg_match('/^([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-9a-f]{32})$/i', $site_uuid)) {
      $form_state->setErrorByName('site_uuid', $this->t('Site UUID must be in valid UUID format (with or without hyphens).'));
    }

    // Validate publishable key is not empty.
    if (empty($publishable_key)) {
      $form_state->setErrorByName('publishable_key', $this->t('Publishable key is required.'));
    }

    // Validate secret key if provided (required for new configurations).
    $current_secret_status = $this->getSecretKeyStatus();
    if (empty($secret_key) && !$current_secret_status['has_key']) {
      $form_state->setErrorByName('secret_key', $this->t('Secret key is required.'));
    }

    // Additional validation for credential changes.
    $this->validateCredentialChanges($form_state);

    // Validate security settings.
    $this->validateSecuritySettings($form_state);

    // Validate default author email if mail routing is enabled.
    $enable_mail_routing = $form_state->getValue('enable_mail_routing');
    $default_author_email = $form_state->getValue('default_author_email');
    
    if ($enable_mail_routing && empty($default_author_email)) {
      $form_state->setErrorByName('default_author_email', $this->t('Default author email is required when mail routing is enabled.'));
    }

    // Clear author selection if credentials changed.
    $config = $this->config('bento_sdk.settings');
    $old_site_uuid = $config->get('site_uuid');
    $old_publishable_key = $config->get('publishable_key');

    $new_site_uuid = $form_state->getValue('site_uuid');
    $new_publishable_key = $form_state->getValue('publishable_key');
    $secret_key = $form_state->getValue('secret_key');

    $credentials_changed = (
      $old_site_uuid !== $new_site_uuid ||
      $old_publishable_key !== $new_publishable_key ||
      !empty($secret_key)
    );

    if ($credentials_changed) {
      // Clear the selected author when credentials change.
      $form_state->setValue('default_author_email', '');
      
      // Clear the authors cache.
      try {
        $bento_service = \Drupal::service('bento.sdk');
        $bento_service->clearAuthorsCache();
      } catch (\Exception $e) {
        $this->logger->warning('Failed to clear authors cache during validation: @message', [
          '@message' => $e->getMessage(),
        ]);
      }
    }

    // Validate default author email if selected.
    $default_author_email = $form_state->getValue('default_author_email');
    if (!empty($default_author_email)) {
      if (!filter_var($default_author_email, FILTER_VALIDATE_EMAIL)) {
        $form_state->setErrorByName('default_author_email', $this->t('Selected author email is not valid.'));
      }
      
      // Validate that the selected author exists in the current list.
      $available_authors = $this->getAuthorOptions();
      if (!isset($available_authors[$default_author_email])) {
        $form_state->setErrorByName('default_author_email', $this->t('Selected author is not available. Please refresh the authors list.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('bento_sdk.settings');
    $changes = [];

    // Track credential changes.
    if ($this->hasCredentialEditAccess()) {
      $old_site_uuid = $config->get('site_uuid');
      $new_site_uuid = $form_state->getValue('site_uuid');
      if ($old_site_uuid !== $new_site_uuid) {
        $changes[] = 'site_uuid';
        $config->set('site_uuid', $new_site_uuid);
      }

      $old_publishable_key = $config->get('publishable_key');
      $new_publishable_key = $form_state->getValue('publishable_key');
      if ($old_publishable_key !== $new_publishable_key) {
        $changes[] = 'publishable_key';
        $config->set('publishable_key', $new_publishable_key);
      }
      
      // Only update secret key if a new one was provided.
      $secret_key = $form_state->getValue('secret_key');
      if (!empty($secret_key)) {
        $changes[] = 'secret_key';
        // Store secret key securely using State API instead of config.
        $this->storeSecretKeySecurely($secret_key);
        // Clear any existing config-stored secret key.
        $config->clear('secret_key');
      }
    }

    // Track mail setting changes.
    if ($this->hasMailEditAccess()) {
      $old_mail_routing = $config->get('enable_mail_routing');
      $new_mail_routing = $form_state->getValue('enable_mail_routing');
      if ($old_mail_routing !== $new_mail_routing) {
        $changes[] = 'enable_mail_routing';
        $config->set('enable_mail_routing', $new_mail_routing);
      }



      // Track author setting changes.
      $old_author_email = $config->get('default_author_email');
      $new_author_email = $form_state->getValue('default_author_email');
      if ($old_author_email !== $new_author_email) {
        $changes[] = 'default_author_email';
        $config->set('default_author_email', $new_author_email);
      }
    }

    // Track validation setting changes.
    if ($this->hasValidationEditAccess()) {
      $validation_fields = [
        'enable_email_validation',
        'email_validation_cache_valid_duration',
        'email_validation_cache_invalid_duration',
      ];

      foreach ($validation_fields as $field) {
        $old_value = $config->get($field);
        $new_value = $form_state->getValue($field);
        if ($old_value !== $new_value) {
          $changes[] = $field;
          $config->set($field, $new_value);
        }
      }
    }

    // Track performance setting changes.
    if ($this->hasPerformanceEditAccess()) {
      $performance_fields = [
        'enable_rate_limiting',
        'max_requests_per_minute',
        'max_requests_per_hour',
        'max_test_emails_per_hour',
        'enable_circuit_breaker',
        'circuit_breaker_failure_threshold',
        'circuit_breaker_timeout',
        'ssl_verification',
        'request_timeout',
        'connection_timeout',
        'enable_request_id_tracking',
      ];

      foreach ($performance_fields as $field) {
        $old_value = $config->get($field);
        $new_value = $form_state->getValue($field);
        if ($old_value !== $new_value) {
          $changes[] = $field;
          $config->set($field, $new_value);
        }
      }
    }
    
    $config->save();

    // Log configuration changes for audit trail.
    if (!empty($changes)) {
      $this->logConfigurationChanges($changes);
    }

    parent::submitForm($form, $form_state);
  }

  /**
   * Gets the author options for the dropdown.
   *
   * @return array
   *   Array of email addresses keyed by email.
   */
  private function getAuthorOptions(): array {
    if (!$this->isConfigured()) {
      return [];
    }

    try {
      $bento_service = \Drupal::service('bento.sdk');
      $authors = $bento_service->fetchAuthors();
      
      $options = [];
      foreach ($authors as $email) {
        $options[$email] = $email;
      }
      
      return $options;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to fetch authors for dropdown: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Checks if Bento is properly configured.
   *
   * @return bool
   *   TRUE if configured, FALSE otherwise.
   */
  private function isConfigured(): bool {
    try {
      $bento_service = \Drupal::service('bento.sdk');
      return $bento_service->isConfigured();
    }
    catch (\Exception $e) {
      return FALSE;
    }
  }

  /**
   * Checks if test email can be sent.
   *
   * @return bool
   *   TRUE if test email can be sent, FALSE otherwise.
   */
  private function canSendTestEmail(): bool {
    // Check if user has permission
    if (!$this->hasMailEditAccess()) {
      return FALSE;
    }

    // Check if Bento is configured
    if (!$this->isConfigured()) {
      return FALSE;
    }

    // Check if an author is selected
    $config = $this->config('bento_sdk.settings');
    $selected_author = $config->get('default_author_email');
    
    if (empty($selected_author)) {
      return FALSE;
    }

    // Validate the selected author email
    if (!filter_var($selected_author, FILTER_VALIDATE_EMAIL)) {
      return FALSE;
    }

    // Check rate limiting
    $rate_limit = $this->checkTestEmailRateLimit();
    if (!$rate_limit['allowed']) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Checks if test email rate limit is exceeded.
   *
   * @return array
   *   Array with 'allowed' boolean and 'next_allowed' timestamp.
   */
  private function checkTestEmailRateLimit(): array {
    $user_id = $this->currentUser->id();
    $cache_key = 'bento_test_email_rate_limit:' . $user_id;
    
    // Get rate limiting configuration
    $config = $this->config('bento_sdk.settings');
    $max_test_emails_per_hour = $config->get('max_test_emails_per_hour') ?: 5; // Default: 5 per hour
    
    // Get current usage
    $cached = \Drupal::cache()->get($cache_key);
    $current_usage = $cached ? $cached->data : ['count' => 0, 'reset_time' => time() + 3600];
    
    // Check if we need to reset the counter
    if (time() > $current_usage['reset_time']) {
      $current_usage = ['count' => 0, 'reset_time' => time() + 3600];
    }
    
    // Check if rate limit is exceeded
    if ($current_usage['count'] >= $max_test_emails_per_hour) {
      return [
        'allowed' => FALSE,
        'next_allowed' => $current_usage['reset_time'],
      ];
    }
    
    return [
      'allowed' => TRUE,
      'next_allowed' => $current_usage['reset_time'],
    ];
  }

  /**
   * Increments the test email rate limit counter.
   */
  private function incrementTestEmailRateLimit(): void {
    $user_id = $this->currentUser->id();
    $cache_key = 'bento_test_email_rate_limit:' . $user_id;
    
    // Get current usage
    $cached = \Drupal::cache()->get($cache_key);
    $current_usage = $cached ? $cached->data : ['count' => 0, 'reset_time' => time() + 3600];
    
    // Check if we need to reset the counter
    if (time() > $current_usage['reset_time']) {
      $current_usage = ['count' => 0, 'reset_time' => time() + 3600];
    }
    
    // Increment counter
    $current_usage['count']++;
    
    // Store updated usage
    \Drupal::cache()->set($cache_key, $current_usage, $current_usage['reset_time']);
  }

  /**
   * AJAX callback to refresh the authors dropdown.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The AJAX response.
   */
  public function refreshAuthorsCallback(array &$form, FormStateInterface $form_state) {
    $response = new AjaxResponse();

    try {
      // Clear the authors cache
      $bento_service = \Drupal::service('bento.sdk');
      $bento_service->clearAuthorsCache();

      // Fetch fresh authors
      $authors = $bento_service->fetchAuthors();

      // Update the dropdown options
      $options = [];
      foreach ($authors as $email) {
        $options[$email] = $email;
      }

      // Create the updated dropdown element
      $updated_element = [
        '#type' => 'select',
        '#title' => $this->t('Default author email'),
        '#description' => $this->t('Select a verified sender email address from your Bento account. This will be used as the default "from" address for emails sent through Bento.'),
        '#options' => $options,
        '#default_value' => $form_state->getValue('default_author_email'),
        '#disabled' => !$this->hasMailEditAccess() || !$this->isConfigured(),
        '#empty_option' => $this->t('- Select an author -'),
        '#empty_value' => '',
        '#states' => [
          'visible' => [
            ':input[name="enable_mail_routing"]' => ['checked' => TRUE],
          ],
        ],
        '#prefix' => '<div id="authors-dropdown-wrapper">',
        '#suffix' => '</div>',
      ];

      // Add success message
      $message = $this->t('Authors list refreshed successfully. Found @count authors.', [
        '@count' => count($authors),
      ]);
      $response->addCommand(new MessageCommand($message, 'status'));

      // Replace the dropdown
      $response->addCommand(new ReplaceCommand('#authors-dropdown-wrapper', \Drupal::service('renderer')->render($updated_element)));

    } catch (\Exception $e) {
      // Add error message
      $error_message = $this->t('Failed to refresh authors: @message', [
        '@message' => $e->getMessage(),
      ]);
      $response->addCommand(new MessageCommand($error_message, 'error'));

      // Log the error
      $this->logger->error('Failed to refresh authors via AJAX: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    return $response;
  }

  /**
   * AJAX callback when credentials are changed.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The AJAX response.
   */
  public function credentialsChangedCallback(array &$form, FormStateInterface $form_state) {
    $response = new AjaxResponse();

    // Clear the authors cache when credentials change
    try {
      $bento_service = \Drupal::service('bento.sdk');
      $bento_service->clearAuthorsCache();
    } catch (\Exception $e) {
      // Log but don't fail the callback
      $this->logger->warning('Failed to clear authors cache on credential change: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    // Check if we have valid credentials now
    $site_uuid = $form_state->getValue('site_uuid');
    $publishable_key = $form_state->getValue('publishable_key');
    $secret_key = $form_state->getValue('secret_key');

    $has_credentials = !empty($site_uuid) && !empty($publishable_key) && !empty($secret_key);

    if ($has_credentials) {
      // Try to fetch authors with new credentials
      try {
        $authors = $this->getAuthorOptions();
        
        // Create updated dropdown
        $updated_element = [
          '#type' => 'select',
          '#title' => $this->t('Default author email'),
          '#description' => $this->t('Select a verified sender email address from your Bento account. This will be used as the default "from" address for emails sent through Bento.'),
          '#options' => $authors,
          '#default_value' => '',
          '#disabled' => !$this->hasMailEditAccess(),
          '#empty_option' => $this->t('- Select an author -'),
          '#empty_value' => '',
          '#states' => [
            'visible' => [
              ':input[name="enable_mail_routing"]' => ['checked' => TRUE],
            ],
          ],
          '#prefix' => '<div id="authors-dropdown-wrapper">',
          '#suffix' => '</div>',
        ];

        // Add success message
        $message = $this->t('Credentials updated. Found @count authors.', [
          '@count' => count($authors),
        ]);
        $response->addCommand(new MessageCommand($message, 'status'));

        // Replace the dropdown
        $response->addCommand(new ReplaceCommand('#authors-dropdown-wrapper', \Drupal::service('renderer')->render($updated_element)));

      } catch (\Exception $e) {
        // Credentials might be invalid, show disabled dropdown
        $disabled_element = [
          '#type' => 'select',
          '#title' => $this->t('Default author email'),
          '#description' => $this->t('Configure valid API credentials first to load authors.'),
          '#options' => [],
          '#default_value' => '',
          '#disabled' => TRUE,
          '#empty_option' => $this->t('Configure API credentials first'),
          '#empty_value' => '',
          '#states' => [
            'visible' => [
              ':input[name="enable_mail_routing"]' => ['checked' => TRUE],
            ],
          ],
          '#prefix' => '<div id="authors-dropdown-wrapper">',
          '#suffix' => '</div>',
        ];

        // Add warning message
        $message = $this->t('Please configure valid API credentials to load authors.');
        $response->addCommand(new MessageCommand($message, 'warning'));

        // Replace the dropdown
        $response->addCommand(new ReplaceCommand('#authors-dropdown-wrapper', \Drupal::service('renderer')->render($disabled_element)));
      }
    } else {
      // No credentials, show disabled dropdown
      $disabled_element = [
        '#type' => 'select',
        '#title' => $this->t('Default author email'),
        '#description' => $this->t('Configure API credentials first to load authors.'),
        '#options' => [],
        '#default_value' => '',
        '#disabled' => TRUE,
        '#empty_option' => $this->t('Configure API credentials first'),
        '#empty_value' => '',
        '#states' => [
          'visible' => [
            ':input[name="enable_mail_routing"]' => ['checked' => TRUE],
          ],
        ],
        '#prefix' => '<div id="authors-dropdown-wrapper">',
        '#suffix' => '</div>',
      ];

      // Replace the dropdown
      $response->addCommand(new ReplaceCommand('#authors-dropdown-wrapper', \Drupal::service('renderer')->render($disabled_element)));
    }

    return $response;
  }

  /**
   * AJAX callback to send test email.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The AJAX response.
   */
  public function sendTestEmailCallback(array &$form, FormStateInterface $form_state) {
    $response = new AjaxResponse();

    try {
      // Check rate limiting first
      $rate_limit = $this->checkTestEmailRateLimit();
      if (!$rate_limit['allowed']) {
        $next_allowed = date('H:i:s', $rate_limit['next_allowed']);
        $error_message = $this->t('Test email rate limit exceeded. You can send another test email after @time.', [
          '@time' => $next_allowed,
        ]);
        $response->addCommand(new MessageCommand($error_message, 'warning'));
        
        // Create empty messages wrapper
        $messages_element = [
          '#type' => 'markup',
          '#markup' => '<div id="test-email-messages"></div>',
        ];
        $response->addCommand(new ReplaceCommand('#test-email-messages', \Drupal::service('renderer')->render($messages_element)));
        
        return $response;
      }

      // Validate prerequisites
      if (!$this->canSendTestEmail()) {
        $error_message = $this->t('Cannot send test email. Please ensure API credentials are configured and an author is selected.');
        $response->addCommand(new MessageCommand($error_message, 'error'));
        
        // Create empty messages wrapper
        $messages_element = [
          '#type' => 'markup',
          '#markup' => '<div id="test-email-messages"></div>',
        ];
        $response->addCommand(new ReplaceCommand('#test-email-messages', \Drupal::service('renderer')->render($messages_element)));
        
        return $response;
      }

      // Get the selected author
      $config = $this->config('bento_sdk.settings');
      $selected_author = $config->get('default_author_email');

      // Prepare test email data
      $test_email_data = [
        'to' => $selected_author,
        'subject' => 'Test Email',
        'html_body' => '<p>This is a test email from Bento SDK.</p><p>If you received this email, your Bento email configuration is working correctly.</p>',
        'text_body' => "This is a test email from Bento SDK.\n\nIf you received this email, your Bento email configuration is working correctly.",
      ];

      // Send test email
      $bento_service = \Drupal::service('bento.sdk');
      $success = $bento_service->sendTransactionalEmail($test_email_data);

      if ($success) {
        // Increment rate limit counter after successful send
        $this->incrementTestEmailRateLimit();

        // Success message
        $success_message = $this->t('Test email sent successfully to @email', [
          '@email' => $this->sanitizeEmailForLogging($selected_author),
        ]);
        $response->addCommand(new MessageCommand($success_message, 'status'));

        // Log successful test email
        $this->logger->info('Test email sent successfully to @email by user @username', [
          '@email' => $this->sanitizeEmailForLogging($selected_author),
          '@username' => $this->currentUser->getAccountName(),
        ]);

      } else {
        // Failure message
        $error_message = $this->t('Test email failed. Please check your API credentials and ensure a valid author is selected.');
        $response->addCommand(new MessageCommand($error_message, 'error'));

        // Log failed test email
        $this->logger->error('Test email failed for user @username to @email', [
          '@username' => $this->currentUser->getAccountName(),
          '@email' => $this->sanitizeEmailForLogging($selected_author),
        ]);
      }

      // Create messages wrapper with the result
      $messages_element = [
        '#type' => 'markup',
        '#markup' => '<div id="test-email-messages"></div>',
      ];
      $response->addCommand(new ReplaceCommand('#test-email-messages', \Drupal::service('renderer')->render($messages_element)));

    } catch (\Exception $e) {
      // Exception handling
      $error_message = $this->t('Test email failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      $response->addCommand(new MessageCommand($error_message, 'error'));

      // Log the exception
      $this->logger->error('Test email exception for user @username: @message', [
        '@username' => $this->currentUser->getAccountName(),
        '@message' => $e->getMessage(),
      ]);

      // Create empty messages wrapper
      $messages_element = [
        '#type' => 'markup',
        '#markup' => '<div id="test-email-messages"></div>',
      ];
      $response->addCommand(new ReplaceCommand('#test-email-messages', \Drupal::service('renderer')->render($messages_element)));
    }

    return $response;
  }

  /**
   * Gets the current secret key configuration status.
   *
   * @return array
   *   Array with 'has_key' boolean and 'source' string indicating where the key is stored.
   */
  private function getSecretKeyStatus(): array {
    // Check environment variable first (highest priority).
    if (!empty($_ENV['BENTO_SECRET_KEY']) || !empty(getenv('BENTO_SECRET_KEY'))) {
      return [
        'has_key' => TRUE,
        'source' => 'environment variable',
      ];
    }

    // Check State API (secure storage).
    if (!empty($this->state->get('bento_sdk.secret_key'))) {
      return [
        'has_key' => TRUE,
        'source' => 'secure storage',
      ];
    }

    // Check legacy config storage (deprecated).
    $config = $this->config('bento_sdk.settings');
    if (!empty($config->get('secret_key'))) {
      return [
        'has_key' => TRUE,
        'source' => 'configuration (deprecated)',
      ];
    }

    return [
      'has_key' => FALSE,
      'source' => 'none',
    ];
  }

  /**
   * Stores the secret key securely using State API.
   *
   * @param string $secret_key
   *   The secret key to store.
   */
  private function storeSecretKeySecurely(string $secret_key): void {
    // Store in State API which is not exported with configuration.
    $this->state->set('bento_sdk.secret_key', $secret_key);
    
    // Log the security improvement (without exposing the key).
    \Drupal::logger('bento_sdk')->info('Secret key updated and stored securely via State API.');
  }

  /**
   * Migrates existing secret key from config to secure storage.
   *
   * This method automatically moves any secret key stored in configuration
   * to the more secure State API storage.
   */
  private function migrateSecretKeyToSecureStorage(): void {
    $config = $this->config('bento_sdk.settings');
    $config_secret = $config->get('secret_key');
    
    // Only migrate if there's a key in config and none in secure storage.
    if (!empty($config_secret) && empty($this->state->get('bento_sdk.secret_key'))) {
      // Check if environment variable is not set (don't override env var).
      if (empty($_ENV['BENTO_SECRET_KEY']) && empty(getenv('BENTO_SECRET_KEY'))) {
        $this->storeSecretKeySecurely($config_secret);
        
        // Clear the config-stored key.
        $config->clear('secret_key')->save();
        
        \Drupal::logger('bento_sdk')->info('Migrated secret key from configuration to secure storage for improved security.');
        
        // Show a message to the admin about the migration.
        \Drupal::messenger()->addStatus($this->t('Your secret key has been automatically migrated to secure storage for improved security.'));
      }
    }
  }

  /**
   * Checks if the current user can edit credentials.
   *
   * @return bool
   *   TRUE if the user has permission to edit credentials.
   */
  private function hasCredentialEditAccess(): bool {
    return $this->currentUser->hasPermission('administer bento sdk') ||
           $this->currentUser->hasPermission('edit bento sdk credentials');
  }

  /**
   * Checks if the current user can edit mail settings.
   *
   * @return bool
   *   TRUE if the user has permission to edit mail settings.
   */
  private function hasMailEditAccess(): bool {
    return $this->currentUser->hasPermission('administer bento sdk') ||
           $this->currentUser->hasPermission('edit bento sdk mail settings');
  }

  /**
   * Checks if the current user can edit validation settings.
   *
   * @return bool
   *   TRUE if the user has permission to edit validation settings.
   */
  private function hasValidationEditAccess(): bool {
    return $this->currentUser->hasPermission('administer bento sdk') ||
           $this->currentUser->hasPermission('edit bento sdk validation settings');
  }

  /**
   * Checks if the current user can edit performance settings.
   *
   * @return bool
   *   TRUE if the user has permission to edit performance settings.
   */
  private function hasPerformanceEditAccess(): bool {
    return $this->currentUser->hasPermission('administer bento sdk') ||
           $this->currentUser->hasPermission('edit bento sdk performance settings');
  }

  /**
   * Logs configuration changes for audit trail.
   *
   * @param array $changes
   *   Array of changed configuration keys.
   */
  private function logConfigurationChanges(array $changes): void {
    $user_id = $this->currentUser->id();
    $username = $this->currentUser->getAccountName();
    $user_email = $this->currentUser->getEmail();
    
    // Log each change with user context.
    $this->logger->notice('Bento SDK configuration updated by user @username (ID: @uid, Email: @email). Changed fields: @changes', [
      '@username' => $username,
      '@uid' => $user_id,
      '@email' => $user_email ? $this->sanitizeEmailForLogging($user_email) : 'N/A',
      '@changes' => implode(', ', $changes),
    ]);

    // Log sensitive credential changes with higher severity.
    $credential_fields = ['site_uuid', 'publishable_key', 'secret_key'];
    $credential_changes = array_intersect($changes, $credential_fields);
    
    if (!empty($credential_changes)) {
      $this->logger->warning('Bento SDK credentials modified by user @username (ID: @uid). Changed credentials: @credentials', [
        '@username' => $username,
        '@uid' => $user_id,
        '@credentials' => implode(', ', $credential_changes),
      ]);
    }
  }

  /**
   * Validates credential changes for additional security.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  private function validateCredentialChanges(FormStateInterface $form_state): void {
    if (!$this->hasCredentialEditAccess()) {
      return;
    }

    $config = $this->config('bento_sdk.settings');
    $site_uuid = $form_state->getValue('site_uuid');
    $publishable_key = $form_state->getValue('publishable_key');
    $secret_key = $form_state->getValue('secret_key');

    // Check if credentials are being changed.
    $credentials_changed = (
      $config->get('site_uuid') !== $site_uuid ||
      $config->get('publishable_key') !== $publishable_key ||
      !empty($secret_key)
    );

    if ($credentials_changed) {
      // Validate that the user has sufficient permissions for credential changes.
      if (!$this->currentUser->hasPermission('edit bento sdk credentials') && 
          !$this->currentUser->hasPermission('administer bento sdk')) {
        $form_state->setErrorByName('api_credentials', $this->t('You do not have permission to modify API credentials.'));
        return;
      }

      // Log the credential change attempt.
      $this->logger->info('Credential change attempt by user @username (ID: @uid)', [
        '@username' => $this->currentUser->getAccountName(),
        '@uid' => $this->currentUser->id(),
      ]);
    }
  }

  /**
   * Validates security settings for potential issues.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  private function validateSecuritySettings(FormStateInterface $form_state): void {
    if (!$this->hasPerformanceEditAccess()) {
      return;
    }

    $ssl_verification = $form_state->getValue('ssl_verification');
    $request_timeout = $form_state->getValue('request_timeout');
    $connection_timeout = $form_state->getValue('connection_timeout');

    // Warn about disabling SSL verification.
    if (!$ssl_verification) {
      \Drupal::messenger()->addWarning($this->t('SSL certificate verification is disabled. This should only be used in development environments with self-signed certificates.'));
    }

    // Validate timeout values.
    if ($connection_timeout >= $request_timeout) {
      $form_state->setErrorByName('connection_timeout', $this->t('Connection timeout must be less than request timeout.'));
    }

    // Warn about very short timeouts.
    if ($request_timeout < 10) {
      \Drupal::messenger()->addWarning($this->t('Very short request timeouts may cause API calls to fail unnecessarily.'));
    }

    // Warn about very long timeouts.
    if ($request_timeout > 120) {
      \Drupal::messenger()->addWarning($this->t('Very long request timeouts may impact user experience if the API is slow to respond.'));
    }
  }

}