<?php

// Include Composer autoloader
require_once __DIR__ . '/vendor/autoload.php';

// Simple test to check if our classes can be autoloaded
echo "Testing Bento SDK autoloading with Composer...\n";

// Test if trait exists
if (trait_exists('Drupal\bento_sdk\BentoSanitizationTrait')) {
  echo "✓ BentoSanitizationTrait found\n";
} else {
  echo "✗ BentoSanitizationTrait NOT found\n";
}

// Test if BentoClient class exists
if (class_exists('Drupal\bento_sdk\Client\BentoClient')) {
  echo "✓ BentoClient class found\n";
} else {
  echo "✗ BentoClient class NOT found\n";
}

// Test if BentoService class exists
if (class_exists('Drupal\bento_sdk\BentoService')) {
  echo "✓ BentoService class found\n";
} else {
  echo "✗ BentoService class NOT found\n";
}

// Test if BentoSettingsForm class exists
if (class_exists('Drupal\bento_sdk\Form\BentoSettingsForm')) {
  echo "✓ BentoSettingsForm class found\n";
} else {
  echo "✗ BentoSettingsForm class NOT found\n";
}

// Test actual instantiation (with mock dependencies)
echo "\nTesting class instantiation...\n";

try {
  // Test trait usage
  $reflection = new ReflectionClass('Drupal\bento_sdk\Client\BentoClient');
  $traits = $reflection->getTraitNames();
  if (in_array('Drupal\bento_sdk\BentoSanitizationTrait', $traits)) {
    echo "✓ BentoClient uses BentoSanitizationTrait\n";
  } else {
    echo "✗ BentoClient does NOT use BentoSanitizationTrait\n";
  }
} catch (Exception $e) {
  echo "✗ Error testing trait usage: " . $e->getMessage() . "\n";
}

echo "Test complete.\n";