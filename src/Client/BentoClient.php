<?php

namespace Drupal\bento_sdk\Client;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;
use Psr\Log\LoggerInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\bento_sdk\BentoSanitizationTrait;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * HTTP client for communicating with the Bento API.
 *
 * Handles all HTTP communication with Bento's REST API including
 * authentication, error handling, and response processing.
 */
class BentoClient {
  use BentoSanitizationTrait;

  /**
   * The Bento API base URL.
   */
  const BASE_URL = 'https://app.bentonow.com/api/v1/';

  /**
   * Default request timeout in seconds.
   */
  const DEFAULT_TIMEOUT = 30;

  /**
   * Maximum allowed endpoint length.
   */
  const MAX_ENDPOINT_LENGTH = 255;

  /**
   * Maximum allowed parameter value length.
   */
  const MAX_PARAM_VALUE_LENGTH = 10000;

  /**
   * Maximum allowed request body size in bytes.
   */
  const MAX_REQUEST_BODY_SIZE = 1048576; // 1MB

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  private ClientInterface $httpClient;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  private LoggerInterface $logger;

  /**
   * The Bento site UUID.
   *
   * @var string
   */
  private string $siteUuid = '';

  /**
   * The Bento publishable key.
   *
   * @var string
   */
  private string $publishableKey = '';

  /**
   * The Bento secret key.
   *
   * @var string
   */
  private string $secretKey = '';

  /**
   * The cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  private CacheBackendInterface $cache;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  private ConfigFactoryInterface $configFactory;

  /**
   * The Drupal version.
   *
   * @var string
   */
  private string $drupalVersion = '';

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  private ModuleHandlerInterface $moduleHandler;

  /**
   * Constructs a new BentoClient.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache backend.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param string $drupal_version
   *   The Drupal version.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler service.
   */
  public function __construct(ClientInterface $http_client, LoggerInterface $logger, CacheBackendInterface $cache, ConfigFactoryInterface $config_factory, string $drupal_version = '', ?ModuleHandlerInterface $module_handler = NULL) {
    $this->httpClient = $http_client;
    $this->logger = $logger;
    $this->cache = $cache;
    $this->configFactory = $config_factory;
    $this->drupalVersion = $drupal_version ?: \Drupal::VERSION;
    $this->moduleHandler = $module_handler ?: \Drupal::service('module_handler');
  }

  /**
   * Sets the API credentials.
   *
   * @param string $site_uuid
   *   The Bento site UUID.
   * @param string $publishable_key
   *   The Bento publishable key.
   * @param string $secret_key
   *   The Bento secret key.
   */
  public function setCredentials(string $site_uuid, string $publishable_key, string $secret_key): void {
    // Validate credential formats.
    $this->validateCredentialFormat($site_uuid, 'site_uuid');
    $this->validateCredentialFormat($publishable_key, 'publishable_key');
    $this->validateCredentialFormat($secret_key, 'secret_key');
    
    $this->siteUuid = $site_uuid;
    $this->publishableKey = $publishable_key;
    $this->secretKey = $secret_key;
  }

  /**
   * Performs a GET request to the Bento API.
   *
   * @param string $endpoint
   *   The API endpoint (without base URL).
   * @param array $params
   *   Query parameters to include in the request.
   *
   * @return array
   *   The decoded JSON response.
   *
   * @throws \Exception
   *   When the request fails or credentials are not set.
   */
  public function get(string $endpoint, array $params = []): array {
    $this->areCredentialsSet();
    
    // Validate and sanitize endpoint.
    $endpoint = $this->validateAndSanitizeEndpoint($endpoint);
    
    // Validate and sanitize query parameters.
    $params = $this->validateAndSanitizeParams($params);

    // Add site_uuid to all requests.
    $params['site_uuid'] = $this->siteUuid;

    $options = [
      'timeout' => $this->getRequestTimeout(),
      'connect_timeout' => $this->getConnectionTimeout(),
      'verify' => $this->getSslVerification(),
      'auth' => [$this->publishableKey, $this->secretKey],
      'query' => $params,
      'headers' => $this->buildSecurityHeaders(),
    ];

    return $this->makeRequest('GET', $endpoint, $options);
  }

