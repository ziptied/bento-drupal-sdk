<?php

namespace Drupal\bento_sdk\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Simple controller for test email functionality.
 */
class TestEmailController extends ControllerBase {
  /**
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * @var object
   */
  protected $bentoService;

  public function __construct(ConfigFactoryInterface $config_factory, StateInterface $state, $bento_service) {
    $this->configFactory = $config_factory;
    $this->state = $state;
    $this->bentoService = $bento_service;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('state'),
      $container->get('bento.sdk')
    );
  }

  /**
   * Handles test email sending via AJAX.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with success/error status.
   */
  public function sendTestEmail() {
    $config = $this->configFactory->get('bento_sdk.settings');
    $publishable_key = $config->get('publishable_key');
    $default_author = $config->get('default_author_email');

    // Check if BentoService is configured (includes secret key check)
    $is_configured = $this->bentoService->isConfigured();

    if (empty($default_author)) {
      return new JsonResponse([
        'success' => false,
        'message' => 'No default author email configured.',
      ]);
    }
    
    if (empty($publishable_key) || !$is_configured) {
      return new JsonResponse([
        'success' => false,
        'message' => 'API credentials not configured properly',
      ]);
    }
    
    // Use the BentoService to send the test email
    try {
      $email_data = [
        'to' => $default_author,
        'from' => $default_author, // Use same email as from
        'subject' => 'Test Email from Bento SDK',
        'html_body' => '<p>This is a test email from Bento SDK.</p>',
        'text_body' => 'This is a test email from Bento SDK.',
      ];
      
      $success = $this->bentoService->sendTransactionalEmail($email_data);
      
      if ($success) {
        self::incrementTestEmailRateLimit();
        return new JsonResponse([
          'success' => true,
          'message' => 'Test email sent successfully!',
        ]);
      } else {
        // Get the detailed error from the BentoService
        $last_error = $this->bentoService->getLastError();
        
        return new JsonResponse([
          'success' => false,
          'message' => 'Failed to send test email: ' . ($last_error ?: 'Unknown error'),
        ]);
      }
      
    } catch (\Exception $e) {
      return new JsonResponse([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage(),
      ]);
    }
  }

  /**
   * Handles test webform event sending via AJAX.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with success/error status.
   */
  public function sendTestWebformEvent() {
    $config = $this->configFactory->get('bento_sdk.settings');
    
    // Check if webform integration is enabled
    if (!$config->get('enable_webform_integration')) {
      return new JsonResponse([
        'success' => false,
        'message' => 'Webform integration is disabled.',
      ]);
    }
    
    // Check if Bento is configured
    if (!$this->bentoService->isConfigured()) {
      return new JsonResponse([
        'success' => false,
        'message' => 'Bento SDK is not properly configured.',
      ]);
    }
    
    // Get current user's email for the test event
    $current_user = \Drupal::currentUser();
    $user_email = $current_user->getEmail();
    if (empty($user_email)) {
      $user_email = 'test@example.com';
    }
    
    try {
      // Send the test webform event using the service method
      $success = $this->bentoService->sendTestWebformEvent($user_email);
      
      if ($success) {
        self::incrementTestEmailRateLimit();
        return new JsonResponse([
          'success' => true,
          'message' => 'Test webform event queued successfully!',
        ]);
      } else {
        // Get the detailed error from the BentoService
        $last_error = $this->bentoService->getLastError();
        
        return new JsonResponse([
          'success' => false,
          'message' => 'Failed to queue test webform event: ' . ($last_error ?: 'Unknown error'),
        ]);
      }
      
    } catch (\Exception $e) {
      return new JsonResponse([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage(),
      ]);
    }
  }

  /**
   * Increments the test email rate limit counter for the current user.
   */
  private static function incrementTestEmailRateLimit(): void {
    $current_user = \Drupal::currentUser();
    $user_id = $current_user->id();
    $cache_key = 'bento_test_email_rate_limit:' . $user_id;
    
    // Get current usage
    $cached = \Drupal::cache()->get($cache_key);
    $current_usage = $cached ? $cached->data : ['count' => 0, 'reset_time' => time() + 3600];
    
    // Check if we need to reset the counter
    if (time() > $current_usage['reset_time']) {
      $current_usage = ['count' => 0, 'reset_time' => time() + 3600];
    }
    
    // Increment counter
    $current_usage['count']++;
    
    // Store updated usage
    \Drupal::cache()->set($cache_key, $current_usage, $current_usage['reset_time']);
  }

}