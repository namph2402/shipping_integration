<?php

namespace Drupal\shipping_integration\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\shipping_integration\ShippingProvidersPluginManager;
use Drupal\taxonomy\TermInterface;
use Psr\Log\LoggerInterface;

/**
 * Đọc cấu hình kết nối hãng vận chuyển và giữ cho token còn hiệu lực.
 *
 * Mỗi term trong từ vựng "shipping_integration" là một tài khoản kết nối tới
 * một hãng: term trỏ sang entity shipping_type để biết dùng plugin nào, còn
 * host, tài khoản và token thì nằm ngay trên term.
 */
class GetConfigShipping {

  use StringTranslationTrait;

  /**
   * Từ vựng chứa các term cấu hình kết nối, mỗi term là một tài khoản hãng.
   */
  public const VOCABULARY = "shipping_integration";

  /**
   * Thời hạn sử dụng của token sau khi lấy mới.
   *
   * VN-Post không công bố thời hạn token nên module tự đặt mốc ngắn và dựa
   * thêm vào ShippingTokenException để lấy lại giữa chừng khi hãng từ chối.
   */
  protected const TOKEN_LIFETIME = "+10 day";

  /**
   * {@inheritdoc}
   */
  public function __construct(
    protected ShippingProvidersPluginManager $providers,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected MessengerInterface $messenger,
    protected LoggerInterface $logger,
    protected TimeInterface $time,
  ) {}

  /**
   * Lấy cấu hình, tự động làm mới token khi hết hạn hoặc chưa có.
   *
   * @param TermInterface $config_entity
   *   Term cấu hình kết nối.
   *
   * @return array
   *   Cấu hình đã chuẩn hoá.
   */
  public function handle(TermInterface $config_entity): array {
    $config = $this->buildConfig($config_entity);

    if (!$this->isExpired($config)) {
      return $config;
    }

    $refreshed = $this->refreshToken($config_entity, $config);

    if ($refreshed === NULL) {
      return $config;
    }

    $this->saveConfigEntity($config_entity);

    return $refreshed;
  }

  /**
   * Lấy token mới cho cấu hình đang dùng dở giữa chừng.
   *
   * Dùng khi hãng báo token hết hạn sớm hơn mốc lưu trong term: tải lại term
   * theo id đã nhúng sẵn trong mảng cấu hình rồi xin token khác.
   *
   * @param array $config
   *   Cấu hình đang dùng, phải có khoá "shipping_config_id".
   *
   * @return array|null
   *   Cấu hình đã cập nhật token, hoặc NULL khi không làm mới được.
   */
  public function refresh(array $config): ?array {
    $term_id = $config["shipping_config_id"] ?? NULL;

    if (empty($term_id)) {
      return NULL;
    }

    $config_entity = $this->entityTypeManager
      ->getStorage("taxonomy_term")
      ->load($term_id);

    if (!$config_entity instanceof TermInterface) {
      return NULL;
    }

    $refreshed = $this->refreshToken($config_entity);

    if ($refreshed === NULL) {
      return NULL;
    }

    $this->saveConfigEntity($config_entity);

    return $refreshed;
  }

  /**
   * Đọc cấu hình kết nối từ taxonomy term.
   *
   * @param TermInterface $config_entity
   *   Term cấu hình kết nối.
   *
   * @return array
   *   Cấu hình đã chuẩn hoá.
   */
  public function buildConfig(TermInterface $config_entity): array {
    $shipping_type = $config_entity->hasField("field_si_type")
      ? $config_entity->get("field_si_type")->entity
      : NULL;

    return [
      "shipping_config_id" => $config_entity->id(),
      "shipping_config_label" => $config_entity->label(),
      "shipping_type_id" => $shipping_type?->id(),
      "shipping_type_label" => $shipping_type?->label(),
      "shipping_provider" => $this->providerId($shipping_type),
      "shipping_host" => $this->value($config_entity, "field_si_host"),
      "shipping_username" => $this->value($config_entity, "field_si_username"),
      "shipping_password" => $this->value($config_entity, "field_si_password"),
      "shipping_code" => $this->value($config_entity, "field_si_code"),
      "shipping_contract" => $this->value($config_entity, "field_si_contract"),
      "shipping_token" => $this->value($config_entity, "field_si_token"),
      "shipping_expiration" => $this->value($config_entity, "field_si_expiration"),
    ];
  }

