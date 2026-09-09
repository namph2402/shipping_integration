<?php

declare(strict_types=1);

namespace Drupal\shipping_integration\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\shipping_integration\Service\HandleShipping;
use Drupal\shipping_integration\ShippingOrderInterface;
use Drupal\shipping_integration\ShippingOrderService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Các trang quản lý đơn hàng trong nước.
 *
 * Trang danh sách và trang chi tiết dùng template riêng của module, còn các
 * lệnh tác động lên đơn (tạo, hủy, tính cước, đồng bộ) nhận POST rồi quay lại
 * đúng trang người dùng vừa đứng, trừ hai lệnh chỉ đọc là hành trình và tính
 * thử cước thì trả JSON cho hộp thoại.
 */
final class ShippingOrderController extends ControllerBase {

  /**
   * Bundle đơn hàng trong nước của VN-Post.
   */
  private const DOMESTIC_BUNDLE = "vnpost";

  /**
   * Route mặc định khi destination gửi lên không hợp lệ.
   */
  private const LIST_ROUTE = "shipping_integration.order_list";

  /**
   * The controller constructor.
   */
  public function __construct(
    protected HandleShipping $handleShipping,
    protected ShippingOrderService $orderService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get("shipping_integration.handle_shipping"),
      $container->get("shipping_integration.order_service"),
    );
  }

  /**
   * Danh sách đơn hàng trong nước.
   *
   * @param Request $request
   *   Request hiện tại.
   *
   * @return array
   *   Render array của trang danh sách.
   */
  public function listOrders(Request $request): array {
    $result = $this->orderService->getOrders($request, self::DOMESTIC_BUNDLE);

    $data = [];

    foreach ($result["orders"] as $order) {
      $data[] = $this->orderService->buildRow($order, $result["option_status"]);
    }

    return [
      "#theme" => "list_shipping_order",
      "#data" => $data,
      "#filter" => [
        "date" => $result["date"],
        "status" => $result["status"],
        "config_id" => $result["config_id"],
        "keyword" => $result["keyword"],
        "page_size" => $result["page_size"],
        "option_page_size" => $result["option_page_size"],
        "option_status" => $result["option_status"],
        "option_config" => $result["option_config"],
        "destination" => $request->getRequestUri(),
        "current_user" => $this->currentUser()->getAccountName(),
        "bundle" => self::DOMESTIC_BUNDLE,
      ],
      "#attached" => [
        "library" => ["shipping_integration/shipping_order"],
      ],
      "#cache" => ["max-age" => 0],
    ];
  }

  /**
   * Chi tiết một đơn hàng.
   *
   * @param ShippingOrderInterface $shipping_order
   *   Đơn hàng cần xem.
   * @param Request $request
   *   Request hiện tại.
   *
   * @return array
   *   Render array của trang chi tiết.
   */
  public function detail(ShippingOrderInterface $shipping_order, Request $request): array {
    $status_options = $this->orderService->allowedValues($shipping_order->bundle(), "field_so_status");

    return [
      "#theme" => "detail_shipping_order",
      "#data" => $this->orderService->buildRow($shipping_order, $status_options),
      "#filter" => [
        "destination" => $request->getRequestUri(),
      ],
      "#attached" => [
        "library" => ["shipping_integration/shipping_order"],
      ],
      "#cache" => ["max-age" => 0],
    ];
  }

  /**
   * Đẩy các đơn được chọn sang hãng vận chuyển.
   *
   * @param Request $request
   *   Request hiện tại.
   *
   * @return RedirectResponse
   *   Quay lại trang trước đó.
   */
  public function createOrders(Request $request): RedirectResponse {
    $draft = $request->request->get("draft") === "1";

    return $this->batch($request, function (ShippingOrderInterface $order) use ($draft): array {
      return $this->handleShipping->createOrder($order, $draft);
    });
  }

  /**
   * Phát hành các đơn nháp được chọn lên hệ thống hãng.
   *
   * @param Request $request
   *   Request hiện tại.
   *
   * @return RedirectResponse
   *   Quay lại trang trước đó.
   */
  public function confirmDrafts(Request $request): RedirectResponse {
    return $this->batch($request, fn (ShippingOrderInterface $order): array => $this->handleShipping->confirmDraft($order));
  }

  /**
   * Gửi yêu cầu hiệu chỉnh cho các đơn được chọn.
   *
   * @param Request $request
   *   Request hiện tại.
   *
   * @return RedirectResponse
   *   Quay lại trang trước đó.
   */
  public function updateOrders(Request $request): RedirectResponse {
    return $this->batch($request, fn (ShippingOrderInterface $order): array => $this->handleShipping->updateOrder($order));
  }

  /**
   * Gửi yêu cầu hủy cho các đơn được chọn.
   *
   * @param Request $request
   *   Request hiện tại.
   *
   * @return RedirectResponse
   *   Quay lại trang trước đó.
   */
  public function cancelOrders(Request $request): RedirectResponse {
    return $this->batch($request, fn (ShippingOrderInterface $order): array => $this->handleShipping->cancelOrder($order));
  }

  /**
   * Lấy kết quả phê duyệt hiệu chỉnh hoặc hủy của các đơn được chọn.
   *
   * @param Request $request
   *   Request hiện tại.
   *
   * @return RedirectResponse
   *   Quay lại trang trước đó.
   */
  public function approvalOrders(Request $request): RedirectResponse {
    return $this->batch($request, fn (ShippingOrderInterface $order): array => $this->handleShipping->approvalResult($order));
  }

  /**
   * Đồng bộ trạng thái các đơn được chọn từ hệ thống hãng.
   *
   * @param Request $request
   *   Request hiện tại.
   *
   * @return RedirectResponse
   *   Quay lại trang trước đó.
   */
  public function synchronizeOrders(Request $request): RedirectResponse {
    return $this->batch($request, fn (ShippingOrderInterface $order): array => $this->handleShipping->synchronizeOrder($order));
  }

  /**
   * Tính và lưu cước phí cho các đơn được chọn.
   *
   * @param Request $request
   *   Request hiện tại.
   *
   * @return RedirectResponse
   *   Quay lại trang trước đó.
   */
  public function calculateFees(Request $request): RedirectResponse {
    return $this->batch($request, fn (ShippingOrderInterface $order): array => $this->handleShipping->calculateFee($order));
  }

  /**
   * Tải vận đơn của các đơn được chọn.
   *
   * @param Request $request
   *   Request hiện tại.
   *
   * @return RedirectResponse
   *   Quay lại trang trước đó.
   */
  public function printLabels(Request $request): RedirectResponse {
    return $this->batch($request, fn (ShippingOrderInterface $order): array => $this->handleShipping->printLabel($order));
  }

  /**
   * Trả hành trình của một đơn hàng dưới dạng JSON cho hộp thoại.
   *
   * @param ShippingOrderInterface $shipping_order
   *   Đơn hàng cần tra hành trình.
   *
   * @return JsonResponse
   *   Kết quả tra hành trình.
   */
  public function history(ShippingOrderInterface $shipping_order): JsonResponse {
    return new JsonResponse($this->handleShipping->orderHistory($shipping_order));
  }

  /**
   * Tính thử cước phí của một đơn hàng mà không ghi vào entity.
   *
   * @param ShippingOrderInterface $shipping_order
   *   Đơn hàng cần tính cước.
   *
   * @return JsonResponse
   *   Bảng cước.
   */
  public function previewFee(ShippingOrderInterface $shipping_order): JsonResponse {
    return new JsonResponse($this->handleShipping->calculateFee($shipping_order, FALSE));
  }

  /**
   * Kéo đơn hàng từ hệ thống hãng về theo một cấu hình kết nối.
   *
   * @param Request $request
   *   Request hiện tại.
   *
   * @return RedirectResponse
   *   Quay lại trang trước đó.
   */
  public function pullOrders(Request $request): RedirectResponse {
    $redirect = $this->orderService->safeRedirect(
      $request->request->get("destination"),
      self::LIST_ROUTE
    );

    $config_id = (string) $request->request->get("config_id");
    $config_entity = $config_id === ""
      ? NULL
      : $this->entityTypeManager()->getStorage("taxonomy_term")->load($config_id);

    if ($config_entity === NULL) {
      $this->messenger()->addError($this->t("Please choose a shipping connection."));
      return new RedirectResponse($redirect);
    }

    $result = $this->handleShipping->pullOrders($config_entity, [
      "from" => (string) $request->request->get("start_date"),
      "to" => (string) $request->request->get("end_date"),
      "type" => (string) ($request->request->get("type") ?: "GUI"),
    ]);

    if (empty($result["success"])) {
      $this->messenger()->addError($result["message"] ?? $this->t("Pull orders failed"));
    }
    else {
      $this->messenger()->addStatus($result["message"]);
    }

    return new RedirectResponse($redirect);
  }

  /**
   * Chạy một lệnh trên tất cả đơn hàng được chọn rồi gom kết quả thành thông báo.
   *
   * Lệnh chạy trên từng đơn riêng biệt và có thể thành công một phần, nên
   * thành công đếm gộp còn thất bại báo rõ từng đơn để người dùng biết đơn nào
   * cần xử lý lại.
   *
   * @param Request $request
   *   Request hiện tại.
   * @param callable $operation
   *   Hàm nhận một đơn hàng và trả về mảng kết quả.
   *
   * @return RedirectResponse
   *   Quay lại trang trước đó.
   */
  private function batch(Request $request, callable $operation): RedirectResponse {
    $redirect = $this->orderService->safeRedirect(
      $request->request->get("destination"),
      self::LIST_ROUTE
    );

    $orders = $this->loadSelected($request);

    if (empty($orders)) {
      $this->messenger()->addError($this->t("Please choose at least one order."));
      return new RedirectResponse($redirect);
    }

    $succeeded = 0;

    foreach ($orders as $order) {
      $result = $operation($order);

      if (!empty($result["success"])) {
        $succeeded++;
        continue;
      }

      $this->messenger()->addError($this->t("@order: @message", [
        "@order" => $order->label(),
        "@message" => $result["message"] ?? $this->t("Unknown error"),
      ]));
    }

    if ($succeeded > 0) {
      $this->messenger()->addStatus($this->t("Processed @count order(s) successfully.", [
        "@count" => $succeeded,
      ]));
    }

    return new RedirectResponse($redirect);
  }

  /**
   * Nạp các đơn hàng người dùng đã tích chọn trên bảng.
   *
   * @param Request $request
   *   Request hiện tại.
   *
   * @return array
   *   Danh sách entity đơn hàng.
   */
  private function loadSelected(Request $request): array {
    $raw = (string) $request->request->get("order_ids");
    $ids = array_values(array_filter(array_map("trim", explode(",", $raw))));

    if (empty($ids)) {
      return [];
    }

    return $this->entityTypeManager()
      ->getStorage("shipping_order")
      ->loadMultiple($ids);
  }

  /**
   * Tải file vận đơn đã lưu của một đơn hàng.
   *
   * @param ShippingOrderInterface $shipping_order
   *   Đơn hàng cần lấy vận đơn.
   *
   * @return RedirectResponse
   *   Chuyển tới file vận đơn.
   */
  public function downloadLabel(ShippingOrderInterface $shipping_order): RedirectResponse {
    if ($shipping_order->get("field_so_label")->isEmpty()) {
      $result = $this->handleShipping->printLabel($shipping_order);

      if (empty($result["success"])) {
        throw new NotFoundHttpException($result["message"] ?? "");
      }
    }

    $file = $shipping_order->get("field_so_label")->entity;

    if ($file === NULL) {
      throw new NotFoundHttpException();
    }

    return new RedirectResponse($file->createFileUrl(FALSE));
  }

}
