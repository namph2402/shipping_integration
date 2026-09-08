<?php

namespace Drupal\shipping_integration\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\shipping_integration\Exception\ShippingTokenException;
use Drupal\shipping_integration\ShippingOrderInterface;
use Drupal\shipping_integration\ShippingProvidersInterface;
use Drupal\shipping_integration\ShippingProvidersPluginManager;
use Drupal\taxonomy\TermInterface;
use Psr\Log\LoggerInterface;

/**
 * Tầng nghiệp vụ cho đơn hàng trong nước.
 *
 * Service đứng giữa entity shipping_order và plugin của từng hãng: đọc entity
 * ra mảng chuẩn hoá, gọi plugin, rồi ghi kết quả ngược lại entity. Mọi lệnh
 * đều đi qua ::call() để nếu hãng từ chối token thì xin token mới và gọi lại
 * đúng một lần.
 */
class HandleShipping {

  /**
   * Thư mục lưu file vận đơn tải về từ hãng.
   */
  protected const LABEL_DIRECTORY = "public://shipping/label";

  /**
   * Giá trị field_so_status khi đơn mới chỉ nằm nháp trên hệ thống hãng.
   */
  public const STATUS_DRAFT = 0;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    protected ShippingProvidersPluginManager $providers,
    protected GetConfigShipping $getConfig,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileSystemInterface $fileSystem,
    protected FileRepositoryInterface $fileRepository,
    protected LoggerInterface $logger,
    protected TimeInterface $time,
  ) {}

  /**
   * Lấy token cho một term cấu hình.
   *
   * @param \Drupal\taxonomy\TermInterface $config_entity
   *   Term cấu hình kết nối.
   *
   * @return array|null
   *   Cấu hình đã cập nhật token, hoặc NULL khi lấy token thất bại.
   */
  public function getToken(TermInterface $config_entity): ?array {
    return $this->getConfig->refreshToken($config_entity);
  }

  /**
   * Đẩy một đơn hàng sang hãng vận chuyển.
   *
   * @param \Drupal\shipping_integration\ShippingOrderInterface $order
   *   Đơn hàng cần tạo.
   * @param bool $draft
   *   TRUE thì chỉ lưu nháp trên hệ thống hãng.
   *
   * @return array
   *   Kết quả gồm success và message, kèm data khi thành công.
   */
  public function createOrder(ShippingOrderInterface $order, bool $draft = FALSE): array {
    return $this->run($order, function (ShippingProvidersInterface $provider, array $config) use ($order, $draft): array {
      $payload = $this->buildOrderPayload($order);
      $payload["draft"] = $draft;

      $record = $provider->createOrder($config, $payload);
      $this->applyRecord($order, $record);
      $order->save();

      return [
        "success" => TRUE,
        "message" => "Đã tạo đơn hàng " . ($record["itemCode"] ?? ""),
        "data" => $record,
      ];
    });
  }

  /**
   * Phát hành một đơn đang nháp lên hệ thống hãng.
   *
   * Đơn nháp mới chỉ nằm trên hệ thống hãng chứ chưa vào luồng khai thác;
   * lệnh này mới là lúc bưu gửi thực sự được nhận. Hãng trả về bản ghi đơn
   * đầy đủ nên ghi đè lại toàn bộ như khi tạo mới.
   *
   * @param \Drupal\shipping_integration\ShippingOrderInterface $order
   *   Đơn nháp cần phát hành.
   *
   * @return array
   *   Kết quả gồm success và message, kèm data khi thành công.
   */
  public function confirmDraft(ShippingOrderInterface $order): array {
    return $this->run($order, function (ShippingProvidersInterface $provider, array $config) use ($order): array {
      if ((int) $this->fieldValue($order, "field_so_status") !== self::STATUS_DRAFT) {
        return [
          "success" => FALSE,
          "message" => "Đơn hàng không còn ở trạng thái nháp",
        ];
      }

      $record = $provider->confirmDraft($config, $this->lookupCode($order), $this->lookupType($order));
      $this->applyRecord($order, $record);
      $order->save();

      return [
        "success" => TRUE,
        "message" => "Đã phát hành đơn nháp " . ($record["itemCode"] ?? ""),
        "data" => $record,
      ];
    });
  }

  /**
   * Hiệu chỉnh một đơn hàng đã tạo trên hệ thống hãng.
   *
   * @param \Drupal\shipping_integration\ShippingOrderInterface $order
   *   Đơn hàng cần hiệu chỉnh.
   *
   * @return array
   *   Kết quả gồm success và message.
   */
  public function updateOrder(ShippingOrderInterface $order): array {
    return $this->run($order, function (ShippingProvidersInterface $provider, array $config) use ($order): array {
      $result = $provider->updateOrder($config, $this->buildOrderPayload($order));
      $this->applyCase($order, $result);
      $order->save();

      return [
        "success" => !empty($result["success"]) || !empty($result["pending"]),
        "message" => $result["message"] ?: "Đã gửi yêu cầu hiệu chỉnh",
        "data" => $result,
      ];
    });
  }

  /**
   * Hủy một đơn hàng.
   *
   * @param \Drupal\shipping_integration\ShippingOrderInterface $order
   *   Đơn hàng cần hủy.
   *
   * @return array
   *   Kết quả gồm success và message.
   */
  public function cancelOrder(ShippingOrderInterface $order): array {
    return $this->run($order, function (ShippingProvidersInterface $provider, array $config) use ($order): array {
      $original_id = $this->fieldValue($order, "field_so_original_id");

      // Đơn còn ở trạng thái nháp chưa có ID gốc thì xóa nháp thay vì hủy.
      if ($original_id === "" && (int) $this->fieldValue($order, "field_so_status") === self::STATUS_DRAFT) {
        $result = $provider->deleteDraft($config, $this->lookupCode($order), $this->lookupType($order));

        return [
          "success" => TRUE,
          "message" => $result["message"] ?: "Đã xóa đơn nháp",
        ];
      }

      $result = $provider->cancelOrder($config, $original_id);
      $this->applyCase($order, $result);
      $order->save();

      return [
        "success" => !empty($result["success"]) || !empty($result["pending"]),
        "message" => $result["message"] ?: "Đã gửi yêu cầu hủy đơn",
        "data" => $result,
      ];
    });
  }

  /**
   * Lấy kết quả phê duyệt hiệu chỉnh hoặc hủy đơn.
   *
   * @param \Drupal\shipping_integration\ShippingOrderInterface $order
   *   Đơn hàng đang chờ phê duyệt.
   *
   * @return array
   *   Kết quả gồm success và message.
   */
  public function approvalResult(ShippingOrderInterface $order): array {
    return $this->run($order, function (ShippingProvidersInterface $provider, array $config) use ($order): array {
      $case_id = $this->fieldValue($order, "field_so_case_id");

      if ($case_id === "") {
        return [
          "success" => FALSE,
          "message" => "Đơn hàng không có yêu cầu hiệu chỉnh hoặc hủy nào",
        ];
      }

      $results = $provider->approvalResult(
        $config,
        $this->fieldValue($order, "field_so_original_id"),
        $case_id
      );

      $result = reset($results) ?: [];

      if (!empty($result)) {
        $this->applyCase($order, $result);
        $order->save();
      }

      return [
        "success" => !empty($result["success"]),
        "message" => $result["message"] ?? "Chưa có kết quả phê duyệt",
        "data" => $result,
      ];
    });
  }

  /**
   * Tính cước phí cho một đơn hàng.
   *
   * @param \Drupal\shipping_integration\ShippingOrderInterface $order
   *   Đơn hàng cần tính cước.
   * @param bool $save
   *   TRUE thì ghi bảng cước vào entity.
   *
   * @return array
   *   Kết quả gồm success, message và data là bảng cước.
   */
  public function calculateFee(ShippingOrderInterface $order, bool $save = TRUE): array {
    return $this->run($order, function (ShippingProvidersInterface $provider, array $config) use ($order, $save): array {
      $fee = $provider->calculateFee($config, $this->buildOrderPayload($order));

      if ($save) {
        $this->setValue($order, "field_so_main_fee", $fee["main_fee"]);
        $this->setValue($order, "field_so_vas_fee", $fee["vas_fee"]);
        $this->setValue($order, "field_so_total_fee", $fee["total_fee"]);
        $this->setValue($order, "field_so_price_weight", $fee["price_weight"]);
        $order->save();
      }

      return [
        "success" => TRUE,
        "message" => "Đã tính cước phí",
        "data" => $fee,
      ];
    });
  }

  /**
   * Đồng bộ trạng thái và cước phí của một đơn hàng từ hệ thống hãng.
   *
   * @param \Drupal\shipping_integration\ShippingOrderInterface $order
   *   Đơn hàng cần đồng bộ.
   *
   * @return array
   *   Kết quả gồm success và message.
   */
  public function synchronizeOrder(ShippingOrderInterface $order): array {
    return $this->run($order, function (ShippingProvidersInterface $provider, array $config) use ($order): array {
      $record = $provider->getOrder($config, $this->lookupCode($order), $this->lookupType($order));

      if (empty($record)) {
        return [
          "success" => FALSE,
          "message" => "Không tìm thấy đơn hàng trên hệ thống hãng",
        ];
      }

      $this->applyRecord($order, $record);
      $order->save();

      return [
        "success" => TRUE,
        "message" => "Đã đồng bộ đơn hàng",
        "data" => $record,
      ];
    });
  }

  /**
   * Lấy hành trình của một đơn hàng và lưu lại trên entity.
   *
   * @param \Drupal\shipping_integration\ShippingOrderInterface $order
   *   Đơn hàng cần tra hành trình.
   *
   * @return array
   *   Kết quả gồm success, message và data là danh sách mốc hành trình.
   */
  public function orderHistory(ShippingOrderInterface $order): array {
    return $this->run($order, function (ShippingProvidersInterface $provider, array $config) use ($order): array {
      $history = $provider->orderHistory($config, $this->lookupCode($order), $this->lookupType($order));

      $this->setValue($order, "field_so_history", json_encode($history, JSON_UNESCAPED_UNICODE));
      $order->save();

      return [
        "success" => TRUE,
        "message" => "Đã lấy hành trình đơn hàng",
        "data" => $history,
      ];
    });
  }

  /**
   * Tải vận đơn của một đơn hàng và gắn vào entity.
   *
   * @param \Drupal\shipping_integration\ShippingOrderInterface $order
   *   Đơn hàng cần in vận đơn.
   *
   * @return array
   *   Kết quả gồm success, message và data là entity file.
   */
  public function printLabel(ShippingOrderInterface $order): array {
    return $this->run($order, function (ShippingProvidersInterface $provider, array $config) use ($order): array {
      $item_code = $this->fieldValue($order, "field_so_item_code");

      if ($item_code === "") {
        return [
          "success" => FALSE,
          "message" => "Đơn hàng chưa có số hiệu bưu gửi",
        ];
      }

      $files = $provider->printLabel($config, [$item_code]);
      $file = $this->saveLabel($item_code, reset($files));

      if ($file !== NULL) {
        $this->setValue($order, "field_so_label", ["target_id" => $file->id()]);
        $order->save();
      }

      return [
        "success" => $file !== NULL,
        "message" => $file !== NULL ? "Đã tải vận đơn" : "Không lưu được file vận đơn",
        "data" => $file,
      ];
    });
  }

  /**
   * Kéo danh sách đơn hàng của một cấu hình về thành entity.
   *
   * Đơn đã có trong hệ thống được cập nhật theo số hiệu bưu gửi, đơn chưa có
   * thì tạo mới, nên chạy lại nhiều lần vẫn an toàn.
   *
   * @param \Drupal\taxonomy\TermInterface $config_entity
   *   Term cấu hình kết nối.
   * @param array $params
   *   Tham số lọc: from, to (Y-m-d), type (GUI|NHAN).
   *
   * @return array
   *   Kết quả gồm success, message, created và updated.
   */
  public function pullOrders(TermInterface $config_entity, array $params = []): array {
    try {
      $config = $this->getConfig->handle($config_entity);
      $provider = $this->provider($config);

      try {
        $records = $provider->listOrders($config, $params);
      }
      catch (ShippingTokenException) {
        $config = $this->getConfig->refresh($config) ?? $config;
        $records = $provider->listOrders($config, $params);
      }
    }
    catch (\DomainException | ShippingTokenException $e) {
      return ["success" => FALSE, "message" => $e->getMessage()];
    }
    catch (\Throwable $e) {
      $this->logger->error("Shipping system error: @message", [
        "@message" => $e->getMessage(),
        "exception" => $e,
      ]);

      return ["success" => FALSE, "message" => "The system is experiencing problems"];
    }

    $storage = $this->entityTypeManager->getStorage("shipping_order");
    $created = 0;
    $updated = 0;

    foreach ($records as $record) {
      $item_code = (string) ($record["itemCode"] ?? "");

      if ($item_code === "") {
        continue;
      }

      $existing = $storage->loadByProperties(["field_so_item_code" => $item_code]);
      $order = reset($existing);

      if (!$order instanceof ShippingOrderInterface) {
        $order = $storage->create([
          "bundle" => $this->bundleFor($config),
          "label" => $item_code,
          "field_so_config" => $config["shipping_config_id"],
          "field_so_carrier" => $config["shipping_type_id"],
        ]);
        $created++;
      }
      else {
        $updated++;
      }

      $this->applyRecord($order, $record, TRUE);
      $order->save();
    }

    return [
      "success" => TRUE,
      "message" => "Đã kéo về {$created} đơn mới và cập nhật {$updated} đơn",
      "created" => $created,
      "updated" => $updated,
    ];
  }

  /**
   * Chạy một lệnh trên đơn hàng, bọc sẵn xử lý lỗi và làm mới token.
   *
   * @param \Drupal\shipping_integration\ShippingOrderInterface $order
   *   Đơn hàng đang thao tác.
   * @param callable $operation
   *   Hàm nhận (provider, config) và trả về mảng kết quả.
   *
   * @return array
   *   Kết quả gồm success và message.
   */
  private function run(ShippingOrderInterface $order, callable $operation): array {
    try {
      $config_entity = $order->hasField("field_so_config")
        ? $order->get("field_so_config")->entity
        : NULL;

      if (!$config_entity instanceof TermInterface) {
        throw new \DomainException("Đơn hàng chưa gắn cấu hình kết nối");
      }

      $config = $this->getConfig->handle($config_entity);
      $provider = $this->provider($config);

      try {
        return $operation($provider, $config);
      }
      catch (ShippingTokenException) {
        // Hãng từ chối token sớm hơn mốc lưu trong term, xin bộ mới rồi thử
        // lại đúng một lần để không lặp vô hạn khi tài khoản sai.
        $config = $this->getConfig->refresh($config) ?? $config;
        return $operation($provider, $config);
      }
    }
    catch (\DomainException | ShippingTokenException $e) {
      return ["success" => FALSE, "message" => $e->getMessage()];
    }
    catch (\Throwable $e) {
      $this->logger->error("Shipping system error: @message", [
        "@message" => $e->getMessage(),
        "exception" => $e,
      ]);

      return ["success" => FALSE, "message" => "The system is experiencing problems"];
    }
  }

  /**
   * Lấy plugin của hãng theo cấu hình.
   *
   * @param array $config
   *   Cấu hình kết nối.
   *
   * @return \Drupal\shipping_integration\ShippingProvidersInterface
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

    /** @var \Drupal\shipping_integration\ShippingProvidersInterface $provider */
    $provider = $this->providers->createInstance($provider_id);

    return $provider;
  }

  /**
   * Đọc entity đơn hàng ra mảng chuẩn hoá cho plugin.
   *
   * @param \Drupal\shipping_integration\ShippingOrderInterface $order
   *   Đơn hàng cần đọc.
   *
   * @return array
   *   Đơn hàng đã chuẩn hoá.
   */
  public function buildOrderPayload(ShippingOrderInterface $order): array {
    return [
      "type" => "GUI",
      "sale_code" => $this->fieldValue($order, "field_so_sale_code"),
      "item_code" => $this->fieldValue($order, "field_so_item_code"),
      "original_id" => $this->fieldValue($order, "field_so_original_id"),
      "content" => $this->fieldValue($order, "field_so_content"),
      "weight" => (int) $this->fieldValue($order, "field_so_weight"),
      "length" => $this->fieldValue($order, "field_so_length"),
      "width" => $this->fieldValue($order, "field_so_width"),
      "height" => $this->fieldValue($order, "field_so_height"),
      "service" => $this->fieldValue($order, "field_so_service"),
      "vehicle" => $this->fieldValue($order, "field_so_vehicle") ?: "BO",
      "send_type" => $this->fieldValue($order, "field_so_send_type") ?: "1",
      "is_broken" => (bool) $this->fieldValue($order, "field_so_is_broken"),
      "delivery_time" => $this->fieldValue($order, "field_so_delivery_time"),
      "delivery_require" => $this->fieldValue($order, "field_so_delivery_require"),
      "delivery_note" => $this->fieldValue($order, "field_so_delivery_note"),
      "org_collect" => $this->fieldValue($order, "field_so_org_collect"),
      "org_accept" => $this->fieldValue($order, "field_so_org_accept"),
      "cod" => (float) $this->fieldValue($order, "field_so_cod"),
      "insurance" => (float) $this->fieldValue($order, "field_so_insurance"),
      "is_new_address" => (bool) $this->fieldValue($order, "field_so_is_new_address"),
      "sender" => $this->buildParty($order, "sender"),
      "receiver" => $this->buildParty($order, "receiver"),
    ];
  }

  /**
   * Đọc thông tin một bên gửi hoặc nhận của đơn hàng.
   *
   * @param \Drupal\shipping_integration\ShippingOrderInterface $order
   *   Đơn hàng cần đọc.
   * @param string $party
   *   "sender" hoặc "receiver".
   *
   * @return array
   *   Thông tin bên tương ứng.
   */
  private function buildParty(ShippingOrderInterface $order, string $party): array {
    return [
      "name" => $this->fieldValue($order, "field_so_{$party}_name"),
      "phone" => $this->fieldValue($order, "field_so_{$party}_phone"),
      "email" => $this->fieldValue($order, "field_so_{$party}_email"),
      "address" => $this->fieldValue($order, "field_so_{$party}_address"),
      "province_code" => $this->addressCode($order, "field_so_{$party}_province"),
      "province_name" => $this->addressLabel($order, "field_so_{$party}_province"),
      "district_code" => $this->addressCode($order, "field_so_{$party}_district"),
      "district_name" => $this->addressLabel($order, "field_so_{$party}_district"),
      "commune_code" => $this->addressCode($order, "field_so_{$party}_commune"),
      "commune_name" => $this->addressLabel($order, "field_so_{$party}_commune"),
    ];
  }

  /**
   * Ghi bản ghi đơn hàng của hãng ngược lại entity.
   *
   * @param \Drupal\shipping_integration\ShippingOrderInterface $order
   *   Đơn hàng cần cập nhật.
   * @param array $record
   *   Bản ghi hãng trả về.
   * @param bool $with_parties
   *   TRUE thì ghi cả thông tin người gửi và người nhận, dùng khi kéo đơn về
   *   từ hệ thống hãng thay vì khi vừa đẩy đơn do mình dựng lên.
   */
  private function applyRecord(ShippingOrderInterface $order, array $record, bool $with_parties = FALSE): void {
    $this->setValue($order, "field_so_hdr_id", $record["orderHdrID"] ?? "");
    $this->setValue($order, "field_so_original_id", $record["originalID"] ?? "");
    $this->setValue($order, "field_so_item_code", $record["itemCode"] ?? "");
    $this->setValue($order, "field_so_sale_code", $record["saleOrderCode"] ?? "");
    $this->setValue($order, "field_so_status", $record["status"] ?? NULL);
    $this->setValue($order, "field_so_service", $record["serviceCode"] ?? "");
    $this->setValue($order, "field_so_main_fee", $record["mainFee"] ?? NULL);
    $this->setValue($order, "field_so_vas_fee", $record["vasFee"] ?? NULL);
    $this->setValue($order, "field_so_total_fee", $record["totalFee"] ?? NULL);
    $this->setValue($order, "field_so_price_weight", $record["priceWeight"] ?? NULL);
    $this->setValue($order, "field_so_bcp_code", $record["bcpCode"] ?? "");
    $this->setValue($order, "field_so_bcp_name", $record["bcpName"] ?? "");
    $this->setValue($order, "field_so_payload", json_encode($record, JSON_UNESCAPED_UNICODE));
    $this->setValue($order, "field_so_synced", $this->time->getRequestTime());

    if ($order->get("label")->isEmpty() && !empty($record["itemCode"])) {
      $order->set("label", $record["itemCode"]);
    }

    if (!$with_parties) {
      return;
    }

    $this->setValue($order, "field_so_sender_name", $record["senderName"] ?? "");
    $this->setValue($order, "field_so_sender_phone", $record["senderPhone"] ?? "");
    $this->setValue($order, "field_so_sender_email", $record["senderEmail"] ?? "");
    $this->setValue($order, "field_so_sender_address", $record["senderAddress"] ?? "");
    $this->setValue($order, "field_so_receiver_name", $record["receiverName"] ?? "");
    $this->setValue($order, "field_so_receiver_phone", $record["receiverPhone"] ?? "");
    $this->setValue($order, "field_so_receiver_email", $record["receiverEmail"] ?? "");
    $this->setValue($order, "field_so_receiver_address", $record["receiverAddress"] ?? "");
    $this->setValue($order, "field_so_weight", $record["weight"] ?? NULL);
    $this->setValue($order, "field_so_content", $record["contentNote"] ?? "");
    $this->setValue($order, "field_so_cod", $record["codAmount"] ?? NULL);
    $this->setValue($order, "field_so_vehicle", $record["vehicle"] ?? "");
    $this->setValue($order, "field_so_send_type", $record["sendType"] ?? "");
    $this->setValue($order, "field_so_delivery_time", $record["deliveryTime"] ?? "");
    $this->setValue($order, "field_so_delivery_require", $record["deliveryRequire"] ?? "");
    $this->setValue($order, "field_so_delivery_note", $record["deliveryInstruction"] ?? "");
    $this->setValue($order, "field_so_org_collect", $record["orgCodeCollect"] ?? "");
    $this->setValue($order, "field_so_org_accept", $record["orgCodeAccept"] ?? "");
  }

  /**
   * Ghi kết quả hiệu chỉnh hoặc hủy vào entity.
   *
   * @param \Drupal\shipping_integration\ShippingOrderInterface $order
   *   Đơn hàng cần cập nhật.
   * @param array $result
   *   Kết quả do plugin chuẩn hoá.
   */
  private function applyCase(ShippingOrderInterface $order, array $result): void {
    $this->setValue($order, "field_so_case_id", $result["case_id"] ?? "");
    $this->setValue($order, "field_so_case_type", $result["type"] ?? "");
    $this->setValue($order, "field_so_synced", $this->time->getRequestTime());
  }

  /**
   * Lưu nội dung PDF vận đơn thành file entity.
   *
   * @param string $item_code
   *   Số hiệu bưu gửi, dùng làm tên file.
   * @param string|false $binary
   *   Nội dung nhị phân của file.
   *
   * @return \Drupal\file\FileInterface|null
   *   File đã lưu, hoặc NULL khi không lưu được.
   */
  private function saveLabel(string $item_code, string|false $binary): ?object {
    if (empty($binary)) {
      return NULL;
    }

    if (!$this->fileSystem->prepareDirectory(
      $directory = static::LABEL_DIRECTORY,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS
    )) {
      $this->logger->error("Cannot prepare shipping label directory @dir", ["@dir" => $directory]);
      return NULL;
    }

    try {
      return $this->fileRepository->writeData(
        $binary,
        $directory . "/" . $item_code . ".pdf",
        FileExists::Replace
      );
    }
    catch (\Throwable $e) {
      $this->logger->error("Cannot save shipping label: @message", [
        "@message" => $e->getMessage(),
        "exception" => $e,
      ]);

      return NULL;
    }
  }

  /**
   * Bundle dùng cho đơn hàng kéo về của một cấu hình.
   *
   * @param array $config
   *   Cấu hình kết nối.
   *
   * @return string
   *   Machine name của bundle.
   */
  private function bundleFor(array $config): string {
    $provider_id = (string) ($config["shipping_provider"] ?? "");
    $bundles = $this->entityTypeManager
      ->getStorage("shipping_order_type")
      ->getQuery()
      ->accessCheck(FALSE)
      ->execute();

    // Quy ước: mỗi hãng một bundle trùng tên plugin. Hãng nào chưa có bundle
    // riêng thì rơi về bundle đầu tiên để đơn vẫn được lưu lại.
    return isset($bundles[$provider_id]) ? $provider_id : (string) reset($bundles);
  }

  /**
   * Giá trị tra cứu đơn hàng ưu tiên số hiệu bưu gửi.
   *
   * @param \Drupal\shipping_integration\ShippingOrderInterface $order
   *   Đơn hàng cần tra cứu.
   *
   * @return string
   *   Giá trị tra cứu.
   */
  private function lookupCode(ShippingOrderInterface $order): string {
    foreach (["field_so_item_code", "field_so_sale_code", "field_so_original_id"] as $field) {
      $value = $this->fieldValue($order, $field);

      if ($value !== "") {
        return $value;
      }
    }

    throw new \DomainException("Đơn hàng chưa có mã để tra cứu");
  }

  /**
   * Kiểu tra cứu tương ứng với giá trị ::lookupCode() chọn được.
   *
   * @param \Drupal\shipping_integration\ShippingOrderInterface $order
   *   Đơn hàng cần tra cứu.
   *
   * @return string
   *   1 số hiệu bưu gửi, 2 mã đơn hàng, 3 ID gốc.
   */
  private function lookupType(ShippingOrderInterface $order): string {
    if ($this->fieldValue($order, "field_so_item_code") !== "") {
      return "1";
    }

    return $this->fieldValue($order, "field_so_sale_code") !== "" ? "2" : "3";
  }

  /**
   * Đọc mã của một địa chỉ được đơn hàng trỏ tới.
   *
   * @param \Drupal\shipping_integration\ShippingOrderInterface $order
   *   Đơn hàng cần đọc.
   * @param string $field
   *   Tên field tham chiếu địa chỉ.
   *
   * @return string
   *   Mã địa chỉ, chuỗi rỗng khi chưa chọn.
   */
  private function addressCode(ShippingOrderInterface $order, string $field): string {
    $address = $order->hasField($field) ? $order->get($field)->entity : NULL;

    if ($address === NULL || !$address->hasField("field_code")) {
      return "";
    }

    return (string) $address->get("field_code")->value;
  }

  /**
   * Đọc tên của một địa chỉ được đơn hàng trỏ tới.
   *
   * @param \Drupal\shipping_integration\ShippingOrderInterface $order
   *   Đơn hàng cần đọc.
   * @param string $field
   *   Tên field tham chiếu địa chỉ.
   *
   * @return string
   *   Tên địa chỉ, chuỗi rỗng khi chưa chọn.
   */
  private function addressLabel(ShippingOrderInterface $order, string $field): string {
    $address = $order->hasField($field) ? $order->get($field)->entity : NULL;

    return $address === NULL ? "" : (string) $address->label();
  }

  /**
   * Đọc giá trị đơn trị của một field.
   *
   * @param \Drupal\shipping_integration\ShippingOrderInterface $order
   *   Đơn hàng cần đọc.
   * @param string $field
   *   Tên field.
   *
   * @return string
   *   Giá trị field, chuỗi rỗng khi trống.
   */
  private function fieldValue(ShippingOrderInterface $order, string $field): string {
    if (!$order->hasField($field) || $order->get($field)->isEmpty()) {
      return "";
    }

    return (string) $order->get($field)->value;
  }

  /**
   * Gán giá trị cho field nếu bundle có field đó và giá trị không rỗng.
   *
   * @param \Drupal\shipping_integration\ShippingOrderInterface $order
   *   Đơn hàng cần cập nhật.
   * @param string $field
   *   Tên field.
   * @param mixed $value
   *   Giá trị cần gán.
   */
  private function setValue(ShippingOrderInterface $order, string $field, mixed $value): void {
    if (!$order->hasField($field) || $value === NULL || $value === "") {
      return;
    }

    $order->set($field, $value);
  }

}