  /**
   * Lấy token mới và gán vào term.
   *
   * Term không được lưu ở đây, chỗ gọi tự quyết định: hook presave chỉ cần
   * gán, còn ::handle() và ::refresh() thì phải gọi save.
   *
   * @param TermInterface $config_entity
   *   Term cấu hình sẽ được gán token mới.
   * @param array|null $config
   *   Cấu hình đã đọc sẵn, để trống thì tự đọc lại từ term.
   *
   * @return array|null
   *   Cấu hình đã cập nhật token, hoặc NULL khi lấy token thất bại.
   */
  public function refreshToken(TermInterface $config_entity, ?array $config = NULL): ?array {
    $config = $config ?? $this->buildConfig($config_entity);
    $provider_id = (string) ($config["shipping_provider"] ?? "");

    if ($provider_id === "" || !$this->providers->hasDefinition($provider_id)) {
      $this->messenger->addError(
        $this->t("The shipping provider is not configured correctly.")
      );

      return NULL;
    }

    try {
      /** @var \Drupal\shipping_integration\ShippingProvidersInterface $provider */
      $provider = $this->providers->createInstance($provider_id);
      $token = $provider->getToken($config);
    }
    catch (\DomainException $e) {
      $this->messenger->addError($e->getMessage());
      return NULL;
    }
    catch (\Throwable $e) {
      $this->logger->error("Shipping token error: @message", [
        "@message" => $e->getMessage(),
        "exception" => $e,
      ]);

      $this->messenger->addError($this->t("The system is experiencing problems"));
      return NULL;
    }

    $config["shipping_token"] = (string) ($token["token"] ?? "");
    $config["shipping_expiration"] = date(
      "Y-m-d H:i:s",
      strtotime(static::TOKEN_LIFETIME, $this->time->getRequestTime())
    );

    if ($config_entity->hasField("field_si_token")) {
      $config_entity->set("field_si_token", $config["shipping_token"]);
    }

    if ($config_entity->hasField("field_si_expiration")) {
      $config_entity->set("field_si_expiration", $config["shipping_expiration"]);
    }

    $this->messenger->addStatus($this->t("Get token successfully"));

    return $config;
  }

  /**
   * Lấy mã plugin của hãng vận chuyển.
   *
   * @param object|null $shipping_type
   *   Entity shipping_type được term trỏ tới.
   *
   * @return string
   *   Mã plugin, chuỗi rỗng khi chưa cấu hình.
   */
  private function providerId(?object $shipping_type): string {
    if ($shipping_type === NULL || !$shipping_type->hasField("field_code")) {
      return "";
    }

    return (string) $shipping_type->get("field_code")->value;
  }

  /**
   * Kiểm tra token đã hết hạn hoặc chưa từng được lấy hay chưa.
   *
   * @param array $config
   *   Cấu hình đã đọc từ term.
   *
   * @return bool
   *   TRUE khi cần lấy token mới.
   */
  private function isExpired(array $config): bool {
    if (empty($config["shipping_token"])) {
      return TRUE;
    }

    $expiration = strtotime((string) ($config["shipping_expiration"] ?? ""));

    return $expiration === FALSE || $expiration < $this->time->getRequestTime();
  }

  /**
   * Lưu term cấu hình mà không kích hoạt lại hook lấy token.
   *
   * @param TermInterface $config_entity
   *   Term cấu hình vừa được gán token mới.
   */
  private function saveConfigEntity(TermInterface $config_entity): void {
    $config_entity->skip_call_token = TRUE;

    try {
      $config_entity->save();
    }
    catch (\Throwable $e) {
      $this->logger->error("Cannot save shipping token: @message", [
        "@message" => $e->getMessage(),
        "exception" => $e,
      ]);
    }
  }

  /**
   * Đọc giá trị field kiểu chuỗi, trả chuỗi rỗng khi bundle không có field.
   *
   * @param TermInterface $config_entity
   *   Term cấu hình.
   * @param string $field
   *   Tên field.
   *
   * @return string
   *   Giá trị field.
   */
  private function value(TermInterface $config_entity, string $field): string {
    return $config_entity->hasField($field)
      ? (string) $config_entity->get($field)->value
      : "";
  }

}
