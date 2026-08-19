<?php

namespace Drupal\shipping_integration\Service;

use Psr\Log\LoggerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Utility\Token;
use Drupal\file\FileRepositoryInterface;
use Drupal\shipping_integration\ShippingProvidersPluginManager;

/**
 * Shipping get config shipping.
 */
class HandleShipping {

  /**
   * {@inheritdoc}
   */
  public function __construct(
    protected ShippingProvidersPluginManager $providers,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected FileSystemInterface $fileSystem,
    protected FileRepositoryInterface $fileRepository,
    protected LoggerInterface $logger,
    protected Token $token,
    protected Connection $database,
  ) {}

  /**
   * Lấy token.
   */
  public function getToken(array $config): array {
    try {
      $type = $config["shipping_type"];
      $provider_id = $type->hasField("field_code") ? $type->get("field_code")->value : NULL;

      $provider = $this->getProvider($provider_id);
      return $provider->getToken($config);
    }
    catch (\DomainException $e) {
      return [
        "success" => FALSE,
        "message" => $e->getMessage(),
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error(
        "Invoice system error: @message",
        ["@message" => $e->getMessage(), "exception" => $e]
      );

      return [
        "success" => FALSE,
        "message" => "The system is experiencing problems",
      ];
    }
  }

  /**
   * Lấy danh sách provider.
   */
  private function getProvider(string $provider_id): object {
    if (empty($provider_id)) {
      throw new \DomainException("Invoice provider not yet configured");
    }

    if (!$this->providers->hasDefinition($provider_id)) {
      throw new \DomainException("The invoice provider {$provider_id} does not exist");
    }

    return $this->providers->createInstance($provider_id);
  }
}