  /**
   * Performs a POST request to the Bento API.
   *
   * @param string $endpoint
   *   The API endpoint (without base URL).
   * @param array $data
   *   Data to send in the request body.
   *
   * @return array
   *   The decoded JSON response.
   *
   * @throws \Exception
   *   When the request fails or credentials are not set.
   */
  public function post(string $endpoint, array $data = []): array {
    $this->areCredentialsSet();
    
    // Validate and sanitize endpoint.
    $endpoint = $this->validateAndSanitizeEndpoint($endpoint);
    
    // Validate and sanitize request data.
    $data = $this->validateAndSanitizeRequestData($data);

    // Add site_uuid to all requests.
    $data['site_uuid'] = $this->siteUuid;

    $options = [
      'timeout' => $this->getRequestTimeout(),
      'connect_timeout' => $this->getConnectionTimeout(),
      'verify' => $this->getSslVerification(),
      'auth' => [$this->publishableKey, $this->secretKey],
      'json' => $data,
      'headers' => $this->buildSecurityHeaders(['Content-Type' => 'application/json']),
    ];

    return $this->makeRequest('POST', $endpoint, $options);
  }

  /**
   * Fetches the list of verified authors from Bento API.
   *
   * @return array
   *   Array of author email addresses.
   *
   * @throws \Exception
   *   When the request fails or credentials are not set.
   */
  public function fetchAuthors(): array {
    $response = $this->get('fetch/authors');
    
    // The API returns a data array with author objects containing attributes
    // Extract email addresses from the response
    $authors = [];
    if (isset($response['data']) && is_array($response['data'])) {
      foreach ($response['data'] as $author) {
        if (isset($author['attributes']['email']) && filter_var($author['attributes']['email'], FILTER_VALIDATE_EMAIL)) {
          $authors[] = $author['attributes']['email'];
        }
      }
    }
    
    return $authors;
  }

  /**
   * Validates the API credentials by making a test request.
   *
   * @return bool
   *   TRUE if credentials are valid, FALSE otherwise.
   */
  public function validateCredentials(): bool {
    if (empty($this->siteUuid) || empty($this->publishableKey) || empty($this->secretKey)) {
      return FALSE;
    }

    try {
      // Make a simple request to validate credentials.
      $this->get('batch/events', ['limit' => 1]);
      return TRUE;
    }
    catch (\Exception $e) {
      $this->logger->warning('Bento API credential validation failed: @message', [
        '@message' => $this->sanitizeErrorMessage($e->getMessage()),
      ]);
      return FALSE;
    }
  }

  /**
   * Makes an HTTP request to the Bento API.
   *
   * @param string $method
   *   The HTTP method (GET, POST, etc.).
   * @param string $endpoint
   *   The API endpoint.
   * @param array $options
   *   Request options for Guzzle.
   *
   * @return array
   *   The decoded JSON response.
   *
   * @throws \Exception
   *   When the request fails.
   */
  private function makeRequest(string $method, string $endpoint, array $options): array {
    // Check rate limits and circuit breaker before making request.
    $this->checkRateLimits();
    $this->checkCircuitBreaker();

    $url = self::BASE_URL . ltrim($endpoint, '/');

    try {
      $response = $this->httpClient->request($method, $url, $options);
      $body = $response->getBody()->getContents();
      $data = json_decode($body, TRUE);

      if (json_last_error() !== JSON_ERROR_NONE) {
        throw new \Exception('Invalid JSON response from Bento API');
      }

      // Record successful request for rate limiting.
      $this->recordSuccessfulRequest();

      $this->logger->info('Bento API request successful: @method @endpoint', [
        '@method' => $method,
        '@endpoint' => $this->sanitizeEndpointForLogging($endpoint),
      ]);

      return $data ?: [];
    }
    catch (ConnectException $e) {
      // Record failure for circuit breaker.
      $this->recordFailedRequest();
      
      $sanitized_message = $this->sanitizeErrorMessage($e->getMessage());
      $user_message = 'Failed to connect to Bento API';
      
      $this->logger->error('Bento API connection failed: @message', [
        '@message' => $sanitized_message,
      ]);
      
      throw new \Exception($user_message);
    }
    catch (RequestException $e) {
      // Record failure for circuit breaker.
      $this->recordFailedRequest();
      
      $status_code = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
      $user_message = $this->getErrorMessage($status_code, 'API request failed');
      $sanitized_message = $this->sanitizeErrorMessage($e->getMessage());
      
      $this->logger->error('Bento API request failed: @message (Status: @status)', [
        '@message' => $sanitized_message,
        '@status' => $status_code,
      ]);
      
      throw new \Exception($user_message);
    }
  }

