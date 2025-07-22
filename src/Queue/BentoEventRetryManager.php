<?php

namespace Drupal\bento_sdk\Queue;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Manages retry logic for failed Bento events.
 *
 * This service handles exponential backoff retry logic, dead letter queue
 * management, and retry configuration.
 */
class BentoEventRetryManager {

  /**
   * The queue factory service.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  private QueueFactory $queueFactory;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  private ConfigFactoryInterface $configFactory;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  private StateInterface $state;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  private LoggerInterface $logger;

  /**
   * The main queue instance.
   *
   * @var \Drupal\Core\Queue\QueueInterface|null
   */
  private ?QueueInterface $mainQueue = NULL;

  /**
   * The dead letter queue instance.
   *
   * @var \Drupal\Core\Queue\QueueInterface|null
   */
  private ?QueueInterface $deadLetterQueue = NULL;

  /**
   * Constructs a new BentoEventRetryManager.
   *
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   *   The queue factory service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   */
  public function __construct(QueueFactory $queue_factory, ConfigFactoryInterface $config_factory, StateInterface $state, LoggerInterface $logger) {
    $this->queueFactory = $queue_factory;
    $this->configFactory = $config_factory;
    $this->state = $state;
    $this->logger = $logger;
  }

  /**
   * Handles retry logic for a failed queue item.
   *
   * @param array $queue_item
   *   The original queue item data.
   * @param \Exception $exception
   *   The exception that caused the failure.
   *
   * @return bool
   *   TRUE if the item was re-queued for retry, FALSE if moved to dead letter queue.
   */
  public function handleRetry(array $queue_item, \Exception $exception): bool {
    $attempt_count = ($queue_item['attempt_count'] ?? 0) + 1;
    $max_attempts = $this->getMaxRetryAttempts();

    // Update queue item with retry information
    $updated_item = $queue_item;
    $updated_item['attempt_count'] = $attempt_count;
    $updated_item['last_attempt'] = time();
    $updated_item['error_message'] = $exception->getMessage();

    if ($attempt_count >= $max_attempts) {
      // Move to dead letter queue
      $this->moveToDeadLetterQueue($updated_item);
      
      $this->logger->error('Bento event moved to dead letter queue after @attempts attempts: @type for @email', [
        '@attempts' => $attempt_count,
        '@type' => $queue_item['event_data']['type'] ?? 'unknown',
        '@email' => $queue_item['event_data']['email'] ?? 'unknown',
      ]);
      
      return FALSE;
    }

    // Calculate exponential backoff delay
    $delay = $this->calculateBackoffDelay($attempt_count);
    
    // Re-queue with delay
    $this->requeueWithDelay($updated_item, $delay);
    
    $this->logger->info('Bento event re-queued for retry @attempt/@max (delay: @delay seconds): @type for @email', [
      '@attempt' => $attempt_count,
      '@max' => $max_attempts,
      '@delay' => $delay,
      '@type' => $queue_item['event_data']['type'] ?? 'unknown',
      '@email' => $queue_item['event_data']['email'] ?? 'unknown',
    ]);
    
    return TRUE;
  }

  /**
   * Moves a failed item to the dead letter queue.
   *
   * @param array $queue_item
   *   The queue item to move to dead letter queue.
   */
  public function moveToDeadLetterQueue(array $queue_item): void {
    try {
      // Add metadata for dead letter queue
      $dead_letter_item = $queue_item;
      $dead_letter_item['moved_to_dlq'] = time();
      $dead_letter_item['final_error'] = $queue_item['error_message'] ?? 'Unknown error';
      
      // Add to dead letter queue
      $dlq = $this->getDeadLetterQueue();
      $dlq->createItem($dead_letter_item);
      
      $this->logger->info('Item moved to dead letter queue: @type for @email', [
        '@type' => $queue_item['event_data']['type'] ?? 'unknown',
        '@email' => $queue_item['event_data']['email'] ?? 'unknown',
      ]);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to move item to dead letter queue: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Re-queues an item with a delay.
   *
   * Note: Drupal's core queue system doesn't support delayed queuing natively.
   * This implementation uses a simple approach with state storage for scheduling.
   *
   * @param array $queue_item
   *   The queue item to re-queue.
   * @param int $delay
   *   The delay in seconds before the item should be processed.
   */
  public function requeueWithDelay(array $queue_item, int $delay): void {
    try {
      // Store the item in state with a scheduled time
      $scheduled_time = time() + $delay;
      $scheduled_items = $this->state->get('bento_sdk.scheduled_retries', []);
      
      // Generate a unique key for this scheduled item
      $item_key = md5(serialize($queue_item) . $scheduled_time);
      
      $scheduled_items[$item_key] = [
        'item' => $queue_item,
        'scheduled_time' => $scheduled_time,
        'created' => time(),
      ];
      
      $this->state->set('bento_sdk.scheduled_retries', $scheduled_items);
      
      $this->logger->debug('Item scheduled for retry in @delay seconds', [
        '@delay' => $delay,
      ]);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to schedule item for retry: @message', [
        '@message' => $e->getMessage(),
      ]);
      
      // Fallback: add immediately to queue without delay
      $this->getMainQueue()->createItem($queue_item);
    }
  }

