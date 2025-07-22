<?php

namespace Drupal\bento_sdk;

use Drupal\bento_sdk\Client\BentoClient;
use Drupal\bento_sdk\Queue\BentoEventQueueManager;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;
use Drupal\bento_sdk\BentoSanitizationTrait;

/**
 * Main service for interacting with the Bento API.
 *
 * Provides a clean Drupal-specific API for other modules to send events
 * and interact with Bento services.
 */
class BentoService {
  use BentoSanitizationTrait;

  /**
   * The Bento HTTP client.
   *
   * @var \Drupal\bento_sdk\Client\BentoClient
   */
  private BentoClient $client;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  private ConfigFactoryInterface $configFactory;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  private LoggerInterface $logger;

  /**
   * The cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  private CacheBackendInterface $cache;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  private StateInterface $state;

  /**
   * The queue manager service.
   *
   * @var \Drupal\bento_sdk\Queue\BentoEventQueueManager
   */
  private BentoEventQueueManager $queueManager;

  /**
   * Whether credentials have been loaded.
   *
   * @var bool
   */
  private bool $credentialsLoaded = FALSE;

  /**
   * @var string|null
   */
  private $lastError = NULL;

  /**
   * Constructs a new BentoService.
   *
   * @param \Drupal\bento_sdk\Client\BentoClient $client
   *   The Bento HTTP client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache backend.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\bento_sdk\Queue\BentoEventQueueManager $queue_manager
   *   The queue manager service.
   */
  public function __construct(BentoClient $client, ConfigFactoryInterface $config_factory, LoggerInterface $logger, CacheBackendInterface $cache, StateInterface $state, BentoEventQueueManager $queue_manager) {
    $this->client = $client;
    $this->configFactory = $config_factory;
    $this->logger = $logger;
    $this->cache = $cache;
    $this->state = $state;
    $this->queueManager = $queue_manager;
  }

  /**
   * Sends an event to Bento via queue for asynchronous processing.
   *
   * This method queues events for background processing to ensure zero
   * impact on page load performance. Falls back to synchronous sending
   * if queue system is unavailable.
   *
   * @param array $event_data
   *   Event data containing:
   *   - type: (string) Event type (required)
   *   - email: (string) User email (required)
   *   - fields: (array) Additional user fields (optional)
   *   - details: (array) Event-specific details (optional)
   *
   * @return bool
   *   TRUE if the event was queued successfully, FALSE otherwise.
   */
  public function sendEvent(array $event_data): bool {
    // Basic validation before queuing is now handled by the queue manager.
    try {
      // Attempt to queue the event
      $queued = $this->queueManager->queueEvent($event_data);
      
      if ($queued) {
        $this->logger->info('Bento event queued successfully: @type for @email', [
          '@type' => $event_data['type'],
          '@email' => $this->sanitizeEmailForLogging($event_data['email']),
        ]);
        return TRUE;
      }
      else {
        // Queue failed, try fallback
        $this->logger->warning('Failed to queue Bento event, attempting synchronous fallback');
        return $this->sendEventSync($event_data);
      }
    }
    catch (\Exception $e) {
      // Queue system error, fallback to synchronous sending
      $this->logger->warning('Queue system error for Bento event, using synchronous fallback: @message', [
        '@message' => $this->sanitizeErrorMessage($e->getMessage()),
      ]);
      
      return $this->sendEventSync($event_data);
    }
  }