  /**
   * Validates that credentials are set.
   *
   * @throws \Exception
   *   When credentials are not properly configured.
   */
  private function areCredentialsSet(): void {
    if (empty($this->siteUuid)) {
      throw new \Exception('Bento site UUID is not configured');
    }
    if (empty($this->publishableKey)) {
      throw new \Exception('Bento publishable key is not configured');
    }
    if (empty($this->secretKey)) {
      throw new \Exception('Bento secret key is not configured');
    }
  }

  /**
   * Gets a user-friendly error message based on HTTP status code.
   *
   * @param int $status_code
   *   The HTTP status code.
   * @param string $default_message
   *   The default error message.
   *
   * @return string
   *   A user-friendly error message.
   */
  private function getErrorMessage(int $status_code, string $default_message): string {
    switch ($status_code) {
      case 401:
        return 'Invalid Bento API credentials';

      case 403:
        return 'Access denied to Bento API resource';

      case 404:
        return 'Bento API endpoint not found';

      case 429:
        return 'Bento API rate limit exceeded';

      case 500:
      case 502:
      case 503:
      case 504:
        return 'Bento API server error';

      default:
        return $default_message;
    }
  }

  /**
   * Validates and sanitizes an API endpoint.
   *
   * @param string $endpoint
   *   The endpoint to validate.
   *
   * @return string
   *   The sanitized endpoint.
   *
   * @throws \InvalidArgumentException
   *   When the endpoint is invalid.
   */
  private function validateAndSanitizeEndpoint(string $endpoint): string {
    // Check for empty endpoint.
    if (empty($endpoint)) {
      throw new \InvalidArgumentException('API endpoint cannot be empty');
    }

    // Check endpoint length.
    if (strlen($endpoint) > self::MAX_ENDPOINT_LENGTH) {
      throw new \InvalidArgumentException('API endpoint too long (max ' . self::MAX_ENDPOINT_LENGTH . ' characters)');
    }

    // Validate endpoint format - only allow alphanumeric, forward slashes, hyphens, and underscores.
    if (!preg_match('/^[a-zA-Z0-9\/_-]+$/', $endpoint)) {
      throw new \InvalidArgumentException('Invalid endpoint format. Only alphanumeric characters, forward slashes, hyphens, and underscores are allowed');
    }

    // Remove any leading/trailing slashes and normalize.
    $endpoint = trim($endpoint, '/');

    // Prevent directory traversal attempts.
    if (strpos($endpoint, '..') !== FALSE) {
      throw new \InvalidArgumentException('Directory traversal not allowed in endpoint');
    }

    // Validate against known Bento API endpoints.
    $this->validateKnownEndpoint($endpoint);

    return $endpoint;
  }

  /**
   * Validates that the endpoint matches known Bento API patterns.
   *
   * @param string $endpoint
   *   The endpoint to validate.
   *
   * @throws \InvalidArgumentException
   *   When the endpoint doesn't match known patterns.
   */
  private function validateKnownEndpoint(string $endpoint): void {
    $allowed_patterns = [
      '/^batch\/(events|emails|subscribers)$/',
      '/^fetch\/commands$/',
      '/^fetch\/authors$/',
      '/^experimental\/validation$/',
      '/^subscribers\/[a-zA-Z0-9%._-]+$/', // Allow URL-encoded characters
      '/^events\/[a-zA-Z0-9_-]+$/',
    ];

    foreach ($allowed_patterns as $pattern) {
      if (preg_match($pattern, $endpoint)) {
        return;
      }
    }

    throw new \InvalidArgumentException('Endpoint not allowed: ' . $endpoint);
  }

