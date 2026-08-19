<?php

namespace Drupal\shipping_integration\Service;

use Psr\Log\LoggerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\shipping_integration\ShippingProvidersPluginManager;

/**
 * {@inheritdoc}
 */
class SynchronizeAddresses {

  /**
   * {@inheritdoc}
   */
  public function __construct(
    protected ShippingProvidersPluginManager $providers,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LoggerInterface $logger,
    protected Connection $database,
  ) {}

  /**
   * Đồng bộ địa chỉ.
   */
  public function synchronize(Term $config_term): array {
    try {
      $config = [
        "shipping_type" => $config_term->get("field_shipping_type")->entity,
        "shipping_host" => $config_term->get("field_shipping_host")->uri,
        "shipping_token" => $config_term->get("field_shipping_token")->value,
      ];

      if (empty($config["shipping_type"])
        || empty($config["shipping_host"])
        || empty($config["shipping_token"])) {
        return [
          "success" => FALSE,
          "message" => 'Shipping configuration is incomplete',
        ];
      }

      $type = $config["shipping_type"];
      $config["shipping_type_id"] = $type->id();

      $provider_id = $type->hasField("field_code") ? $type->get("field_code")->value : NULL;

      $provider = $this->getProvider($provider_id);
      $data = $provider->synchronizeAddresses($config);

      if (empty($data["success"])) {
        return $data;
      }

      return $this->saveAddresses($data["data"] ?? [], $config["shipping_type_id"]);
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
   * Lưu các địa chỉ chưa có, bỏ qua địa chỉ đã đồng bộ trước đó.
   */
  private function saveAddresses(array $addresses, string|int $type_id): array {
    $storage = $this->entityTypeManager->getStorage("shipping_address");
    $existing = $this->loadExistingAddresses($type_id);
    $created = 0;

    foreach ($addresses as $address) {
      $key = $this->addressKey($address["bundle"], $address["is_new"], $address["code"]);

      if (isset($existing[$key])) {
        continue;
      }

      $values = [
        "bundle" => $address["bundle"],
        "label" => $address["label"],
        "field_code" => $address["code"],
        "field_type" => $type_id,
        "field_is_new_address" => $address["is_new"],
      ];

      if ($address["bundle"] !== "province" && !empty($address["province_code"])) {
        $province_key = $this->addressKey("province", $address["is_new"], $address["province_code"]);
        $values["field_province"] = $existing[$province_key] ?? NULL;
      }

      if ($address["bundle"] === "commune" && !empty($address["district_code"])) {
        $district_key = $this->addressKey("district", $address["is_new"], $address["district_code"]);
        $values["field_district"] = $existing[$district_key] ?? NULL;
      }

      $entity = $storage->create($values);
      $entity->save();

      $existing[$key] = $entity->id();
      $created++;

      if ($created % 500 === 0) {
        $storage->resetCache();
      }
    }

    return [
      "success" => TRUE,
      "created" => $created,
      "total" => count($addresses),
    ];
  }

  /**
   * Lấy các địa chỉ đã tồn tại theo khoá bundle - cũ/mới - mã.
   */
  private function loadExistingAddresses(string|int $type_id): array {
    $query = $this->database->select("shipping_address", "a");
    $query->join("shipping_address__field_type", "t", "t.entity_id = a.id");
    $query->join("shipping_address__field_code", "c", "c.entity_id = a.id");
    $query->leftJoin("shipping_address__field_is_new_address", "n", "n.entity_id = a.id");
    $query->fields("a", ["id", "bundle"]);
    $query->addField("c", "field_code_value", "code");
    $query->addField("n", "field_is_new_address_value", "is_new");
    $query->condition("t.field_type_target_id", $type_id);

    $existing = [];
    foreach ($query->execute() as $row) {
      $existing[$this->addressKey($row->bundle, $row->is_new, $row->code)] = $row->id;
    }

    return $existing;
  }

  /**
   * Khoá tra cứu địa chỉ.
   */
  private function addressKey(string $bundle, $is_new, $code): string {
    return $bundle . ":" . (int) $is_new . ":" . $code;
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