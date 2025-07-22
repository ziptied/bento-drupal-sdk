<?php

namespace Drupal\bento_sdk\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\bento_sdk\BentoService;
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
   */
  public function __construct(array $configuration, string $plugin_id, $plugin_definition, BentoService $bento_service, LoggerInterface $logger) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->bentoService = $bento_service;
    $this->logger = $logger;
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
      $container->get('logger.channel.bento_sdk')
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
    // Validate queue item structure
    if (!$this->isValidQueueItem($data)) {
      throw new \InvalidArgumentException('Invalid queue item structure');
    }

    // Extract event data
    $event_data = $data['event_data'];

    try {
      // Process the event - this will be implemented in BEN-162
      // For now, we just log that we received the item
      $this->logger->info('Processing Bento event from queue: @type for @email', [
        '@type' => $event_data['type'] ?? 'unknown',
        '@email' => $event_data['email'] ?? 'unknown',
      ]);

      // TODO: Implement actual event processing in BEN-162
      // This is just the infrastructure setup for BEN-160
      
    }
    catch (\Exception $e) {
      // Log error and re-throw for retry handling (to be implemented in BEN-163)
      $this->logger->error('Failed to process Bento event from queue: @message', [
        '@message' => $e->getMessage(),
        '@event_type' => $event_data['type'] ?? 'unknown',
      ]);
      
      throw $e;
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

}