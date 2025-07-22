<?php

namespace Drupal\bento_sdk\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Simple controller for test email functionality.
 */
class TestEmailController extends ControllerBase {

  /**
   * Handles test email sending via AJAX.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with success/error status.
   */
  public function sendTestEmail() {
    try {
      // Get config using the proper method
      $config = \Drupal::config('bento_sdk.settings');
      $publishable_key = $config->get('publishable_key');
      $site_uuid = $config->get('site_uuid');
      $default_author = $config->get('default_author_email');

      // Check all possible sources for secret key
      $env_secret_key = $_ENV['BENTO_SECRET_KEY'] ?? getenv('BENTO_SECRET_KEY');
      $state_secret_key = \Drupal::state()->get('bento_sdk.secret_key');
      $config_secret_key = $config->get('secret_key');
      
      // Determine which secret key to use (same logic as BentoService)
      $secret_key = null;
      $secret_key_source = 'none';
      
      if (!empty($env_secret_key)) {
        $secret_key = $env_secret_key;
        $secret_key_source = 'environment variable';
      } elseif (!empty($state_secret_key)) {
        $secret_key = $state_secret_key;
        $secret_key_source = 'state API';
      } elseif (!empty($config_secret_key)) {
        $secret_key = $config_secret_key;
        $secret_key_source = 'configuration (deprecated)';
      }

      if (empty($default_author)) {
        return new JsonResponse([
          'success' => false,
          'message' => 'No default author email configured.',
        ]);
      }
      
      if (empty($publishable_key) || empty($secret_key)) {
        return new JsonResponse([
          'success' => false,
          'message' => 'API credentials not configured properly',
        ]);
      }
      
      // Use the BentoService to send the test email
      try {
        $bento_service = \Drupal::service('bento.sdk');
        
        $email_data = [
          'to' => $default_author,
          'from' => $default_author, // Use same email as from
          'subject' => 'Test Email from Bento SDK',
          'html_body' => '<p>This is a test email from Bento SDK.</p>',
          'text_body' => 'This is a test email from Bento SDK.',
        ];
        
        // Check if BentoService is configured before attempting to send
        $is_configured = $bento_service->isConfigured();
        
        if (!$is_configured) {
          return new JsonResponse([
            'success' => false,
            'message' => 'BentoService is not properly configured',
          ]);
        }
        
        $success = $bento_service->sendTransactionalEmail($email_data);
        
        if ($success) {
          return new JsonResponse([
            'success' => true,
            'message' => 'Test email sent successfully!',
          ]);
        } else {
          // Get the detailed error from the BentoService
          $last_error = $bento_service->getLastError();
          
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

    } catch (\Exception $e) {
      return new JsonResponse([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage(),
      ]);
    }
  }

}