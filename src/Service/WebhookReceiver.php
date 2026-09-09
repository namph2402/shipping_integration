<?php

declare(strict_types=1);

namespace Drupal\shipping_integration\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Nhận và xử lý dữ liệu webhook hãng vận chuyển đẩy về.
 *
 * MyVNPost gọi webhook mỗi khi đơn hàng đổi thông tin hoặc trạng thái, gói tin
 * có dạng {data: [đơn...], sendDate, signature}. Chữ ký là chỗ duy nhất chứng
 * minh gói tin đúng là của hãng nên gói nào ký sai đều bị bỏ, không ghi gì vào
 * cơ sở dữ liệu.
 *
 * Quy tắc ký của hãng: signature = RSASHA256("MYVNP" + sendDate + itemCode +
 * status), trong đó itemCode và status lấy của bưu gửi đầu tiên trong mảng
 * data, còn khoá công khai RSA 2048 lưu ở field_si_webhook_key của term kết
 * nối.
 *
 * @see https://my-uat.vnpost.vn/static/api/webhook/send-webhook
 */
final class WebhookReceiver {

  /**
   * Tiền tố cố định của chuỗi được hãng ký.
   */
  private const SIGNATURE_PREFIX = "MYVNP";

  /**
   * Khoá công khai RSA 2048 của MyVNPost theo tài liệu.
   *
   * Dùng làm giá trị mặc định cho kết nối mới; môi trường thật có thể dùng cặp
   * khoá khác nên field_si_webhook_key của từng kết nối vẫn là nguồn quyết
   * định.
   */
  public const DEFAULT_PUBLIC_KEY = "MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAuAYZvjAsrSnyhl8lQxZklGywLaMfE8sLBQLIo3Q8kyW9jrpCVttyUAAP+IdaEuLYx4T6hV7MgKqJlkQAyikY1bkos7Gj0KkTUKv0gf7KL8+v55nbObdBagjb+amX/wbuWXYhJ3m67PI63tHbm1GoeyUeVmZh+XOcXrFoCts+Z+S590w2cfGfo8h60sp4TOu+EiNZq/jvcWCSDA+xszSdbOagCY0MXBFCG7iQ3WQlS3A3VcFILOIBwT75j/CIYG+jbGrrvRhrU+eu7C4hboG9wNVrDtxIUYjxoFH8OpFgxaCoYGBCAhXjtWC+MpzcR44l1Kku1wlzQLzh6dXgI3fPOwIDAQAB";

