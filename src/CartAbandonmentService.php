<?php

namespace Drupal\bento_sdk;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Psr\Log\LoggerInterface;
use Drupal\bento_sdk\BentoSanitizationTrait;

/**
 * Service for detecting and processing abandoned shopping carts.
 *
 * Identifies carts that have been inactive beyond the configured threshold
 * and sends cart abandonment events to Bento for marketing automation.
 */
class CartAbandonmentService {
  use BentoSanitizationTrait;

  /**
   * Minimum cart abandonment threshold in hours.
   */
  private const MIN_THRESHOLD_HOURS = 1;

  /**
   * Maximum cart abandonment threshold in hours (30 days).
   */
  private const MAX_THRESHOLD_HOURS = 720;

  /**
   * Minimum check interval in minutes.
   */
  private const MIN_CHECK_INTERVAL_MINUTES = 15;

  /**
   * Maximum check interval in minutes (24 hours).
   */
  private const MAX_CHECK_INTERVAL_MINUTES = 1440;

  /**
   * Maximum number of processed carts to keep in state storage.
   */
  private const MAX_PROCESSED_CARTS_LIMIT = 1000;

  /**
   * Default batch size for processing abandoned carts.
   */
  private const DEFAULT_BATCH_SIZE = 50;

  /**
   * Cart creation window in seconds for detecting new carts.
   */
  private const CART_CREATION_WINDOW_SECONDS = 10;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  private ConfigFactoryInterface $configFactory;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  private Connection $database;

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
   * The Bento service.
   *
   * @var \Drupal\bento_sdk\BentoService
   */
  private BentoService $bentoService;

  /**
   * The email extractor service.
   *
   * @var \Drupal\bento_sdk\CommerceEmailExtractor
   */
  private CommerceEmailExtractor $emailExtractor;

  /**
   * Constructs a new CartAbandonmentService.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   * @param \Drupal\bento_sdk\BentoService $bento_service
   *   The Bento service.
   * @param \Drupal\bento_sdk\CommerceEmailExtractor $email_extractor
   *   The email extractor service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    EntityTypeManagerInterface $entity_type_manager,
    Connection $database,
    StateInterface $state,
    LoggerInterface $logger,
    BentoService $bento_service,
    CommerceEmailExtractor $email_extractor
  ) {
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->database = $database;
    $this->state = $state;
    $this->logger = $logger;
    $this->bentoService = $bento_service;
    $this->emailExtractor = $email_extractor;
  }

  /**
   * Check for abandoned carts and send events.
   *
   * Main entry point for cart abandonment processing. Checks if Commerce
   * integration and cart abandonment tracking are enabled, then processes
   * all carts that meet the abandonment criteria using batch processing.
   */
  public function processAbandonedCarts(): void {
    if (!$this->bentoService->isCommerceIntegrationEnabled()) {
      $this->logger->debug('Cart abandonment processing skipped: Commerce integration disabled');
      return;
    }

    $config = $this->configFactory->get('bento_sdk.settings');
    if (!$config->get('commerce_integration.enable_cart_abandonment')) {
      $this->logger->debug('Cart abandonment processing skipped: Cart abandonment tracking disabled');
      return;
    }

    $threshold_hours = $config->get('commerce_integration.cart_abandonment.threshold_hours') ?? 24;
    
    // Validate threshold value
    try {
      $this->validateThreshold($threshold_hours);
    } catch (\InvalidArgumentException $e) {
      $this->logger->error('Invalid cart abandonment threshold configuration: @error', [
        '@error' => $this->sanitizeErrorMessage($e->getMessage()),
      ]);
      return;
    }

    $check_interval = $config->get('commerce_integration.cart_abandonment.check_interval') ?? self::MIN_CHECK_INTERVAL_MINUTES;
    // Validate check interval value
    try {
      $this->validateCheckInterval($check_interval);
    } catch (\InvalidArgumentException $e) {
      $this->logger->error('Invalid cart abandonment check interval configuration: @error', [
        '@error' => $this->sanitizeErrorMessage($e->getMessage()),
      ]);
      return;
    }

    // Check if enough time has passed since the last run
    $last_run_timestamp = $this->state->get('bento_sdk.cart_abandonment.last_run', 0);
    $current_time = time();
    $check_interval_seconds = $check_interval * 60; // Convert minutes to seconds
    
    if (($current_time - $last_run_timestamp) < $check_interval_seconds) {
      $this->logger->debug('Cart abandonment processing skipped: Check interval not yet elapsed. Last run: @last_run, Current time: @current_time, Required interval: @interval_seconds seconds', [
        '@last_run' => $last_run_timestamp,
        '@current_time' => $current_time,
        '@interval_seconds' => $check_interval_seconds,
      ]);
      return;
    }

    $threshold_timestamp = $current_time - ($threshold_hours * 3600);
    $batch_size = $config->get('commerce_integration.cart_abandonment.batch_size') ?? self::DEFAULT_BATCH_SIZE;

    // Process abandoned carts
    try {
      $this->processAbandonedCartsBatch($threshold_timestamp, $batch_size);
      
      $this->logger->debug('Cart abandonment processing completed successfully');
    } catch (\Exception $e) {
      $this->logger->error('Cart abandonment processing failed: @error', [
        '@error' => $this->sanitizeErrorMessage($e->getMessage()),
      ]);
    }
    
    // Update the last run timestamp regardless of processing success/failure
    // This ensures the check interval is respected on subsequent runs
    $this->state->set('bento_sdk.cart_abandonment.last_run', $current_time);
    $this->logger->debug('Updated last run timestamp to: @timestamp', [
      '@timestamp' => $current_time,
    ]);
  }

