<?php

namespace Drupal\bento_sdk;

use Drupal\commerce_cart\Event\CartEvents;
use Drupal\commerce_cart\Event\CartEntityAddEvent;
use Drupal\commerce_cart\Event\CartOrderItemUpdateEvent;
use Drupal\commerce_cart\Event\CartOrderItemRemoveEvent;
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
    // Only subscribe to events if Commerce cart module is available
    if (!\Drupal::moduleHandler()->moduleExists('commerce_cart')) {
      return [];
    }

    // Check if the CartEvents class exists to avoid fatal errors
    if (!class_exists('\Drupal\commerce_cart\Event\CartEvents')) {
      return [];
    }

    return [
      CartEvents::CART_ENTITY_ADD => 'onCartItemAdd',
      CartEvents::CART_ORDER_ITEM_UPDATE => 'onCartItemUpdate',
      CartEvents::CART_ORDER_ITEM_REMOVE => 'onCartItemRemove',
    ];
  }

  /**
   * Handles cart item addition events.
   *
   * @param \Drupal\commerce_cart\Event\CartEntityAddEvent $event
   *   The cart entity add event.
   */
  public function onCartItemAdd(CartEntityAddEvent $event): void {
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

    // Check if cart was created within the last few seconds
    $created_time = $cart->getCreatedTime();
    $current_time = time();
    
    // Consider it a new cart if created within the last 10 seconds
    return ($current_time - $created_time) <= 10;
  }

}