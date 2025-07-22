<?php

namespace Drupal\bento_sdk\Plugin\Mail;

use Drupal\bento_sdk\BentoService;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Mail\MailInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\Markup;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines the Bento Mail plugin for sending emails via Bento API.
 *
 * @Mail(
 *   id = "bento_mail",
 *   label = @Translation("Bento Transactional Mail"),
 *   description = @Translation("Send emails via Bento API with fallback to default mail system")
 * )
 */
class BentoMail implements MailInterface, ContainerFactoryPluginInterface {

  /**
   * The Bento service.
   *
   * @var \Drupal\bento_sdk\BentoService
   */
  private BentoService $bentoService;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  private ConfigFactoryInterface $configFactory;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  private LoggerInterface $logger;

  /**
   * The default mail plugin.
   *
   * @var \Drupal\Core\Mail\MailInterface
   */
  private MailInterface $defaultMailPlugin;

  /**
   * Constructs a new BentoMail plugin.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\bento_sdk\BentoService $bento_service
   *   The Bento service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   * @param \Drupal\Core\Mail\MailInterface $default_mail_plugin
   *   The default mail plugin for fallback.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    BentoService $bento_service,
    ConfigFactoryInterface $config_factory,
    LoggerInterface $logger,
    MailInterface $default_mail_plugin
  ) {
    $this->bentoService = $bento_service;
    $this->configFactory = $config_factory;
    $this->logger = $logger;
    $this->defaultMailPlugin = $default_mail_plugin;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('bento_sdk.service'),
      $container->get('config.factory'),
      $container->get('logger.factory')->get('bento_sdk'),
      $container->get('plugin.manager.mail')->createInstance('php_mail')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function format(array $message): array {
    // Let the default mail plugin handle formatting.
    return $this->defaultMailPlugin->format($message);
  }

  /**
   * {@inheritdoc}
   */
  public function mail(array $message): bool {
    $config = $this->configFactory->get('bento_sdk.settings');
    
    // Check if Bento mail routing is enabled.
    if (!$config->get('enable_mail_routing')) {
      $this->logger->info('Bento mail routing is disabled, using default mail system.');
      return $this->defaultMailPlugin->mail($message);
    }

    // Check if Bento is properly configured.
    if (!$this->bentoService->isConfigured()) {
      $this->logger->warning('Bento SDK is not configured, falling back to default mail system.');
      return $this->defaultMailPlugin->mail($message);
    }

    try {
      // Convert Drupal message format to Bento email format.
      $email_data = $this->convertDrupalMessageToBentoFormat($message);
      
      // Attempt to send via Bento.
      $success = $this->bentoService->sendTransactionalEmail($email_data);
      
      if ($success) {
        $this->logger->info('Email sent successfully via Bento to @to', [
          '@to' => $message['to'],
        ]);
        return TRUE;
      }
      else {
        // Fallback to default mail system.
        $this->logger->warning('Bento email send failed, falling back to default mail system.');
        return $this->defaultMailPlugin->mail($message);
      }
    }
    catch (\Exception $e) {
      // Fallback to default mail system on any exception.
      $this->logger->error('Bento mail plugin error: @message. Falling back to default mail system.', [
        '@message' => $e->getMessage(),
      ]);
      return $this->defaultMailPlugin->mail($message);
    }
  }

  /**
   * Converts Drupal message format to Bento email format.
   *
   * @param array $message
   *   The Drupal message array.
   *
   * @return array
   *   The Bento email data array.
   */
  private function convertDrupalMessageToBentoFormat(array $message): array {
    $email_data = [
      'to' => $message['to'],
      'subject' => $message['subject'],
    ];

    // Add sender if provided.
    if (!empty($message['from'])) {
      $email_data['from'] = $message['from'];
    }

    // Handle body content.
    $body = $message['body'];
    
    // If body is an array, join it.
    if (is_array($body)) {
      $body = implode("\n\n", $body);
    }

    // Convert Markup objects to strings.
    if ($body instanceof Markup) {
      $body = (string) $body;
    }

    // Determine if content is HTML or plain text.
    if ($this->isHtmlContent($body)) {
      $email_data['html_body'] = $body;
      // Also provide a plain text version by stripping HTML.
      $email_data['text_body'] = strip_tags($body);
    }
    else {
      $email_data['text_body'] = $body;
    }

    // Extract personalizations from message parameters.
    if (!empty($message['params']) && is_array($message['params'])) {
      $personalizations = [];
      
      // Look for common personalization patterns.
      foreach ($message['params'] as $key => $value) {
        if (is_string($value) || is_numeric($value)) {
          $personalizations[$key] = (string) $value;
        }
      }
      
      if (!empty($personalizations)) {
        $email_data['personalizations'] = $personalizations;
      }
    }

    return $email_data;
  }

  /**
   * Determines if content appears to be HTML.
   *
   * @param string $content
   *   The content to check.
   *
   * @return bool
   *   TRUE if content appears to be HTML, FALSE otherwise.
   */
  private function isHtmlContent(string $content): bool {
    // Simple check for HTML tags.
    return $content !== strip_tags($content);
  }

}