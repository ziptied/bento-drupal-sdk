<?php

namespace Drupal\bento_sdk;

use Drupal\commerce_cart\Event\CartEvents;
use Drupal\commerce_cart\Event\CartEntityAddEvent;
use Drupal\commerce_cart\Event\CartOrderItemUpdateEvent;
use Drupal\commerce_cart\Event\CartOrderItemRemoveEvent;
use Drupal\commerce_order\Event\OrderEvents;
use Drupal\commerce_order\Event\OrderEvent;
use Drupal\commerce_payment\Event\PaymentEvents;
use Drupal\commerce_payment\Event\PaymentEvent;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Psr\Log\LoggerInterface;

/**
 * Event subscriber for Commerce cart events.
 *
 * Listens to cart events and processes them for Bento integration.
 * Handles cart item additions, updates, and removals.
 */
class CommerceEventSubscriber implements EventSubscriberInterface {

  /**
   * Cart creation window in seconds for detecting new carts.
   */
  private const CART_CREATION_WINDOW_SECONDS = 10;

  /**
   * Minimum price difference threshold for tracking order updates.
   */
  private const MIN_PRICE_DIFFERENCE_THRESHOLD = 0.01;

  /**
   * The Commerce event processor service.
   *
   * @var \Drupal\bento_sdk\CommerceEventProcessor
   */
  protected CommerceEventProcessor $processor;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs a new CommerceEventSubscriber.
   *
   * @param \Drupal\bento_sdk\CommerceEventProcessor $processor
   *   The Commerce event processor service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   */
  public function __construct(CommerceEventProcessor $processor, LoggerInterface $logger) {
    $this->processor = $processor;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events = [];

    // Only subscribe to events if the required classes exist
    // We can't use \Drupal::moduleHandler() here as the container isn't ready yet
    
    // Cart events - only if commerce_cart classes are available
    if (class_exists('\Drupal\commerce_cart\Event\CartEvents')) {
      $events[CartEvents::CART_ENTITY_ADD] = 'onCartItemAdd';
      $events[CartEvents::CART_ORDER_ITEM_UPDATE] = 'onCartItemUpdate';
      $events[CartEvents::CART_ORDER_ITEM_REMOVE] = 'onCartItemRemove';
    }

    // Order events - only if commerce_order classes are available
    if (class_exists('\Drupal\commerce_order\Event\OrderEvents')) {
      $events[OrderEvents::ORDER_PLACE] = 'onOrderPlace';
      $events[OrderEvents::ORDER_UPDATE] = 'onOrderUpdate';
    }

    // Payment events - only if commerce_payment classes are available
    if (class_exists('\Drupal\commerce_payment\Event\PaymentEvents')) {
      $events[PaymentEvents::PAYMENT_INSERT] = 'onPaymentInsert';
    }

    // State machine events for order status changes
    if (class_exists('\Drupal\state_machine\Event\WorkflowTransitionEvent')) {
      $events['commerce_order.place.post_transition'] = 'onOrderStateChange';
      $events['commerce_order.fulfill.post_transition'] = 'onOrderStateChange';
      $events['commerce_order.cancel.post_transition'] = 'onOrderStateChange';
    }

    return $events;
  }

