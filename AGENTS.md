# AGENTS.md - Drupal Bento SDK

## Build/Test Commands
This is a Drupal 10 module - no npm/composer build commands. Test using Drupal's testing framework:
- `drush en bento_sdk` - Enable module
- `drush config:import` - Import configuration  
- `drush cache:rebuild` - Clear caches
- **Single test**: Use Drupal's built-in test email functionality at `/admin/config/bento/test-email`
- **Manual testing**: Test API integration via admin UI at `/admin/config/bento/settings`

## Code Style Guidelines
- **Language**: PHP 8.1+ with strict typing (`string $param`, `array $data`)
- **Namespace**: All classes in `Drupal\bento_sdk\` namespace with PSR-4 autoloading
- **Imports**: Use full namespaces, group by type (core, contrib, custom). Example:
  ```php
  use Drupal\Core\Config\ConfigFactoryInterface;
  use Drupal\bento_sdk\Client\BentoClient;
  use Psr\Log\LoggerInterface;
  ```
- **Formatting**: 2-space indentation, opening braces on same line, no trailing spaces
- **Documentation**: Full PHPDoc blocks with `@param`, `@return`, `@throws` for all public methods
- **Naming**: camelCase for variables/methods, PascalCase for classes, snake_case for config keys
- **Error Handling**: Use exceptions with descriptive messages, log via LoggerInterface, sanitize sensitive data
- **Constants**: ALL_CAPS with descriptive names (e.g., `DEFAULT_TIMEOUT`)
- **Validation**: Validate all inputs, use empty() checks for required fields, filter_var() for emails
- **Configuration**: Store settings in `bento_sdk.settings` config object, use State API for secrets
- **Services**: Inject dependencies via constructor, use private properties with type hints
- **Security**: Never log sensitive data, use BentoSanitizationTrait for email/error sanitization

## File Structure
- `src/Client/` - HTTP client classes (BentoClient.php)
- `src/Form/` - Drupal form classes (BentoSettingsForm.php)
- `src/Controller/` - Route controllers (TestEmailController.php)
- `src/Plugin/` - Drupal plugins (Mail, QueueWorker)
- `src/Queue/` - Queue management classes
- `src/` - Main service classes (BentoService.php)
- `config/` - Configuration schema and defaults