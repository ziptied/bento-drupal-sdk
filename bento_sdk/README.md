# Bento SDK for Drupal 10

Official Bento API integration module for Drupal 10.

## Description

The Bento SDK module provides seamless integration with the Bento API, allowing Drupal sites to send customer events, manage email marketing campaigns, and track user engagement.

## Requirements

- Drupal 10.2 or higher
- PHP 8.1 or higher
- Guzzle HTTP client library (automatically installed via Composer)

## Installation

1. Download and extract the module to your `modules/contrib` directory
2. Run `composer install` to install dependencies
3. Enable the module via the Drupal admin interface or using Drush:
   ```bash
   drush en bento_sdk
   ```

## Configuration

1. Navigate to **Administration » Configuration » Bento » Settings** (`/admin/config/bento/settings`)
2. Enter your Bento API credentials:
   - Site UUID
   - Publishable Key
   - Secret Key
3. Save the configuration

## Usage

Once configured, other modules can use the Bento SDK service to send events:

```php
$bento_service = \Drupal::service('bento.sdk');
$bento_service->sendEvent([
  'type' => 'user_registration',
  'email' => 'user@example.com',
  'fields' => [
    'first_name' => 'John',
    'last_name' => 'Doe',
  ],
]);
```

## Support

For issues and feature requests, please use the project's issue queue.

## License

This project is licensed under the GPL-2.0-or-later license.