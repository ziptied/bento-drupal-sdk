<?php

namespace Drupal\bento_sdk;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Url;
use Psr\Log\LoggerInterface;

/**
 * Service for enriching Commerce event data with detailed product and customer information.
 *
 * Provides comprehensive data enrichment for Commerce events including
 * product details, customer context, order metadata, and analytics data.
 */
class CommerceDataEnricher {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  private LoggerInterface $logger;

  /**
   * The file URL generator service.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface
   */
  private FileUrlGeneratorInterface $fileUrlGenerator;

  /**
   * Constructs a new CommerceDataEnricher.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $file_url_generator
   *   The file URL generator service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    LoggerInterface $logger,
    FileUrlGeneratorInterface $file_url_generator
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger;
    $this->fileUrlGenerator = $file_url_generator;

    // Check if Commerce module is available
    if (!$this->isCommerceModuleAvailable()) {
      $this->logger->warning('Commerce module not available - CommerceDataEnricher will not function properly');
    }
  }

  /**
   * Check if the Commerce module is available.
   *
   * @return bool
   *   TRUE if Commerce module is available, FALSE otherwise.
   */
  private function isCommerceModuleAvailable(): bool {
    return class_exists('\Drupal\commerce_order\Entity\OrderInterface');
  }

  /**
   * Enrich order items with detailed product information.
   *
   * Processes an array of order items and enriches each with comprehensive
   * product details including attributes, categories, images, and metadata.
   *
   * @param array $items
   *   Array of order item entities.
   *
   * @return array
   *   Array of enriched item data.
   */
  public function enrichOrderItems(array $items): array {
    // Check if Commerce is available
    if (!$this->isCommerceModuleAvailable()) {
      return [];
    }

    $enriched_items = [];

    foreach ($items as $item) {
      $enriched_item = $this->enrichOrderItem($item);
      if ($enriched_item) {
        $enriched_items[] = $enriched_item;
      }
    }

    return $enriched_items;
  }

  /**
   * Enrich a single order item with product details.
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $item
   *   The order item to enrich.
   *
   * @return array|null
   *   Enriched item data or NULL if item cannot be processed.
   */
  private function enrichOrderItem(OrderItemInterface $item): ?array {
    $variation = $item->getPurchasedEntity();
    if (!$variation instanceof ProductVariationInterface) {
      return NULL;
    }

    $product = $variation->getProduct();
    if (!$product instanceof ProductInterface) {
      return NULL;
    }

    $enriched_item = [
      // Basic item information
      'order_item_id' => $item->id(),
      'quantity' => (int) $item->getQuantity(),
      'unit_price' => $this->formatPrice($item->getUnitPrice()),
      'total_price' => $this->formatPrice($item->getTotalPrice()),

      // Product variation details
      'product_variation_id' => $variation->id(),
      'product_sku' => $variation->getSku(),
      'product_title' => $variation->getTitle(),

      // Product details
      'product_id' => $product->id(),
      'product_name' => $product->getTitle(),
      'product_type' => $product->bundle(),
      'product_url' => $this->getProductUrl($product),

      // Product attributes
      'attributes' => $this->getProductAttributes($variation),

      // Product categories
      'categories' => $this->getProductCategories($product),

      // Product images
      'images' => $this->getProductImages($product, $variation),

      // Additional product data
      'brand' => $this->getProductBrand($product),
      'weight' => $this->getProductWeight($variation),
      'dimensions' => $this->getProductDimensions($variation),
    ];

    // Add custom product fields
    $enriched_item = array_merge($enriched_item, $this->getCustomProductFields($product));

    return array_filter($enriched_item, function ($value) {
      return $value !== NULL && $value !== '';
    });
  }

  /**
   * Get product attributes from variation.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface $variation
   *   The product variation.
   *
   * @return array
   *   Array of product attributes.
   */
  private function getProductAttributes(ProductVariationInterface $variation): array {
    $attributes = [];

    foreach ($variation->getAttributeValues() as $attribute_value) {
      $attribute = $attribute_value->getAttribute();
      $attributes[$attribute->id()] = [
        'name' => $attribute->label(),
        'value' => $attribute_value->getName(),
        'value_id' => $attribute_value->id(),
      ];
    }

    return $attributes;
  }