  /**
   * Processes scheduled retries that are ready to be re-queued.
   *
   * This method should be called regularly (e.g., via cron) to check for
   * scheduled items that are ready to be re-queued.
   */
  public function processScheduledRetries(): void {
    try {
      $scheduled_items = $this->state->get('bento_sdk.scheduled_retries', []);
      $current_time = time();
      $processed_count = 0;
      $remaining_items = [];
      
      foreach ($scheduled_items as $item_key => $scheduled_data) {
        if ($scheduled_data['scheduled_time'] <= $current_time) {
          // Time to re-queue this item
          $this->getMainQueue()->createItem($scheduled_data['item']);
          $processed_count++;
          
          $this->logger->debug('Re-queued scheduled retry item');
        }
        else {
          // Keep for later processing
          $remaining_items[$item_key] = $scheduled_data;
        }
      }
      
      // Update state with remaining items
      $this->state->set('bento_sdk.scheduled_retries', $remaining_items);
      
      if ($processed_count > 0) {
        $this->logger->info('Processed @count scheduled retry items', [
          '@count' => $processed_count,
        ]);
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to process scheduled retries: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Gets the maximum number of retry attempts.
   *
   * @return int
   *   The maximum number of retry attempts.
   */
  public function getMaxRetryAttempts(): int {
    $config = $this->configFactory->get('bento_sdk.settings');
    return $config->get('retry.max_attempts') ?? 3;
  }

  /**
   * Calculates the exponential backoff delay.
   *
   * @param int $attempt_count
   *   The current attempt count (1-based).
   *
   * @return int
   *   The delay in seconds.
   */
  public function calculateBackoffDelay(int $attempt_count): int {
    $config = $this->configFactory->get('bento_sdk.settings');
    $base_delay = $config->get('retry.base_delay') ?? 60; // 1 minute default
    $max_delay = $config->get('retry.max_delay') ?? 300;  // 5 minutes default
    
    // Exponential backoff: base_delay * 2^(attempt - 1)
    // Attempt 1: 60 * 2^0 = 60 seconds (1 minute)
    // Attempt 2: 60 * 2^1 = 120 seconds (2 minutes)  
    // Attempt 3: 60 * 2^2 = 240 seconds (4 minutes)
    $delay = $base_delay * pow(2, $attempt_count - 1);
    
    // Cap at maximum delay
    return min($delay, $max_delay);
  }

  /**
   * Gets statistics about retry operations.
   *
   * @return array
   *   Array containing retry statistics.
   */
  public function getRetryStats(): array {
    try {
      $scheduled_items = $this->state->get('bento_sdk.scheduled_retries', []);
      $dlq = $this->getDeadLetterQueue();
      
      return [
        'scheduled_retries' => count($scheduled_items),
        'dead_letter_queue_size' => $dlq->numberOfItems(),
        'max_attempts' => $this->getMaxRetryAttempts(),
      ];
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to get retry statistics: @message', [
        '@message' => $e->getMessage(),
      ]);
      
      return [
        'scheduled_retries' => 0,
        'dead_letter_queue_size' => 0,
        'max_attempts' => 3,
      ];
    }
  }

  /**
   * Gets the main queue instance.
   *
   * @return \Drupal\Core\Queue\QueueInterface
   *   The main queue instance.
   */
  private function getMainQueue(): QueueInterface {
    if ($this->mainQueue === NULL) {
      $this->mainQueue = $this->queueFactory->get('bento_event_processor');
    }
    
    return $this->mainQueue;
  }

  /**
   * Gets the dead letter queue instance.
   *
   * @return \Drupal\Core\Queue\QueueInterface
   *   The dead letter queue instance.
   */
  private function getDeadLetterQueue(): QueueInterface {
    if ($this->deadLetterQueue === NULL) {
      $this->deadLetterQueue = $this->queueFactory->get('bento_event_dead_letter');
    }
    
    return $this->deadLetterQueue;
  }

}