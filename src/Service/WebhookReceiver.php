<?php

declare(strict_types=1);

namespace Drupal\shipping_integration\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\taxonomy\TermInterface;
use Psr\Log\LoggerInterface;

/**
 * Nhận và xử lý dữ liệu webhook hãng vận chuyển đẩy về.
 *
 * MyVNPost gọi webhook mỗi khi đơn hàng đổi thông tin hoặc trạng thái, gói tin
 * có dạng {data: [đơn...], sendDate, signature}. Trường signature là chỗ duy
 * nhất chứng minh gói tin đúng là của hãng nên gói nào sai đều bị bỏ, không ghi
 * gì vào cơ sở dữ liệu.
 *
 * Chuỗi được xác thực là "MYVNP" + sendDate + itemCode + status, trong đó
 * itemCode và status lấy của bưu gửi đầu tiên trong mảng data.
 *
 * Tài liệu hãng gọi signature là RSASHA256 nhưng mã mẫu Java của chính họ lại
 * là Cipher.getInstance("RSA/ECB/PKCS1Padding") với Cipher.ENCRYPT_MODE và
 * khoá công khai: đó là mã hoá RSA chứ không phải ký số. Hệ quả là khoá công
 * khai không kiểm được gói tin, phải có khoá riêng của hãng để giải mã rồi so
 * chuỗi giải ra với chuỗi dựng lại tại chỗ. Khoá riêng lưu ở
 * field_si_webhook_privkey của term kết nối.
 *
 * Khoá riêng để trống thì quay về kiểm chữ ký số bằng khoá công khai ở
 * field_si_webhook_key, phòng khi hãng sửa lại cho đúng tài liệu.
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

    $connections = $this->connections($first);
    $skipped = $this->skipping($connections);
    $verified = FALSE;

    if ($skipped !== NULL) {
      $this->logger->warning(
        "Webhook: BỎ QUA kiểm chữ ký theo cấu hình của kết nối @name, gói tin được ghi nhận mà không xác thực.",
        ["@name" => $skipped]
      );
    }
    elseif (!$this->verify($data, $signature, $connections)) {
      $this->logger->warning("Webhook: chữ ký không hợp lệ cho bưu gửi @code.", [
        "@code" => (string) ($first["itemCode"] ?? ""),
      ]);

      return $this->result(FALSE, "Chữ ký không hợp lệ", 401);
    }
    else {
      $verified = TRUE;
    }

    $applied = $this->handleShipping->applyWebhook($records, $verified);

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
   * Xác thực gói tin với từng kết nối đang khai báo.
   *
   * Ưu tiên khoá riêng: hãng mã hoá chuỗi xác thực bằng khoá công khai nên chỉ
   * khoá riêng mới mở ra được, giải xong so nguyên văn với chuỗi dựng lại tại
   * chỗ. Kết nối nào không khai khoá riêng thì thử tiếp cách cũ là kiểm chữ ký
   * số bằng khoá công khai, để gói tin vẫn qua được nếu hãng sửa lại cho khớp
   * tài liệu.
   *
   * @param string $data
   *   Chuỗi xác thực dựng từ gói tin.
   * @param string $signature
   *   Trường signature dạng base64.
   * @param \Drupal\taxonomy\TermInterface[] $connections
   *   Các kết nối cần thử, kết nối khớp mã khách hàng đứng trước.
   *
   * @return bool
   *   TRUE nếu có một kết nối xác thực được gói tin.
   */
  private function verify(string $data, string $signature, array $connections): bool {
    $binary = base64_decode($signature, TRUE);

    if ($binary === FALSE || $binary === "") {
      $this->logger->warning("Webhook: trường signature không phải base64 hợp lệ.");

      return FALSE;
    }

    foreach ($connections as $term) {
      if ($this->decrypts($data, $binary, $this->keyValue($term, "field_si_webhook_privkey"))) {
        return TRUE;
      }

      if ($this->signed($data, $binary, $this->keyValue($term, "field_si_webhook_key"))) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Giải mã trường signature bằng khoá riêng rồi so với chuỗi mong đợi.
   *
   * @param string $data
   *   Chuỗi xác thực mong đợi.
   * @param string $binary
   *   Trường signature đã giải base64.
   * @param string $key
   *   Khoá riêng dạng base64 hoặc PEM, chuỗi rỗng nghĩa là chưa khai.
   *
   * @return bool
   *   TRUE nếu giải ra đúng chuỗi mong đợi.
   */
  private function decrypts(string $data, string $binary, string $key): bool {
    if ($key === "") {
      return FALSE;
    }

    $resource = openssl_pkey_get_private($this->pem($key, "PRIVATE KEY"));

    if ($resource === FALSE) {
      $this->logger->warning("Webhook: khoá bí mật khai báo sai định dạng.");

      return FALSE;
    }

    $plain = "";

    if (!openssl_private_decrypt($binary, $plain, $resource, OPENSSL_PKCS1_PADDING)) {
      return FALSE;
    }

    return hash_equals($data, $plain);
  }

  /**
   * Kiểm chữ ký số bằng khoá công khai.
   *
   * @param string $data
   *   Chuỗi được ký.
   * @param string $binary
   *   Chữ ký đã giải base64.
   * @param string $key
   *   Khoá công khai dạng base64 hoặc PEM, chuỗi rỗng nghĩa là chưa khai.
   *
   * @return bool
   *   TRUE nếu chữ ký hợp lệ.
   */
  private function signed(string $data, string $binary, string $key): bool {
    if ($key === "") {
      return FALSE;
    }

    $resource = openssl_pkey_get_public($this->pem($key, "PUBLIC KEY"));

    if ($resource === FALSE) {
      $this->logger->warning("Webhook: khoá công khai khai báo sai định dạng.");

      return FALSE;
    }

    return openssl_verify($data, $binary, $resource, OPENSSL_ALGO_SHA256) === 1;
  }

  /**
   * Đọc một field khoá của term, đã cắt khoảng trắng thừa.
   *
   * @param \Drupal\taxonomy\TermInterface $term
   *   Term kết nối.
   * @param string $field
   *   Tên field chứa khoá.
   *
   * @return string
   *   Nội dung khoá, chuỗi rỗng khi term không có field hoặc để trống.
   */
  private function keyValue(TermInterface $term, string $field): string {
    if (!$term->hasField($field)) {
      return "";
    }

    return trim((string) $term->get($field)->value);
  }

  /**
   * Tên kết nối đang bật cờ bỏ qua kiểm chữ ký, NULL nếu không có kết nối nào.
   *
   * @param \Drupal\taxonomy\TermInterface[] $connections
   *   Các kết nối cần xét.
   *
   * @return string|null
   *   Tên kết nối đầu tiên bật cờ, hoặc NULL.
   */
  private function skipping(array $connections): ?string {
    foreach ($connections as $term) {
      if ($term->hasField("field_si_webhook_skip") && !empty($term->get("field_si_webhook_skip")->value)) {
        return (string) $term->label();
      }
    }

    return NULL;
  }

  /**
   * Các kết nối có thể đã gửi gói tin này, sắp theo thứ tự ưu tiên.
   *
   * Ưu tiên kết nối có mã khách hàng trùng với người gửi trong gói tin; không
   * tra được thì thử mọi kết nối đang khai báo, vì một site có thể nối tới
   * nhiều tài khoản của cùng một hãng.
   *
   * @param array $record
   *   Bản ghi đơn hàng đầu tiên trong gói tin.
   *
   * @return \Drupal\taxonomy\TermInterface[]
   *   Danh sách term kết nối.
   */
  private function connections(array $record): array {
    $terms = $this->entityTypeManager
      ->getStorage("taxonomy_term")
      ->loadByProperties(["vid" => GetConfigShipping::VOCABULARY]);

    $sender_code = (string) ($record["senderCode"] ?? "");
    $matched = [];
    $others = [];

    foreach ($terms as $term) {
      $code = $term->hasField("field_si_code") ? (string) $term->get("field_si_code")->value : "";

      if ($sender_code !== "" && $code === $sender_code) {
        $matched[] = $term;
        continue;
      }

      $others[] = $term;
    }

    return [...$matched, ...$others];
  }

  /**
   * Bọc khoá dạng base64 thành PEM cho OpenSSL đọc được.
   *
   * @param string $key
   *   Khoá, có thể đã ở dạng PEM sẵn.
   * @param string $label
   *   Nhãn khối PEM, "PUBLIC KEY" hoặc "PRIVATE KEY".
   *
   * @return string
   *   Khoá dạng PEM.
   */
  private function pem(string $key, string $label): string {
    $key = trim($key);

    if (str_contains($key, "-----BEGIN")) {
      return $key;
    }

    return "-----BEGIN {$label}-----\n"
      . chunk_split(preg_replace("/\s+/", "", $key), 64, "\n")
      . "-----END {$label}-----\n";
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
