<?php

declare(strict_types=1);

namespace Drupal\shipping_integration\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\shipping_integration\Service\WebhookReceiver;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Điểm nhận webhook của hãng vận chuyển.
 *
 * Đường dẫn mở cho mọi lời gọi vì hãng không gửi kèm tài khoản hay khoá API;
 * chữ ký RSA trong gói tin là thứ duy nhất chứng minh người gọi là hãng, nên
 * mọi việc kiểm tra dồn vào WebhookReceiver và gói nào ký sai đều bị từ chối
 * trước khi chạm tới dữ liệu.
 *
 * @see https://my-uat.vnpost.vn/static/api/webhook/send-webhook
 */
final class WebhookController extends ControllerBase {

  /**
   * Khởi tạo controller.
   *
   * @param \Drupal\shipping_integration\Service\WebhookReceiver $receiver
   *   Nghiệp vụ kiểm tra và ghi dữ liệu webhook.
   */
  public function __construct(
    protected WebhookReceiver $receiver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get("shipping_integration.webhook_receiver"));
  }

  /**
   * Tiếp nhận một gói tin webhook.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   Request hiện tại.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   Phản hồi cho hãng, luôn ở dạng JSON.
   */
  public function receive(Request $request): JsonResponse {
    $payload = json_decode((string) $request->getContent(), TRUE);

    if (!is_array($payload)) {
      $this->getLogger("shipping_integration")->warning("Webhook: nội dung gửi lên không phải JSON hợp lệ.");

      return new JsonResponse([
        "success" => FALSE,
        "message" => "Nội dung gửi lên không phải JSON hợp lệ",
      ], 400);
    }

    $result = $this->receiver->process($payload);

    return new JsonResponse([
      "success" => $result["success"],
      "message" => $result["message"],
      "updated" => $result["updated"],
    ], $result["status"]);
  }

}
