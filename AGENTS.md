# AGENTS.md - Drupal Bento SDK

## Build/Test Commands
This is a Drupal 10 module - no npm/composer build commands. Test using Drupal's testing framework:
- `drush en bento_sdk` - Enable module
- `drush config:import` - Import configuration  
- `drush cache:rebuild` - Clear caches

## Code Style Guidelines
- **Language**: PHP 8.1+ with strict typing (`string $param`, `array $data`)
- **Namespace**: All classes in `Drupal\bento_sdk\` namespace with PSR-4 autoloading
- **Imports**: Use full namespaces, group by type (core, contrib, custom)
- **Formatting**: 2-space indentation, opening braces on same line
- **Documentation**: Full PHPDoc blocks with `@param`, `@return`, `@throws`
- **Naming**: camelCase for variables/methods, PascalCase for classes
- **Error Handling**: Use exceptions with descriptive messages, log errors via LoggerInterface
- **Constants**: ALL_CAPS with descriptive names (e.g., `DEFAULT_TIMEOUT`)
- **Validation**: Validate all inputs, use empty() checks for required fields
- **Configuration**: Store settings in `bento_sdk.settings` config object
- **Services**: Inject dependencies via constructor, use private properties with type hints

## File Structure
- `src/Client/` - HTTP client classes
- `src/Form/` - Drupal form classes  
- `src/` - Main service classes
- `config/` - Configuration schema and defaults