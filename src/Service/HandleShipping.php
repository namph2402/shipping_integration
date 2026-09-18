<?php

namespace Drupal\shipping_integration\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\shipping_integration\Catalog\VnpostCatalog;
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
   * Id địa chỉ đã tra trong request, khoá theo cấp, mã, bộ, hãng và cấp cha.
   *
   * Kéo về hàng trăm đơn thì cùng một tỉnh bị tra lại rất nhiều lần.
   */
  private array $addressCache = [];

  /**
   * Các trường người dùng khai trên form, được chụp lại trước khi hiệu chỉnh.
   *
   * Form lưu đơn trước rồi mới gửi yêu cầu hiệu chỉnh, nên hãng từ chối thì
   * phải có bản cũ để trả đơn về đúng thông tin đang nằm trên hệ thống hãng.
   */
  private const CORRECTION_FIELDS = [
    "field_so_config",
    "field_so_carrier",
    "field_so_sale_code",
    "field_so_service",
    "field_so_content",
    "field_so_weight",
    "field_so_length",
    "field_so_width",
    "field_so_height",
    "field_so_vehicle",
    "field_so_send_type",
    "field_so_is_broken",
    "field_so_cod",
    "field_so_insurance",
    "field_so_addons",
    "field_so_delivery_time",
    "field_so_delivery_require",
    "field_so_delivery_note",
    "field_so_org_collect",
    "field_so_org_accept",
    "field_so_is_new_address",
    "field_so_sender_name",
    "field_so_sender_phone",
    "field_so_sender_email",
    "field_so_sender_address",
    "field_so_sender_province",
    "field_so_sender_district",
    "field_so_sender_commune",
    "field_so_receiver_name",
    "field_so_receiver_phone",
    "field_so_receiver_email",
    "field_so_receiver_address",
    "field_so_receiver_province",
    "field_so_receiver_district",
    "field_so_receiver_commune",
  ];

  /**
   * Mã kết quả hãng dùng cho yêu cầu hiệu chỉnh hoặc hủy bị từ chối.
   */
  private const CASE_REJECTED = "01";

  /**
   * Múi giờ của các mốc thời gian hãng trả về.
   */
  private const CARRIER_TIMEZONE = "Asia/Ho_Chi_Minh";

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
    protected ShippingOrderLogger $orderLogger,
  ) {}

  /**
   * Lấy token cho một term cấu hình.
   *
   * @param TermInterface $config_entity
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
   * @param ShippingOrderInterface $order
   *   Đơn hàng cần tạo.
   * @param bool $draft
   *   TRUE thì chỉ lưu nháp trên hệ thống hãng.
   *
   * @return array
   *   Kết quả gồm success và message, kèm data khi thành công.
   */
  public function createOrder(ShippingOrderInterface $order, bool $draft = FALSE): array {
    return $this->run($order, $draft ? "draft" : "create", function (ShippingProvidersInterface $provider, array $config) use ($order, $draft): array {
      $payload = $this->buildOrderPayload($order);
      $payload["draft"] = $draft;

      $record = $provider->createOrder($config, $payload);
      $this->applyRecord($order, $record, $config);
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
   * @param ShippingOrderInterface $order
   *   Đơn nháp cần phát hành.
   *
   * @return array
   *   Kết quả gồm success và message, kèm data khi thành công.
   */
  public function confirmDraft(ShippingOrderInterface $order): array {
    return $this->run($order, "confirm_draft", function (ShippingProvidersInterface $provider, array $config) use ($order): array {
      if ((int) $this->fieldValue($order, "field_so_status") !== self::STATUS_DRAFT) {
        return [
          "success" => FALSE,
          "message" => "Đơn hàng không còn ở trạng thái nháp",
        ];
      }

      $record = $provider->confirmDraft($config, $this->lookupCode($order), $this->lookupType($order));
      $this->applyRecord($order, $record, $config);
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
   * Bản chụp thông tin trước khi sửa được cất trong nhật ký cùng CaseId, để
   * khi hãng từ chối (ngay lúc gửi hoặc lúc lấy kết quả phê duyệt) thì trả đơn
   * về đúng thông tin cũ.
   *
   * @param ShippingOrderInterface $order
   *   Đơn hàng cần hiệu chỉnh.
   * @param array|null $previous
   *   Bản chụp thông tin trước khi sửa, lấy bằng ::snapshot() trước khi form
   *   lưu đơn. Để trống thì chụp theo dữ liệu đang lưu, dùng khi gửi lại đơn
   *   mà không sửa gì.
   * @param string $affair_type
   *   Loại hiệu chỉnh người dùng chọn, để trống thì plugin dùng loại mặc định
   *   theo trạng thái của đơn.
   *
   * @return array
   *   Kết quả gồm success và message.
   */
  public function updateOrder(ShippingOrderInterface $order, ?array $previous = NULL, string $affair_type = ""): array {
    $previous ??= $this->snapshot($order);

    $result = $this->run($order, "update", function (ShippingProvidersInterface $provider, array $config) use ($order, $previous, $affair_type): array {
      $result = $provider->updateOrder($config, ["affair_type" => $affair_type] + $this->buildOrderPayload($order));
      $this->applyCase($order, $result);
      $order->save();

      return [
        "success" => !empty($result["success"]) || !empty($result["pending"]),
        "message" => $result["message"] ?: "Đã gửi yêu cầu hiệu chỉnh",
        "data" => $result + ["previous" => $previous],
      ];
    });

    if (empty($result["success"])) {
      $this->restoreSnapshot($order, $previous, $this->fieldValue($order, "field_so_case_id"));
      $result["message"] = ($result["message"] ?? "") . ". Đã khôi phục thông tin trước khi hiệu chỉnh";
    }

    return $result;
  }

  /**
   * Chụp lại các trường người dùng khai của đơn theo dữ liệu đang lưu.
   *
   * Đọc lại từ cơ sở dữ liệu chứ không đọc entity đang cầm, vì form có thể đã
   * chép giá trị mới vào entity trước khi lưu.
   *
   * @param ShippingOrderInterface $order
   *   Đơn hàng cần chụp.
   *
   * @return array
   *   Giá trị thô theo tên field.
   */
  public function snapshot(ShippingOrderInterface $order): array {
    $stored = $order->isNew()
      ? $order
      : ($this->entityTypeManager->getStorage("shipping_order")->loadUnchanged($order->id()) ?? $order);

    $values = [];

    foreach (self::CORRECTION_FIELDS as $field) {
      if ($stored->hasField($field)) {
        $values[$field] = $stored->get($field)->getValue();
      }
    }

    return $values;
  }

  /**
   * Trả đơn về bản chụp trước khi hiệu chỉnh và ghi nhật ký.
   *
   * @param ShippingOrderInterface $order
   *   Đơn hàng cần khôi phục.
   * @param array $previous
   *   Bản chụp do ::snapshot() tạo ra.
   * @param string $case_id
   *   Yêu cầu hiệu chỉnh bị từ chối, rỗng khi hãng chưa kịp cấp.
   */
  private function restoreSnapshot(ShippingOrderInterface $order, array $previous, string $case_id): void {
    if ($previous === []) {
      return;
    }

    try {
      $changed = FALSE;

      foreach ($previous as $field => $value) {
        if (in_array($field, self::CORRECTION_FIELDS, TRUE) && $order->hasField($field) && $order->get($field)->getValue() != $value) {
          $order->set($field, $value);
          $changed = TRUE;
        }
      }

      // Gửi lại đơn mà không sửa gì thì không có gì để trả về.
      if (!$changed) {
        return;
      }

      $order->skip_order_log = TRUE;
      $order->save();
    }
    catch (\Throwable $e) {
      $this->logger->error("Không khôi phục được đơn @order sau hiệu chỉnh bị từ chối: @message", [
        "@order" => $order->id(),
        "@message" => $e->getMessage(),
        "exception" => $e,
      ]);

      return;
    }

    $this->orderLogger->log($order, "restore", [
      "message" => "Khôi phục thông tin trước khi hiệu chỉnh vì VN-Post không chấp nhận",
      "status_from" => $this->orderLogger->currentStatus($order),
      "status_to" => $this->orderLogger->currentStatus($order),
      "payload" => ["case_id" => $case_id, "restored" => $previous],
    ]);
  }

  /**
   * Tìm bản chụp đã cất khi gửi yêu cầu hiệu chỉnh có CaseId cho trước.
   *
   * @param ShippingOrderInterface $order
   *   Đơn hàng.
   * @param string $case_id
   *   Yêu cầu hiệu chỉnh cần tìm.
   *
   * @return array|null
   *   Bản chụp, hoặc NULL khi không có hay yêu cầu này đã được khôi phục rồi,
   *   để lấy kết quả phê duyệt nhiều lần không đè lên những lần sửa sau đó.
   */
  private function findSnapshot(ShippingOrderInterface $order, string $case_id): ?array {
    if ($case_id === "") {
      return NULL;
    }

    $storage = $this->entityTypeManager->getStorage("shipping_order_log");
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition("order_id", $order->id())
      ->condition("source", ["update", "restore"], "IN")
      ->sort("id", "DESC")
      ->range(0, 50)
      ->execute();

    foreach ($storage->loadMultiple($ids) as $entry) {
      $payload = json_decode((string) $entry->get("payload")->value, TRUE);

      if (!is_array($payload) || (string) ($payload["case_id"] ?? "") !== $case_id) {
        continue;
      }

      if ($entry->get("source")->value === "restore") {
        return NULL;
      }

      return is_array($payload["previous"] ?? NULL) ? $payload["previous"] : NULL;
    }

    return NULL;
  }

  /**
   * Hủy một đơn hàng.
   *
   * @param ShippingOrderInterface $order
   *   Đơn hàng cần hủy.
   *
   * @return array
   *   Kết quả gồm success và message.
   *
   * Đơn còn ở trạng thái nháp chưa có ID gốc thì xóa nháp thay vì hủy.
   */
  public function cancelOrder(ShippingOrderInterface $order): array {
    return $this->run($order, "cancel", function (ShippingProvidersInterface $provider, array $config) use ($order): array {
      $original_id = $this->fieldValue($order, "field_so_original_id");

      // Đơn nháp phải xóa nháp chứ không hủy: /orderCancel từ chối đơn chưa
      // phát hành. Hãng vẫn cấp originalID cho bản nháp nên chỉ được nhìn
      // trạng thái để phân nhánh, không nhìn originalID.
      if ((int) $this->fieldValue($order, "field_so_status") === self::STATUS_DRAFT) {
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
   * @param ShippingOrderInterface $order
   *   Đơn hàng đang chờ phê duyệt.
   *
   * @return array
   *   Kết quả gồm success và message.
   */
  public function approvalResult(ShippingOrderInterface $order): array {
    return $this->run($order, "approval", function (ShippingProvidersInterface $provider, array $config) use ($order): array {
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

      $message = $result["message"] ?? "Chưa có kết quả phê duyệt";

      // Yêu cầu hủy không có bản chụp nên findSnapshot() trả NULL và bỏ qua.
      if (($result["type"] ?? "") === self::CASE_REJECTED) {
        $previous = $this->findSnapshot($order, $case_id);

        if ($previous !== NULL) {
          $this->restoreSnapshot($order, $previous, $case_id);
          $message .= ". Đã khôi phục thông tin trước khi hiệu chỉnh";
        }
      }

      return [
        "success" => !empty($result["success"]),
        "message" => $message,
        "data" => $result,
      ];
    });
  }

  /**
   * Tính cước phí cho một đơn hàng.
   *
   * @param ShippingOrderInterface $order
   *   Đơn hàng cần tính cước.
   * @param bool $save
   *   TRUE thì ghi bảng cước vào entity.
   *
   * @return array
   *   Kết quả gồm success, message và data là bảng cước.
   */
  public function calculateFee(ShippingOrderInterface $order, bool $save = TRUE): array {
    return $this->run($order, "fee", function (ShippingProvidersInterface $provider, array $config) use ($order, $save): array {
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
   * @param ShippingOrderInterface $order
   *   Đơn hàng cần đồng bộ.
   *
   * @return array
   *   Kết quả gồm success và message.
   */
  public function synchronizeOrder(ShippingOrderInterface $order): array {
    return $this->run($order, "sync", function (ShippingProvidersInterface $provider, array $config) use ($order): array {
      $record = $provider->getOrder($config, $this->lookupCode($order), $this->lookupType($order));

      if (empty($record)) {
        return [
          "success" => FALSE,
          "message" => "Không tìm thấy đơn hàng trên hệ thống hãng",
        ];
      }

      $this->applyRecord($order, $record, $config);
      $this->applyContacts($order, $record);
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
   * @param ShippingOrderInterface $order
   *   Đơn hàng cần tra hành trình.
   *
   * @return array
   *   Kết quả gồm success, message và data là danh sách mốc hành trình.
   */
  public function orderHistory(ShippingOrderInterface $order): array {
    return $this->run($order, "history", function (ShippingProvidersInterface $provider, array $config) use ($order): array {
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
   * @param ShippingOrderInterface $order
   *   Đơn hàng cần in vận đơn.
   *
   * @return array
   *   Kết quả gồm success, message và data là entity file.
   */
  public function printLabel(ShippingOrderInterface $order): array {
    return $this->run($order, "label", function (ShippingProvidersInterface $provider, array $config) use ($order): array {
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
   * @param TermInterface $config_entity
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
      $before = $order instanceof ShippingOrderInterface ? $this->orderLogger->currentStatus($order) : NULL;

      if (!$order instanceof ShippingOrderInterface) {
        $order = $storage->create([
          "bundle" => $this->bundleFor($config),
          "label" => $item_code,
          "field_so_config" => $config["shipping_config_id"],
          "field_so_carrier" => $config["shipping_type_id"],
        ]);
        $created++;
        $is_new = TRUE;
      }
      else {
        $updated++;
        $is_new = FALSE;
      }

      $this->applyRecord($order, $record, $config, TRUE);
      $order->save();

      $this->orderLogger->log($order, "pull", [
        "message" => $is_new ? "Kéo về đơn mới từ hệ thống hãng" : "Cập nhật đơn theo hệ thống hãng",
        "status_from" => $before,
        "status_to" => $this->orderLogger->currentStatus($order),
        "payload" => $record,
      ]);
    }

    return [
      "success" => TRUE,
      "message" => "Đã kéo về {$created} đơn mới và cập nhật {$updated} đơn",
      "created" => $created,
      "updated" => $updated,
    ];
  }

  /**
   * Ghi dữ liệu webhook hãng đẩy về vào các đơn hàng tương ứng.
   *
   * Webhook chỉ báo thay đổi của đơn đã có trên hệ thống hãng nên hàm này
   * không tạo đơn mới: bưu gửi không tra được sẽ được ghi lại để đối soát chứ
   * không dựng ra đơn rỗng thiếu người gửi, người nhận.
   *
   * Gói tin dùng bộ tên trường riêng và có những mục module không có cột lưu
   * (lý do không phát được, người nhận thực tế, trạng thái thanh toán) nên bản
   * ghi thô được giữ nguyên trong field_so_payload để tra khi cần.
   *
   * @param array $records
   *   Danh sách bản ghi đơn hàng trong gói tin webhook.
   *
   * @return array
   *   Số đơn đã cập nhật và danh sách bưu gửi không tra được.
   *
   * @see https://my-uat.vnpost.vn/static/api/webhook/send-webhook
   */
  public function applyWebhook(array $records, bool $verified = FALSE): array {
    $storage = $this->entityTypeManager->getStorage("shipping_order");
    $updated = 0;
    $missing = [];

    foreach ($records as $record) {
      $item_code = (string) ($record["itemCode"] ?? $record["originalItemCode"] ?? "");
      $original_id = (string) ($record["originalId"] ?? $record["originalID"] ?? "");

      $order = NULL;

      foreach (["field_so_item_code" => $item_code, "field_so_original_id" => $original_id] as $field => $value) {
        if ($value === "") {
          continue;
        }

        $found = $storage->loadByProperties([$field => $value]);
        $order = reset($found);

        if ($order instanceof ShippingOrderInterface) {
          break;
        }

        $order = NULL;
      }

      if ($order === NULL) {
        $missing[] = $item_code !== "" ? $item_code : $original_id;

        // Bưu gửi chưa có trên hệ thống vẫn ghi lại: về sau còn biết hãng đã
        // từng đẩy gì về mà mình bỏ qua.
        $this->orderLogger->log(NULL, "webhook", [
          "item_code" => $item_code !== "" ? $item_code : $original_id,
          "succeeded" => FALSE,
          "message" => "Bưu gửi chưa có trên hệ thống",
          "status_to" => $record["status"] ?? NULL,
          "payload" => $record,
          "verified" => $verified,
        ]);

        continue;
      }

      $before = $this->orderLogger->currentStatus($order);

      $this->applyWebhookRecord($order, $record);
      $order->save();
      $updated++;

      $this->orderLogger->log($order, "webhook", [
        "message" => "Hãng đẩy trạng thái về",
        "status_from" => $before,
        "status_to" => $this->orderLogger->currentStatus($order),
        "payload" => $record,
        "verified" => $verified,
      ]);
    }

    return ["updated" => $updated, "missing" => $missing];
  }

  /**
   * Ghi một bản ghi webhook vào entity đơn hàng.
   *
   * Chỉ nhận những trường module có cột lưu và chắc chắn cùng bộ giá trị với
   * lệnh tạo đơn. Hình thức gửi và yêu cầu khi phát cố tình bỏ qua vì webhook
   * mô tả chúng bằng bộ mã khác (TGTN, GHTBC) so với danh sách giá trị hợp lệ
   * của field, ghi vào sẽ làm hỏng dữ liệu đang có.
   *
   * @param ShippingOrderInterface $order
   *   Đơn hàng cần cập nhật.
   * @param array $record
   *   Bản ghi trong gói tin webhook.
   */
  private function applyWebhookRecord(ShippingOrderInterface $order, array $record): void {
    $this->setValue($order, "field_so_hdr_id", $record["orderHdrId"] ?? $record["orderHdrID"] ?? "");
    $this->setValue($order, "field_so_original_id", $record["originalId"] ?? $record["originalID"] ?? "");
    $this->setValue($order, "field_so_item_code", $record["itemCode"] ?? "");
    $this->setValue($order, "field_so_sale_code", $record["saleOrderCode"] ?? "");
    $this->setValue($order, "field_so_status", $record["status"] ?? NULL);
    $this->setValue($order, "field_so_service", $record["serviceCode"] ?? "");
    $this->setValue($order, "field_so_main_fee", $record["mainFee"] ?? NULL);
    $this->setValue($order, "field_so_vas_fee", $record["vasFee"] ?? NULL);
    $this->setValue($order, "field_so_total_fee", $record["totalFee"] ?? NULL);
    $this->setValue($order, "field_so_price_weight", $record["priceWeight"] ?? NULL);
    $this->setValue($order, "field_so_cod", $record["codAmount"] ?? NULL);
    $this->setValue($order, "field_so_weight", $record["weight"] ?? NULL);
    $this->setValue($order, "field_so_content", $record["contentNote"] ?? "");
    $this->setValue($order, "field_so_org_accept", $record["orgCodeAccept"] ?? "");
    $this->setValue($order, "field_so_payload", json_encode($record, JSON_UNESCAPED_UNICODE));
    $this->setValue($order, "field_so_synced", $this->time->getRequestTime());

    if ($order->get("label")->isEmpty() && !empty($record["itemCode"])) {
      $order->set("label", $record["itemCode"]);
    }
  }

  /**
   * Chạy một lệnh trên đơn hàng, bọc sẵn xử lý lỗi và làm mới token.
   *
   * @param ShippingOrderInterface $order
   *   Đơn hàng đang thao tác.
   * @param callable $operation
   *   Hàm nhận (provider, config) và trả về mảng kết quả.
   *
   * @return array
   *   Kết quả gồm success và message.
   */
  private function run(ShippingOrderInterface $order, string $source, callable $operation): array {
    $before = $this->orderLogger->currentStatus($order);
    $result = $this->execute($order, $operation);

    // Chỉ ghi lại dữ liệu dạng mảng: có lệnh trả về entity file nên json_encode
    // sẽ ra thứ vô nghĩa, mà nhật ký cần đọc được chứ không cần đủ.
    $data = $result["data"] ?? NULL;

    $this->orderLogger->log($order, $source, [
      "succeeded" => !empty($result["success"]),
      "message" => $result["message"] ?? "",
      "status_from" => $before,
      "status_to" => $this->orderLogger->currentStatus($order),
      "payload" => is_array($data) ? $data : NULL,
    ]);

    return $result;
  }

  /**
   * Chạy lệnh và chuẩn hoá lỗi, không quan tâm tới nhật ký.
   *
   * @param ShippingOrderInterface $order
   *   Đơn hàng đang thao tác.
   * @param callable $operation
   *   Hàm nhận (provider, config) và trả về mảng kết quả.
   *
   * @return array
   *   Kết quả gồm success và message.
   */
  private function execute(ShippingOrderInterface $order, callable $operation): array {
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

  /**
   * Đọc entity đơn hàng ra mảng chuẩn hoá cho plugin.
   *
   * @param ShippingOrderInterface $order
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
      "status" => $this->fieldValue($order, "field_so_status"),
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
      "addons" => VnpostCatalog::decode(
        $this->fieldValue($order, "field_so_addons"),
        (float) $this->fieldValue($order, "field_so_cod"),
        (float) $this->fieldValue($order, "field_so_insurance"),
      ),
      "is_new_address" => (bool) $this->fieldValue($order, "field_so_is_new_address"),
      "sender" => $this->buildParty($order, "sender"),
      "receiver" => $this->buildParty($order, "receiver"),
    ];
  }

  /**
   * Đọc thông tin một bên gửi hoặc nhận của đơn hàng.
   *
   * @param ShippingOrderInterface $order
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
   * @param ShippingOrderInterface $order
   *   Đơn hàng cần cập nhật.
   * @param array $record
   *   Bản ghi hãng trả về.
   * @param array $config
   *   Cấu hình kết nối, dùng để tra danh mục địa chỉ đúng hãng.
   * @param bool $with_parties
   *   TRUE thì ghi cả thông tin người gửi và người nhận, dùng khi kéo đơn về
   *   từ hệ thống hãng thay vì khi vừa đẩy đơn do mình dựng lên.
   */
  private function applyRecord(ShippingOrderInterface $order, array $record, array $config, bool $with_parties = FALSE): void {
    // Đơn kéo về hoặc đơn còn thiếu địa chỉ được phép đổi bộ hai/ba cấp theo
    // dữ liệu hãng; đơn do mình khai đủ thì chỉ cập nhật trong đúng bộ đang
    // dùng, vì /CreateOrder trả mã bộ ba cấp cũ kể cả khi đơn khai hai cấp.
    $this->applyAddresses(
      $order,
      $record,
      (string) ($config["shipping_type_id"] ?? ""),
      $with_parties || !$this->hasAddresses($order)
    );

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

    $this->applyContacts($order, $record);

    // Đơn kéo về lấy ngày tạo trên hệ thống hãng thay cho lúc tạo bản ghi,
    // nếu không mọi đơn kéo chung một lượt sẽ cùng một ngày tạo.
    $created = $this->carrierTime((string) ($record["createdDate"] ?? ""));

    if ($created !== NULL) {
      $order->set("created", $created);
    }

    $this->setValue($order, "field_so_weight", $record["weight"] ?? NULL);
    $this->setValue($order, "field_so_content", $record["contentNote"] ?? "");
    $this->setValue($order, "field_so_cod", $record["codAmount"] ?? NULL);

    $addons = VnpostCatalog::fromRecord($record);

    if ($addons !== []) {
      $this->setValue($order, "field_so_addons", json_encode($addons, JSON_UNESCAPED_UNICODE));
      $this->setValue($order, "field_so_insurance", $addons[VnpostCatalog::ADDON_INSURANCE][VnpostCatalog::PROP_INSURANCE_VALUE] ?? NULL);
    }

    $this->setValue($order, "field_so_vehicle", $record["vehicle"] ?? "");
    $this->setValue($order, "field_so_send_type", $record["sendType"] ?? "");
    $this->setValue($order, "field_so_delivery_time", $record["deliveryTime"] ?? "");
    $this->setValue($order, "field_so_delivery_require", $record["deliveryRequire"] ?? "");
    $this->setValue($order, "field_so_delivery_note", $record["deliveryInstruction"] ?? "");
    $this->setValue($order, "field_so_org_collect", $record["orgCodeCollect"] ?? "");
    $this->setValue($order, "field_so_org_accept", $record["orgCodeAccept"] ?? "");
  }

  /**
   * Ghi tên, SĐT, email, địa chỉ người gửi và người nhận theo bản ghi hãng.
   *
   * Lấy đúng như hãng trả, kể cả bản đã bị che bằng dấu "+" vì hãng không trả
   * bản đầy đủ. Hàm updateOrder() của hãng chặn hiệu chỉnh khi SĐT/địa chỉ
   * còn bị che nên chuỗi này không bị gửi ngược lên hãng.
   *
   * @param ShippingOrderInterface $order
   *   Đơn hàng cần cập nhật.
   * @param array $record
   *   Bản ghi hãng trả về.
   */
  private function applyContacts(ShippingOrderInterface $order, array $record): void {
    foreach (["sender", "receiver"] as $party) {
      foreach (["name" => "Name", "phone" => "Phone", "email" => "Email", "address" => "Address"] as $field => $key) {
        $this->setValue($order, "field_so_{$party}_{$field}", (string) ($record[$party . $key] ?? ""));
      }
    }
  }

  /**
   * Đổi mã tỉnh, quận, xã trong bản ghi của hãng thành địa chỉ trên entity.
   *
   * Gói tin không cho biết đơn khai theo bộ hai cấp hay ba cấp. Các khoá chính
   * có thể mang mã bộ ba cấp cũ (như /CreateOrder) còn mã hai cấp nằm ở các
   * khoá hậu tố "New", nên bộ hai cấp ưu tiên đọc khoá "New". Mã phường/xã lại
   * có thể trùng giữa hai bộ, nên bộ ba cấp chỉ nhận khi tra được trọn chuỗi
   * tỉnh → quận → xã.
   *
   * Chỉ ghi khi tra được đủ cả người gửi lẫn người nhận trong cùng một bộ: ghi
   * lẻ một bên hoặc mỗi bên một bộ sẽ làm cờ hai cấp lệch với địa chỉ đang lưu,
   * form mở lại không còn tìm thấy lựa chọn và xoá trắng địa chỉ khi lưu.
   * Không tra đủ thì giữ nguyên toàn bộ giá trị đang có.
   *
   * @param ShippingOrderInterface $order
   *   Đơn hàng cần cập nhật.
   * @param array $record
   *   Bản ghi hãng trả về.
   * @param string $type_id
   *   ID của entity shipping_type sở hữu danh mục địa chỉ.
   * @param bool $allow_switch
   *   TRUE cho phép chuyển đơn sang bộ còn lại khi bộ đang dùng không tra được.
   */
  private function applyAddresses(ShippingOrderInterface $order, array $record, string $type_id, bool $allow_switch): void {
    if ($type_id === "" || !$order->hasField("field_so_sender_province")) {
      return;
    }

    $current = $order->hasField("field_so_is_new_address") && !$order->get("field_so_is_new_address")->isEmpty()
      ? (bool) $order->get("field_so_is_new_address")->value
      : TRUE;

    foreach ($allow_switch ? [$current, !$current] : [$current] as $two_level) {
      $resolved = [];

      foreach (["sender", "receiver"] as $party) {
        $ids = $this->resolveAddress($this->addressCodes($record, $party, $two_level), $two_level, $type_id);

        if ($ids === NULL) {
          continue 2;
        }

        $resolved[$party] = $ids;
      }

      $order->skip_order_log = TRUE;

      if ($order->hasField("field_so_is_new_address")) {
        $order->set("field_so_is_new_address", $two_level);
      }

      foreach ($resolved as $party => $ids) {
        foreach ($ids as $level => $id) {
          if ($order->hasField("field_so_{$party}_{$level}")) {
            $order->set("field_so_{$party}_{$level}", $id);
          }
        }
      }

      return;
    }
  }

  /**
   * Đọc mã tỉnh, quận, xã của một bên trong bản ghi hãng.
   *
   * @param array $record
   *   Bản ghi hãng trả về.
   * @param string $party
   *   "sender" hoặc "receiver".
   * @param bool $two_level
   *   TRUE đọc mã bộ hai cấp, ưu tiên các khoá hậu tố "New".
   *
   * @return array
   *   Mã province, district, commune.
   */
  private function addressCodes(array $record, string $party, bool $two_level): array {
    // Bên nhận dùng khoá rút gọn "Comm" ở /CreateOrder, /getOrder và
    // /GetListOrder nên đọc cả hai kiểu tên.
    $read = static function (array $keys) use ($record, $party): string {
      foreach ($keys as $key) {
        $value = (string) ($record[$party . $key] ?? "");

        if ($value !== "") {
          return $value;
        }
      }

      return "";
    };

    $province = ["ProvinceCode"];
    $commune = ["CommuneCode", "CommCode"];

    if ($two_level) {
      $province = ["ProvinceCodeNew", ...$province];
      $commune = ["CommuneCodeNew", "CommCodeNew", ...$commune];
    }

    return [
      "province" => $read($province),
      "district" => $read(["DistrictCode"]),
      "commune" => $read($commune),
    ];
  }

  /**
   * Đổi mốc thời gian dạng "d/m/Y H:i:s" giờ Việt Nam của hãng sang timestamp.
   *
   * @param string $value
   *   Chuỗi thời gian hãng trả về.
   *
   * @return int|null
   *   Timestamp, hoặc NULL khi không đọc được.
   */
  private function carrierTime(string $value): ?int {
    $date = \DateTimeImmutable::createFromFormat(
      "!d/m/Y H:i:s",
      trim($value),
      new \DateTimeZone(self::CARRIER_TIMEZONE)
    );

    return $date === FALSE ? NULL : $date->getTimestamp();
  }

  /**
   * Kiểm tra đơn đã có đủ tỉnh và xã cho cả hai bên hay chưa.
   *
   * @param ShippingOrderInterface $order
   *   Đơn hàng cần kiểm tra.
   *
   * @return bool
   *   TRUE khi cả người gửi và người nhận đều đã có tỉnh và xã.
   */
  private function hasAddresses(ShippingOrderInterface $order): bool {
    foreach (["sender", "receiver"] as $party) {
      foreach (["province", "commune"] as $level) {
        $field = "field_so_{$party}_{$level}";

        if (!$order->hasField($field) || $order->get($field)->isEmpty()) {
          return FALSE;
        }
      }
    }

    return TRUE;
  }

  /**
   * Tra id địa chỉ của một bên theo bộ hai cấp hoặc ba cấp.
   *
   * @param array $codes
   *   Mã province, district, commune của hãng.
   * @param bool $two_level
   *   TRUE tra theo bộ hai cấp.
   * @param string $type_id
   *   ID của entity shipping_type.
   *
   * @return array|null
   *   Id theo từng cấp (district là NULL với bộ hai cấp), hoặc NULL khi không
   *   tra được trọn chuỗi.
   */
  private function resolveAddress(array $codes, bool $two_level, string $type_id): ?array {
    if ($codes["province"] === "" || $codes["commune"] === "") {
      return NULL;
    }

    $province = $this->findAddress("province", $codes["province"], $two_level, $type_id);

    if ($province === NULL) {
      return NULL;
    }

    if ($two_level) {
      $commune = $this->findAddress("commune", $codes["commune"], TRUE, $type_id, ["field_province" => $province]);

      return $commune === NULL ? NULL : [
        "province" => $province,
        "district" => NULL,
        "commune" => $commune,
      ];
    }

    if ($codes["district"] === "") {
      return NULL;
    }

    $district = $this->findAddress("district", $codes["district"], FALSE, $type_id, ["field_province" => $province]);
    $commune = $district === NULL
      ? NULL
      : $this->findAddress("commune", $codes["commune"], FALSE, $type_id, ["field_district" => $district]);

    return $commune === NULL ? NULL : [
      "province" => $province,
      "district" => $district,
      "commune" => $commune,
    ];
  }

  /**
   * Tìm id một địa chỉ trong danh mục theo mã của hãng.
   *
   * @param string $bundle
   *   Cấp địa chỉ: province, district hoặc commune.
   * @param string $code
   *   Mã của hãng.
   * @param bool $two_level
   *   TRUE tìm trong bộ hai cấp.
   * @param string $type_id
   *   ID của entity shipping_type.
   * @param array $parents
   *   Điều kiện cấp cha, khoá là tên field.
   *
   * @return string|null
   *   Id địa chỉ, hoặc NULL khi không có.
   */
  private function findAddress(string $bundle, string $code, bool $two_level, string $type_id, array $parents = []): ?string {
    $key = implode(":", [$bundle, $code, (int) $two_level, $type_id, ...array_values($parents)]);

    if (!array_key_exists($key, $this->addressCache)) {
      $query = $this->entityTypeManager->getStorage("shipping_address")->getQuery()
        ->accessCheck(FALSE)
        ->condition("bundle", $bundle)
        ->condition("field_code", $code)
        ->condition("field_type", $type_id)
        ->condition("field_is_new_address", (int) $two_level)
        ->range(0, 1);

      foreach ($parents as $field => $value) {
        $query->condition($field, $value);
      }

      $ids = $query->execute();
      $this->addressCache[$key] = $ids ? (string) reset($ids) : NULL;
    }

    return $this->addressCache[$key];
  }

  /**
   * Ghi kết quả hiệu chỉnh hoặc hủy vào entity.
   *
   * @param ShippingOrderInterface $order
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
   * 
   * prepareDirectory() nhận tham số theo tham chiếu nên phải truyền biến thật,
   * không truyền thẳng biểu thức gán.
   */
  private function saveLabel(string $item_code, string|false $binary): ?object {
    if (empty($binary)) {
      return NULL;
    }

    $directory = static::LABEL_DIRECTORY;

    if (!$this->fileSystem->prepareDirectory(
      $directory,
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
   * 
   * Quy ước: mỗi hãng một bundle trùng tên plugin. Hãng nào chưa có bundle
   * riêng thì rơi về bundle đầu tiên để đơn vẫn được lưu lại.
   */
  private function bundleFor(array $config): string {
    $provider_id = (string) ($config["shipping_provider"] ?? "");
    $bundles = $this->entityTypeManager
      ->getStorage("shipping_order_type")
      ->getQuery()
      ->accessCheck(FALSE)
      ->execute();

    return isset($bundles[$provider_id]) ? $provider_id : (string) reset($bundles);
  }

  /**
   * Giá trị tra cứu đơn hàng ưu tiên số hiệu bưu gửi.
   *
   * @param ShippingOrderInterface $order
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
   * @param ShippingOrderInterface $order
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
   * @param ShippingOrderInterface $order
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
   * @param ShippingOrderInterface $order
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
   * @param ShippingOrderInterface $order
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
   * @param ShippingOrderInterface $order
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

    // Mọi thay đổi do gọi hãng đều đi qua đây và đã được ghi nhật ký với nguồn
    // cụ thể, nên đánh dấu để hook presave không ghi thêm một dòng "sửa tay"
    // trùng lặp cho cùng một lần lưu.
    $order->skip_order_log = TRUE;
    $order->set($field, $value);
  }

}