  /**
   * Process abandoned carts in batches to handle large datasets efficiently.
   *
   * @param int $threshold_timestamp
   *   Unix timestamp before which carts are considered abandoned.
   * @param int $batch_size
   *   Number of carts to process in each batch.
   */
  private function processAbandonedCartsBatch(int $threshold_timestamp, int $batch_size): void {
    $total_processed = 0;
    $total_sent = 0;
    $offset = 0;

    do {
      $abandoned_carts = $this->findAbandonedCarts($threshold_timestamp, $batch_size, $offset);
      
      if (empty($abandoned_carts)) {
        break;
      }

      $batch_processed = 0;
      $batch_sent = 0;

      foreach ($abandoned_carts as $cart) {
        $batch_processed++;
        if ($this->processAbandonedCart($cart)) {
          $batch_sent++;
        }
      }

      $total_processed += $batch_processed;
      $total_sent += $batch_sent;
      $offset += $batch_size;

      $this->logger->debug('Processed batch of @batch_size carts: @sent events sent', [
        '@batch_size' => $batch_processed,
        '@sent' => $batch_sent,
      ]);

    } while (count($abandoned_carts) === $batch_size);

    $this->logger->info('Cart abandonment processing completed: @total_processed carts processed, @total_sent events sent', [
      '@total_processed' => $total_processed,
      '@total_sent' => $total_sent,
    ]);
  }

  /**
   * Validate cart abandonment threshold value.
   *
   * @param int $threshold
   *   The threshold value in hours to validate.
   *
   * @throws \InvalidArgumentException
   *   When the threshold is outside the valid range.
   */
  private function validateThreshold(int $threshold): void {
    if ($threshold < self::MIN_THRESHOLD_HOURS || $threshold > self::MAX_THRESHOLD_HOURS) {
      throw new \InvalidArgumentException(sprintf(
        'Cart abandonment threshold must be between %d and %d hours',
        self::MIN_THRESHOLD_HOURS,
        self::MAX_THRESHOLD_HOURS
      ));
    }
  }

  /**
   * Validate check interval value.
   *
   * @param int $check_interval
   *   The check interval value in minutes to validate.
   *
   * @throws \InvalidArgumentException
   *   When the check interval is outside the valid range.
   */
  private function validateCheckInterval(int $check_interval): void {
    if ($check_interval < self::MIN_CHECK_INTERVAL_MINUTES || $check_interval > self::MAX_CHECK_INTERVAL_MINUTES) {
      throw new \InvalidArgumentException(sprintf(
        'Check interval must be between %d and %d minutes',
        self::MIN_CHECK_INTERVAL_MINUTES,
        self::MAX_CHECK_INTERVAL_MINUTES
      ));
    }
  }

