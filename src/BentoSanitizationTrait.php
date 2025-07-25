<?php

namespace Drupal\bento_sdk;

/**
 * Provides sanitization utilities for error messages and emails.
 */
trait BentoSanitizationTrait {
  /**
   * Sanitizes error messages to prevent information disclosure.
   *
   * @param string $message
   *   The original error message.
   *
   * @return string
   *   The sanitized error message.
   */
  private function sanitizeErrorMessage(string $message): string {
    // Remove potential sensitive information from error messages.
    $patterns = [
      // Remove anything that looks like a UUID.
      '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i' => '[UUID]',
      // Remove anything that looks like an API key (common formats).
      '/\b(?:sk|pk|api[_-]?key|token|secret)[_-]?[a-zA-Z0-9]{16,}\b/i' => '[API_KEY]',
      // Remove base64-like strings that might be keys.
      '/\b[a-zA-Z0-9+\/]{40,}={0,2}\b/' => '[API_KEY]',
      // Remove email addresses.
      '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/' => '[EMAIL]',
      // Remove file paths.
      '/\/[a-zA-Z0-9._\/-]+/' => '[PATH]',
      // Remove URLs.
      '/https?:\/\/[^\s]+/' => '[URL]',
      // Remove IP addresses.
      '/\b(?:[0-9]{1,3}\.){3}[0-9]{1,3}\b/' => '[IP]',
    ];

    $sanitized = $message;
    foreach ($patterns as $pattern => $replacement) {
      $sanitized = preg_replace($pattern, $replacement, $sanitized);
    }

    // Limit message length to prevent log flooding.
    if (strlen($sanitized) > 500) {
      $sanitized = substr($sanitized, 0, 497) . '...';
    }

    return $sanitized;
  }

  /**
   * Sanitizes email addresses for logging to prevent information disclosure.
   *
   * @param string $email
   *   The email address to sanitize.
   *
   * @return string
   *   The sanitized email address.
   */
   protected function sanitizeEmailForLogging(string $email): string {    // For privacy, only show the domain and first character of local part.
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $parts = explode('@', $email);
      if (count($parts) === 2) {
        $local = $parts[0];
        $domain = $parts[1];
        // Show first character and length, then domain.
        $sanitized_local = substr($local, 0, 1) . str_repeat('*', max(0, strlen($local) - 1));
        return $sanitized_local . '@' . $domain;
      }
    }
    // If not a valid email, just show [EMAIL].
    return '[EMAIL]';
  }

  /**
   * Sanitizes API endpoints for logging to prevent information disclosure.
   *
   * @param string $endpoint
   *   The API endpoint to sanitize.
   *
   * @return string
   *   The sanitized endpoint.
   */
  private function sanitizeEndpointForLogging(string $endpoint): string {
    // Endpoints are generally safe to log, but we'll remove any potential
    // sensitive information like IDs or tokens.
    $patterns = [
      // Remove anything that looks like a UUID in the endpoint.
      '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i' => '[UUID]',
      // Remove anything that looks like an API key in the endpoint.
      '/\b(?:sk|pk|api[_-]?key|token|secret)[_-]?[a-zA-Z0-9]{16,}\b/i' => '[API_KEY]',
      // Remove base64-like strings that might be keys.
      '/\b[a-zA-Z0-9+\/]{40,}={0,2}\b/' => '[API_KEY]',
    ];

    $sanitized = $endpoint;
    foreach ($patterns as $pattern => $replacement) {
      $sanitized = preg_replace($pattern, $replacement, $sanitized);
    }

    // Limit endpoint length to prevent log flooding.
    if (strlen($sanitized) > 200) {
      $sanitized = substr($sanitized, 0, 197) . '...';
    }

    return $sanitized;
  }
} 