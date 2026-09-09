<?php

namespace Drupal\shipping_integration\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\shipping_integration\Exception\ShippingTokenException;
use Drupal\shipping_integration\ShippingProvidersInterface;
use Drupal\shipping_integration\ShippingProvidersPluginManager;
use Drupal\taxonomy\TermInterface;
use Psr\Log\LoggerInterface;

/**
 * Đồng bộ danh mục địa chỉ của một hãng vận chuyển về entity shipping_address.
 *
 * Danh mục địa chỉ Việt Nam đang tồn tại song song hai bộ: bộ ba cấp cũ
 * (tỉnh - huyện - xã) và bộ hai cấp sau sáp nhập (tỉnh - xã). Cả hai được lưu
 * chung một entity, phân biệt bằng field_is_new_address, vì đơn hàng cũ vẫn
 * tra cứu theo mã cũ còn đơn mới dùng mã mới.
 */
class SynchronizeAddresses {

  /**
   * Số bản ghi lưu được thì xả cache tĩnh một lần.
   */
  protected const RESET_CACHE_EVERY = 500;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    protected ShippingProvidersPluginManager $providers,
    protected GetConfigShipping $getConfig,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LoggerInterface $logger,
    protected Connection $database,
  ) {}

  /**
   * Đồng bộ địa chỉ theo một term cấu hình kết nối.
   *
   * @param TermInterface $config_entity
   *   Term cấu hình kết nối.
   *
   * @return array
   *   Kết quả gồm success, message, created và total.
   */
  public function synchronize(TermInterface $config_entity): array {
    try {
      $config = $this->getConfig->handle($config_entity);

      if (empty($config["shipping_type_id"])) {
        return [
          "success" => FALSE,
          "message" => "Cấu hình chưa chọn hãng vận chuyển",
        ];
      }

      $provider = $this->provider($config);

      try {
        $data = $provider->synchronizeAddresses($config);
      }
      catch (ShippingTokenException) {
        $config = $this->getConfig->refresh($config) ?? $config;
        $data = $provider->synchronizeAddresses($config);
      }

      if (empty($data["success"])) {
        return $data;
      }

      return $this->saveAddresses($data["data"] ?? [], $config["shipping_type_id"]);
    }
    catch (\DomainException | ShippingTokenException $e) {
      return [
        "success" => FALSE,
        "message" => $e->getMessage(),
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error(
        "Shipping system error: @message",
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
   *
   * @param array $addresses
   *   Danh sách địa chỉ đã chuẩn hoá, xếp theo thứ tự tỉnh, huyện, xã.
   * @param string|int $type_id
   *   ID của entity shipping_type.
   *
   * @return array
   *   Kết quả gồm success, created và total.
   * 
   * Cha của bản ghi luôn được đồng bộ trước trong cùng lượt chạy nên tra
   * trong $existing là đủ, không cần truy vấn lại.
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

      if ($created % static::RESET_CACHE_EVERY === 0) {
        $storage->resetCache();
      }
    }

    return [
      "success" => TRUE,
      "message" => "Đã đồng bộ {$created} địa chỉ mới trên tổng số " . count($addresses),
      "created" => $created,
      "total" => count($addresses),
    ];
  }

  /**
   * Lấy các địa chỉ đã tồn tại theo khoá bundle - cũ/mới - mã.
   *
   * Truy vấn thẳng bảng thay vì loadMultiple vì danh mục địa chỉ lên tới hàng
   * chục nghìn bản ghi, nạp hết ra entity sẽ vỡ bộ nhớ.
   *
   * @param string|int $type_id
   *   ID của entity shipping_type.
   *
   * @return array
   *   Mảng khoá tra cứu ánh xạ sang id địa chỉ.
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
   *
   * @param string $bundle
   *   Bundle của địa chỉ.
   * @param mixed $is_new
   *   Cờ danh mục hai cấp.
   * @param mixed $code
   *   Mã địa chỉ.
   *
   * @return string
   *   Khoá tra cứu.
   */
  private function addressKey(string $bundle, mixed $is_new, mixed $code): string {
    return $bundle . ":" . (int) $is_new . ":" . $code;
  }

  /**
   * Lấy plugin của hãng theo cấu hình.
   *
   * @param array $config
   *   Cấu hình kết nối.
   *
   * @return ShippingProvidersInterface
   *   Plugin tương ứng.
   */
  private function provider(array $config): ShippingProvidersInterface {
    $provider_id = (string) ($config["shipping_provider"] ?? "");

    if ($provider_id === "") {
      throw new \DomainException("Shipping provider not yet configured");
    }

    if (!$this->providers->hasDefinition($provider_id)) {
      throw new \DomainException("The shipping provider {$provider_id} does not exist");
    }

    /** @var ShippingProvidersInterface $provider */
    $provider = $this->providers->createInstance($provider_id);

    return $provider;
  }

}
