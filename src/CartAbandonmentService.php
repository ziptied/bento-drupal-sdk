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
   * all carts that meet the abandonment criteria.
   */
  public function processAbandonedCarts(): void {
    if (!$this->bentoService->isCommerceIntegrationEnabled()) {
      return;
    }

    $config = $this->configFactory->get('bento_sdk.settings');
    if (!$config->get('commerce_integration.enable_cart_abandonment')) {
      return;
    }

    $threshold_hours = $config->get('commerce_integration.cart_abandonment_threshold') ?? 24;
    $threshold_timestamp = time() - ($threshold_hours * 3600);

    $abandoned_carts = $this->findAbandonedCarts($threshold_timestamp);
    
    foreach ($abandoned_carts as $cart) {
      $this->processAbandonedCart($cart);
    }

    $this->logger->info('Processed @count abandoned carts', [
      '@count' => count($abandoned_carts),
    ]);
  }

  /**
   * Find carts that meet abandonment criteria.
   *
   * Queries for draft orders (carts) that haven't been updated since the
   * threshold timestamp and filters them for abandonment criteria.
   *
   * @param int $threshold_timestamp
   *   Unix timestamp before which carts are considered abandoned.
   *
   * @return array
   *   Array of OrderInterface objects representing abandoned carts.
   */
  private function findAbandonedCarts(int $threshold_timestamp): array {
    $order_storage = $this->entityTypeManager->getStorage('commerce_order');
    
    // Get cart order IDs that haven't been updated since threshold
    $query = $order_storage->getQuery()
      ->condition('state', 'draft') // Only draft orders (carts)
      ->condition('changed', $threshold_timestamp, '<')
      ->condition('cart', TRUE) // Only cart orders
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
   */
  private function processAbandonedCart(OrderInterface $cart): void {
    $email = $this->emailExtractor->extractEmailFromOrder($cart);
    if (!$email) {
      return;
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
      
      $this->logger->info('Sent cart abandonment event for cart @cart_id', [
        '@cart_id' => $cart->id(),
      ]);
    } catch (\Exception $e) {
      $this->logger->error('Failed to send cart abandonment event for cart @cart_id: @error', [
        '@cart_id' => $cart->id(),
        '@error' => $this->sanitizeErrorMessage($e->getMessage()),
      ]);
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
   * Maintains only the most recent 1000 entries to prevent unlimited growth.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $cart
   *   The cart to mark as processed.
   */
  private function markAbandonmentEventSent(OrderInterface $cart): void {
    $sent_events = $this->state->get('bento_sdk.cart_abandonment_sent', []);
    $sent_events[] = $cart->id();
    
    // Keep only recent entries to prevent unlimited growth
    $sent_events = array_slice($sent_events, -1000);
    
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