  /**
   * Get product categories/taxonomy terms.
   *
   * @param \Drupal\commerce_product\Entity\ProductInterface $product
   *   The product entity.
   *
   * @return array
   *   Array of product categories.
   */
  private function getProductCategories(ProductInterface $product): array {
    $categories = [];

    // Common category field names
    $category_fields = ['field_category', 'field_categories', 'field_product_category'];

    foreach ($category_fields as $field_name) {
      if ($product->hasField($field_name) && !$product->get($field_name)->isEmpty()) {
        foreach ($product->get($field_name)->referencedEntities() as $term) {
          $categories[] = [
            'id' => $term->id(),
            'name' => $term->getName(),
            'vocabulary' => $term->bundle(),
          ];
        }
      }
    }

    return $categories;
  }

  /**
   * Get product images.
   *
   * @param \Drupal\commerce_product\Entity\ProductInterface $product
   *   The product entity.
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface $variation
   *   The product variation.
   *
   * @return array
   *   Array of product images.
   */
  private function getProductImages(ProductInterface $product, ProductVariationInterface $variation): array {
    $images = [];

    // Try variation images first
    if ($variation->hasField('field_image') && !$variation->get('field_image')->isEmpty()) {
      foreach ($variation->get('field_image')->referencedEntities() as $file) {
        $images[] = [
          'url' => $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri()),
          'alt' => $variation->get('field_image')->alt ?? '',
          'title' => $variation->get('field_image')->title ?? '',
        ];
      }
    }

