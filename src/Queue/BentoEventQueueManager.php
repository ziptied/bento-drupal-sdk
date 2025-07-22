<?php

namespace Drupal\bento_sdk\Queue;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Psr\Log\LoggerInterface;

/**
 * Manages Bento event queue operations.
 *
 * This service provides a clean interface for adding events to the queue
 * and managing queue-related operations.
 */
class BentoEventQueueManager {

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
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  private LoggerInterface $logger;

  /**
   * The queue instance.
   *
   * @var \Drupal\Core\Queue\QueueInterface|null
   */
  private ?QueueInterface $queue = NULL;

  /**
   * Constructs a new BentoEventQueueManager.
   *
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   *   The queue factory service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   */
  public function __construct(QueueFactory $queue_factory, ConfigFactoryInterface $config_factory, LoggerInterface $logger) {
    $this->queueFactory = $queue_factory;
    $this->configFactory = $config_factory;
    $this->logger = $logger;
  }

  /**
   * Adds an event to the processing queue.
   *
   * @param array $event_data
   *   The event data to queue containing:
   *   - type: (string) Event type (required)
   *   - email: (string) User email (required)
   *   - fields: (array) Additional user fields (optional)
   *   - details: (array) Event-specific details (optional)
   *
   * @return bool
   *   TRUE if the event was successfully queued, FALSE otherwise.
   */
  public function queueEvent(array $event_data): bool {
    try {
      // Validate event data
      if (!$this->validateEventData($event_data)) {
        return FALSE;
      }

      // Create queue item with metadata
      $queue_item = [
        'event_data' => $event_data,
        'attempt_count' => 0,
        'created' => time(),
        'last_attempt' => NULL,
        'error_message' => NULL,
      ];

      // Add to queue
      $queue = $this->getQueue();
      $item_id = $queue->createItem($queue_item);

      if ($item_id) {
        $this->logger->info('Bento event queued successfully: @type for @email', [
          '@type' => $event_data['type'],
          '@email' => $event_data['email'],
        ]);
        return TRUE;
      }
      else {
        $this->logger->error('Failed to create queue item for Bento event: @type', [
          '@type' => $event_data['type'],
        ]);
        return FALSE;
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Exception while queuing Bento event: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Gets the current queue size.
   *
   * @return int
   *   The number of items in the queue.
   */
  public function getQueueSize(): int {
    try {
      return $this->getQueue()->numberOfItems();
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to get queue size: @message', [
        '@message' => $e->getMessage(),
      ]);
      return 0;
    }
  }

  /**
   * Clears all items from the queue.
   *
   * This should be used with caution as it will remove all pending events.
   *
   * @return bool
   *   TRUE if the queue was cleared successfully, FALSE otherwise.
   */
  public function clearQueue(): bool {
    try {
      $queue = $this->getQueue();
      $cleared = 0;
      while ($item = $queue->claimItem()) {
        $queue->deleteItem($item);
        $cleared++;
      }
      $this->logger->info('Bento event queue cleared. Removed @count items.', [
        '@count' => $cleared,
      ]);
      return TRUE;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to clear queue: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Gets queue statistics.
   *
   * @return array
   *   Array containing queue statistics:
   *   - size: Number of items in queue
   *   - created: When the queue was created
   */
  public function getQueueStats(): array {
    try {
      $queue = $this->getQueue();
      
      return [
        'size' => $queue->numberOfItems(),
      ];
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to get queue stats: @message', [
        '@message' => $e->getMessage(),
      ]);
      
      return [
        'size' => 0,
      ];
    }
  }

  /**
   * Gets the queue instance.
   *
   * @return \Drupal\Core\Queue\QueueInterface
   *   The queue instance.
   */
  private function getQueue(): QueueInterface {
    if ($this->queue === NULL) {
      $this->queue = $this->queueFactory->get('bento_event_processor');
    }
    
    return $this->queue;
  }

  /**
   * Validates event data structure.
   *
   * @param array $event_data
   *   The event data to validate.
   *
   * @return bool
   *   TRUE if valid, FALSE otherwise.
   */
  private function validateEventData(array $event_data): bool {
    // Check required fields
    if (empty($event_data['type'])) {
      $this->logger->error('Event type is required for queue items');
      return FALSE;
    }

    if (empty($event_data['email'])) {
      $this->logger->error('Email is required for queue items');
      return FALSE;
    }

    // Validate email format
    if (!filter_var($event_data['email'], FILTER_VALIDATE_EMAIL)) {
      $this->logger->error('Invalid email format in queue item: @email', [
        '@email' => $event_data['email'],
      ]);
      return FALSE;
    }

    // Check data size to prevent memory issues
    $serialized_size = strlen(serialize($event_data));
    $max_size = 1024 * 1024; // 1MB limit
    
    if ($serialized_size > $max_size) {
      $this->logger->error('Event data too large for queue: @size bytes (max: @max)', [
        '@size' => $serialized_size,
        '@max' => $max_size,
      ]);
      return FALSE;
    }

    return TRUE;
  }

}