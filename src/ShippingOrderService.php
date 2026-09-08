<?php

namespace Drupal\shipping_integration;

use Drupal\shipping_integration\Service\GetConfigShipping;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\Request;

/**
 * Hỗ trợ dựng danh sách đơn vận chuyển cho các trang quản lý.
 *
 * Toàn bộ logic đọc bộ lọc từ querystring, truy vấn entity và cộng tổng nằm ở
 * đây để controller chỉ còn việc đổ dữ liệu vào template.
 */
class ShippingOrderService {

  use StringTranslationTrait;

  /**
   * Số dòng mỗi trang cho bảng danh sách đơn hàng.
   *
   * Truy vấn lấy toàn bộ đơn khớp bộ lọc, việc chia trang do trình duyệt xử
   * lý nên đây chỉ là danh sách gợi ý cho ô chọn số dòng mỗi trang.
   */
  public const PAGE_SIZES = [20, 50, 100, 200, 500];

  /**
   * Số dòng mỗi trang mặc định khi người dùng chưa chọn.
   */
  public const DEFAULT_PAGE_SIZE = 50;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
  ) {}

  /**
   * Đọc bộ lọc từ request và lấy danh sách đơn hàng tương ứng.
   *
   * @param Request $request
   *   Request hiện tại.
   * @param string $bundle
   *   Bundle đơn hàng cần lấy.
   *
   * @return array
   *   Mảng gồm orders, date, status, config_id, page_size.
   */
  public function getOrders(Request $request, string $bundle): array {
    $storage = $this->entityTypeManager->getStorage("shipping_order");

    [$start_date, $end_date] = $this->dateRange($request);

    $status = (string) ($request->query->get("status") ?? "");
    $config_id = (string) ($request->query->get("config_id") ?? "");
    $keyword = trim((string) ($request->query->get("keyword") ?? ""));

    $page_size = (int) ($request->query->get("page_size") ?? static::DEFAULT_PAGE_SIZE);

    if (!in_array($page_size, static::PAGE_SIZES, TRUE) && $page_size !== 0) {
      $page_size = static::DEFAULT_PAGE_SIZE;
    }

    $query = $storage->getQuery()
      ->condition("bundle", $bundle)
      ->sort("created", "DESC")
      ->accessCheck(TRUE);

    if ($start_date !== NULL) {
      $query->condition("created", strtotime($start_date . " 00:00:00"), ">=");
    }

    if ($end_date !== NULL) {
      $query->condition("created", strtotime($end_date . " 23:59:59"), "<=");
    }

    if ($status !== "") {
      $query->condition("field_so_status", $status);
    }

    if ($config_id !== "") {
      $query->condition("field_so_config", $config_id);
    }

    if ($keyword !== "") {
      // Người dùng dán số hiệu bưu gửi, mã đơn hoặc số điện thoại người nhận
      // vào cùng một ô tìm kiếm nên phải dò cả ba trường.
      $group = $query->orConditionGroup()
        ->condition("field_so_item_code", $keyword, "CONTAINS")
        ->condition("field_so_sale_code", $keyword, "CONTAINS")
        ->condition("field_so_receiver_phone", $keyword, "CONTAINS")
        ->condition("field_so_receiver_name", $keyword, "CONTAINS");

      $query->condition($group);
    }

    $orders = $storage->loadMultiple($query->execute());

    return [
      "orders" => $orders,
      "date" => ["start" => $start_date, "end" => $end_date],
      "status" => $status,
      "config_id" => $config_id,
      "keyword" => $keyword,
      "page_size" => $page_size,
      "option_page_size" => static::PAGE_SIZES,
      "option_status" => $this->allowedValues($bundle, "field_so_status"),
      "option_config" => $this->configOptions(),
    ];
  }

  /**
   * Danh sách giá trị cho phép của một field kiểu list.
   *
   * @param string $bundle
   *   Bundle đơn hàng.
   * @param string $field
   *   Tên field.
   *
   * @return array
   *   Mảng giá trị ánh xạ sang nhãn.
   */
  public function allowedValues(string $bundle, string $field): array {
    $definitions = $this->entityFieldManager->getFieldDefinitions("shipping_order", $bundle);

    if (!isset($definitions[$field])) {
      return [];
    }

    return $definitions[$field]->getSetting("allowed_values") ?? [];
  }

  /**
   * Danh sách term cấu hình kết nối để lọc theo tài khoản.
   *
   * @return array
   *   Mảng id term ánh xạ sang tên term.
   */
  public function configOptions(): array {
    $terms = $this->entityTypeManager
      ->getStorage("taxonomy_term")
      ->loadByProperties(["vid" => GetConfigShipping::VOCABULARY]);

    $options = [];

    foreach ($terms as $term) {
      $options[$term->id()] = $term->label();
    }

    return $options;
  }

  /**
   * Đọc khoảng ngày lọc, hỗ trợ cả nút chọn nhanh.
   *
   * @param Request $request
   *   Request hiện tại.
   *
   * @return array
   *   Cặp ngày bắt đầu và kết thúc, NULL nghĩa là không giới hạn.
   */
  private function dateRange(Request $request): array {
    $start_date = $request->query->get("start_date") ?: date("Y-m-01");
    $end_date = $request->query->get("end_date") ?: date("Y-m-d");

    switch ($request->query->get("search_date")) {
      case "last_month":
        $start_date = date("Y-m-01", strtotime("first day of last month"));
        $end_date = date("Y-m-t", strtotime("last month"));
        break;

      case "last_week":
        $start_date = date("Y-m-d", strtotime("monday last week"));
        $end_date = date("Y-m-d", strtotime("sunday last week"));
        break;

      case "full_date":
        $start_date = $end_date = NULL;
        break;
    }

    return [$start_date, $end_date];
  }

  /**
   * Chuẩn hoá đường dẫn quay lại do người dùng gửi lên.
   *
   * $destination đến từ input nên không được dùng thẳng cho RedirectResponse:
   * URL tuyệt đối hoặc protocol-relative sẽ biến thành lỗ hổng open redirect.
   * Chỉ chấp nhận đường dẫn nội bộ bắt đầu bằng đúng một dấu "/".
   *
   * @param string|null $destination
   *   Giá trị destination lấy từ request.
   * @param string $route
   *   Route dùng làm mặc định khi destination không hợp lệ.
   *
   * @return string
   *   Đường dẫn nội bộ an toàn để redirect.
   */
  public function safeRedirect(?string $destination, string $route): string {
    $destination = trim((string) $destination);

    if ($destination !== ""
      && str_starts_with($destination, "/")
      && !str_starts_with($destination, "//")
      && !UrlHelper::isExternal($destination)
    ) {
      return rtrim($destination, "/") ?: "/";
    }

    return Url::fromRoute($route)->toString();
  }

  /**
   * Đọc một đơn hàng ra mảng phẳng để đổ vào template.
   *
   * @param \Drupal\shipping_integration\ShippingOrderInterface $order
   *   Đơn hàng cần đọc.
   * @param array $status_options
   *   Danh sách nhãn trạng thái đã nạp sẵn.
   *
   * @return array
   *   Mảng dữ liệu cho template.
   */
  public function buildRow(ShippingOrderInterface $order, array $status_options = []): array {
    $status = $order->hasField("field_so_status") && !$order->get("field_so_status")->isEmpty()
      ? (string) $order->get("field_so_status")->value
      : "";

    return [
      "id" => $order->id(),
      "uuid" => $order->uuid(),
      "label" => $order->label(),
      "created" => $order->get("created")->value,
      "item_code" => $this->value($order, "field_so_item_code"),
      "sale_code" => $this->value($order, "field_so_sale_code"),
      "original_id" => $this->value($order, "field_so_original_id"),
      "status" => $status,
      "status_label" => $status_options[$status] ?? "",
      "case_id" => $this->value($order, "field_so_case_id"),
      "case_type" => $this->value($order, "field_so_case_type"),
      "config" => $order->hasField("field_so_config") ? $order->get("field_so_config")->entity?->label() : NULL,
      "carrier" => $order->hasField("field_so_carrier") ? $order->get("field_so_carrier")->entity?->label() : NULL,
      "sender_name" => $this->value($order, "field_so_sender_name"),
      "sender_phone" => $this->value($order, "field_so_sender_phone"),
      "sender_address" => $this->fullAddress($order, "sender"),
      "receiver_name" => $this->value($order, "field_so_receiver_name"),
      "receiver_phone" => $this->value($order, "field_so_receiver_phone"),
      "receiver_address" => $this->fullAddress($order, "receiver"),
      "service" => $this->value($order, "field_so_service"),
      "weight" => $this->value($order, "field_so_weight"),
      "price_weight" => $this->value($order, "field_so_price_weight"),
      "content" => $this->value($order, "field_so_content"),
      "cod" => (float) $this->value($order, "field_so_cod"),
      "insurance" => (float) $this->value($order, "field_so_insurance"),
      "main_fee" => (float) $this->value($order, "field_so_main_fee"),
      "vas_fee" => (float) $this->value($order, "field_so_vas_fee"),
      "total_fee" => (float) $this->value($order, "field_so_total_fee"),
      "bcp_name" => $this->value($order, "field_so_bcp_name"),
      "synced" => $this->value($order, "field_so_synced"),
      "history" => $this->history($order),
      "label_file" => $this->labelUrl($order),
    ];
  }

  /**
   * Ghép địa chỉ chi tiết với phường/xã, quận/huyện và tỉnh/TP.
   *
   * @param \Drupal\shipping_integration\ShippingOrderInterface $order
   *   Đơn hàng cần đọc.
   * @param string $party
   *   "sender" hoặc "receiver".
   *
   * @return string
   *   Địa chỉ đầy đủ.
   */
  private function fullAddress(ShippingOrderInterface $order, string $party): string {
    $parts = [$this->value($order, "field_so_{$party}_address")];

    foreach (["commune", "district", "province"] as $level) {
      $field = "field_so_{$party}_{$level}";

      if ($order->hasField($field) && ($address = $order->get($field)->entity)) {
        $parts[] = $address->label();
      }
    }

    return implode(", ", array_filter($parts));
  }

  /**
   * Đọc hành trình đã lưu của đơn hàng.
   *
   * @param \Drupal\shipping_integration\ShippingOrderInterface $order
   *   Đơn hàng cần đọc.
   *
   * @return array
   *   Danh sách mốc hành trình, rỗng khi chưa tra hành trình lần nào.
   */
  private function history(ShippingOrderInterface $order): array {
    $raw = $this->value($order, "field_so_history");

    if ($raw === "") {
      return [];
    }

    $decoded = json_decode($raw, TRUE);

    return is_array($decoded) ? $decoded : [];
  }

  /**
   * Đường dẫn file vận đơn đã tải về.
   *
   * @param \Drupal\shipping_integration\ShippingOrderInterface $order
   *   Đơn hàng cần đọc.
   *
   * @return string|null
   *   URL của file, hoặc NULL khi chưa có.
   */
  private function labelUrl(ShippingOrderInterface $order): ?string {
    if (!$order->hasField("field_so_label") || $order->get("field_so_label")->isEmpty()) {
      return NULL;
    }

    $file = $order->get("field_so_label")->entity;

    return $file === NULL ? NULL : $file->createFileUrl(FALSE);
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
  private function value(ShippingOrderInterface $order, string $field): string {
    if (!$order->hasField($field) || $order->get($field)->isEmpty()) {
      return "";
    }

    return (string) $order->get($field)->value;
  }

}
