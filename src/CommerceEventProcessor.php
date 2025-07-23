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
   * The email extractor service.
   *
   * @var \Drupal\bento_sdk\CommerceEmailExtractor
   */
  protected CommerceEmailExtractor $emailExtractor;

  /**
   * The data enricher service.
   *
   * @var \Drupal\bento_sdk\CommerceDataEnricher
   */
  protected CommerceDataEnricher $dataEnricher;

  /**
   * Constructs a new CommerceEventProcessor.
   *
   * @param \Drupal\bento_sdk\BentoService $bento_service
   *   The Bento service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   * @param \Drupal\bento_sdk\CommerceEmailExtractor $email_extractor
   *   The email extractor service.
   * @param \Drupal\bento_sdk\CommerceDataEnricher $data_enricher
   *   The data enricher service.
   */
  public function __construct(BentoService $bento_service, ConfigFactoryInterface $config_factory, LoggerInterface $logger, CommerceEmailExtractor $email_extractor, CommerceDataEnricher $data_enricher) {
    $this->bentoService = $bento_service;
    $this->configFactory = $config_factory;
    $this->logger = $logger;
    $this->emailExtractor = $email_extractor;
    $this->dataEnricher = $data_enricher;
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

    // Check if email collection is allowed
    if (!$this->emailExtractor->isEmailCollectionAllowed($cart)) {
      $this->logger->info('Skipping cart event - email collection not allowed for cart @cart_id', [
        '@cart_id' => $cart->id(),
      ]);
      return;
    }

    // Extract email from cart
    $email = $this->emailExtractor->extractEmailFromOrder($cart);
    if (!$email) {
      $this->logger->info('Skipping cart event - no valid email found for cart @cart_id', [
        '@cart_id' => $cart->id(),
      ]);
      return;
    }

    // Build event data with enriched information
    $event_data = [
      'type' => $event_type,
      'email' => $email,
      'details' => [
        'cart_id' => $cart->id(),
        'cart_total' => $this->formatPrice($cart->getTotalPrice()),
        'currency' => $cart->getTotalPrice()->getCurrencyCode(),
        'item_count' => count($cart->getItems()),
        'items' => $this->dataEnricher->enrichOrderItems($cart->getItems()),
        'created' => $cart->getCreatedTime(),
        'changed' => $cart->getChangedTime(),
        'cart_url' => $cart->toUrl('canonical', ['absolute' => TRUE])->toString(),
      ],
    ];

    // Add enriched customer context
    $customer_context = $this->dataEnricher->enrichCustomerContext($cart);
    if (!empty($customer_context)) {
      $event_data['details']['customer'] = $customer_context;
    }

    // Add enriched order context
    $order_context = $this->dataEnricher->enrichOrderContext($cart);
    if (!empty($order_context)) {
      $event_data['details'] = array_merge($event_data['details'], $order_context);
    }

    // Add customer fields if available
    $customer_name = $this->emailExtractor->extractCustomerName($cart);
    if (!empty($customer_name)) {
      $event_data['fields'] = $customer_name;
    }

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

    // Check if email collection is allowed
    if (!$this->emailExtractor->isEmailCollectionAllowed($order)) {
      $this->logger->info('Skipping order event - email collection not allowed for order @order_id', [
        '@order_id' => $order->id(),
      ]);
      return;
    }

    // Extract email from order
    $email = $this->emailExtractor->extractEmailFromOrder($order);
    if (!$email) {
      $this->logger->info('Skipping order event - no valid email found for order @order_id', [
        '@order_id' => $order->id(),
      ]);
      return;
    }

    // Build event data with enriched information
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
        'items' => $this->dataEnricher->enrichOrderItems($order->getItems()),
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

    // Add enriched customer context
    $customer_context = $this->dataEnricher->enrichCustomerContext($order);
    if (!empty($customer_context)) {
      $event_data['details']['customer'] = $customer_context;
    }

    // Add enriched order context
    $order_context = $this->dataEnricher->enrichOrderContext($order);
    if (!empty($order_context)) {
      $event_data['details'] = array_merge($event_data['details'], $order_context);
    }

    // Add customer information
    $customer_name = $this->emailExtractor->extractCustomerName($order);
    if (!empty($customer_name)) {
      $event_data['fields'] = $customer_name;
    }
    
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
    
    // Check if email collection is allowed
    if (!$this->emailExtractor->isEmailCollectionAllowed($order)) {
      $this->logger->info('Skipping payment event - email collection not allowed for order @order_id', [
        '@order_id' => $order->id(),
      ]);
      return;
    }

    // Extract email from order
    $email = $this->emailExtractor->extractEmailFromOrder($order);
    if (!$email) {
      $this->logger->info('Skipping payment event - no valid email found for order @order_id', [
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
    $customer_name = $this->emailExtractor->extractCustomerName($order);
    if (!empty($customer_name)) {
      $event_data['fields'] = $customer_name;
    }

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



}