  /**
   * Sends an event to Bento synchronously (fallback method).
   *
   * This method provides synchronous event sending as a fallback when
   * the queue system is unavailable. It maintains the original behavior
   * for backwards compatibility and reliability.
   *
   * @param array $event_data
   *   Event data containing:
   *   - type: (string) Event type (required)
   *   - email: (string) User email (required)
   *   - fields: (array) Additional user fields (optional)
   *   - details: (array) Event-specific details (optional)
   *
   * @return bool
   *   TRUE if the event was sent successfully, FALSE otherwise.
   */
  public function sendEventSync(array $event_data): bool {
    if (!$this->isConfigured()) {
      $this->logger->error('Bento SDK is not properly configured. Please configure API credentials.');
      return FALSE;
    }

    // Validate required fields.
    if (empty($event_data['type'])) {
      $this->logger->error('Event type is required for Bento events.');
      return FALSE;
    }

    if (empty($event_data['email'])) {
      $this->logger->error('Email is required for Bento events.');
      return FALSE;
    }

    // Ensure credentials are loaded.
    $this->loadCredentials();

    try {
      // Format event data according to Bento API specification.
      $formatted_data = $this->formatEventData($event_data);
      
      // Send event to Bento API.
      $response = $this->client->post('batch/events', $formatted_data);
      
      $this->logger->info('Bento event sent synchronously: @type for @email', [
        '@type' => $event_data['type'],
        '@email' => $this->sanitizeEmailForLogging($event_data['email']),
      ]);
      
      return TRUE;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to send Bento event synchronously: @message', [
        '@message' => $this->sanitizeErrorMessage($e->getMessage()),
      ]);
      return FALSE;
    }
  }

  /**
   * Checks if the Bento SDK is properly configured.
   *
   * @return bool
   *   TRUE if all required configuration is present, FALSE otherwise.
   */
  public function isConfigured(): bool {
    $config = $this->configFactory->get('bento_sdk.settings');
    
    return !empty($config->get('site_uuid')) &&
           !empty($config->get('publishable_key')) &&
           !empty($this->getSecretKey());
  }

  /**
   * Gets the current configuration status.
   *
   * @return array
   *   Array containing configuration status information.
   */
  public function getConfigurationStatus(): array {
    $config = $this->configFactory->get('bento_sdk.settings');
    
    return [
      'configured' => $this->isConfigured(),
      'site_uuid' => !empty($config->get('site_uuid')),
      'publishable_key' => !empty($config->get('publishable_key')),
      'secret_key' => !empty($this->getSecretKey()),
    ];
  }

  /**
   * Validates the current API credentials.
   *
   * @return bool
   *   TRUE if credentials are valid, FALSE otherwise.
   */
  public function validateCredentials(): bool {
    if (!$this->isConfigured()) {
      return FALSE;
    }

    $this->loadCredentials();
    return $this->client->validateCredentials();
  }

  /**
   * Fetches the list of verified authors from Bento API.
   *
   * @return array
   *   Array of author email addresses, or empty array on failure.
   */
  public function fetchAuthors(): array {
    if (!$this->isConfigured()) {
      $this->logger->error('Bento SDK is not properly configured. Please configure API credentials.');
      return [];
    }

    // Check cache first
    $cache_key = 'bento_authors_list';
    $cached = $this->cache->get($cache_key);
    if ($cached && $cached->data) {
      $this->logger->info('Retrieved authors list from cache');
      return $cached->data;
    }

    // Ensure credentials are loaded
    $this->loadCredentials();

    try {
      $authors = $this->client->fetchAuthors();
      
      // Cache the result for 1 hour
      $this->cache->set($cache_key, $authors, time() + 3600);
      
      $this->logger->info('Successfully fetched @count authors from Bento API', [
        '@count' => count($authors),
      ]);
      
      return $authors;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to fetch authors from Bento API: @message', [
        '@message' => $this->sanitizeErrorMessage($e->getMessage()),
      ]);
      return [];
    }
  }

  /**
   * Clears the cached authors list.
   */
  public function clearAuthorsCache(): void {
    $this->cache->delete('bento_authors_list');
    $this->logger->info('Authors cache cleared');
  }

  /**
   * Loads credentials from configuration into the HTTP client.
   */
  private function loadCredentials(): void {
    if ($this->credentialsLoaded) {
      return;
    }

    $config = $this->configFactory->get('bento_sdk.settings');
    
    $this->client->setCredentials(
      $config->get('site_uuid'),
      $config->get('publishable_key'),
      $this->getSecretKey()
    );
    
    $this->credentialsLoaded = TRUE;
  }

  /**
   * Execute a single subscriber command.
   *
   * @param string $command
   *   The command type (add_tag, remove_tag, add_field, remove_field, 
   *   subscribe, unsubscribe, change_email).
   * @param string $email
   *   The subscriber email address.
   * @param mixed $query
   *   Command-specific query parameter (string for tags/fields, 
   *   array for add_field, null for subscribe/unsubscribe).
   *
   * @return bool
   *   TRUE if the command was executed successfully, FALSE otherwise.
   */
  public function executeSubscriberCommand(string $command, string $email, $query = NULL): bool {
    if (!$this->isConfigured()) {
      $this->logger->error('Bento SDK is not properly configured. Please configure API credentials.');
      return FALSE;
    }

    // Validate email format.
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $this->logger->error('Invalid email format: @email', ['@email' => $this->sanitizeEmailForLogging($email)]);
      return FALSE;
    }

    // Validate command type.
    $allowed_commands = [
      'add_tag', 'remove_tag', 'add_field', 'remove_field',
      'subscribe', 'unsubscribe', 'change_email'
    ];
    
    if (!in_array($command, $allowed_commands)) {
      $this->logger->error('Invalid command type: @command', ['@command' => $command]);
      return FALSE;
    }

    // Validate query parameter based on command type.
    if (!$this->validateCommandQuery($command, $query)) {
      return FALSE;
    }

    // Ensure credentials are loaded.
    $this->loadCredentials();

    try {
      $command_data = [
        'command' => $command,
        'email' => $email,
      ];

      // Add query parameter if provided.
      if ($query !== NULL) {
        $command_data['query'] = $query;
      }

      // Send command to Bento API.
      $response = $this->client->post('fetch/commands', $command_data);
      
      $this->logger->info('Bento subscriber command executed successfully: @command for @email', [
        '@command' => $command,
        '@email' => $this->sanitizeEmailForLogging($email),
      ]);
      
      return TRUE;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to execute Bento subscriber command: @message', [
        '@message' => $this->sanitizeErrorMessage($e->getMessage()),
      ]);
      return FALSE;
    }
  }

  /**
   * Execute multiple commands in batch.
   *
   * @param array $commands
   *   Array of command arrays, each containing 'command', 'email', 
   *   and optionally 'query'.
   *
   * @return array
   *   Array of results with success/failure status for each command.
   */
  public function executeSubscriberCommands(array $commands): array {
    $results = [];
    
    foreach ($commands as $index => $command_data) {
      if (!isset($command_data['command']) || !isset($command_data['email'])) {
        $results[$index] = [
          'success' => FALSE,
          'error' => 'Missing required command or email parameter',
        ];
        continue;
      }

      $success = $this->executeSubscriberCommand(
        $command_data['command'],
        $command_data['email'],
        $command_data['query'] ?? NULL
      );

      $results[$index] = [
        'success' => $success,
        'command' => $command_data['command'],
        'email' => $command_data['email'],
      ];

      if (!$success) {
        $results[$index]['error'] = 'Command execution failed';
      }
    }

    return $results;
  }

  /**
   * Add a tag to a subscriber.
   *
   * @param string $email
   *   The subscriber email address.
   * @param string $tag
   *   The tag name to add.
   *
   * @return bool
   *   TRUE if successful, FALSE otherwise.
   */
  public function addTag(string $email, string $tag): bool {
    return $this->executeSubscriberCommand('add_tag', $email, $tag);
  }

  /**
   * Remove a tag from a subscriber.
   *
   * @param string $email
   *   The subscriber email address.
   * @param string $tag
   *   The tag name to remove.
   *
   * @return bool
   *   TRUE if successful, FALSE otherwise.
   */
  public function removeTag(string $email, string $tag): bool {
    return $this->executeSubscriberCommand('remove_tag', $email, $tag);
  }

  /**
   * Add a custom field to a subscriber.
   *
   * @param string $email
   *   The subscriber email address.
   * @param string $key
   *   The field key.
   * @param string $value
   *   The field value.
   *
   * @return bool
   *   TRUE if successful, FALSE otherwise.
   */
  public function addField(string $email, string $key, string $value): bool {
    return $this->executeSubscriberCommand('add_field', $email, [
      'key' => $key,
      'value' => $value,
    ]);
  }

  /**
   * Remove a custom field from a subscriber.
   *
   * @param string $email
   *   The subscriber email address.
   * @param string $field
   *   The field name to remove.
   *
   * @return bool
   *   TRUE if successful, FALSE otherwise.
   */
  public function removeField(string $email, string $field): bool {
    return $this->executeSubscriberCommand('remove_field', $email, $field);
  }

  /**
   * Subscribe a user.
   *
   * @param string $email
   *   The subscriber email address.
   *
   * @return bool
   *   TRUE if successful, FALSE otherwise.
   */
  public function subscribeUser(string $email): bool {
    return $this->executeSubscriberCommand('subscribe', $email);
  }

  /**
   * Unsubscribe a user.
   *
   * @param string $email
   *   The subscriber email address.
   *
   * @return bool
   *   TRUE if successful, FALSE otherwise.
   */
  public function unsubscribeUser(string $email): bool {
    return $this->executeSubscriberCommand('unsubscribe', $email);
  }

  /**
   * Change a subscriber's email address.
   *
   * @param string $old_email
   *   The current email address.
   * @param string $new_email
   *   The new email address.
   *
   * @return bool
   *   TRUE if successful, FALSE otherwise.
   */
  public function changeEmail(string $old_email, string $new_email): bool {
    // Validate new email format.
    if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
      $this->logger->error('Invalid new email format: @email', ['@email' => $this->sanitizeEmailForLogging($new_email)]);
      return FALSE;
    }

    return $this->executeSubscriberCommand('change_email', $old_email, $new_email);
  }

  /**
   * Send transactional email via Bento.
   *
   * @param array $email_data
   *   Email data containing:
   *   - to: (string) Recipient email address (required)
   *   - from: (string) Sender email address (optional, uses default if not provided)
   *   - subject: (string) Email subject (required)
   *   - html_body: (string) HTML email content (optional)
   *   - text_body: (string) Plain text email content (optional)
   *   - personalizations: (array) Template variables (optional)
   *
   * @return bool
   *   TRUE if the email was sent successfully, FALSE otherwise.
   */
  public function sendTransactionalEmail(array $email_data): bool {
    // Clear any previous error
    $this->clearLastError();
    
    if (!$this->isConfigured()) {
      $error_message = 'Bento SDK is not properly configured. Please configure API credentials.';
      $this->lastError = $error_message;
      $this->logger->error($error_message);
      return FALSE;
    }

    // Validate required fields.
    if (empty($email_data['to'])) {
      $error_message = 'Recipient email (to) is required for transactional emails.';
      $this->lastError = $error_message;
      $this->logger->error($error_message);
      return FALSE;
    }

    if (empty($email_data['subject'])) {
      $error_message = 'Subject is required for transactional emails.';
      $this->lastError = $error_message;
      $this->logger->error($error_message);
      return FALSE;
    }

    // Validate email format.
    if (!filter_var($email_data['to'], FILTER_VALIDATE_EMAIL)) {
      $error_message = 'Invalid recipient email format: ' . $this->sanitizeEmailForLogging($email_data['to']);
      $this->lastError = $error_message;
      $this->logger->error($error_message);
      return FALSE;
    }

    // Validate sender email if provided.
    if (!empty($email_data['from']) && !filter_var($email_data['from'], FILTER_VALIDATE_EMAIL)) {
      $error_message = 'Invalid sender email format: ' . $this->sanitizeEmailForLogging($email_data['from']);
      $this->lastError = $error_message;
      $this->logger->error($error_message);
      return FALSE;
    }

    // Ensure at least one content type is provided.
    if (empty($email_data['html_body']) && empty($email_data['text_body'])) {
      $error_message = 'Either html_body or text_body must be provided for transactional emails.';
      $this->lastError = $error_message;
      $this->logger->error($error_message);
      return FALSE;
    }

    // Log which "from" address will be used if not explicitly provided.
    $from_email = $this->getDefaultFromEmail();
    if (empty($email_data['from'])) {
      $email_data['from'] = $from_email;
      $this->logger->info('Using default from address: @email', [
        '@email' => $this->sanitizeEmailForLogging($from_email),
      ]);
    }

    // Ensure credentials are loaded.
    $this->loadCredentials();

    try {
      // Format email data according to Bento API specification.
      $formatted_data = $this->formatTransactionalEmailData($email_data);
      
      // Send email to Bento API.
      $response = $this->client->post('batch/emails', $formatted_data);
      
      $this->logger->info('Bento transactional email sent successfully to @email', [
        '@email' => $this->sanitizeEmailForLogging($email_data['to']),
      ]);
      
      return TRUE;
    }
    catch (\Exception $e) {
      $error_message = 'Failed to send Bento transactional email: ' . $this->sanitizeErrorMessage($e->getMessage());
      $this->lastError = $error_message;
      
      $this->logger->error($error_message);
      
      // Log additional error details if available
      if (method_exists($e, 'getResponse') && $e->getResponse()) {
        $response_details = 'Bento API error response: ' . $e->getResponse()->getStatusCode() . ' - ' . $e->getResponse()->getBody()->getContents();
        $this->lastError .= ' | ' . $response_details;
        $this->logger->error($response_details);
      }
      
      return FALSE;
    }
  }

  /**
   * Send batch transactional emails.
   *
   * @param array $emails
   *   Array of email data arrays, each following the same structure
   *   as sendTransactionalEmail().
   *
   * @return array
   *   Array of results with success/failure status for each email.
   */
  public function sendTransactionalEmails(array $emails): array {
    $results = [];
    
    foreach ($emails as $index => $email_data) {
      $success = $this->sendTransactionalEmail($email_data);

      $results[$index] = [
        'success' => $success,
        'to' => $email_data['to'] ?? 'unknown',
        'subject' => $email_data['subject'] ?? 'unknown',
      ];

      if (!$success) {
        $results[$index]['error'] = 'Email send failed';
      }
    }

    return $results;
  }

  /**
   * Validate an email address using Bento API.
   *
   * @param string $email
   *   The email address to validate.
   * @param string $name
   *   Optional name associated with the email.
   * @param string $ip
   *   Optional IP address for validation context.
   *
   * @return array
   *   Validation result array containing:
   *   - valid: (bool) Whether the email is valid
   *   - email: (string) The validated email address
   *   - reason: (string) Reason if invalid
   *   - suggestions: (array) Suggested corrections if applicable
   *   - cached: (bool) Whether result was from cache
   */
  public function validateEmail(string $email, ?string $name = NULL, ?string $ip = NULL): array {
    // Basic email format validation first.
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      return [
        'valid' => FALSE,
        'email' => $email,
        'reason' => 'Invalid email format',
        'suggestions' => [],
        'cached' => FALSE,
      ];
    }

    // Check if email validation is enabled.
    if (!$this->isEmailValidationEnabled()) {
      $this->logger->info('Email validation is disabled, skipping API validation for @email', [
        '@email' => $email,
      ]);
      return [
        'valid' => TRUE,
        'email' => $email,
        'reason' => '',
        'suggestions' => [],
        'cached' => FALSE,
      ];
    }

    // Check cache first.
    $cache_key = $this->getEmailValidationCacheKey($email, $name, $ip);
    $cached_result = $this->cache->get($cache_key);
    
    if ($cached_result && $cached_result->data) {
      $result = $cached_result->data;
      $result['cached'] = TRUE;
      return $result;
    }

    // Ensure credentials are loaded.
    $this->loadCredentials();

    try {
      // Prepare query parameters.
      $params = ['email' => $email];
      
      if ($name !== NULL) {
        $params['name'] = $name;
      }
      
      if ($ip !== NULL) {
        $params['ip'] = $ip;
      }

      // Call Bento validation API.
      $response = $this->client->get('experimental/validation', $params);
      
      // Format response.
      $result = [
        'valid' => $response['valid'] ?? FALSE,
        'email' => $email,
        'reason' => $response['reason'] ?? '',
        'suggestions' => $response['suggestions'] ?? [],
        'cached' => FALSE,
      ];

      // Cache the result.
      $this->cacheEmailValidationResult($cache_key, $result);
      
      $this->logger->info('Email validation completed for @email: @status', [
        '@email' => $this->sanitizeEmailForLogging($email),
        '@status' => $result['valid'] ? 'valid' : 'invalid',
      ]);
      
      return $result;
    }
    catch (\Exception $e) {
      $this->logger->error('Email validation API error for @email: @message', [
        '@email' => $this->sanitizeEmailForLogging($email),
        '@message' => $this->sanitizeErrorMessage($e->getMessage()),
      ]);
      
      // Return a default valid result on API failure to not block operations.
      return [
        'valid' => TRUE,
        'email' => $email,
        'reason' => 'API validation unavailable',
        'suggestions' => [],
        'cached' => FALSE,
      ];
    }
  }

  /**
   * Validate multiple emails in batch.
   *
   * @param array $emails
   *   Array of email addresses or arrays with email, name, and ip keys.
   *
   * @return array
   *   Array of validation results keyed by email address.
   */
  public function validateEmails(array $emails): array {
    $results = [];
    
    foreach ($emails as $email_data) {
      // Handle both string emails and array format.
      if (is_string($email_data)) {
        $email = $email_data;
        $name = NULL;
        $ip = NULL;
      }
      else {
        $email = $email_data['email'] ?? '';
        $name = $email_data['name'] ?? NULL;
        $ip = $email_data['ip'] ?? NULL;
      }

      if (empty($email)) {
        $results[$email] = [
          'valid' => FALSE,
          'email' => $email,
          'reason' => 'Empty email address',
          'suggestions' => [],
          'cached' => FALSE,
        ];
        continue;
      }

      $results[$email] = $this->validateEmail($email, $name, $ip);
    }

    return $results;
  }

  /**
   * Check if email validation is enabled and configured.
   *
   * @return bool
   *   TRUE if email validation is enabled and Bento is configured.
   */
  public function isEmailValidationEnabled(): bool {
    $config = $this->configFactory->get('bento_sdk.settings');
    
    return $config->get('enable_email_validation') && $this->isConfigured();
  }

  /**
   * Create a single subscriber.
   *
   * @param array $subscriber_data
   *   Subscriber data containing:
   *   - email: (string) Email address (required)
   *   - first_name: (string) First name (optional)
   *   - last_name: (string) Last name (optional)
   *   - tags: (string) Comma-separated tags (optional)
   *   - remove_tags: (string) Comma-separated tags to remove (optional)
   *   - custom fields: Any additional custom field data (optional)
   *
   * @return bool
   *   TRUE if the subscriber was created successfully, FALSE otherwise.
   */
  public function createSubscriber(array $subscriber_data): bool {
    if (!$this->isConfigured()) {
      $this->logger->error('Bento SDK is not properly configured. Please configure API credentials.');
      return FALSE;
    }

    // Validate subscriber data.
    if (!$this->validateSubscriberData($subscriber_data)) {
      return FALSE;
    }

    // Ensure credentials are loaded.
    $this->loadCredentials();

    try {
      // Format subscriber data for batch API.
      $formatted_data = [
        'subscribers' => [$subscriber_data],
      ];
      
      // Send subscriber to Bento API.
      $response = $this->client->post('batch/subscribers', $formatted_data);
      
      $this->logger->info('Bento subscriber created successfully: @email', [
        '@email' => $this->sanitizeEmailForLogging($subscriber_data['email']),
      ]);
      
      return TRUE;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to create Bento subscriber: @message', [
        '@message' => $this->sanitizeErrorMessage($e->getMessage()),
      ]);
      return FALSE;
    }
  }

  /**
   * Import multiple subscribers in batch.
   *
   * @param array $subscribers
   *   Array of subscriber data arrays, each following the same structure
   *   as createSubscriber().
   *
   * @return array
   *   Array of results with success/failure status for each subscriber.
   *   Each result contains:
   *   - success: (bool) Whether the import was successful
   *   - email: (string) The subscriber email
   *   - error: (string) Error message if failed
   */
  public function importSubscribers(array $subscribers): array {
    if (!$this->isConfigured()) {
      $this->logger->error('Bento SDK is not properly configured. Please configure API credentials.');
      return [];
    }

    $results = [];
    $valid_subscribers = [];
    
    // Validate each subscriber and collect valid ones.
    foreach ($subscribers as $index => $subscriber_data) {
      if (!$this->validateSubscriberData($subscriber_data)) {
        $results[$index] = [
          'success' => FALSE,
          'email' => $subscriber_data['email'] ?? 'unknown',
          'error' => 'Invalid subscriber data',
        ];
        continue;
      }

      $valid_subscribers[$index] = $subscriber_data;
    }

    // If no valid subscribers, return early.
    if (empty($valid_subscribers)) {
      $this->logger->warning('No valid subscribers found for batch import.');
      return $results;
    }

    // Process subscribers in chunks to handle API limits and rate limiting.
    $chunk_size = $this->getBatchChunkSize();
    $subscriber_chunks = array_chunk($valid_subscribers, $chunk_size, TRUE);

    foreach ($subscriber_chunks as $chunk_index => $chunk) {
      // Add delay between chunks to respect rate limits.
      if ($chunk_index > 0) {
        $this->throttleBatchOperation();
      }
      
      $chunk_results = $this->processBatchSubscribers($chunk);
      $results = array_merge($results, $chunk_results);
    }

    $successful_count = count(array_filter($results, function($result) {
      return $result['success'];
    }));
    
    $this->logger->info('Batch subscriber import completed: @successful/@total successful', [
      '@successful' => $successful_count,
      '@total' => count($results),
    ]);

    return $results;
  }

  /**
   * Validates command query parameter based on command type.
   *
   * @param string $command
   *   The command type.
   * @param mixed $query
   *   The query parameter to validate.
   *
   * @return bool
   *   TRUE if valid, FALSE otherwise.
   */
  private function validateCommandQuery(string $command, $query): bool {
    switch ($command) {
      case 'add_tag':
      case 'remove_tag':
      case 'remove_field':
      case 'change_email':
        if (empty($query) || !is_string($query)) {
          $this->logger->error('Command @command requires a non-empty string query parameter', [
            '@command' => $command,
          ]);
          return FALSE;
        }
        break;

      case 'add_field':
        if (!is_array($query) || empty($query['key']) || empty($query['value'])) {
          $this->logger->error('Command add_field requires query array with key and value');
          return FALSE;
        }
        break;

      case 'subscribe':
      case 'unsubscribe':
        // These commands don't require a query parameter.
        break;

      default:
        $this->logger->error('Unknown command type: @command', ['@command' => $command]);
        return FALSE;
    }

    return TRUE;
  }

  /**
   * Gets the default "from" email address based on configuration.
   *
   * @return string
   *   The default from email address.
   */
  public function getDefaultFromEmail(): string {
    $config = $this->configFactory->get('bento_sdk.settings');
    
    // Use the selected author email or fallback
    if (!empty($config->get('default_author_email'))) {
      return $config->get('default_author_email');
    }
    
    return 'noreply@example.com';
  }

  /**
   * Formats transactional email data according to Bento API specification.
   *
   * @param array $email_data
   *   Raw email data from the caller.
   *
   * @return array
   *   Formatted data ready for the Bento API.
   */
  private function formatTransactionalEmailData(array $email_data): array {
    $config = $this->configFactory->get('bento_sdk.settings');
    
    // Priority order for "from" address:
    // 1. Explicitly provided 'from' in email_data
    // 2. Selected author email from configuration
    // 3. Default sender email from configuration
    // 4. Fallback to noreply@example.com
    
    $default_from = 'noreply@example.com';
    
    if (!empty($email_data['from'])) {
      $from_email = $email_data['from'];
    }
    elseif (!empty($config->get('default_author_email'))) {
      $from_email = $config->get('default_author_email');
    }
    elseif (!empty($config->get('default_sender_email'))) {
      $from_email = $config->get('default_sender_email');
    }
    else {
      $from_email = $default_from;
    }

    $formatted = [
      'emails' => [
        [
          'to' => $email_data['to'],
          'from' => $from_email,
          'subject' => $email_data['subject'],
          'transactional' => TRUE,
        ],
      ],
    ];

    // Add HTML body if provided.
    if (!empty($email_data['html_body'])) {
      $formatted['emails'][0]['html_body'] = $email_data['html_body'];
    }

    // Add text body if provided.
    if (!empty($email_data['text_body'])) {
      $formatted['emails'][0]['text_body'] = $email_data['text_body'];
    }

    // Add personalizations if provided.
    if (!empty($email_data['personalizations']) && is_array($email_data['personalizations'])) {
      $formatted['emails'][0]['personalizations'] = $email_data['personalizations'];
    }

    return $formatted;
  }

  /**
   * Formats event data according to Bento API specification.
   *
   * @param array $event_data
   *   Raw event data from the caller.
   *
   * @return array
   *   Formatted data ready for the Bento API.
   */
  private function formatEventData(array $event_data): array {
    $formatted = [
      'events' => [
        [
          'type' => $event_data['type'],
          'email' => $event_data['email'],
          'fields' => $event_data['fields'] ?? [],
          'details' => $event_data['details'] ?? [],
        ],
      ],
    ];

    // Add timestamp if not provided.
    if (!isset($formatted['events'][0]['details']['timestamp'])) {
      $formatted['events'][0]['details']['timestamp'] = time();
    }

    return $formatted;
  }

  /**
   * Generates a cache key for email validation.
   *
   * @param string $email
   *   The email address.
   * @param string $name
   *   Optional name parameter.
   * @param string $ip
   *   Optional IP parameter.
   *
   * @return string
   *   The cache key.
   */
  private function getEmailValidationCacheKey(string $email, ?string $name = NULL, ?string $ip = NULL): string {
    $key_parts = ['bento_email_validation', strtolower($email)];
    
    if ($name !== NULL) {
      $key_parts[] = 'name_' . md5($name);
    }
    
    if ($ip !== NULL) {
      $key_parts[] = 'ip_' . md5($ip);
    }
    
    return implode(':', $key_parts);
  }

  /**
   * Caches an email validation result.
   *
   * @param string $cache_key
   *   The cache key.
   * @param array $result
   *   The validation result to cache.
   */
  private function cacheEmailValidationResult(string $cache_key, array $result): void {
    $config = $this->configFactory->get('bento_sdk.settings');
    
    // Use different cache durations for valid vs invalid emails.
    if ($result['valid']) {
      // Cache valid emails for 24 hours (or configured duration).
      $cache_duration = $config->get('email_validation_cache_valid_duration') ?: 86400;
    }
    else {
      // Cache invalid emails for 1 hour (or configured duration).
      $cache_duration = $config->get('email_validation_cache_invalid_duration') ?: 3600;
    }
    
    $expire_time = time() + $cache_duration;
    $this->cache->set($cache_key, $result, $expire_time);
  }

  /**
   * Validates subscriber data structure.
   *
   * @param array $data
   *   The subscriber data to validate.
   *
   * @return bool
   *   TRUE if valid, FALSE otherwise.
   */
  private function validateSubscriberData(array $data): bool {
    // Email is required and must be valid.
    if (empty($data['email'])) {
      $this->logger->error('Email is required for subscriber data.');
      return FALSE;
    }

    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
      $this->logger->error('Invalid email format: @email', ['@email' => $this->sanitizeEmailForLogging($data['email'])]);
      return FALSE;
    }

    // Validate tags format if provided.
    if (isset($data['tags']) && !is_string($data['tags'])) {
      $this->logger->error('Tags must be a comma-separated string for subscriber @email', [
        '@email' => $this->sanitizeEmailForLogging($data['email']),
      ]);
      return FALSE;
    }

    // Validate remove_tags format if provided.
    if (isset($data['remove_tags']) && !is_string($data['remove_tags'])) {
      $this->logger->error('Remove tags must be a comma-separated string for subscriber @email', [
        '@email' => $this->sanitizeEmailForLogging($data['email']),
      ]);
      return FALSE;
    }

    // Validate name fields if provided.
    if (isset($data['first_name']) && !is_string($data['first_name'])) {
      $this->logger->error('First name must be a string for subscriber @email', [
        '@email' => $this->sanitizeEmailForLogging($data['email']),
      ]);
      return FALSE;
    }

    if (isset($data['last_name']) && !is_string($data['last_name'])) {
      $this->logger->error('Last name must be a string for subscriber @email', [
        '@email' => $this->sanitizeEmailForLogging($data['email']),
      ]);
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Processes a batch of subscribers through the Bento API.
   *
   * @param array $subscribers
   *   Array of validated subscriber data indexed by original position.
   *
   * @return array
   *   Array of results with success/failure status for each subscriber.
   */
  private function processBatchSubscribers(array $subscribers): array {
    $results = [];
    
    // Ensure credentials are loaded.
    $this->loadCredentials();

    try {
      // Format data for batch API.
      $formatted_data = [
        'subscribers' => array_values($subscribers),
      ];
      
      // Send batch to Bento API.
      $response = $this->client->post('batch/subscribers', $formatted_data);
      
      // Mark all as successful if API call succeeded.
      foreach ($subscribers as $index => $subscriber_data) {
        $results[$index] = [
          'success' => TRUE,
          'email' => $subscriber_data['email'],
        ];
      }
      
      $this->logger->info('Batch of @count subscribers processed successfully', [
        '@count' => count($subscribers),
      ]);
    }
    catch (\Exception $e) {
      // Mark all as failed if API call failed.
      foreach ($subscribers as $index => $subscriber_data) {
        $results[$index] = [
          'success' => FALSE,
          'email' => $subscriber_data['email'],
          'error' => 'API request failed: ' . $e->getMessage(),
        ];
      }
      
      $this->logger->error('Failed to process batch subscribers: @message', [
        '@message' => $this->sanitizeErrorMessage($e->getMessage()),
      ]);
    }

    return $results;
  }

  /**
   * Retrieves the secret key from secure storage.
   *
   * Checks multiple sources in order of security preference:
   * 1. Environment variable (most secure)
   * 2. State API (secure, not exported with config)
   * 3. Configuration (legacy, deprecated)
   *
   * @return string|null
   *   The secret key if found, NULL otherwise.
   */
  private function getSecretKey(): ?string {
    // Check environment variable first (highest security).
    $env_key = $_ENV['BENTO_SECRET_KEY'] ?? getenv('BENTO_SECRET_KEY');
    if (!empty($env_key)) {
      return $env_key;
    }

    // Check State API (secure storage).
    $state_key = $this->state->get('bento_sdk.secret_key');
    if (!empty($state_key)) {
      return $state_key;
    }

    // Check legacy config storage (deprecated but maintained for backwards compatibility).
    $config = $this->configFactory->get('bento_sdk.settings');
    $config_key = $config->get('secret_key');
    if (!empty($config_key)) {
      // Log a warning about using deprecated storage method.
      $this->logger->warning('Secret key is stored in configuration. For security, consider moving it to an environment variable or updating via the admin form.');
      return $config_key;
    }

    return NULL;
  }

  /**
   * Gets the appropriate batch chunk size based on rate limiting configuration.
   *
   * @return int
   *   The chunk size for batch operations.
   */
  private function getBatchChunkSize(): int {
    $config = $this->configFactory->get('bento_sdk.settings');
    
    if (!$config->get('enable_rate_limiting')) {
      return 100; // Default chunk size when rate limiting is disabled.
    }

    $max_per_minute = $config->get('max_requests_per_minute') ?: 60;
    
    // Use a conservative chunk size to avoid hitting rate limits.
    // Aim for no more than 25% of the per-minute limit per batch.
    $chunk_size = max(10, floor($max_per_minute * 0.25));
    
    return min($chunk_size, 50); // Cap at 50 for reasonable processing.
  }

  /**
   * Adds throttling delay between batch operations.
   */
  private function throttleBatchOperation(): void {
    $config = $this->configFactory->get('bento_sdk.settings');
    
    if (!$config->get('enable_rate_limiting')) {
      return;
    }

    $max_per_minute = $config->get('max_requests_per_minute') ?: 60;
    
    // Calculate delay to spread requests evenly across the minute.
    // Add a small buffer to be conservative.
    $delay_seconds = max(1, ceil(60 / $max_per_minute) + 1);
    
    $this->logger->info('Throttling batch operation: waiting @seconds seconds', [
      '@seconds' => $delay_seconds,
    ]);
    
    sleep($delay_seconds);
  }

  /**
   * Get the last error that occurred.
   *
   * @return string|null
   *   The last error message or NULL if no error occurred.
   */
  public function getLastError(): ?string {
    return $this->lastError;
  }

  /**
   * Clear the last error.
   */
  public function clearLastError(): void {
    $this->lastError = NULL;
  }

  /**
   * Gets queue statistics for monitoring.
   *
   * @return array
   *   Array containing queue statistics:
   *   - size: Number of items in queue
   *   - created: When the queue was created
   */
  public function getQueueStats(): array {
    try {
      return $this->queueManager->getQueueStats();
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to get queue statistics: @message', [
        '@message' => $this->sanitizeErrorMessage($e->getMessage()),
      ]);
      
      return [
        'size' => 0,
        'created' => 0,
      ];
    }
  }

  /**
   * Process a webform submission and send to Bento.
   *
   * Extracts form data from the webform submission and creates a Bento event.
   * This method handles email validation, field mapping, and error handling.
   * Only processes submissions if webform integration is enabled.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $submission
   *   The webform submission object.
   *
   * @return bool
   *   TRUE if the event was processed successfully, FALSE otherwise.
   */
  public function processWebformSubmission($submission): bool {
    // Check if webform integration is enabled
    $config = $this->configFactory->get('bento_sdk.settings');
    if (!$config->get('enable_webform_integration')) {
      $this->logger->info('Webform submission skipped - webform integration is disabled');
      return FALSE;
    }

    try {
      // Extract form data from submission
      $form_data = $submission->getData();
      $webform_id = $submission->getWebform()->id();
      
      // Map webform data to Bento event format
      $event_data = $this->mapWebformDataToEvent($form_data, $webform_id);
      
      if (!$event_data) {
        $this->logger->warning('Webform submission skipped - no valid email found in webform @webform_id', [
          '@webform_id' => $webform_id,
        ]);
        return FALSE;
      }
      
      // Send event to Bento
      $success = $this->sendEvent($event_data);
      
      if ($success) {
        $this->logger->info('Webform submission processed successfully for webform @webform_id', [
          '@webform_id' => $webform_id,
        ]);
      }
      
      return $success;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to process webform submission: @message', [
        '@message' => $this->sanitizeErrorMessage($e->getMessage()),
      ]);
      return FALSE;
    }
  }

  /**
   * Map webform data to Bento event format.
   *
   * Extracts email, first_name, last_name and other form data to create
   * a properly formatted Bento event. Email is required for processing.
   * Uses the webform ID as the event type automatically.
   *
   * @param array $form_data
   *   The form data from the webform submission.
   * @param string $webform_id
   *   The webform machine name, used to generate the event type.
   *
   * @return array|null
   *   Formatted event data or NULL if no valid email found.
   */
  private function mapWebformDataToEvent(array $form_data, string $webform_id): ?array {
    // Extract email (required)
    $email = $this->extractEmail($form_data);
    if (!$email) {
      return NULL;
    }
    
    // Generate event type from webform ID
    $event_type = $this->formatWebformEventType($webform_id);
    
    // Start building the event
    $event = [
      'type' => $event_type,
      'email' => $email,
    ];
    
    // Extract known fields for Bento subscriber fields
    $fields = [];
    if (isset($form_data['first_name']) && !empty($form_data['first_name'])) {
      $fields['first_name'] = $form_data['first_name'];
    }
    if (isset($form_data['last_name']) && !empty($form_data['last_name'])) {
      $fields['last_name'] = $form_data['last_name'];
    }
    
    if (!empty($fields)) {
      $event['fields'] = $fields;
    }
    
    // Map remaining data to event details
    $details = [
      'webform_id' => $webform_id,
      'original_webform_id' => $webform_id, // Keep original for reference
    ];
    
    $form_data_details = [];
    foreach ($form_data as $key => $value) {
      // Skip email and name fields as they're handled above
      if (!in_array($key, ['email', 'mail', 'email_address', 'user_email', 'first_name', 'last_name'])) {
        $form_data_details[$key] = $value;
      }
    }
    
    if (!empty($form_data_details)) {
      $details['form_data'] = $form_data_details;
    }
    
    $event['details'] = $details;
    
    return $event;
  }

  /**
   * Extract email from form data, checking common field names.
   *
   * Searches for email in common field names and validates the format
   * before returning. This ensures we have a valid email for Bento events.
   *
   * @param array $form_data
   *   The form data to search for email fields.
   *
   * @return string|null
   *   Valid email address or NULL if none found.
   */
  private function extractEmail(array $form_data): ?string {
    // Common email field names in webforms
    $email_fields = ['email', 'mail', 'email_address', 'user_email'];
    
    foreach ($email_fields as $field) {
      if (isset($form_data[$field]) && !empty($form_data[$field])) {
        $email = trim($form_data[$field]);
        
        // Validate email format
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
          return $email;
        }
      }
    }
    
    return NULL;
  }

  /**
   * Convert Webform ID to a Bento-compatible event type.
   *
   * Sanitizes the webform machine name and formats it as a Bento event type
   * with the required $ prefix. Ensures the event type follows Bento's
   * naming conventions.
   *
   * @param string $webform_id
   *   The webform machine name.
   *
   * @return string
   *   Formatted event type (e.g., 'contact_form' becomes '$contact_form').
   */
  private function formatWebformEventType(string $webform_id): string {
    // Sanitize the webform ID to ensure it's safe for Bento
    // Convert to lowercase and replace any non-alphanumeric characters with underscores
    $sanitized = preg_replace('/[^a-z0-9_-]/', '_', strtolower($webform_id));
    
    // Remove multiple consecutive underscores
    $sanitized = preg_replace('/_+/', '_', $sanitized);
    
    // Remove leading/trailing underscores
    $sanitized = trim($sanitized, '_');
    
    // Ensure we have a valid event name
    if (empty($sanitized)) {
      $sanitized = 'webform_submission';
    }
    
    // Add the $ prefix for Bento system events
    return '$' . $sanitized;
  }

  /**
   * Send a test webform event with sample data.
   *
   * Creates a mock webform submission event for testing the integration.
   * This method bypasses the actual webform submission process and directly
   * creates a properly formatted event using the webform ID as event type.
   *
   * @param string $email
   *   The email address to use for the test event.
   * @param string $test_webform_id
   *   Optional webform ID to use for testing. Defaults to 'admin_test_form'.
   *
   * @return bool
   *   TRUE if the test event was sent successfully, FALSE otherwise.
   */
  public function sendTestWebformEvent(string $email, string $test_webform_id = 'admin_test_form'): bool {
    if (!$this->isConfigured()) {
      $this->logger->error('Cannot send test webform event - Bento SDK is not properly configured.');
      return FALSE;
    }

    // Check if webform integration is enabled
    $config = $this->configFactory->get('bento_sdk.settings');
    if (!$config->get('enable_webform_integration')) {
      $this->logger->warning('Cannot send test webform event - webform integration is disabled.');
      return FALSE;
    }

    try {
      // Create sample webform data
      $sample_form_data = [
        'email' => $email,
        'first_name' => 'Test',
        'last_name' => 'User',
        'subject' => 'Test Webform Submission',
        'message' => 'This is a test webform submission sent from the Drupal admin interface to verify the Bento integration is working correctly.',
        'phone' => '555-123-4567',
        'company' => 'Test Company',
        'how_did_you_hear' => 'Admin Test',
        'newsletter_signup' => TRUE,
      ];

      // Use the same mapping logic as real webform submissions
      // The webform ID will be automatically converted to the event type
      $event_data = $this->mapWebformDataToEvent($sample_form_data, $test_webform_id);

      if (!$event_data) {
        $this->logger->error('Failed to create test webform event data - no valid email found.');
        return FALSE;
      }

      // Add test-specific details
      $event_data['details']['test_event'] = TRUE;
      $event_data['details']['sent_from'] = 'drupal_admin_interface';
      $event_data['details']['timestamp'] = time();

      // Send the event
      $success = $this->sendEvent($event_data);

      if ($success) {
        $this->logger->info('Test webform event sent successfully for @email using event type @type (from webform @webform_id)', [
          '@email' => $this->sanitizeEmailForLogging($email),
          '@type' => $event_data['type'],
          '@webform_id' => $test_webform_id,
        ]);
      }

      return $success;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to send test webform event: @message', [
        '@message' => $this->sanitizeErrorMessage($e->getMessage()),
      ]);
      return FALSE;
    }
  }

}