    // Fallback to product images
    if (empty($images) && $product->hasField('field_image') && !$product->get('field_image')->isEmpty()) {
      foreach ($product->get('field_image')->referencedEntities() as $file) {
        $images[] = [
          'url' => $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri()),
          'alt' => $product->get('field_image')->alt ?? '',
          'title' => $product->get('field_image')->title ?? '',
        ];
      }
    }

    return $images;
  }

  /**
   * Get product brand information.
   *
   * @param \Drupal\commerce_product\Entity\ProductInterface $product
   *   The product entity.
   *
   * @return string|null
   *   The product brand if found, NULL otherwise.
   */
  private function getProductBrand(ProductInterface $product): ?string {
    $brand_fields = ['field_brand', 'field_manufacturer', 'field_vendor'];

    foreach ($brand_fields as $field_name) {
      if ($product->hasField($field_name) && !$product->get($field_name)->isEmpty()) {
        $field_value = $product->get($field_name)->first();
        if ($field_value) {
          // Handle entity reference
          if (method_exists($field_value, 'entity') && $field_value->entity) {
            return $field_value->entity->getName();
          }
          // Handle text field
          if (isset($field_value->value)) {
            return $field_value->value;
          }
        }
      }
    }

    return NULL;
  }

  /**
   * Get product weight.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface $variation
   *   The product variation.
   *
   * @return array|null
   *   Weight data with value and unit, or NULL if not available.
   */
  private function getProductWeight(ProductVariationInterface $variation): ?array {
    if ($variation->hasField('weight') && !$variation->get('weight')->isEmpty()) {
      $weight = $variation->get('weight')->first();
      return [
        'value' => (float) $weight->number,
        'unit' => $weight->unit,
      ];
    }

    return NULL;
  }

  /**
   * Get product dimensions.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface $variation
   *   The product variation.
   *
   * @return array|null
   *   Dimensions data with length, width, height, and unit, or NULL if not available.
   */
  private function getProductDimensions(ProductVariationInterface $variation): ?array {
    if ($variation->hasField('dimensions') && !$variation->get('dimensions')->isEmpty()) {
      $dimensions = $variation->get('dimensions')->first();
      return [
        'length' => (float) $dimensions->length,
        'width' => (float) $dimensions->width,
        'height' => (float) $dimensions->height,
        'unit' => $dimensions->unit,
      ];
    }

    return NULL;
  }

  /**
   * Get custom product fields.
   *
   * @param \Drupal\commerce_product\Entity\ProductInterface $product
   *   The product entity.
   *
   * @return array
   *   Array of custom field values.
   */
  private function getCustomProductFields(ProductInterface $product): array {
    $custom_fields = [];

    // Define custom fields to include
    $field_mapping = [
      'field_description' => 'description',
      'field_short_description' => 'short_description',
      'field_features' => 'features',
      'field_specifications' => 'specifications',
    ];

    foreach ($field_mapping as $field_name => $key) {
      if ($product->hasField($field_name) && !$product->get($field_name)->isEmpty()) {
        $field_value = $product->get($field_name)->first();
        if ($field_value && isset($field_value->value)) {
          $custom_fields[$key] = $field_value->value;
        }
      }
    }

    return $custom_fields;
  }

  /**
   * Get product URL.
   *
   * @param \Drupal\commerce_product\Entity\ProductInterface $product
   *   The product entity.
   *
   * @return string
   *   The absolute product URL or empty string if URL cannot be generated.
   */
  private function getProductUrl(ProductInterface $product): string {
    try {
      return $product->toUrl('canonical', ['absolute' => TRUE])->toString();
    }
    catch (\Exception $e) {
      $this->logger->warning('Could not generate product URL for product @id: @error', [
        '@id' => $product->id(),
        '@error' => $e->getMessage(),
      ]);
      return '';
    }
  }

  /**
   * Enrich order with customer context.
   *
   * Adds comprehensive customer information including demographics,
   * purchase history, and behavioral data.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order entity.
   *
   * @return array
   *   Array of customer context data.
   */
  public function enrichCustomerContext($order): array {
    // Check if Commerce is available
    if (!$this->isCommerceModuleAvailable()) {
      return [];
    }
    $customer_context = [];

    $customer = $order->getCustomer();
    if ($customer && $customer->isAuthenticated()) {
      $customer_context = [
        'customer_id' => $customer->id(),
        'customer_created' => $customer->getCreatedTime(),
        'customer_last_login' => $customer->getLastLoginTime(),
        'customer_timezone' => $customer->getTimeZone(),
        'customer_preferred_langcode' => $customer->getPreferredLangcode(),
        'customer_roles' => $customer->getRoles(),
        'is_new_customer' => $this->isNewCustomer($customer),
        'customer_lifetime_value' => $this->getCustomerLifetimeValue($customer),
        'previous_order_count' => $this->getCustomerOrderCount($customer),
        'last_order_date' => $this->getCustomerLastOrderDate($customer),
      ];
    }

    return array_filter($customer_context, function ($value) {
      return $value !== NULL && $value !== '';
    });
  }

  /**
   * Enrich order with shipping and payment context.
   *
   * Adds order metadata including shipping, payment, and discount information.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order entity.
   *
   * @return array
   *   Array of order context data.
   */
  public function enrichOrderContext($order): array {
    // Check if Commerce is available
    if (!$this->isCommerceModuleAvailable()) {
      return [];
    }
    $order_context = [
      'order_created' => $order->getCreatedTime(),
      'order_placed' => $order->getPlacedTime(),
      'order_completed' => $order->getCompletedTime(),
      'order_state' => $order->getState()->getId(),
      'order_workflow' => $order->getState()->getWorkflow()->getId(),
      'store_id' => $order->getStoreId(),
      'currency' => $order->getTotalPrice()->getCurrencyCode(),
    ];

    // Add shipping information
    $shipping_info = $this->getShippingInformation($order);
    if (!empty($shipping_info)) {
      $order_context['shipping'] = $shipping_info;
    }

    // Add payment information
    $payment_info = $this->getPaymentInformation($order);
    if (!empty($payment_info)) {
      $order_context['payment'] = $payment_info;
    }

    // Add discount information
    $discount_info = $this->getDiscountInformation($order);
    if (!empty($discount_info)) {
      $order_context['discounts'] = $discount_info;
    }

    return array_filter($order_context, function ($value) {
      return $value !== NULL && $value !== '';
    });
  }

  /**
   * Get shipping information from order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order entity.
   *
   * @return array
   *   Array of shipping information.
   */
  private function getShippingInformation(OrderInterface $order): array {
    $shipping_info = [];

    $shipments = $order->get('shipments')->referencedEntities();
    if (!empty($shipments)) {
      $shipment = $shipments[0]; // Use first shipment

      $shipping_info = [
        'shipment_id' => $shipment->id(),
        'shipping_method' => $shipment->getShippingMethod() ? $shipment->getShippingMethod()->getName() : NULL,
        'shipping_service' => $shipment->getShippingService() ? $shipment->getShippingService()->getName() : NULL,
        'tracking_code' => $shipment->getTrackingCode(),
        'shipment_state' => $shipment->getState()->getId(),
      ];
    }

    return array_filter($shipping_info);
  }

  /**
   * Get payment information from order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order entity.
   *
   * @return array
   *   Array of payment information.
   */
  private function getPaymentInformation(OrderInterface $order): array {
    $payment_info = [];

    $payments = $order->get('payments')->referencedEntities();
    if (!empty($payments)) {
      $payment = $payments[0]; // Use first payment

      $payment_info = [
        'payment_id' => $payment->id(),
        'payment_method' => $payment->getPaymentMethod() ? $payment->getPaymentMethod()->label() : NULL,
        'payment_gateway' => $payment->getPaymentGateway()->label(),
        'payment_state' => $payment->getState()->getId(),
        'authorized_time' => $payment->getAuthorizedTime(),
        'completed_time' => $payment->getCompletedTime(),
      ];
    }

    return array_filter($payment_info);
  }

  /**
   * Get discount/promotion information from order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order entity.
   *
   * @return array
   *   Array of discount information.
   */
  private function getDiscountInformation(OrderInterface $order): array {
    $discounts = [];

    foreach ($order->getAdjustments() as $adjustment) {
      if ($adjustment->getType() === 'promotion') {
        $discounts[] = [
          'type' => $adjustment->getType(),
          'label' => $adjustment->getLabel(),
          'amount' => $this->formatPrice($adjustment->getAmount()),
          'source_id' => $adjustment->getSourceId(),
        ];
      }
    }

    return $discounts;
  }

  /**
   * Check if customer is new (first order).
   *
   * @param mixed $customer
   *   The customer user entity.
   *
   * @return bool
   *   TRUE if this is the customer's first order, FALSE otherwise.
   */
  private function isNewCustomer($customer): bool {
    $order_storage = $this->entityTypeManager->getStorage('commerce_order');
    $query = $order_storage->getQuery()
      ->condition('uid', $customer->id())
      ->condition('state', 'completed')
      ->accessCheck(FALSE)
      ->count();

    return $query->execute() <= 1;
  }

  /**
   * Calculate customer lifetime value.
   *
   * @param mixed $customer
   *   The customer user entity.
   *
   * @return float|null
   *   The customer's lifetime value or NULL if no completed orders.
   */
  private function getCustomerLifetimeValue($customer): ?float {
    // Check if Commerce is available
    if (!$this->isCommerceModuleAvailable()) {
      return NULL;
    }

    $order_storage = $this->entityTypeManager->getStorage('commerce_order');
    
    // Use aggregation query for better performance
    $query = $order_storage->getAggregateQuery()
      ->condition('uid', $customer->id())
      ->condition('state', 'completed')
      ->aggregate('total_price.number', 'SUM')
      ->accessCheck(FALSE);
    
    $result = $query->execute();
    
    if (!empty($result) && isset($result[0]['total_price_number_sum'])) {
      $total = (float) $result[0]['total_price_number_sum'];
      return $total > 0 ? $total : NULL;
    }
    
    return NULL;
  }

  /**
   * Get customer order count.
   *
   * @param mixed $customer
   *   The customer user entity.
   *
   * @return int
   *   The number of completed orders for this customer.
   */
  private function getCustomerOrderCount($customer): int {
    $order_storage = $this->entityTypeManager->getStorage('commerce_order');
    $query = $order_storage->getQuery()
      ->condition('uid', $customer->id())
      ->condition('state', 'completed')
      ->accessCheck(FALSE)
      ->count();

    return (int) $query->execute();
  }

  /**
   * Get customer's last order date.
   *
   * @param mixed $customer
   *   The customer user entity.
   *
   * @return int|null
   *   The timestamp of the customer's last completed order or NULL if none.
   */
  private function getCustomerLastOrderDate($customer): ?int {
    $order_storage = $this->entityTypeManager->getStorage('commerce_order');
    $query = $order_storage->getQuery()
      ->condition('uid', $customer->id())
      ->condition('state', 'completed')
      ->sort('completed', 'DESC')
      ->range(0, 1)
      ->accessCheck(FALSE);

    $order_ids = $query->execute();
    if (!empty($order_ids)) {
      $order = $order_storage->load(reset($order_ids));
      return $order->getCompletedTime();
    }

    return NULL;
  }

  /**
   * Format price for API.
   *
   * @param mixed $price
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