  /**
   * Find carts that meet abandonment criteria with pagination support.
   *
   * Queries for draft orders (carts) that haven't been updated since the
   * threshold timestamp and filters them for abandonment criteria.
   *
   * @param int $threshold_timestamp
   *   Unix timestamp before which carts are considered abandoned.
   * @param int $limit
   *   Maximum number of carts to return.
   * @param int $offset
   *   Number of carts to skip.
   *
   * @return array
   *   Array of OrderInterface objects representing abandoned carts.
   */
  private function findAbandonedCarts(int $threshold_timestamp, int $limit = self::DEFAULT_BATCH_SIZE, int $offset = 0): array {
    $order_storage = $this->entityTypeManager->getStorage('commerce_order');
    
    // Get cart order IDs that haven't been updated since threshold
    $query = $order_storage->getQuery()
      ->condition('state', 'draft') // Only draft orders (carts)
      ->condition('changed', $threshold_timestamp, '<')
      ->condition('cart', TRUE) // Only cart orders
      ->range($offset, $limit)
      ->sort('changed', 'ASC') // Process oldest carts first
      ->accessCheck(FALSE);

    $cart_ids = $query->execute();
    
    if (empty($cart_ids)) {
      return [];
    }

    // Load carts and filter for those with items and email
    $carts = $order_storage->loadMultiple($cart_ids);
    $abandoned_carts = [];

    foreach ($carts as $cart) {
      if ($this->isCartAbandoned($cart)) {
        $abandoned_carts[] = $cart;
      }
    }

    return $abandoned_carts;
  }

