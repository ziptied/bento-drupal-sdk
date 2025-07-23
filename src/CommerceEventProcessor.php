<?php

namespace Drupal\bento_sdk;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Psr\Log\LoggerInterface;
use Drupal\bento_sdk\BentoSanitizationTrait;

/**
 * Processes Commerce events for Bento integration.
 *
 * Handles the conversion of Commerce cart and order events into
 * Bento-compatible event data and sends them via the Bento service.
 */
class CommerceEventProcessor {
  use BentoSanitizationTrait;

  /**
   * The Bento service.
   *
   * @var \Drupal\bento_sdk\BentoService
   */
  protected BentoService $bentoService;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs a new CommerceEventProcessor.
   *
   * @param \Drupal\bento_sdk\BentoService $bento_service
   *   The Bento service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   */
  public function __construct(BentoService $bento_service, ConfigFactoryInterface $config_factory, LoggerInterface $logger) {
    $this->bentoService = $bento_service;
    $this->configFactory = $config_factory;
    $this->logger = $logger;
  }

  /**
   * Processes a cart event and sends it to Bento.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $cart
   *   The cart order entity.
   * @param string $event_type
   *   The event type (e.g., '$cart_created', '$cart_updated').
   */
  public function processCartEvent(OrderInterface $cart, string $event_type): void {
    // Check if Commerce integration is enabled
    if (!$this->bentoService->isCommerceIntegrationEnabled()) {
      return;
    }

    // Check if cart event tracking is specifically enabled
    if (!$this->bentoService->isCartTrackingEnabled()) {
      return;
    }

    // Extract email from cart
    $email = $this->extractEmailFromCart($cart);
    if (!$email) {
      $this->logger->info('Skipping cart event - no email found for cart @cart_id', [
        '@cart_id' => $cart->id(),
      ]);
      return;
    }

    // Build event data
    $event_data = [
      'type' => $event_type,
      'email' => $email,
      'details' => [
        'cart_id' => $cart->id(),
        'cart_total' => $this->formatPrice($cart->getTotalPrice()),
        'currency' => $cart->getTotalPrice()->getCurrencyCode(),
        'item_count' => count($cart->getItems()),
        'items' => $this->formatCartItems($cart->getItems()),
        'created' => $cart->getCreatedTime(),
        'changed' => $cart->getChangedTime(),
        'cart_url' => $cart->toUrl('canonical', ['absolute' => TRUE])->toString(),
      ],
    ];

    // Add customer fields if available
    $this->addCustomerFields($event_data, $cart);

    // Send event
    $success = $this->bentoService->sendEvent($event_data);
    
    if ($success) {
      $this->logger->info('Commerce cart event sent successfully: @type for cart @cart_id', [
        '@type' => $event_type,
        '@cart_id' => $cart->id(),
      ]);
    } else {
      $this->logger->warning('Failed to send Commerce cart event: @type for cart @cart_id', [
        '@type' => $event_type,
        '@cart_id' => $cart->id(),
      ]);
    }
  }

  /**
   * Extracts email from cart, checking multiple sources.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $cart
   *   The cart order entity.
   *
   * @return string|null
   *   The email address if found, NULL otherwise.
   */
  private function extractEmailFromCart(OrderInterface $cart): ?string {
    // Try to get email from customer first
    if ($customer = $cart->getCustomer()) {
      if ($customer->isAuthenticated()) {
        return $customer->getEmail();
      }
    }

    // Try to get from order email field
    if ($cart->hasField('mail') && !$cart->get('mail')->isEmpty()) {
      return $cart->get('mail')->value;
    }

    // Try to get email from billing profile
    if ($billing_profile = $cart->getBillingProfile()) {
      if ($billing_profile->hasField('field_email') && !$billing_profile->get('field_email')->isEmpty()) {
        return $billing_profile->get('field_email')->value;
      }
    }

    return NULL;
  }

  /**
   * Formats cart items for Bento event data.
   *
   * @param array $items
   *   Array of cart items.
   *
   * @return array
   *   Formatted items array.
   */
  private function formatCartItems(array $items): array {
    $formatted_items = [];
    
    foreach ($items as $item) {
      $product = $item->getPurchasedEntity();
      
      if (!$product) {
        continue;
      }

      $formatted_item = [
        'product_id' => $product->id(),
        'product_sku' => $product->getSku(),
        'product_title' => $product->getTitle(),
        'quantity' => (int) $item->getQuantity(),
        'unit_price' => $this->formatPrice($item->getUnitPrice()),
        'total_price' => $this->formatPrice($item->getTotalPrice()),
      ];

      // Add product URL if available
      if ($product->hasLinkTemplate('canonical')) {
        $formatted_item['product_url'] = $product->toUrl('canonical', ['absolute' => TRUE])->toString();
      }

      $formatted_items[] = $formatted_item;
    }
    
    return $formatted_items;
  }

