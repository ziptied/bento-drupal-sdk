<?php

/**
 * @file
 * Drush commands for Bento SDK module.
 */

/**
 * Implements hook_drush_command().
 */
function bento_sdk_drush_command() {
  $items = [];

  $items['bento-set-secret'] = [
    'description' => 'Set Bento secret key in secure storage.',
    'aliases' => ['bento:set-secret'],
    'arguments' => [
      'secret_key' => 'The Bento secret key to store.',
    ],
    'examples' => [
      'drush bento-set-secret "your-secret-key-here"' => 'Store the secret key securely.',
    ],
  ];

  return $items;
}

/**
 * Callback for bento-set-secret command.
 */
function drush_bento_sdk_bento_set_secret($secret_key = NULL) {
  if (empty($secret_key)) {
    drush_set_error('BENTO_SECRET_MISSING', 'Secret key is required. Usage: drush bento-set-secret "your-secret-key"');
    return;
  }

  try {
    // Store the secret key securely
    \Drupal::state()->set('bento_sdk.secret_key', $secret_key);
    
    drush_print("✅ Secret key stored securely in State API");
    drush_print("Key preview: " . substr($secret_key, 0, 8) . "...");
    
    // Verify it was stored
    $stored_key = \Drupal::state()->get('bento_sdk.secret_key');
    if ($stored_key === $secret_key) {
      drush_print("✅ Secret key verification successful");
    } else {
      drush_print("❌ Secret key verification failed");
    }

  } catch (\Exception $e) {
    drush_set_error('BENTO_SECRET_ERROR', 'Error storing secret key: ' . $e->getMessage());
  }
} 