  /**
   * Validates and sanitizes query parameters.
   *
   * @param array $params
   *   The parameters to validate.
   *
   * @return array
   *   The sanitized parameters.
   *
   * @throws \InvalidArgumentException
   *   When parameters are invalid.
   */
  private function validateAndSanitizeParams(array $params): array {
    $sanitized = [];

    foreach ($params as $key => $value) {
      // Validate parameter key.
      if (!is_string($key) || empty($key)) {
        throw new \InvalidArgumentException('Parameter keys must be non-empty strings');
      }

      if (strlen($key) > 100) {
        throw new \InvalidArgumentException('Parameter key too long: ' . $key);
      }

      if (!preg_match('/^[a-zA-Z0-9_-]+$/', $key)) {
        throw new \InvalidArgumentException('Invalid parameter key format: ' . $key);
      }

      // Validate and sanitize parameter value.
      $sanitized[$key] = $this->validateAndSanitizeParamValue($value, $key);
    }

    return $sanitized;
  }

  /**
   * Validates and sanitizes a parameter value.
   *
   * @param mixed $value
   *   The value to validate.
   * @param string $key
   *   The parameter key for context.
   *
   * @return mixed
   *   The sanitized value.
   *
   * @throws \InvalidArgumentException
   *   When the value is invalid.
   */
  private function validateAndSanitizeParamValue($value, string $key) {
    // Handle null values.
    if ($value === NULL) {
      return NULL;
    }

    // Handle boolean values.
    if (is_bool($value)) {
      return $value;
    }

    // Handle numeric values.
    if (is_numeric($value)) {
      // Validate reasonable numeric ranges.
      if (is_int($value) && ($value < -2147483648 || $value > 2147483647)) {
        throw new \InvalidArgumentException('Integer value out of range for parameter: ' . $key);
      }
      return $value;
    }

    // Handle string values.
    if (is_string($value)) {
      if (strlen($value) > self::MAX_PARAM_VALUE_LENGTH) {
        throw new \InvalidArgumentException('Parameter value too long for: ' . $key);
      }

      // Sanitize string by removing null bytes and control characters.
      $sanitized = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
      
      return $sanitized;
    }

    // Handle array values (recursive validation).
    if (is_array($value)) {
      return $this->validateAndSanitizeParams($value);
    }

    throw new \InvalidArgumentException('Unsupported parameter type for: ' . $key);
  }

  /**
   * Validates and sanitizes request data for POST requests.
   *
   * @param array $data
   *   The request data to validate.
   *
   * @return array
   *   The sanitized data.
   *
   * @throws \InvalidArgumentException
   *   When the data is invalid.
   */
  private function validateAndSanitizeRequestData(array $data): array {
    // Check overall request size.
    $json_size = strlen(json_encode($data));
    if ($json_size > self::MAX_REQUEST_BODY_SIZE) {
      throw new \InvalidArgumentException('Request body too large (max ' . self::MAX_REQUEST_BODY_SIZE . ' bytes)');
    }

    // Validate data structure depth to prevent deeply nested attacks.
    $this->validateDataDepth($data, 0, 10);

    // Use the same validation as parameters.
    return $this->validateAndSanitizeParams($data);
  }

  /**
   * Validates data structure depth to prevent deeply nested data attacks.
   *
   * @param mixed $data
   *   The data to check.
   * @param int $current_depth
   *   The current nesting depth.
   * @param int $max_depth
   *   The maximum allowed depth.
   *
   * @throws \InvalidArgumentException
   *   When data is nested too deeply.
   */
  private function validateDataDepth($data, int $current_depth, int $max_depth): void {
    if ($current_depth > $max_depth) {
      throw new \InvalidArgumentException('Data nested too deeply (max depth: ' . $max_depth . ')');
    }

    if (is_array($data)) {
      foreach ($data as $value) {
        $this->validateDataDepth($value, $current_depth + 1, $max_depth);
      }
    }
  }