  /**
   * Formats a price object for Bento event data.
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

  /**
   * Processes an order event and sends it to Bento.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order entity.
   * @param string $event_type
   *   The event type (e.g., '$purchase', '$order_fulfilled').
   */
  public function processOrderEvent(OrderInterface $order, string $event_type): void {
    // Check if Commerce integration is enabled
    if (!$this->bentoService->isCommerceIntegrationEnabled()) {
      return;
    }

    // Check if order event tracking is specifically enabled
    if (!$this->bentoService->isOrderTrackingEnabled()) {
      return;
    }

    // Extract email from order
    $email = $this->extractEmailFromOrder($order);
    if (!$email) {
      $this->logger->info('Skipping order event - no email found for order @order_id', [
        '@order_id' => $order->id(),
      ]);
      return;
    }

    // Build base event data
    $event_data = [
      'type' => $event_type,
      'email' => $email,
      'details' => [
        'order_id' => $order->id(),
        'order_number' => $order->getOrderNumber(),
        'order_total' => $this->formatPrice($order->getTotalPrice()),
        'currency' => $order->getTotalPrice()->getCurrencyCode(),
        'order_state' => $order->getState()->getId(),
        'item_count' => count($order->getItems()),
        'items' => $this->formatOrderItems($order->getItems()),
        'placed' => $order->getPlacedTime(),
        'completed' => $order->getCompletedTime(),
      ],
    ];

    // Add unique identifier and value for purchase events
    if ($event_type === '$purchase') {
      $event_data['details']['unique'] = [
        'key' => $order->getOrderNumber(),
      ];
      $event_data['details']['value'] = [
        'currency' => $order->getTotalPrice()->getCurrencyCode(),
        'amount' => (int) ($order->getTotalPrice()->getNumber() * 100), // Convert to cents
      ];
    }

    // Add customer information
    $this->addCustomerFields($event_data, $order);
    
    // Add billing/shipping information
    $this->addAddressInformation($event_data, $order);

    // Send event
    $success = $this->bentoService->sendEvent($event_data);
    
    if ($success) {
      $this->logger->info('Commerce order event sent successfully: @type for order @order_id', [
        '@type' => $event_type,
        '@order_id' => $order->id(),
      ]);
    } else {
      $this->logger->warning('Failed to send Commerce order event: @type for order @order_id', [
        '@type' => $event_type,
        '@order_id' => $order->id(),
      ]);
    }
  }

  /**
   * Processes a payment event and sends it to Bento.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The payment entity.
   * @param string $event_type
   *   The event type (e.g., '$order_paid').
   */
  public function processPaymentEvent($payment, string $event_type): void {
    // Check if Commerce integration is enabled
    if (!$this->bentoService->isCommerceIntegrationEnabled()) {
      return;
    }

    // Check if payment event tracking is specifically enabled
    if (!$this->bentoService->isPaymentTrackingEnabled()) {
      return;
    }

    $order = $payment->getOrder();
    
    // Extract email from order
    $email = $this->extractEmailFromOrder($order);
    if (!$email) {
      $this->logger->info('Skipping payment event - no email found for order @order_id', [
        '@order_id' => $order->id(),
      ]);
      return;
    }

    // Build payment event data
    $event_data = [
      'type' => $event_type,
      'email' => $email,
      'details' => [
        'order_id' => $order->id(),
        'order_number' => $order->getOrderNumber(),
        'payment_id' => $payment->id(),
        'payment_method' => $payment->getPaymentMethod() ? $payment->getPaymentMethod()->label() : 'Unknown',
        'payment_gateway' => $payment->getPaymentGateway()->label(),
        'amount_paid' => $this->formatPrice($payment->getAmount()),
        'payment_state' => $payment->getState()->getId(),
        'completed_time' => $payment->getCompletedTime(),
      ],
    ];

    // Add customer information
    $this->addCustomerFields($event_data, $order);

    // Send event
    $success = $this->bentoService->sendEvent($event_data);
    
    if ($success) {
      $this->logger->info('Commerce payment event sent successfully: @type for payment @payment_id', [
        '@type' => $event_type,
        '@payment_id' => $payment->id(),
      ]);
    } else {
      $this->logger->warning('Failed to send Commerce payment event: @type for payment @payment_id', [
        '@type' => $event_type,
        '@payment_id' => $payment->id(),
      ]);
    }
  }

  /**
   * Extracts email from order, checking multiple sources.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order entity.
   *
   * @return string|null
   *   The email address if found, NULL otherwise.
   */
  private function extractEmailFromOrder(OrderInterface $order): ?string {
    // Try order email field first
    if ($order->hasField('mail') && !$order->get('mail')->isEmpty()) {
      return $order->get('mail')->value;
    }

    // Try customer email
    if ($customer = $order->getCustomer()) {
      if ($customer->isAuthenticated()) {
        return $customer->getEmail();
      }
    }

    // Try billing profile email
    if ($billing_profile = $order->getBillingProfile()) {
      if ($billing_profile->hasField('field_email') && !$billing_profile->get('field_email')->isEmpty()) {
        return $billing_profile->get('field_email')->value;
      }
    }

    return NULL;
  }