  /**
   * Khởi tạo service.
   *
   * @param \Drupal\shipping_integration\Service\HandleShipping $handleShipping
   *   Nghiệp vụ ghi dữ liệu hãng trả về vào đơn hàng.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Trình quản lý entity.
   * @param \Psr\Log\LoggerInterface $logger
   *   Kênh ghi log của module.
   */
  public function __construct(
    private readonly HandleShipping $handleShipping,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Xử lý một gói tin webhook.
   *
   * @param array $payload
   *   Gói tin đã giải mã từ JSON.
   *
   * @return array
   *   Kết quả gồm success, message, status (mã HTTP nên trả về) và số đơn đã
   *   cập nhật.
   */
  public function process(array $payload): array {
    $records = array_values(array_filter($payload["data"] ?? [], "is_array"));

    if (!$records) {
      $this->logger->warning("Webhook: gói tin không có đơn hàng nào.");

      return $this->result(FALSE, "Gói tin không có dữ liệu đơn hàng", 400);
    }

    $send_date = (string) ($payload["sendDate"] ?? "");
    $signature = (string) ($payload["signature"] ?? "");

    if ($signature === "") {
      $this->logger->warning("Webhook: gói tin thiếu chữ ký.");

      return $this->result(FALSE, "Gói tin thiếu chữ ký", 401);
    }

    $first = $records[0];
    $data = self::SIGNATURE_PREFIX
      . $send_date
      . (string) ($first["itemCode"] ?? "")
      . (string) ($first["status"] ?? "");

    if (!$this->verify($data, $signature, $this->publicKeys($first))) {
      $this->logger->warning("Webhook: chữ ký không hợp lệ cho bưu gửi @code.", [
        "@code" => (string) ($first["itemCode"] ?? ""),
      ]);

      return $this->result(FALSE, "Chữ ký không hợp lệ", 401);
    }

    $applied = $this->handleShipping->applyWebhook($records);

    $this->logger->notice("Webhook: cập nhật @updated đơn, bỏ qua @missing bưu gửi chưa có trên hệ thống.", [
      "@updated" => $applied["updated"],
      "@missing" => count($applied["missing"]),
    ]);

    return [
      "success" => TRUE,
      "message" => "Đã tiếp nhận dữ liệu webhook",
      "status" => 200,
      "updated" => $applied["updated"],
      "missing" => $applied["missing"],
    ];
  }

  /**
   * Kiểm tra chữ ký với từng khoá công khai đang khai báo.
   *
   * @param string $data
   *   Chuỗi được hãng ký.
   * @param string $signature
   *   Chữ ký dạng base64.
   * @param array $keys
   *   Danh sách khoá công khai dạng base64 hoặc PEM.
   *
   * @return bool
   *   TRUE nếu có một khoá xác thực được chữ ký.
   */
  private function verify(string $data, string $signature, array $keys): bool {
    $binary = base64_decode($signature, TRUE);

    if ($binary === FALSE) {
      return FALSE;
    }

    foreach ($keys as $key) {
      $resource = openssl_pkey_get_public($this->pem($key));

      if ($resource === FALSE) {
        $this->logger->warning("Webhook: khoá công khai khai báo sai định dạng.");
        continue;
      }

      if (openssl_verify($data, $binary, $resource, OPENSSL_ALGO_SHA256) === 1) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Danh sách khoá công khai dùng để kiểm tra gói tin.
   *
   * Ưu tiên khoá của đúng kết nối có mã khách hàng trùng với người gửi trong
   * gói tin; không tra được thì thử mọi kết nối đang khai báo, vì một site có
   * thể nối tới nhiều tài khoản của cùng một hãng.
   *
   * @param array $record
   *   Bản ghi đơn hàng đầu tiên trong gói tin.
   *
   * @return array
   *   Danh sách khoá công khai, đã bỏ khoá rỗng.
   */
  private function publicKeys(array $record): array {
    $terms = $this->entityTypeManager
      ->getStorage("taxonomy_term")
      ->loadByProperties(["vid" => GetConfigShipping::VOCABULARY]);

    $sender_code = (string) ($record["senderCode"] ?? "");
    $matched = [];
    $others = [];

    foreach ($terms as $term) {
      if (!$term->hasField("field_si_webhook_key")) {
        continue;
      }

      $key = trim((string) $term->get("field_si_webhook_key")->value);

      if ($key === "") {
        continue;
      }

      $code = $term->hasField("field_si_code") ? (string) $term->get("field_si_code")->value : "";

      if ($sender_code !== "" && $code === $sender_code) {
        $matched[] = $key;
        continue;
      }

      $others[] = $key;
    }

    return array_values(array_unique([...$matched, ...$others]));
  }

  /**
   * Bọc khoá công khai dạng base64 thành PEM cho OpenSSL đọc được.
   *
   * @param string $key
   *   Khoá công khai, có thể đã ở dạng PEM sẵn.
   *
   * @return string
   *   Khoá dạng PEM.
   */
  private function pem(string $key): string {
    $key = trim($key);

    if (str_contains($key, "-----BEGIN")) {
      return $key;
    }

    return "-----BEGIN PUBLIC KEY-----\n"
      . chunk_split(preg_replace("/\s+/", "", $key), 64, "\n")
      . "-----END PUBLIC KEY-----\n";
  }

  /**
   * Dựng kết quả thất bại.
   *
   * @param bool $success
   *   Trạng thái xử lý.
   * @param string $message
   *   Nội dung thông báo.
   * @param int $status
   *   Mã HTTP nên trả về cho hãng.
   *
   * @return array
   *   Kết quả chuẩn hoá.
   */
  private function result(bool $success, string $message, int $status): array {
    return [
      "success" => $success,
      "message" => $message,
      "status" => $status,
      "updated" => 0,
      "missing" => [],
    ];
  }

}