  /**
   * Handles cart item addition events.
   *
   * @param \Drupal\commerce_cart\Event\CartEntityAddEvent $event
   *   The cart entity add event.
   */
  public function onCartItemAdd(CartEntityAddEvent $event): void {
    // Double-check that Commerce is actually available
    if (!\Drupal::moduleHandler()->moduleExists('commerce_cart')) {
      return;
    }

    try {
      $cart = $event->getCart();
      
      // Check if this is a new cart (first item added)
      if ($this->isNewCart($cart)) {
        $this->processor->processCartEvent($cart, '$cart_created');
      } else {
        $this->processor->processCartEvent($cart, '$cart_updated');
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to process cart item add event: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Handles cart item update events.
   *
   * @param \Drupal\commerce_cart\Event\CartOrderItemUpdateEvent $event
   *   The cart order item update event.
   */
  public function onCartItemUpdate(CartOrderItemUpdateEvent $event): void {
    // Double-check that Commerce is actually available
    if (!\Drupal::moduleHandler()->moduleExists('commerce_cart')) {
      return;
    }

    try {
      $this->processor->processCartEvent($event->getCart(), '$cart_updated');
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to process cart item update event: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Handles cart item removal events.
   *
   * @param \Drupal\commerce_cart\Event\CartOrderItemRemoveEvent $event
   *   The cart order item remove event.
   */
  public function onCartItemRemove(CartOrderItemRemoveEvent $event): void {
    // Double-check that Commerce is actually available
    if (!\Drupal::moduleHandler()->moduleExists('commerce_cart')) {
      return;
    }

    try {
      $this->processor->processCartEvent($event->getCart(), '$cart_updated');
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to process cart item remove event: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Handles order placement events.
   *
   * @param \Drupal\commerce_order\Event\OrderEvent $event
   *   The order event.
   */
  public function onOrderPlace(OrderEvent $event): void {
    // Double-check that Commerce is actually available
    if (!\Drupal::moduleHandler()->moduleExists('commerce_order')) {
      return;
    }

    try {
      $this->processor->processOrderEvent($event->getOrder(), '$purchase');
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to process order place event: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Handles order update events.
   *
   * @param \Drupal\commerce_order\Event\OrderEvent $event
   *   The order event.
   */
  public function onOrderUpdate(OrderEvent $event): void {
    // Double-check that Commerce is actually available
    if (!\Drupal::moduleHandler()->moduleExists('commerce_order')) {
      return;
    }

    try {
      $order = $event->getOrder();
      if ($this->shouldTrackOrderUpdate($order)) {
        $this->processor->processOrderEvent($order, '$order_updated');
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to process order update event: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Handles payment insertion events.
   *
   * @param \Drupal\commerce_payment\Event\PaymentEvent $event
   *   The payment event.
   */
  public function onPaymentInsert(PaymentEvent $event): void {
    // Double-check that Commerce is actually available
    if (!\Drupal::moduleHandler()->moduleExists('commerce_payment')) {
      return;
    }

    try {
      $payment = $event->getPayment();
      if ($payment->getState()->getId() === 'completed') {
        $this->processor->processPaymentEvent($payment, '$order_paid');
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to process payment insert event: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Handles order state change events.
   *
   * @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
   *   The workflow transition event.
   */
  public function onOrderStateChange(WorkflowTransitionEvent $event): void {
    // Double-check that Commerce is actually available
    if (!\Drupal::moduleHandler()->moduleExists('commerce_order')) {
      return;
    }

    try {
      $order = $event->getEntity();
      $transition = $event->getTransition();
      
      $transition_id = $transition->getId();
      $event_type = NULL;
      
      switch ($transition_id) {
        case 'fulfill':
          $event_type = '$order_fulfilled';
          break;
        case 'cancel':
          $event_type = '$order_cancelled';
          break;
      }
      
      if ($event_type) {
        $this->processor->processOrderEvent($order, $event_type);
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to process order state change event: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Determines if this is a new cart based on item count and timestamps.
   *
   * Since Commerce doesn't have a specific cart creation event, we detect
   * new carts by checking if this is the first item and the cart was just created.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $cart
   *   The cart order entity.
   *
   * @return bool
   *   TRUE if this appears to be a new cart, FALSE otherwise.
   */
  private function isNewCart($cart): bool {
    // Check if cart has only one item and was recently created
    $items = $cart->getItems();
    if (count($items) !== 1) {
      return FALSE;
    }

    // Check if cart was created within the cart creation window
    $created_time = $cart->getCreatedTime();
    $current_time = time();
    
    // Consider it a new cart if created within the cart creation window
    return ($current_time - $created_time) <= self::CART_CREATION_WINDOW_SECONDS;
  }

  /**
   * Determines if an order update should be tracked.
   *
   * Uses efficient change detection by comparing specific fields rather than
   * entire order states to improve performance.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order entity.
   *
   * @return bool
   *   TRUE if the order update should be tracked, FALSE otherwise.
   */
  private function shouldTrackOrderUpdate($order): bool {
    $original = $order->original ?? NULL;
    if (!$original) {
      return FALSE;
    }

    // Track if state changed
    if ($order->getState()->getId() !== $original->getState()->getId()) {
      return TRUE;
    }

    // Track if total changed significantly
    $current_total = $order->getTotalPrice()->getNumber();
    $original_total = $original->getTotalPrice()->getNumber();
    if (abs($current_total - $original_total) > self::MIN_PRICE_DIFFERENCE_THRESHOLD) {
      return TRUE;
    }

    // Track if item count changed
    $current_item_count = count($order->getItems());
    $original_item_count = count($original->getItems());
    if ($current_item_count !== $original_item_count) {
      return TRUE;
    }

    // Track if order status changed (for non-state machine orders)
    if ($order->getStatus() !== $original->getStatus()) {
      return TRUE;
    }

    return FALSE;
  }

}