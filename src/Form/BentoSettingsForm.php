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
  protected $state;

  /**
   * The current user account.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

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
       'use bento sdk test events',
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
      '#type' => 'details',
      '#title' => $this->t('API Credentials'),
      '#description' => $this->t('Enter your Bento API credentials. You can find these in your Bento account settings.'),
      '#open' => !$this->isConfigured(),
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
      '#type' => 'details',
      '#title' => $this->t('Mail Settings'),
      '#description' => $this->t('Configure how Drupal emails are handled through Bento.'),
      '#open' => FALSE,
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
    $form['mail_settings']['#attached']['html_head'][] = [
      [
        '#tag' => 'style',
        '#value' => '
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
          .test-email-state {
            margin-top: 10px;
            padding: 8px 12px;
            border-radius: 4px;
            font-size: 0.9em;
          }
          .test-email-state:empty {
            display: none;
          }
          #test-email-button:disabled {
            opacity: 0.6;
            cursor: not-allowed;
          }
          #test-email-button:not(:disabled) {
            background-color: #0071b8;
            border-color: #0071b8;
          }
        ',
      ],
      'bento-sdk-test-email-styles'
    ];

    // Add isolated test email section with basic JavaScript
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

    // Add button state display
    $form['mail_settings']['test_email_section']['button_state'] = [
      '#type' => 'markup',
      '#markup' => '<div id="test-email-button-state" class="test-email-state">' . 
        ($this->canSendTestEmail() ? $this->t('Ready to send test email.') : $this->t('Please configure API credentials and select an author first.')) . 
        '</div>',
      '#weight' => 0,
    ];

    // Add isolated test email button
    $form['mail_settings']['test_email_section']['send_test_email'] = [
      '#type' => 'button',
      '#value' => $this->t('Send Test Email'),
      '#disabled' => !$this->canSendTestEmail(),
      '#attributes' => [
        'class' => ['button--primary'],
        'id' => 'isolated-test-email-btn',
      ],
      '#weight' => 1,
    ];

    // Add messages container
    $form['mail_settings']['test_email_section']['test_messages'] = [
      '#type' => 'markup',
      '#markup' => '<div id="test-email-messages" style="margin-top: 10px;"></div>',
      '#weight' => 2,
    ];

    // Attach external JavaScript file for test email functionality
    $form['mail_settings']['test_email_section']['#attached']['library'][] = 'bento_sdk/test-email';

    // Test Events section - for admin testing of event queueing
    $form['test_events'] = [
      '#type' => 'details',
      '#title' => $this->t('Test Events'),
      '#description' => $this->t('Queue sample events for testing Bento integration. Only administrators can access this section.'),
      '#open' => FALSE,
      '#access' => $this->hasTestEventsAccess(),
    ];

    if ($this->hasTestEventsAccess()) {
      $form['test_events']['event_types'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Select event types to queue'),
        '#description' => $this->t('Choose which sample events to add to the processing queue.'),
        '#options' => $this->getTestEventTypes(),
        '#default_value' => array_keys($this->getTestEventTypes()), // All checked by default
      ];

      $form['test_events']['queue_test_events'] = [
        '#type' => 'button',
        '#value' => $this->t('Queue Test Events'),
        '#disabled' => !$this->isConfigured(),
        '#attributes' => [
          'class' => ['button--primary'],
        ],
        '#ajax' => [
          'callback' => '::queueTestEventsAjaxCallback',
          'wrapper' => 'test-events-messages',
          'method' => 'replace',
          'effect' => 'fade',
          'progress' => [
            'type' => 'throbber',
            'message' => $this->t('Queueing test events...'),
          ],
        ],
      ];

      // Add status display similar to test email
      $form['test_events']['queue_status'] = [
        '#type' => 'markup',
        '#markup' => '<div id="test-events-status" class="test-email-state"></div>',
        '#weight' => 5,
      ];

      // Add messages container for AJAX responses
      $form['test_events']['messages'] = [
        '#type' => 'markup',
        '#markup' => '<div id="test-events-messages"></div>',
        '#weight' => 10,
      ];

      if (!$this->isConfigured()) {
        $form['test_events']['queue_test_events']['#description'] = $this->t('Configure API credentials first to queue test events.');
      }
    }







    // Webform Integration Settings
    $form['webform_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Webform Integration'),
      '#description' => $this->t('Configure how webform submissions are processed and sent to Bento. Event types are automatically generated from webform machine names (e.g., "contact_form" becomes "$contact_form").'),
      '#open' => FALSE,
    ];

    if (!$can_edit_performance) {
      $form['webform_settings']['#description'] .= ' ' . $this->t('<strong>Note:</strong> You do not have permission to modify webform settings.');
    }

    $form['webform_settings']['enable_webform_integration'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Webform Integration'),
      '#description' => $this->t('Automatically send Bento events when webforms are submitted. Each webform will generate events with unique event types based on their machine names. Requires the Webform module to be installed.'),
      '#default_value' => $config->get('enable_webform_integration') ?? TRUE,
      '#disabled' => !$can_edit_performance || !\Drupal::moduleHandler()->moduleExists('webform'),
    ];

    if (!\Drupal::moduleHandler()->moduleExists('webform')) {
      $form['webform_settings']['enable_webform_integration']['#description'] .= ' ' . $this->t('<strong>Note:</strong> Webform module is not installed.');
    }

    // Add informational markup about automatic event type generation
    $form['webform_settings']['event_type_info'] = [
      '#type' => 'markup',
      '#markup' => '<div class="messages messages--info">' . 
        $this->t('<strong>Automatic Event Types:</strong> Event types are automatically generated from webform machine names. For example:<br>• "contact_form" → "$contact_form"<br>• "newsletter_signup" → "$newsletter_signup"<br>• "product-inquiry" → "$product_inquiry"<br>This ensures each webform has a unique event type in Bento.') . 
        '</div>',
      '#states' => [
        'visible' => [
          ':input[name="enable_webform_integration"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Add visual separator for test section
    $form['webform_settings']['test_separator'] = [
      '#type' => 'markup',
      '#markup' => '<hr class="test-webform-separator">',
      '#weight' => 10,
      '#states' => [
        'visible' => [
          ':input[name="enable_webform_integration"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Add CSS for test webform section styling
    $form['webform_settings']['#attached']['html_head'][] = [
      [
        '#tag' => 'style',
        '#value' => '
          .test-webform-separator {
            margin: 20px 0;
            border: 0;
            border-top: 1px solid #ccc;
          }
          .test-webform-section {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 4px;
            margin-top: 15px;
          }
          #test-webform-messages {
            margin-top: 10px;
          }
          #test-webform-messages .messages {
            margin: 10px 0;
          }
          .test-webform-state {
            margin-top: 10px;
            padding: 8px 12px;
            border-radius: 4px;
            font-size: 0.9em;
          }
          .test-webform-state:empty {
            display: none;
          }
          #test-webform-button:disabled {
            opacity: 0.6;
            cursor: not-allowed;
          }
          #test-webform-button:not(:disabled) {
            background-color: #0071b8;
            border-color: #0071b8;
          }
        ',
      ],
      'bento-sdk-test-webform-styles'
    ];

    // Add test webform event section
    $form['webform_settings']['test_webform_section'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Test Webform Event'),
      '#description' => $this->t('Test the webform integration by sending a sample webform submission event to Bento.'),
      '#weight' => 11,
      '#states' => [
        'visible' => [
          ':input[name="enable_webform_integration"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Add button state display
    $form['webform_settings']['test_webform_section']['button_state'] = [
      '#type' => 'markup',
      '#markup' => '<div id="test-webform-button-state" class="test-webform-state">' . 
        ($this->canSendTestWebformEvent() ? $this->t('Ready to send test webform event.') : $this->t('Please configure API credentials and enable webform integration first.')) . 
        '</div>',
      '#weight' => 0,
    ];

    // Add test webform event button
    $form['webform_settings']['test_webform_section']['send_test_webform_event'] = [
      '#type' => 'button',
      '#value' => $this->t('Send Test Webform Event'),
      '#disabled' => !$this->canSendTestWebformEvent(),
      '#attributes' => [
        'class' => ['button--primary'],
        'id' => 'test-webform-button',
      ],
      '#weight' => 1,
    ];

    // Add messages container
    $form['webform_settings']['test_webform_section']['test_messages'] = [
      '#type' => 'markup',
      '#markup' => '<div id="test-webform-messages"></div>',
      '#weight' => 2,
    ];

    // Attach JavaScript library for test webform functionality
    $form['webform_settings']['test_webform_section']['#attached']['library'][] = 'bento_sdk/test-webform';

    // Commerce Integration Settings - Add comprehensive configuration UI
    $this->addCommerceIntegrationSection($form, $config);

    // Attach AJAX library to ensure AJAX functionality works
    $form['#attached']['library'][] = 'core/drupal.ajax';
    
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

    // Validate Commerce integration settings.
    $commerce_enabled = $form_state->getValue('enabled');
    if ($commerce_enabled && !\Drupal::moduleHandler()->moduleExists('commerce')) {
      $form_state->setErrorByName('enabled', $this->t('Commerce integration cannot be enabled because the Commerce module is not installed.'));
    }

    // Validate cart abandonment settings if Commerce integration is enabled.
    if ($commerce_enabled) {
      $threshold_hours = $form_state->getValue(['cart_abandonment', 'threshold_hours']);
      if ($threshold_hours !== NULL && ($threshold_hours < 1 || $threshold_hours > 720)) {
        $form_state->setErrorByName('cart_abandonment][threshold_hours', 
          $this->t('Cart abandonment threshold must be between 1 and 720 hours (30 days).'));
      }
      
      $check_interval = $form_state->getValue(['cart_abandonment', 'check_interval']);
      if ($check_interval !== NULL && ($check_interval < 15 || $check_interval > 1440)) {
        $form_state->setErrorByName('cart_abandonment][check_interval', 
          $this->t('Check interval must be between 15 and 1440 minutes (24 hours).'));
      }

      $batch_size = $form_state->getValue(['cart_abandonment', 'batch_size']);
      if ($batch_size !== NULL && ($batch_size < 10 || $batch_size > 500)) {
        $form_state->setErrorByName('cart_abandonment][batch_size', 
          $this->t('Batch size must be between 10 and 500 carts.'));
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
        'enable_webform_integration',
      ];

      foreach ($performance_fields as $field) {
        $old_value = $config->get($field);
        $new_value = $form_state->getValue($field);
        if ($old_value !== $new_value) {
          $changes[] = $field;
          $config->set($field, $new_value);
        }
      }

      // Track Commerce integration setting changes.
      $commerce_enabled_old = $config->get('commerce_integration.enabled');
      $commerce_enabled_new = $form_state->getValue('enabled');
      if ($commerce_enabled_old !== $commerce_enabled_new) {
        $changes[] = 'commerce_integration.enabled';
        $config->set('commerce_integration.enabled', $commerce_enabled_new);
      }

      // Track Commerce event type changes.
      $event_types = ['cart_created', 'cart_updated', 'purchase', 'order_paid', 'order_fulfilled', 'order_cancelled', 'cart_abandoned'];
      foreach ($event_types as $event_type) {
        $old_value = $config->get("commerce_integration.event_types.{$event_type}");
        $new_value = $form_state->getValue($event_type);
        if ($old_value !== $new_value) {
          $changes[] = "commerce_integration.event_types.{$event_type}";
          $config->set("commerce_integration.event_types.{$event_type}", $new_value);
        }
      }

          // Track cart abandonment setting changes.
    $cart_abandonment_fields = ['threshold_hours', 'check_interval', 'batch_size'];
    foreach ($cart_abandonment_fields as $field) {
      $old_value = $config->get("commerce_integration.cart_abandonment.{$field}");
      $new_value = $form_state->getValue(['cart_abandonment', $field]);
      if ($old_value !== $new_value) {
        $changes[] = "commerce_integration.cart_abandonment.{$field}";
        $config->set("commerce_integration.cart_abandonment.{$field}", $new_value);
      }
    }

      // Track data enrichment setting changes.
      $enrichment_fields = ['include_product_details', 'include_customer_context', 'include_order_context'];
      foreach ($enrichment_fields as $field) {
        $old_value = $config->get("commerce_integration.data_enrichment.{$field}");
        $new_value = $form_state->getValue($field);
        if ($old_value !== $new_value) {
          $changes[] = "commerce_integration.data_enrichment.{$field}";
          $config->set("commerce_integration.data_enrichment.{$field}", $new_value);
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
   * Add Commerce integration section to the form.
   *
   * Creates a comprehensive configuration interface for Commerce integration
   * with individual event toggles, cart abandonment settings, data enrichment
   * options, and testing functionality.
   *
   * @param array $form
   *   The form array to modify.
   * @param \Drupal\Core\Config\Config $config
   *   The configuration object.
   */
  private function addCommerceIntegrationSection(array &$form, $config): void {
    $commerce_available = \Drupal::moduleHandler()->moduleExists('commerce');
    $can_edit_performance = $this->hasPerformanceEditAccess();
    
    $form['commerce_integration'] = [
      '#type' => 'details',
      '#title' => $this->t('Drupal Commerce Integration'),
      '#description' => $commerce_available 
        ? $this->t('Configure automatic tracking of Commerce events.')
        : $this->t('Drupal Commerce module is not installed. Install Commerce to enable eCommerce tracking.'),
      '#open' => FALSE,
      '#access' => TRUE, // Always show section for visibility
    ];

    if (!$commerce_available) {
      $form['commerce_integration']['commerce_not_available'] = [
        '#type' => 'markup',
        '#markup' => '<div class="messages messages--warning">' . 
          $this->t('The Drupal Commerce module is not installed. Please install and enable Commerce to use eCommerce event tracking features.') . 
          '</div>',
      ];
      return;
    }

    if (!$can_edit_performance) {
      $form['commerce_integration']['#description'] .= ' ' . $this->t('<strong>Note:</strong> You do not have permission to modify Commerce settings.');
    }

    // Main integration toggle
    $form['commerce_integration']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Commerce Integration'),
      '#default_value' => $config->get('commerce_integration.enabled') ?? FALSE,
      '#description' => $this->t('Automatically track Commerce events and send them to Bento.'),
      '#disabled' => !$can_edit_performance,
    ];

    // Event type toggles
    $form['commerce_integration']['event_types'] = [
      '#type' => 'details',
      '#title' => $this->t('Event Types'),
      '#description' => $this->t('Choose which Commerce events to track and send to Bento.'),
      '#open' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $event_types = [
      'cart_created' => [
        'title' => $this->t('Cart Created'),
        'description' => $this->t('Track when customers create new carts by adding their first item.'),
      ],
      'cart_updated' => [
        'title' => $this->t('Cart Updated'),
        'description' => $this->t('Track when customers add, remove, or modify items in their cart.'),
      ],
      'purchase' => [
        'title' => $this->t('Purchase'),
        'description' => $this->t('Track when customers complete their order placement.'),
      ],
      'order_paid' => [
        'title' => $this->t('Order Paid'),
        'description' => $this->t('Track when payment is successfully completed for an order.'),
      ],
      'order_fulfilled' => [
        'title' => $this->t('Order Fulfilled'),
        'description' => $this->t('Track when orders are marked as fulfilled/shipped.'),
      ],
      'order_cancelled' => [
        'title' => $this->t('Order Cancelled'),
        'description' => $this->t('Track when orders are cancelled.'),
      ],
      'cart_abandoned' => [
        'title' => $this->t('Cart Abandoned'),
        'description' => $this->t('Track when carts are abandoned after a period of inactivity.'),
      ],
    ];

    foreach ($event_types as $event_key => $event_info) {
      $form['commerce_integration']['event_types'][$event_key] = [
        '#type' => 'checkbox',
        '#title' => $event_info['title'],
        '#default_value' => $config->get("commerce_integration.event_types.{$event_key}") ?? ($event_key === 'cart_abandoned' ? FALSE : TRUE),
        '#description' => $event_info['description'],
        '#disabled' => !$can_edit_performance,
      ];
    }



    // Data enrichment settings
    $form['commerce_integration']['data_enrichment'] = [
      '#type' => 'details',
      '#title' => $this->t('Data Enrichment'),
      '#description' => $this->t('Configure what additional data to include with Commerce events.'),
      '#open' => FALSE,
      '#states' => [
        'visible' => [
          ':input[name="enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $enrichment_options = [
      'include_product_details' => [
        'title' => $this->t('Include Product Details'),
        'description' => $this->t('Include detailed product information (categories, attributes, images).'),
      ],
      'include_customer_context' => [
        'title' => $this->t('Include Customer Context'),
        'description' => $this->t('Include customer lifetime value, order history, and demographics.'),
      ],
      'include_order_context' => [
        'title' => $this->t('Include Order Context'),
        'description' => $this->t('Include shipping, payment, and discount information.'),
      ],

    ];

    foreach ($enrichment_options as $option_key => $option_info) {
      $default_value = $config->get("commerce_integration.data_enrichment.{$option_key}");
      if ($default_value === NULL) {
        $default_value = TRUE; // All remaining options default to true
      }

      $form['commerce_integration']['data_enrichment'][$option_key] = [
        '#type' => 'checkbox',
        '#title' => $option_info['title'],
        '#default_value' => $default_value,
        '#description' => $option_info['description'],
        '#disabled' => !$can_edit_performance,
      ];
    }

    // Integration status
    $form['commerce_integration']['status'] = [
      '#type' => 'details',
      '#title' => $this->t('Integration Status'),
      '#description' => $this->t('Current status of your Commerce integration.'),
      '#open' => FALSE,
    ];

    $this->addCommerceIntegrationStatus($form['commerce_integration']['status'], $config);
  }

  /**
   * Add Commerce integration status information.
   *
   * Displays current status indicators for Commerce integration including
   * module availability, configuration status, and queue health.
   *
   * @param array $form
   *   The form section to add status to.
   * @param \Drupal\Core\Config\Config $config
   *   The configuration object.
   */
  private function addCommerceIntegrationStatus(array &$form, $config): void {
    $status_items = [];
    
    // Check if Commerce is available
    $commerce_available = \Drupal::moduleHandler()->moduleExists('commerce');
    $status_items[] = [
      'status' => $commerce_available ? 'success' : 'error',
      'message' => $commerce_available 
        ? $this->t('Drupal Commerce module is installed')
        : $this->t('Drupal Commerce module is not installed'),
    ];

    // Check if integration is enabled
    $integration_enabled = $config->get('commerce_integration.enabled');
    $status_items[] = [
      'status' => $integration_enabled ? 'success' : 'warning',
      'message' => $integration_enabled 
        ? $this->t('Commerce integration is enabled')
        : $this->t('Commerce integration is disabled'),
    ];

    // Check if Bento is configured
    $bento_configured = $this->isConfigured();
    $status_items[] = [
      'status' => $bento_configured ? 'success' : 'error',
      'message' => $bento_configured 
        ? $this->t('Bento SDK is properly configured')
        : $this->t('Bento SDK is not configured'),
    ];

    // Add CSS for status indicators
    $form['#attached']['html_head'][] = [
      [
        '#tag' => 'style',
        '#value' => '
          .status-success { color: #28a745; font-weight: bold; }
          .status-warning { color: #ffc107; font-weight: bold; }
          .status-error { color: #dc3545; font-weight: bold; }
          .commerce-integration-section {
            border: 1px solid #ddd;
            padding: 1rem;
            margin: 1rem 0;
            border-radius: 4px;
          }
        ',
      ],
      'bento-sdk-commerce-integration-styles'
    ];

    $form['status_list'] = [
      '#theme' => 'item_list',
      '#items' => array_map(function($item) {
        return [
          '#markup' => '<span class="status-' . $item['status'] . '">• ' . $item['message'] . '</span>',
        ];
      }, $status_items),
    ];
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
    * Checks if test webform event can be sent.
    *
    * @return bool
    *   TRUE if test webform event can be sent, FALSE otherwise.
    */
   private function canSendTestWebformEvent(): bool {
     // Check if user has permission
     if (!$this->hasPerformanceEditAccess()) {
       return FALSE;
     }

     // Check if Bento is configured
     if (!$this->isConfigured()) {
       return FALSE;
     }

     // Check if webform integration is enabled
     $config = $this->config('bento_sdk.settings');
     if (!$config->get('enable_webform_integration')) {
       return FALSE;
     }

     // Check rate limiting (reuse test email rate limiting)
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
   * AJAX callback for send test webform event button.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The AJAX response.
   */
  public function sendTestWebformEventCallback(array &$form, FormStateInterface $form_state) {
    $response = new AjaxResponse();

    try {
      // Validate permissions
      if (!$this->hasPerformanceEditAccess()) {
        $error_message = $this->t('You do not have permission to send test webform events.');
        $response->addCommand(new MessageCommand($error_message, 'error'));
        return $response;
      }

      // Validate configuration
      if (!$this->isConfigured()) {
        $error_message = $this->t('Please configure API credentials before sending test webform events.');
        $response->addCommand(new MessageCommand($error_message, 'error'));
        return $response;
      }

      // Check if webform integration is enabled
      $config = $this->config('bento_sdk.settings');
      if (!$config->get('enable_webform_integration')) {
        $error_message = $this->t('Webform integration is disabled. Please enable it first.');
        $response->addCommand(new MessageCommand($error_message, 'error'));
        return $response;
      }

      // Check rate limiting
      $rate_limit = $this->checkTestEmailRateLimit();
      if (!$rate_limit['allowed']) {
        $next_allowed = date('H:i:s', $rate_limit['next_allowed']);
        $error_message = $this->t('Rate limit exceeded. You can send another test event at @time.', [
          '@time' => $next_allowed,
        ]);
        $response->addCommand(new MessageCommand($error_message, 'warning'));
        return $response;
      }

      // Get the current user's email for the test event
      $user_email = $this->currentUser->getEmail();
      if (empty($user_email)) {
        $user_email = 'test@example.com';
      }

      // Send the test webform event using the service method
      $bento_service = \Drupal::service('bento.sdk');
      $success = $bento_service->sendTestWebformEvent($user_email);

      if ($success) {
        // Update rate limiting counter
        $this->incrementTestEmailCounter();

        $success_message = $this->t('Test webform event sent successfully! Check your Bento dashboard to verify the event was received.');
        $response->addCommand(new MessageCommand($success_message, 'status'));

        // Log the successful test
        $this->logger->info('Test webform event sent successfully by user @username (ID: @uid)', [
          '@username' => $this->currentUser->getAccountName(),
          '@uid' => $this->currentUser->id(),
        ]);
      }
      else {
        $error_message = $this->t('Failed to send test webform event. Please check the logs for more details.');
        $response->addCommand(new MessageCommand($error_message, 'error'));

        // Log the failure
        $this->logger->warning('Test webform event failed for user @username (ID: @uid)', [
          '@username' => $this->currentUser->getAccountName(),
          '@uid' => $this->currentUser->id(),
        ]);
      }

    }
    catch (\Exception $e) {
      $error_message = $this->t('An error occurred while sending the test webform event: @message', [
        '@message' => $e->getMessage(),
      ]);
      $response->addCommand(new MessageCommand($error_message, 'error'));

      // Log the exception
      $this->logger->error('Exception while sending test webform event: @message', [
        '@message' => $e->getMessage(),
      ]);
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

   /**
    * Checks if the current user has access to test events functionality.
    *
    * @return bool
    *   TRUE if the user has permission to use test events.
    */
   private function hasTestEventsAccess(): bool {
     return $this->currentUser->hasPermission('administer bento sdk') ||
            $this->currentUser->hasPermission('use bento sdk test events');
   }

   /**
    * Gets the available test event types.
    *
    * @return array
    *   Array of event types keyed by machine name with human-readable labels.
    */
   private function getTestEventTypes(): array {
     return [
       'completed_onboarding' => $this->t('Completed Onboarding'),
       'purchase' => $this->t('Purchase'),
       'user_registration' => $this->t('User Registration'),
       'newsletter_signup' => $this->t('Newsletter Signup'),
       'product_view' => $this->t('Product View'),
       'cart_abandonment' => $this->t('Cart Abandonment'),
       'subscription_started' => $this->t('Subscription Started'),
       'subscription_cancelled' => $this->t('Subscription Cancelled'),
     ];
   }

   /**
    * AJAX callback for queue test events button.
    *
    * @param array $form
    *   The form array.
    * @param \Drupal\Core\Form\FormStateInterface $form_state
    *   The form state.
    *
    * @return \Drupal\Core\Ajax\AjaxResponse
    *   The AJAX response.
    */
   public function queueTestEventsAjaxCallback(array &$form, FormStateInterface $form_state) {
     $response = new AjaxResponse();

     try {
       // Validate permissions
       if (!$this->hasTestEventsAccess()) {
         $error_message = $this->t('You do not have permission to queue test events.');
         $response->addCommand(new MessageCommand($error_message, 'error'));
         $status_markup = '<div class="messages messages--error">✗ Permission denied.</div>';
         $response->addCommand(new ReplaceCommand('#test-events-status', $status_markup));
         return $response;
       }

       // Validate configuration
       if (!$this->isConfigured()) {
         $error_message = $this->t('Please configure API credentials before queuing test events.');
         $response->addCommand(new MessageCommand($error_message, 'error'));
         $status_markup = '<div class="messages messages--error">✗ API not configured.</div>';
         $response->addCommand(new ReplaceCommand('#test-events-status', $status_markup));
         return $response;
       }

       // Get selected events
       $selected_events = array_filter($form_state->getValue('event_types', []));
       if (empty($selected_events)) {
         $error_message = $this->t('Please select at least one event type to queue.');
         $response->addCommand(new MessageCommand($error_message, 'error'));
         $status_markup = '<div class="messages messages--error">✗ No events selected.</div>';
         $response->addCommand(new ReplaceCommand('#test-events-status', $status_markup));
         return $response;
       }

       // Queue the events
       $queued_count = 0;
       $failed_count = 0;
       $queue_manager = \Drupal::service('bento_sdk.queue_manager');

       foreach ($selected_events as $event_type) {
         $sample_data = $this->getSampleEventData($event_type);
         
         if ($queue_manager->queueEvent($sample_data)) {
           $queued_count++;
         } else {
           $failed_count++;
         }
       }

       // Provide feedback messages
       if ($queued_count > 0) {
         $success_message = $this->t('Successfully queued @count test events.', [
           '@count' => $queued_count,
         ]);
         $response->addCommand(new MessageCommand($success_message, 'status'));
       }

       if ($failed_count > 0) {
         $warning_message = $this->t('Failed to queue @count test events. Check the logs for details.', [
           '@count' => $failed_count,
         ]);
         $response->addCommand(new MessageCommand($warning_message, 'warning'));
       }

       // Update status display
       $total_selected = count($selected_events);
       if ($queued_count === $total_selected && $failed_count === 0) {
         $status_message = $this->t('✓ All @count test events queued successfully.', [
           '@count' => $queued_count,
         ]);
         $status_class = 'messages messages--status';
       } elseif ($queued_count > 0 && $failed_count > 0) {
         $status_message = $this->t('⚠ @queued of @total events queued (@failed failed).', [
           '@queued' => $queued_count,
           '@total' => $total_selected,
           '@failed' => $failed_count,
         ]);
         $status_class = 'messages messages--warning';
       } elseif ($failed_count === $total_selected) {
         $status_message = $this->t('✗ Failed to queue all @count test events.', [
           '@count' => $total_selected,
         ]);
         $status_class = 'messages messages--error';
       } else {
         $status_message = $this->t('No events were processed.');
         $status_class = 'messages messages--warning';
       }

       $status_markup = '<div class="' . $status_class . '">' . $status_message . '</div>';
       $response->addCommand(new ReplaceCommand('#test-events-status', $status_markup));

       // Log the action for audit trail
       $this->logger->info('Test events queued via AJAX by user @username (ID: @uid). Queued: @queued, Failed: @failed', [
         '@username' => $this->currentUser->getAccountName(),
         '@uid' => $this->currentUser->id(),
         '@queued' => $queued_count,
         '@failed' => $failed_count,
       ]);

     } catch (\Exception $e) {
       $error_message = $this->t('An error occurred while queuing test events: @message', [
         '@message' => $e->getMessage(),
       ]);
       $response->addCommand(new MessageCommand($error_message, 'error'));
       
       $status_markup = '<div class="messages messages--error">✗ System error occurred.</div>';
       $response->addCommand(new ReplaceCommand('#test-events-status', $status_markup));

       $this->logger->error('Exception while queuing test events via AJAX: @message', [
         '@message' => $e->getMessage(),
       ]);
     }

     return $response;
   }

   /**
    * Gets sample event data for a given event type.
    *
    * @param string $event_type
    *   The event type machine name.
    *
    * @return array
    *   Sample event data structure.
    */
   private function getSampleEventData(string $event_type): array {
     // Base sample data - using admin user's email for testing
     $admin_email = $this->currentUser->getEmail() ?: 'admin@example.com';
     
     $base_data = [
       'type' => $event_type,
       'email' => $admin_email,
       'fields' => [
         'first_name' => 'Test',
         'last_name' => 'User',
         'source' => 'drupal_test_events',
       ],
     ];

     // Add event-specific details based on type
     switch ($event_type) {
       case 'completed_onboarding':
         $base_data['details'] = [
           'onboarding_step' => 'profile_complete',
           'completion_date' => date('Y-m-d H:i:s'),
         ];
         break;

       case 'purchase':
         $base_data['details'] = [
           'order_id' => 'TEST-' . time(),
           'total' => 99.99,
           'currency' => 'USD',
           'items' => [
             [
               'name' => 'Sample Product',
               'price' => 99.99,
               'quantity' => 1,
             ],
           ],
         ];
         break;

       case 'user_registration':
         $base_data['details'] = [
           'registration_date' => date('Y-m-d H:i:s'),
           'user_agent' => 'Drupal Test Event',
         ];
         break;

       case 'newsletter_signup':
         $base_data['details'] = [
           'signup_source' => 'admin_test',
           'preferences' => ['weekly_newsletter', 'product_updates'],
         ];
         break;

       case 'product_view':
         $base_data['details'] = [
           'product_id' => 'SAMPLE-PRODUCT-123',
           'product_name' => 'Sample Product',
           'category' => 'Test Category',
           'price' => 49.99,
         ];
         break;

       case 'cart_abandonment':
         $base_data['details'] = [
           'cart_id' => 'CART-' . time(),
           'cart_total' => 149.99,
           'items_count' => 2,
           'abandonment_time' => date('Y-m-d H:i:s'),
         ];
         break;

       case 'subscription_started':
         $base_data['details'] = [
           'subscription_id' => 'SUB-' . time(),
           'plan_name' => 'Premium Plan',
           'monthly_price' => 29.99,
           'start_date' => date('Y-m-d'),
         ];
         break;

       case 'subscription_cancelled':
         $base_data['details'] = [
           'subscription_id' => 'SUB-' . (time() - 86400),
           'plan_name' => 'Premium Plan',
           'cancellation_reason' => 'user_request',
           'cancellation_date' => date('Y-m-d'),
         ];
         break;
     }

      return $base_data;
    }

    /**
     * Increments the test email counter for rate limiting.
     *
     * This method is shared between test email and test webform event functionality
     * to maintain a unified rate limit for admin testing features.
     */
    private function incrementTestEmailCounter(): void {
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
      
      // Save updated usage
      \Drupal::cache()->set($cache_key, $current_usage, $current_usage['reset_time']);
    }

}