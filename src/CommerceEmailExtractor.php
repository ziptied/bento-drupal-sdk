<?php

namespace Drupal\bento_sdk;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for extracting and validating email addresses from Commerce orders.
 *
 * Provides robust email extraction using multiple fallback strategies
 * and includes validation, sanitization, and GDPR compliance features.
 */
class CommerceEmailExtractor {

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
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  private LoggerInterface $logger;

  /**
   * Constructs a new CommerceEmailExtractor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    EntityTypeManagerInterface $entity_type_manager,
    LoggerInterface $logger
  ) {
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger;

    // Check if Commerce module is available
    if (!class_exists('\Drupal\commerce_order\Entity\OrderInterface')) {
      $this->logger->warning('Commerce module not available - CommerceEmailExtractor will not function properly');
    }
  }

  /**
   * Extract email from Commerce order using multiple strategies.
   *
   * Tries multiple extraction methods in priority order to find a valid
   * email address for the order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order entity to extract email from.
   *
   * @return string|null
   *   The email address if found and valid, NULL otherwise.
   */
  public function extractEmailFromOrder($order): ?string {
    // Check if Commerce is available
    if (!class_exists('\Drupal\commerce_order\Entity\OrderInterface')) {
      return NULL;
    }
    $extraction_methods = [
      'getOrderEmail',
      'getCustomerEmail',
      'getBillingProfileEmail',
      'getShippingProfileEmail',
      'getCheckoutEmail',
    ];

    foreach ($extraction_methods as $method) {
      $email = $this->$method($order);
      if ($email && $this->isValidEmail($email)) {
        $this->logger->debug('Email extracted using method @method for order @order_id', [
          '@method' => $method,
          '@order_id' => $order->id(),
        ]);
        return $email;
      }
    }

    $this->logger->info('No valid email found for order @order_id', [
      '@order_id' => $order->id(),
    ]);

    return NULL;
  }

  /**
   * Extract email from order email field.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order entity.
   *
   * @return string|null
   *   The email address if found, NULL otherwise.
   */
  private function getOrderEmail(OrderInterface $order): ?string {
    if ($order->hasField('mail') && !$order->get('mail')->isEmpty()) {
      return trim($order->get('mail')->value);
    }
    return NULL;
  }

  /**
   * Extract email from customer account.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order entity.
   *
   * @return string|null
   *   The email address if found, NULL otherwise.
   */
  private function getCustomerEmail(OrderInterface $order): ?string {
    $customer = $order->getCustomer();
    if ($customer && $customer->isAuthenticated()) {
      return $customer->getEmail();
    }
    return NULL;
  }

  /**
   * Extract email from billing profile.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order entity.
   *
   * @return string|null
   *   The email address if found, NULL otherwise.
   */
  private function getBillingProfileEmail(OrderInterface $order): ?string {
    $billing_profile = $order->getBillingProfile();
    if (!$billing_profile) {
      return NULL;
    }

    // Try common email field names
    $email_fields = ['field_email', 'email', 'mail'];
    foreach ($email_fields as $field_name) {
      if ($billing_profile->hasField($field_name) && !$billing_profile->get($field_name)->isEmpty()) {
        return trim($billing_profile->get($field_name)->value);
      }
    }

    return NULL;
  }

  /**
   * Extract email from shipping profile.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order entity.
   *
   * @return string|null
   *   The email address if found, NULL otherwise.
   */
  private function getShippingProfileEmail(OrderInterface $order): ?string {
    // Get shipping profile from shipments
    $shipments = $order->get('shipments')->referencedEntities();
    if (empty($shipments)) {
      return NULL;
    }

    $shipping_profile = $shipments[0]->getShippingProfile();
    if (!$shipping_profile) {
      return NULL;
    }

    // Try common email field names
    $email_fields = ['field_email', 'email', 'mail'];
    foreach ($email_fields as $field_name) {
      if ($shipping_profile->hasField($field_name) && !$shipping_profile->get($field_name)->isEmpty()) {
        return trim($shipping_profile->get($field_name)->value);
      }
    }

    return NULL;
  }

  /**
   * Extract email from checkout flow data.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order entity.
   *
   * @return string|null
   *   The email address if found, NULL otherwise.
   */
  private function getCheckoutEmail(OrderInterface $order): ?string {
    // Try to get email from checkout flow data stored in order data
    $order_data = $order->getData();

    if (isset($order_data['checkout_email'])) {
      return trim($order_data['checkout_email']);
    }

    if (isset($order_data['customer_email'])) {
      return trim($order_data['customer_email']);
    }

    return NULL;
  }

  /**
   * Validate email format and domain.
   *
   * @param string $email
   *   The email address to validate.
   *
   * @return bool
   *   TRUE if the email is valid, FALSE otherwise.
   */
  private function isValidEmail(string $email): bool {
    // Basic format validation
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      return FALSE;
    }

    // Check for minimum length
    if (strlen($email) < 5) {
      return FALSE;
    }

    // Check for maximum length (RFC 5321)
    if (strlen($email) > 254) {
      return FALSE;
    }