  /**
   * Check if a cart meets abandonment criteria.
   *
   * Validates that the cart has items, a valid email address, hasn't already
   * had an abandonment event sent, and is still in draft state.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $cart
   *   The cart order entity to check.
   *
   * @return bool
   *   TRUE if the cart is considered abandoned, FALSE otherwise.
   */
  private function isCartAbandoned(OrderInterface $cart): bool {
    // Must have items
    if (empty($cart->getItems())) {
      return FALSE;
    }

    // Check if email collection is allowed
    if (!$this->emailExtractor->isEmailCollectionAllowed($cart)) {
      return FALSE;
    }

    // Must have valid email
    $email = $this->emailExtractor->extractEmailFromOrder($cart);
    if (!$email) {
      return FALSE;
    }

    // Check if we've already sent abandonment event
    if ($this->hasAbandonmentEventBeenSent($cart)) {
      return FALSE;
    }

    // Must not be placed
    if ($cart->getState()->getId() !== 'draft') {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Process a single abandoned cart.
   *
   * Extracts cart data, generates recovery URL, builds event data,
   * and sends the cart abandonment event to Bento.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $cart
   *   The abandoned cart to process.
   *
   * @return bool
   *   TRUE if the event was sent successfully, FALSE otherwise.
   */
  private function processAbandonedCart(OrderInterface $cart): bool {
    $email = $this->emailExtractor->extractEmailFromOrder($cart);
    if (!$email) {
      $this->logger->warning('Cannot process abandoned cart @cart_id: No valid email found', [
        '@cart_id' => $cart->id(),
      ]);
      return FALSE;
    }

    // Generate cart recovery URL
    $recovery_url = $this->generateCartRecoveryUrl($cart);

    // Build abandonment event data
    $event_data = [
      'type' => '$cart_abandoned',
      'email' => $email,
      'details' => [
        'cart_id' => $cart->id(),
        'cart_total' => $this->formatPrice($cart->getTotalPrice()),
        'currency' => $cart->getTotalPrice()->getCurrencyCode(),
        'item_count' => count($cart->getItems()),
        'items' => $this->formatCartItems($cart->getItems()),
        'abandoned_at' => time(),
        'cart_created' => $cart->getCreatedTime(),
        'cart_updated' => $cart->getChangedTime(),
        'recovery_url' => $recovery_url,
        'cart' => [
          'items' => $this->formatCartItemsForBento($cart->getItems()),
          'abandoned_checkout_url' => $recovery_url,
        ],
      ],
    ];

    // Add customer fields if available
    $customer_name = $this->emailExtractor->extractCustomerName($cart);
    if (!empty($customer_name)) {
      $event_data['fields'] = $customer_name;
    }

    // Send event
    try {
      $this->bentoService->sendEvent($event_data);
      
      // Mark as sent to prevent duplicates
      $this->markAbandonmentEventSent($cart);
      
      $this->logger->info('Cart abandonment event sent successfully for cart @cart_id', [
        '@cart_id' => $cart->id(),
      ]);
      
      return TRUE;
    } catch (\Exception $e) {
      $this->logger->error('Failed to send cart abandonment event for cart @cart_id: @error', [
        '@cart_id' => $cart->id(),
        '@error' => $this->sanitizeErrorMessage($e->getMessage()),
      ]);
      
      return FALSE;
    }
  }

  /**
   * Check if abandonment event has already been sent for this cart.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $cart
   *   The cart to check.
   *
   * @return bool
   *   TRUE if an abandonment event has been sent, FALSE otherwise.
   */
  private function hasAbandonmentEventBeenSent(OrderInterface $cart): bool {
    $sent_events = $this->state->get('bento_sdk.cart_abandonment_sent', []);
    return in_array($cart->id(), $sent_events);
  }

  /**
   * Mark abandonment event as sent for this cart.
   *
   * Stores the cart ID in state to prevent duplicate abandonment events.
   * Maintains only the most recent entries to prevent unlimited growth.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $cart
   *   The cart to mark as processed.
   */
  private function markAbandonmentEventSent(OrderInterface $cart): void {
    $sent_events = $this->state->get('bento_sdk.cart_abandonment_sent', []);
    $sent_events[] = $cart->id();
    
    // Keep only recent entries to prevent unlimited growth
    $sent_events = array_slice($sent_events, -self::MAX_PROCESSED_CARTS_LIMIT);
    
    $this->state->set('bento_sdk.cart_abandonment_sent', $sent_events);
  }

  /**
   * Generate cart recovery URL.
   *
   * Creates an absolute URL to the cart page with the cart ID as a parameter
   * to allow customers to return to their abandoned cart.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $cart
   *   The cart to generate a recovery URL for.
   *
   * @return string
   *   The absolute URL for cart recovery.
   */
  private function generateCartRecoveryUrl(OrderInterface $cart): string {
    try {
      // Generate URL to cart page with cart ID
      $url = Url::fromRoute('commerce_cart.page', [], [
        'absolute' => TRUE,
        'query' => ['cart_id' => $cart->id()],
      ]);
      
      return $url->toString();
    } catch (\Exception $e) {
      $this->logger->warning('Could not generate cart recovery URL for cart @cart_id: @error', [
        '@cart_id' => $cart->id(),
        '@error' => $this->sanitizeErrorMessage($e->getMessage()),
      ]);
      
      // Fallback to basic cart URL
      try {
        return Url::fromRoute('commerce_cart.page', [], ['absolute' => TRUE])->toString();
      } catch (\Exception $fallback_e) {
        $this->logger->error('Failed to generate fallback cart recovery URL for cart @cart_id: @error', [
          '@cart_id' => $cart->id(),
          '@error' => $this->sanitizeErrorMessage($fallback_e->getMessage()),
        ]);
        return '';
      }
    }
  }

  /**
   * Format cart items for Bento (reuse from CommerceEventProcessor).
   *
   * @param array $items
   *   Array of cart items.
   *
   * @return array
   *   Formatted items array with product details.
   */
  private function formatCartItems(array $items): array {
    $formatted_items = [];
    foreach ($items as $item) {
      $product = $item->getPurchasedEntity();
      if (!$product) {
        continue;
      }
      
      $formatted_items[] = [
        'product_id' => $product->id(),
        'product_sku' => $product->getSku(),
        'product_title' => $product->getTitle(),
        'quantity' => (int) $item->getQuantity(),
        'unit_price' => $this->formatPrice($item->getUnitPrice()),
        'total_price' => $this->formatPrice($item->getTotalPrice()),
      ];
    }
    return $formatted_items;
  }

  /**
   * Format cart items specifically for Bento cart structure.
   *
   * @param array $items
   *   Array of cart items.
   *
   * @return array
   *   Formatted items array for Bento cart structure.
   */
  private function formatCartItemsForBento(array $items): array {
    $formatted_items = [];
    foreach ($items as $item) {
      $product = $item->getPurchasedEntity();
      if (!$product) {
        continue;
      }
      
      $formatted_items[] = [
        'product_sku' => $product->getSku(),
        'product_name' => $product->getTitle(),
        'quantity' => (int) $item->getQuantity(),
      ];
    }
    return $formatted_items;
  }

  /**
   * Format price for API.
   *
   * @param \Drupal\commerce_price\Price $price
   *   The price object.
   *
   * @return array
   *   Formatted price array with amount in cents and currency.
   */
  private function formatPrice($price): array {
    return [
      'amount' => (int) ($price->getNumber() * 100), // Convert to cents
      'currency' => $price->getCurrencyCode(),
    ];
  }

}