  /**
   * Validates credential format.
   *
   * @param string $credential
   *   The credential to validate.
   * @param string $type
   *   The credential type for error messages.
   *
   * @throws \InvalidArgumentException
   *   When the credential format is invalid.
   */
  private function validateCredentialFormat(string $credential, string $type): void {
    if (empty($credential)) {
      throw new \InvalidArgumentException('Credential cannot be empty: ' . $type);
    }

    if (strlen($credential) > 255) {
      throw new \InvalidArgumentException('Credential too long: ' . $type);
    }

    // Check for suspicious characters that might indicate injection attempts.
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $credential)) {
      throw new \InvalidArgumentException('Credential contains invalid characters: ' . $type);
    }

    // Specific validation for site_uuid format.
    if ($type === 'site_uuid') {
      if (!preg_match('/^([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-9a-f]{32})$/i', $credential)) {
        throw new \InvalidArgumentException('Site UUID must be in valid UUID format (with or without hyphens)');
      }
    }
  }

  /**
   * Checks rate limits before making API requests.
   *
   * @throws \Exception
   *   When rate limits are exceeded.
   */
  private function checkRateLimits(): void {
    $config = $this->configFactory->get('bento_sdk.settings');
    
    if (!$config->get('enable_rate_limiting')) {
      return;
    }

    $max_per_minute = $config->get('max_requests_per_minute') ?: 60;
    $max_per_hour = $config->get('max_requests_per_hour') ?: 1000;

    // Check minute-based rate limit.
    $minute_key = 'bento_api_rate_limit_minute:' . floor(time() / 60);
    $minute_cache = $this->cache->get($minute_key);
    $minute_count = $minute_cache ? $minute_cache->data : 0;

    if ($minute_count >= $max_per_minute) {
      $this->logger->warning('Bento API rate limit exceeded: @count requests in current minute (max: @max)', [
        '@count' => $minute_count,
        '@max' => $max_per_minute,
      ]);
      throw new \Exception('API rate limit exceeded. Please try again later.');
    }

    // Check hour-based rate limit.
    $hour_key = 'bento_api_rate_limit_hour:' . floor(time() / 3600);
    $hour_cache = $this->cache->get($hour_key);
    $hour_count = $hour_cache ? $hour_cache->data : 0;

    if ($hour_count >= $max_per_hour) {
      $this->logger->warning('Bento API hourly rate limit exceeded: @count requests in current hour (max: @max)', [
        '@count' => $hour_count,
        '@max' => $max_per_hour,
      ]);
      throw new \Exception('API hourly rate limit exceeded. Please try again later.');
    }

    // Increment counters.
    $this->cache->set($minute_key, $minute_count + 1, time() + 120); // Cache for 2 minutes.
    $this->cache->set($hour_key, $hour_count + 1, time() + 7200); // Cache for 2 hours.
  }

  /**
   * Checks circuit breaker status before making API requests.
   *
   * @throws \Exception
   *   When circuit breaker is open.
   */
  private function checkCircuitBreaker(): void {
    $config = $this->configFactory->get('bento_sdk.settings');
    
    if (!$config->get('enable_circuit_breaker')) {
      return;
    }

    $cache_key = 'bento_api_circuit_breaker';
    $cached = $this->cache->get($cache_key);
    
    if (!$cached) {
      return; // Circuit breaker is closed (normal operation).
    }

    $circuit_data = $cached->data;
    $timeout = $config->get('circuit_breaker_timeout') ?: 300;

    // Check if circuit breaker should be reset.
    if (time() - $circuit_data['opened_at'] > $timeout) {
      $this->cache->delete($cache_key);
      $this->logger->info('Circuit breaker reset after timeout');
      return;
    }

    // Circuit breaker is still open.
    $this->logger->warning('Circuit breaker is open, blocking API request');
    throw new \Exception('API service temporarily unavailable. Please try again later.');
  }

  /**
   * Records a successful API request for circuit breaker tracking.
   */
  private function recordSuccessfulRequest(): void {
    $config = $this->configFactory->get('bento_sdk.settings');
    
    if (!$config->get('enable_circuit_breaker')) {
      return;
    }

    // Reset failure counter on successful request.
    $this->cache->delete('bento_api_circuit_breaker_failures');
  }

  /**
   * Records a failed API request for circuit breaker tracking.
   */
  private function recordFailedRequest(): void {
    $config = $this->configFactory->get('bento_sdk.settings');
    
    if (!$config->get('enable_circuit_breaker')) {
      return;
    }

    $threshold = $config->get('circuit_breaker_failure_threshold') ?: 5;
    $failures_key = 'bento_api_circuit_breaker_failures';
    $cached = $this->cache->get($failures_key);
    $failure_count = $cached ? $cached->data : 0;
    $failure_count++;

    // Store updated failure count.
    $this->cache->set($failures_key, $failure_count, time() + 3600); // Cache for 1 hour.

    // Check if we should open the circuit breaker.
    if ($failure_count >= $threshold) {
      $circuit_data = [
        'opened_at' => time(),
        'failure_count' => $failure_count,
      ];
      
      $this->cache->set('bento_api_circuit_breaker', $circuit_data, time() + 3600);
      
      $this->logger->warning('Circuit breaker opened after @count consecutive failures', [
        '@count' => $failure_count,
      ]);
    }
  }

  /**
   * Gets the request timeout from configuration.
   *
   * @return int
   *   The request timeout in seconds.
   */
  private function getRequestTimeout(): int {
    $config = $this->configFactory->get('bento_sdk.settings');
    return $config->get('request_timeout') ?: self::DEFAULT_TIMEOUT;
  }

  /**
   * Gets the connection timeout from configuration.
   *
   * @return int
   *   The connection timeout in seconds.
   */
  private function getConnectionTimeout(): int {
    $config = $this->configFactory->get('bento_sdk.settings');
    return $config->get('connection_timeout') ?: 10;
  }

  /**
   * Gets the SSL verification setting from configuration.
   *
   * @return bool
   *   TRUE if SSL verification is enabled.
   */
  private function getSslVerification(): bool {
    $config = $this->configFactory->get('bento_sdk.settings');
    return $config->get('ssl_verification') ?? TRUE;
  }

  /**
   * Builds security headers for API requests.
   *
   * @param array $additional_headers
   *   Additional headers to include.
   *
   * @return array
   *   Array of headers for the request.
   */
  private function buildSecurityHeaders(array $additional_headers = []): array {
    $config = $this->configFactory->get('bento_sdk.settings');
    
    $headers = [
      'Accept' => 'application/json',
      'User-Agent' => $this->buildUserAgent(),
      'X-Client-Version' => $this->getModuleVersion(),
      'X-Client-Platform' => 'Drupal',
    ];

    // Add request ID for tracking if enabled.
    if ($config->get('enable_request_id_tracking') ?? TRUE) {
      $headers['X-Request-ID'] = $this->generateRequestId();
    }

    // Add security headers.
    $headers['X-Content-Type-Options'] = 'nosniff';
    $headers['X-Frame-Options'] = 'DENY';
    $headers['Cache-Control'] = 'no-cache, no-store, must-revalidate';

    return array_merge($headers, $additional_headers);
  }

  /**
   * Builds a detailed User-Agent string.
   *
   * @return string
   *   The User-Agent string.
   */
  private function buildUserAgent(): string {
    $module_version = $this->getModuleVersion();
    $drupal_version = $this->drupalVersion;
    $php_version = PHP_VERSION;
    
    return "Drupal-Bento-SDK/{$module_version} (Drupal/{$drupal_version}; PHP/{$php_version})";
  }

  /**
   * Gets the module version.
   *
   * @return string
   *   The module version.
   */
  private function getModuleVersion(): string {
    try {
      $module_info = $this->moduleHandler->getModule('bento_sdk');
      return $module_info->info['version'] ?? '1.0.0';
    }
    catch (\Exception $e) {
      return '1.0.0';
    }
  }

  /**
   * Generates a unique request ID for tracking.
   *
   * @return string
   *   A unique request ID.
   */
  private function generateRequestId(): string {
    return uniqid('bento_', TRUE);
  }

}