    // Check for common test/invalid domains
    $invalid_domains = [
      'example.com',
      'test.com',
      'localhost',
      'invalid.invalid',
      'noemail.com',
    ];

    $domain = substr(strrchr($email, '@'), 1);
    if (in_array(strtolower($domain), $invalid_domains)) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Extract customer name information from order.
   *
   * Tries multiple sources to find customer first and last name information.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order entity.
   *
   * @return array
   *   Array with 'first_name' and 'last_name' keys if found.
   */
  public function extractCustomerName($order): array {
    // Check if Commerce is available
    if (!class_exists('\Drupal\commerce_order\Entity\OrderInterface')) {
      return [];
    }
    $name_data = [
      'first_name' => NULL,
      'last_name' => NULL,
    ];

    // Try customer account fields first
    if ($customer = $order->getCustomer()) {
      if ($customer->isAuthenticated()) {
        $name_data = array_merge($name_data, $this->extractNameFromUser($customer));
      }
    }

    // Try billing profile if no name found
    if (empty($name_data['first_name']) && empty($name_data['last_name'])) {
      if ($billing_profile = $order->getBillingProfile()) {
        $name_data = array_merge($name_data, $this->extractNameFromProfile($billing_profile));
      }
    }

    // Try shipping profile if still no name found
    if (empty($name_data['first_name']) && empty($name_data['last_name'])) {
      $shipments = $order->get('shipments')->referencedEntities();
      if (!empty($shipments)) {
        $shipping_profile = $shipments[0]->getShippingProfile();
        if ($shipping_profile) {
          $name_data = array_merge($name_data, $this->extractNameFromProfile($shipping_profile));
        }
      }
    }

    // Filter out empty values
    return array_filter($name_data);
  }

  /**
   * Extract name from user account.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user entity.
   *
   * @return array
   *   Array with name data if found.
   */
  private function extractNameFromUser(UserInterface $user): array {
    $name_data = [];

    $name_fields = [
      'first_name' => ['field_first_name', 'first_name'],
      'last_name' => ['field_last_name', 'last_name'],
    ];

    foreach ($name_fields as $key => $field_names) {
      foreach ($field_names as $field_name) {
        if ($user->hasField($field_name) && !$user->get($field_name)->isEmpty()) {
          $name_data[$key] = trim($user->get($field_name)->value);
          break;
        }
      }
    }

    return $name_data;
  }

  /**
   * Extract name from profile entity.
   *
   * @param mixed $profile
   *   The profile entity.
   *
   * @return array
   *   Array with name data if found.
   */
  private function extractNameFromProfile($profile): array {
    $name_data = [];

    // Try address field first (Commerce standard)
    if ($profile->hasField('address') && !$profile->get('address')->isEmpty()) {
      $address = $profile->get('address')->first()->getValue();
      if (!empty($address['given_name'])) {
        $name_data['first_name'] = trim($address['given_name']);
      }
      if (!empty($address['family_name'])) {
        $name_data['last_name'] = trim($address['family_name']);
      }
    }

    // Try dedicated name fields if address didn't work
    if (empty($name_data)) {
      $name_fields = [
        'first_name' => ['field_first_name', 'first_name'],
        'last_name' => ['field_last_name', 'last_name'],
      ];

      foreach ($name_fields as $key => $field_names) {
        foreach ($field_names as $field_name) {
          if ($profile->hasField($field_name) && !$profile->get($field_name)->isEmpty()) {
            $name_data[$key] = trim($profile->get($field_name)->value);
            break;
          }
        }
      }
    }

    return $name_data;
  }

  /**
   * Check if email collection is allowed for this order.
   *
   * Checks for GDPR compliance and opt-out preferences.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order entity.
   *
   * @return bool
   *   TRUE if email collection is allowed, FALSE otherwise.
   */
  public function isEmailCollectionAllowed($order): bool {
    // Check if Commerce is available
    if (!class_exists('\Drupal\commerce_order\Entity\OrderInterface')) {
      return FALSE;
    }
    // Check if customer has opted out of email collection
    if ($customer = $order->getCustomer()) {
      if ($customer->isAuthenticated()) {
        // Check for opt-out field
        if ($customer->hasField('field_email_opt_out') &&
            !$customer->get('field_email_opt_out')->isEmpty() &&
            $customer->get('field_email_opt_out')->value) {
          return FALSE;
        }
      }
    }

    // Check order-level opt-out
    if ($order->hasField('field_email_opt_out') &&
        !$order->get('field_email_opt_out')->isEmpty() &&
        $order->get('field_email_opt_out')->value) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Sanitize email for logging (privacy protection).
   *
   * Masks part of the email address for privacy-safe logging.
   *
   * @param string $email
   *   The email address to sanitize.
   *
   * @return string
   *   The sanitized email address.
   */
  public function sanitizeEmailForLogging(string $email): string {
    $parts = explode('@', $email);
    if (count($parts) !== 2) {
      return '[invalid-email]';
    }

    $local = $parts[0];
    $domain = $parts[1];

    // Show first 2 chars of local part, mask the rest
    $masked_local = substr($local, 0, 2) . str_repeat('*', max(0, strlen($local) - 2));

    return $masked_local . '@' . $domain;
  }

}