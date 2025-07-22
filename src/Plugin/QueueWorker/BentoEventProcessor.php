<?php

namespace Drupal\bento_sdk\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\bento_sdk\BentoService;
use Drupal\bento_sdk\Queue\BentoEventRetryManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes Bento events from the queue.
 *
 * This queue worker handles asynchronous processing of Bento events,
 * ensuring that page load performance is not impacted by API calls.
 *
 * @QueueWorker(
 *   id = "bento_event_processor",
 *   title = @Translation("Bento Event Processor"),
 *   cron = {"time" = 60}
 * )
 */
class BentoEventProcessor extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The Bento service.
   *
   * @var \Drupal\bento_sdk\BentoService
   */
  private BentoService $bentoService;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  private LoggerInterface $logger;

  /**
   * The retry manager service.
   *
   * @var \Drupal\bento_sdk\Queue\BentoEventRetryManager
   */
  private BentoEventRetryManager $retryManager;

  /**
   * Error patterns for permanent failures (do not retry).
   */
  private const PERMANENT_ERROR_PATTERNS = [
    'invalid',
    'malformed',
    'validation',
    'bad request',
    'unauthorized',
    'forbidden',
    'authentication',
    'api key',
  ];

  /**
   * Error patterns for retryable failures.
   */
  private const RETRYABLE_ERROR_PATTERNS = [
    'timeout',
    'connection',
    'network',
    '429', // Rate limiting
  ];

  /**
   * Constructs a new BentoEventProcessor.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\bento_sdk\BentoService $bento_service
   *   The Bento service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   * @param \Drupal\bento_sdk\Queue\BentoEventRetryManager $retry_manager
   *   The retry manager service.
   */
  public function __construct(array $configuration, string $plugin_id, $plugin_definition, BentoService $bento_service, LoggerInterface $logger, BentoEventRetryManager $retry_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->bentoService = $bento_service;
    $this->logger = $logger;
    $this->retryManager = $retry_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('bento.sdk'),
      $container->get('logger.channel.bento_sdk'),
      $container->get('bento_sdk.retry_manager')
    );
  }

  /**
   * {@inheritdoc}
   *
   * Processes a single queue item containing Bento event data.
   *
   * @param mixed $data
   *   The queue item data containing:
   *   - event_data: (array) Original event data
   *   - attempt_count: (int) Number of processing attempts
   *   - created: (int) Timestamp when item was created
   *   - last_attempt: (int|null) Timestamp of last attempt
   *   - error_message: (string|null) Last error message
   */
  public function processItem($data): void {
    $start_time = microtime(TRUE);
    
    // Validate queue item structure
    if (!$this->isValidQueueItem($data)) {
      // Log the invalid item and discard it without throwing an exception
      $this->logger->error('Discarding invalid queue item: @item', [
        '@item' => print_r($data, TRUE),
      ]);
      return;
    }

    // Extract event data
    $event_data = $data['event_data'];

    try {
      // Log start of processing
      $this->logger->info('Processing Bento event from queue: @type for @email', [
        '@type' => $event_data['type'] ?? 'unknown',
        '@email' => $event_data['email'] ?? 'unknown',
      ]);

      // Send event synchronously via BentoService
      // We use sendEventSync to avoid re-queuing the same event
      $success = $this->bentoService->sendEventSync($event_data);
      
      if (!$success) {
        // Get the last error from BentoService if available
        $error_message = $this->bentoService->getLastError() ?? 'Unknown error occurred';
        throw new \RuntimeException('Failed to send event to Bento API: ' . $error_message);
      }

      // Calculate processing time
      $processing_time = microtime(TRUE) - $start_time;

      // Log successful processing with timing
      $this->logger->info('Bento event processed successfully from queue: @type for @email (took @time seconds)', [
        '@type' => $event_data['type'],
        '@email' => $event_data['email'],
        '@time' => round($processing_time, 3),
      ]);
      
    }
    catch (\Exception $e) {
      // Determine if this error should be retried or discarded
      $should_retry = $this->shouldRetryError($e);
      
      if ($should_retry) {
        // Use retry manager to handle retry logic with exponential backoff
        $retry_handled = $this->retryManager->handleRetry($data, $e);
        
        if ($retry_handled) {
          // Item was re-queued for retry - don't re-throw to avoid immediate retry
          $this->logger->info('Bento event scheduled for retry with exponential backoff');
          return;
        }
        else {
          // Item was moved to dead letter queue - log and return
          $this->logger->error('Bento event moved to dead letter queue after max retry attempts');
          return;
        }
      }
      else {
        // Log error but don't re-throw (discard the item)
        $this->logger->error('Failed to process Bento event from queue (discarding): @message', [
          '@message' => $e->getMessage(),
          '@event_type' => $event_data['type'] ?? 'unknown',
          '@email' => $event_data['email'] ?? 'unknown',
        ]);
        
        // Don't re-throw - this will mark the item as processed and remove it from queue
        return;
      }
    }
  }

  /**
   * Validates the structure of a queue item.
   *
   * @param mixed $data
   *   The queue item data to validate.
   *
   * @return bool
   *   TRUE if the queue item has valid structure, FALSE otherwise.
   */
  private function isValidQueueItem($data): bool {
    // Must be an array
    if (!is_array($data)) {
      $this->logger->error('Queue item must be an array');
      return FALSE;
    }

    // Must have event_data
    if (!isset($data['event_data']) || !is_array($data['event_data'])) {
      $this->logger->error('Queue item must contain event_data array');
      return FALSE;
    }

    // Event data must have required fields
    $event_data = $data['event_data'];
    if (empty($event_data['type']) || empty($event_data['email'])) {
      $this->logger->error('Event data must contain type and email fields');
      return FALSE;
    }

    // Validate attempt count if present
    if (isset($data['attempt_count']) && (!is_int($data['attempt_count']) || $data['attempt_count'] < 0)) {
      $this->logger->error('Attempt count must be a non-negative integer');
      return FALSE;
    }

    // Validate timestamps if present
    if (isset($data['created']) && (!is_int($data['created']) || $data['created'] <= 0)) {
      $this->logger->error('Created timestamp must be a positive integer');
      return FALSE;
    }

    if (isset($data['last_attempt']) && $data['last_attempt'] !== NULL && (!is_int($data['last_attempt']) || $data['last_attempt'] <= 0)) {
      $this->logger->error('Last attempt timestamp must be a positive integer or NULL');
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Determines if an error should trigger a retry or if the item should be discarded.
   *
   * Some errors indicate permanent failures that won't be resolved by retrying,
   * such as malformed data or authentication issues. Others indicate temporary
   * issues that may resolve with retry, such as network timeouts.
   *
   * @param \Exception $exception
   *   The exception that occurred during processing.
   *
   * @return bool
   *   TRUE if the error should trigger a retry, FALSE if the item should be discarded.
   */
  private function shouldRetryError(\Exception $exception): bool {
    $error_message = strtolower($exception->getMessage());

    // Check for permanent error patterns (do not retry)
    foreach (self::PERMANENT_ERROR_PATTERNS as $pattern) {
      if (strpos($error_message, $pattern) !== FALSE) {
        return FALSE;
      }
    }

    // Don't retry for client errors (4xx) except rate limiting
    if (strpos($error_message, '4') === 0 && strpos($error_message, '429') === FALSE) {
      return FALSE;
    }

    // Retry for retryable error patterns
    foreach (self::RETRYABLE_ERROR_PATTERNS as $pattern) {
      if (strpos($error_message, $pattern) !== FALSE) {
        return TRUE;
      }
    }

    // Retry for 5xx server errors
    if (strpos($error_message, '5') === 0) {
      return TRUE;
    }

    // Default to retry for unknown errors to be safe
    return TRUE;
  }

}