  /**
   * Formats order items for Bento event data.
   *
   * @param array $items
   *   Array of order items.
   *
   * @return array
   *   Formatted items array.
   */
  private function formatOrderItems(array $items): array {
    $formatted_items = [];
    
    foreach ($items as $item) {
      $product = $item->getPurchasedEntity();
      
      if (!$product) {
        continue;
      }

      $formatted_item = [
        'product_id' => $product->id(),
        'product_sku' => $product->getSku(),
        'product_name' => $product->getTitle(),
        'quantity' => (int) $item->getQuantity(),
        'unit_price' => $this->formatPrice($item->getUnitPrice()),
        'total_price' => $this->formatPrice($item->getTotalPrice()),
      ];

      // Add product URL if available
      if ($product->hasLinkTemplate('canonical')) {
        $formatted_item['product_url'] = $product->toUrl('canonical', ['absolute' => TRUE])->toString();
      }

      $formatted_items[] = $formatted_item;
    }
    
    return $formatted_items;
  }

  /**
   * Adds billing and shipping address information to event data.
   *
   * @param array &$event_data
   *   The event data array to modify.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order entity.
   */
  private function addAddressInformation(array &$event_data, OrderInterface $order): void {
    // Add billing address
    if ($billing_profile = $order->getBillingProfile()) {
      if ($billing_profile->hasField('address') && !$billing_profile->get('address')->isEmpty()) {
        $address = $billing_profile->get('address')->first()->getValue();
        $event_data['details']['billing_address'] = [
          'country' => $address['country_code'],
          'state' => $address['administrative_area'],
          'city' => $address['locality'],
          'postal_code' => $address['postal_code'],
          'address_line_1' => $address['address_line1'],
          'address_line_2' => $address['address_line2'],
        ];
      }
    }

    // Add shipping address if different and available
    if ($order->hasField('shipments') && !$order->get('shipments')->isEmpty()) {
      $shipment = $order->get('shipments')->entity;
      if ($shipment && $shipping_profile = $shipment->getShippingProfile()) {
        if ($shipping_profile->hasField('address') && !$shipping_profile->get('address')->isEmpty()) {
          $address = $shipping_profile->get('address')->first()->getValue();
          $event_data['details']['shipping_address'] = [
            'country' => $address['country_code'],
            'state' => $address['administrative_area'],
            'city' => $address['locality'],
            'postal_code' => $address['postal_code'],
            'address_line_1' => $address['address_line1'],
            'address_line_2' => $address['address_line2'],
          ];
        }
      }
    }
  }

  /**
   * Adds customer fields to event data if available.
   *
   * @param array &$event_data
   *   The event data array to modify.
   * @param \Drupal\commerce_order\Entity\OrderInterface $cart
   *   The cart order entity.
   */
  private function addCustomerFields(array &$event_data, OrderInterface $cart): void {
    $fields = [];

    // Try to get customer fields from user account
    if ($customer = $cart->getCustomer()) {
      if ($customer->isAuthenticated()) {
        // Try to get first/last name from user fields
        if ($customer->hasField('field_first_name') && !$customer->get('field_first_name')->isEmpty()) {
          $fields['first_name'] = $customer->get('field_first_name')->value;
        }
        if ($customer->hasField('field_last_name') && !$customer->get('field_last_name')->isEmpty()) {
          $fields['last_name'] = $customer->get('field_last_name')->value;
        }
      }
    }

    // Try to get name from billing profile if not found in user account
    if ($billing_profile = $cart->getBillingProfile()) {
      if (empty($fields['first_name']) && $billing_profile->hasField('field_first_name') && !$billing_profile->get('field_first_name')->isEmpty()) {
        $fields['first_name'] = $billing_profile->get('field_first_name')->value;
      }
      if (empty($fields['last_name']) && $billing_profile->hasField('field_last_name') && !$billing_profile->get('field_last_name')->isEmpty()) {
        $fields['last_name'] = $billing_profile->get('field_last_name')->value;
      }

      // Try address fields for name if custom fields don't exist
      if (empty($fields['first_name']) && empty($fields['last_name']) && $billing_profile->hasField('address') && !$billing_profile->get('address')->isEmpty()) {
        $address = $billing_profile->get('address')->first()->getValue();
        if (!empty($address['given_name'])) {
          $fields['first_name'] = $address['given_name'];
        }
        if (!empty($address['family_name'])) {
          $fields['last_name'] = $address['family_name'];
        }
      }
    }

    if (!empty($fields)) {
      $event_data['fields'] = $fields;
    }
  }

}