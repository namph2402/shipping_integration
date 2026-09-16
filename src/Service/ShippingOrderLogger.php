<?php

declare(strict_types=1);

namespace Drupal\shipping_integration\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\shipping_integration\ShippingOrderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Ghi nhật ký thay đổi của đơn vận chuyển.
 *
 * Gom một chỗ để mọi nơi ghi nhật ký cùng một dạng, và để việc ghi nhật ký
 * không bao giờ làm đổ thao tác chính: lỗi khi ghi chỉ báo vào log hệ thống
 * chứ không ném ra ngoài, vì mất một dòng nhật ký nhẹ hơn mất cả lệnh tạo đơn.
 */
final class ShippingOrderLogger {

  /**
   * Khởi tạo service.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Trình quản lý entity.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   Người dùng hiện tại.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   Ngăn xếp request, dùng để lấy IP người gọi.
   * @param \Psr\Log\LoggerInterface $logger
   *   Kênh ghi log của module.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly RequestStack $requestStack,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Ghi một dòng nhật ký cho một đơn hàng.
   *
   * @param \Drupal\shipping_integration\ShippingOrderInterface|null $order
   *   Đơn hàng liên quan, NULL khi gói tin nói về bưu gửi chưa có trên hệ thống.
   * @param string $source
   *   Mã nguồn gây ra thay đổi, xem ShippingOrderLog::SOURCES.
   * @param array $extra
   *   Các giá trị ghi thêm: message, status_from, status_to, succeeded,
   *   payload, item_code, verified.
   */
  public function log(?ShippingOrderInterface $order, string $source, array $extra = []): void {
    try {
      $payload = $extra['payload'] ?? NULL;

      $this->entityTypeManager->getStorage('shipping_order_log')->create([
        'order_id' => $order?->id(),
        'item_code' => $extra['item_code']
          ?? ($order && $order->hasField('field_so_item_code') ? (string) $order->get('field_so_item_code')->value : ''),
        'source' => $source,
        'status_from' => $this->text($extra['status_from'] ?? NULL, 16),
        'status_to' => $this->text($extra['status_to'] ?? NULL, 16),
        'succeeded' => (bool) ($extra['succeeded'] ?? TRUE),
        'message' => $this->text($extra['message'] ?? '', 255),
        'payload' => is_string($payload) ? $payload : ($payload === NULL ? NULL : json_encode($payload, JSON_UNESCAPED_UNICODE)),
        // Người dùng vô danh (webhook, cron) lưu NULL chứ không lưu uid 0, để
        // phân biệt "không có ai" với "khách chưa đăng nhập".
        'uid' => $this->currentUser->isAuthenticated() ? $this->currentUser->id() : NULL,
        'ip' => $this->requestStack->getCurrentRequest()?->getClientIp(),
        'verified' => (bool) ($extra['verified'] ?? FALSE),
      ])->save();
    }
    catch (\Throwable $e) {
      $this->logger->error('Không ghi được nhật ký đơn hàng: @message', [
        '@message' => $e->getMessage(),
        'exception' => $e,
      ]);
    }
  }

  /**
   * Đọc trạng thái hiện tại của đơn, dùng làm status_from trước khi ghi đè.
   *
   * @param \Drupal\shipping_integration\ShippingOrderInterface|null $order
   *   Đơn hàng cần đọc.
   *
   * @return string|null
   *   Trạng thái hiện tại, NULL khi không có.
   */
  public function currentStatus(?ShippingOrderInterface $order): ?string {
    if ($order === NULL || !$order->hasField('field_so_status') || $order->get('field_so_status')->isEmpty()) {
      return NULL;
    }

    return (string) $order->get('field_so_status')->value;
  }

  /**
   * Cắt giá trị cho vừa độ dài field, tránh lỗi khi hãng trả về chuỗi dài.
   *
   * @param mixed $value
   *   Giá trị gốc.
   * @param int $length
   *   Độ dài tối đa.
   *
   * @return string|null
   *   Chuỗi đã cắt, NULL khi rỗng.
   */
  private function text(mixed $value, int $length): ?string {
    if ($value === NULL || $value === '') {
      return NULL;
    }

    return mb_substr((string) $value, 0, $length);
  }

}
