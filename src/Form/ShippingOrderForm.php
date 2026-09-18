<?php

declare(strict_types=1);

namespace Drupal\shipping_integration\Form;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\shipping_integration\Catalog\VnpostCatalog;
use Drupal\shipping_integration\Plugin\Providers\VnpostProvider;
use Drupal\shipping_integration\Service\AddressOptions;
use Drupal\shipping_integration\Service\GetConfigShipping;
use Drupal\shipping_integration\Service\HandleShipping;
use Drupal\shipping_integration\ShippingOrderService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form tạo và sửa đơn vận chuyển, dựng theo màn khai đơn của MyVNPost.
 *
 * Bố cục hai cột: bên trái là các bên tham gia, bên phải là hàng hoá, dịch vụ
 * và yêu cầu khi phát, cuối trang là thanh tổng hợp cước dính đáy màn hình.
 *
 * Form chỉ khai những trường /CreateOrder thực sự nhận. Các khối riêng của cổng
 * MyVNPost mà API không có (chi tiết hàng hoá, ảnh đính kèm, tủ PUDO) cố tình
 * không dựng để dữ liệu nhập vào không bao giờ rơi vào chỗ hãng không đọc.
 *
 * @see https://my-uat.vnpost.vn/static/api/order/create-domestic
 */
final class ShippingOrderForm extends ContentEntityForm {

  /**
   * Field do module và hãng tự ghi, người dùng không khai tay.
   */
  private const SYSTEM_FIELDS = [
    "label",
    "status",
    "created",
    "field_so_carrier",
    "field_so_item_code",
    "field_so_original_id",
    "field_so_hdr_id",
    "field_so_status",
    "field_so_case_id",
    "field_so_case_type",
    "field_so_is_new_address",
    "field_so_bcp_code",
    "field_so_bcp_name",
    "field_so_main_fee",
    "field_so_vas_fee",
    "field_so_total_fee",
    "field_so_price_weight",
    "field_so_history",
    "field_so_label",
    "field_so_payload",
    "field_so_synced",
  ];

  /**
   * Field dựng bằng element riêng thay cho widget mặc định.
   *
   * Kết nối cần select gọn thay vì ô tự động hoàn thành, còn địa chỉ cần select
   * liên tầng lọc theo cấp cha nên không dùng lại widget entity reference được.
   * Dịch vụ lọc theo hợp đồng của kết nối, còn COD và khai giá nay là thuộc
   * tính của dịch vụ GTGT nên được chép lại từ khối dịch vụ GTGT khi lưu.
   */
  private const CUSTOM_FIELDS = [
    "field_so_config",
    "field_so_service",
    "field_so_addons",
    "field_so_cod",
    "field_so_insurance",
    "field_so_sender_province",
    "field_so_sender_district",
    "field_so_sender_commune",
    "field_so_receiver_province",
    "field_so_receiver_district",
    "field_so_receiver_commune",
  ];

  /**
   * Đường dẫn tới khối địa chỉ trong mảng form, dùng cho AJAX liên tầng.
   */
  private const ADDRESS_PATH = [
    "sender" => ["layout", "left", "sender", "body", "address"],
    "receiver" => ["layout", "left", "receiver", "body", "address"],
  ];

  /**
   * Đường dẫn tới khối thông tin kết nối trong mảng form.
   */
  private const CONNECTION_PATH = ["layout", "left", "sender", "body", "connection"];

  /**
   * Đường dẫn tới thẻ dịch vụ trong mảng form, dựng lại khi đổi kết nối hoặc
   * đổi dịch vụ vì danh sách dịch vụ GTGT phụ thuộc cả hai.
   */
  private const SERVICE_PATH = ["layout", "right", "service"];

  /**
   * Đường dẫn tới bảng cước các dịch vụ trong mảng form.
   */
  private const QUOTES_PATH = ["layout", "right", "service", "body", "quotes"];

  /**
   * Các ô cần thiết để hỏi cước, dùng giới hạn phạm vi kiểm tra của nút hỏi.
   */
  private const QUOTE_FIELDS = [
    ["field_so_config"],
    ["field_so_weight"],
    ["field_so_length"],
    ["field_so_width"],
    ["field_so_height"],
    ["field_so_vehicle"],
    ["addons"],
    ["sender_province"],
    ["sender_district"],
    ["sender_commune"],
    ["receiver_province"],
    ["receiver_district"],
    ["receiver_commune"],
  ];

  /**
   * Khởi tạo form.
   *
   * @param EntityRepositoryInterface $entity_repository
   *   Kho entity.
   * @param EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   Thông tin bundle.
   * @param TimeInterface $time
   *   Dịch vụ thời gian.
   * @param AddressOptions $addressOptions
   *   Danh sách chọn địa chỉ theo cấp.
   * @param HandleShipping $handleShipping
   *   Nghiệp vụ gọi sang hãng vận chuyển.
   * @param ShippingOrderService $orderService
   *   Tiện ích dùng chung của đơn vận chuyển.
   */
  public function __construct(
    // Ba service dưới đây phải là protected và không readonly:
    // DependencySerializationTrait dựng lại chúng sau mỗi vòng AJAX bằng
    // get_object_vars() ở phạm vi lớp cha, nên không nhìn thấy thuộc tính
    // private, còn readonly thì không gán lại được từ phạm vi đó.
    EntityRepositoryInterface $entity_repository,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    TimeInterface $time,
    protected AddressOptions $addressOptions,
    protected HandleShipping $handleShipping,
    protected ShippingOrderService $orderService,
  ) {
    parent::__construct($entity_repository, $entity_type_bundle_info, $time);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get("entity.repository"),
      $container->get("entity_type.bundle.info"),
      $container->get("datetime.time"),
      $container->get("shipping_integration.address_options"),
      $container->get("shipping_integration.handle_shipping"),
      $container->get("shipping_integration.order_service"),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    // Bundle chưa có bộ field của đơn trong nước thì giữ nguyên form mặc định.
    if (!$this->entity->hasField("field_so_service")) {
      return $form;
    }

    $display = $this->getFormDisplay($form_state);

    // Gỡ hẳn khỏi form display chứ không chỉ ẩn đi: có thế
    // copyFormValuesToEntity() mới không ghi rỗng đè lên giá trị đang có.
    foreach ([...self::SYSTEM_FIELDS, ...self::CUSTOM_FIELDS] as $name) {
      $display->removeComponent($name);
      unset($form[$name]);
    }

    $two_level = $this->twoLevel($form_state);

    $form["#attributes"]["class"][] = "shipping-order-form";
    $form["#attached"]["library"][] = "shipping_integration/shipping_order_form";
    $form["two_level"] = ["#type" => "value", "#value" => $two_level];

    $form["layout"] = [
      "#type" => "container",
      "#attributes" => ["class" => ["row", "g-3", "shipping-form-layout"]],
      "#weight" => 0,
      "left" => [
        "#type" => "container",
        "#attributes" => ["class" => ["col-12", "col-xxl-6"]],
      ],
      "right" => [
        "#type" => "container",
        "#attributes" => ["class" => ["col-12", "col-xxl-6"]],
      ],
    ];

    $this->buildSenderCard($form, $form_state, $two_level);
    $this->buildReceiverCard($form, $form_state, $two_level);
    // Cột phải xếp theo thứ tự dựng: hàng hoá, dịch vụ rồi yêu cầu bổ sung.
    $this->buildParcelCard($form);
    $this->buildServiceCard($form, $form_state);
    $this->buildRequestCard($form);
    $this->buildSummary($form);

    return $form;
  }

  /**
   * Dựng khối kết nối và người gửi.
   *
   * @param array $form
   *   Mảng form đang dựng.
   * @param FormStateInterface $form_state
   *   Trạng thái form.
   * @param bool $two_level
   *   TRUE nếu đơn khai theo bộ địa chỉ hai cấp.
   */
  private function buildSenderCard(array &$form, FormStateInterface $form_state, bool $two_level): void {
    $card = $this->card($this->t("Sender"));

    $card["body"]["field_so_config"] = [
      "#type" => "select",
      "#title" => $this->t("Connection"),
      "#options" => $this->orderService->configOptions(),
      "#empty_option" => $this->t("- Select -"),
      "#default_value" => $this->currentValue($form_state, "field_so_config"),
      "#required" => TRUE,
      "#parents" => ["field_so_config"],
      "#wrapper_attributes" => ["class" => ["col-12"]],
      // Đổi kết nối là đổi hợp đồng, nên dựng lại cả thẻ dịch vụ.
      "#ajax" => [
        "callback" => "::refreshConnection",
        "event" => "change",
      ],
    ];

    $card["body"]["connection"] = $this->connectionInfo($form_state);

    $card["body"]["field_so_sender_name"] = $this->tune($form, "field_so_sender_name", [
      "#title" => $this->t("Sender name"),
      "#required" => TRUE,
      "#wrapper_attributes" => ["class" => ["col-md-6"]],
    ]);
    $card["body"]["field_so_sender_phone"] = $this->tune($form, "field_so_sender_phone", [
      "#title" => $this->t("Phone number"),
      "#required" => TRUE,
      "#wrapper_attributes" => ["class" => ["col-md-6"]],
    ]);
    $card["body"]["field_so_sender_email"] = $this->tune($form, "field_so_sender_email", [
      "#title" => $this->t("Email"),
      "#wrapper_attributes" => ["class" => ["col-md-6"]],
    ]);
    $card["body"]["field_so_sender_address"] = $this->tune($form, "field_so_sender_address", [
      "#title" => $this->t("Street address"),
      "#required" => TRUE,
      "#wrapper_attributes" => ["class" => ["col-md-6"]],
    ]);

    $card["body"]["address"] = $this->addressGroup($form_state, "sender", $two_level);

    NestedArray::setValue($form, ["layout", "left", "sender"], $this->ordered($card));
  }

  /**
   * Dựng khối người nhận.
   *
   * @param array $form
   *   Mảng form đang dựng.
   * @param FormStateInterface $form_state
   *   Trạng thái form.
   * @param bool $two_level
   *   TRUE nếu đơn khai theo bộ địa chỉ hai cấp.
   */
  private function buildReceiverCard(array &$form, FormStateInterface $form_state, bool $two_level): void {
    $card = $this->card($this->t("Receiver"));

    $card["body"]["field_so_receiver_name"] = $this->tune($form, "field_so_receiver_name", [
      "#title" => $this->t("Receiver name"),
      "#required" => TRUE,
      "#wrapper_attributes" => ["class" => ["col-md-6"]],
    ]);
    $card["body"]["field_so_receiver_phone"] = $this->tune($form, "field_so_receiver_phone", [
      "#title" => $this->t("Phone number"),
      "#required" => TRUE,
      "#wrapper_attributes" => ["class" => ["col-md-6"]],
    ]);
    $card["body"]["field_so_receiver_email"] = $this->tune($form, "field_so_receiver_email", [
      "#title" => $this->t("Email"),
      "#wrapper_attributes" => ["class" => ["col-md-6"]],
    ]);
    $card["body"]["field_so_receiver_address"] = $this->tune($form, "field_so_receiver_address", [
      "#title" => $this->t("Street address"),
      "#required" => TRUE,
      "#wrapper_attributes" => ["class" => ["col-md-6"]],
    ]);

    $card["body"]["address"] = $this->addressGroup($form_state, "receiver", $two_level);

    NestedArray::setValue($form, ["layout", "left", "receiver"], $this->ordered($card));
  }

  /**
   * Dựng khối chọn dịch vụ và bảng dịch vụ cộng thêm.
   *
   * Dựng theo màn khai đơn của MyVNPost: chọn một SPDV trong số dịch vụ đã
   * tích ở term kết nối, bảng bên dưới liệt kê dịch vụ cộng thêm của đúng SPDV
   * đó, tích dòng nào thì dòng đó mới hiện ô nhập thuộc tính.
   *
   * @param array $form
   *   Mảng form đang dựng.
   * @param FormStateInterface $form_state
   *   Trạng thái form.
   */
  private function buildServiceCard(array &$form, FormStateInterface $form_state): void {
    $card = $this->card($this->t("Choose service"));
    $card["#attributes"]["id"] = "shipping-service-card";

    $service = $this->currentValue($form_state, "field_so_service");
    $options = VnpostCatalog::serviceOptions($this->contractServices($form_state));

    // Đơn cũ đang giữ dịch vụ không còn tích ở kết nối vẫn phải mở ra sửa
    // được, nên giữ lại lựa chọn đó.
    $stored = $this->fieldValue("field_so_service");

    if ($stored !== "" && !isset($options[$stored])) {
      $options[$stored] = VnpostCatalog::serviceOptions([$stored])[$stored] ?? $stored;
    }

    if (!isset($options[$service])) {
      $service = "";
    }

    $card["body"]["field_so_service"] = [
      "#type" => "select",
      "#title" => $this->t("Service name"),
      "#options" => $options,
      "#empty_option" => $this->t("- Select -"),
      "#default_value" => $service,
      "#required" => TRUE,
      "#parents" => ["field_so_service"],
      "#wrapper_attributes" => ["class" => ["col-md-8"]],
      "#ajax" => [
        "callback" => "::refreshService",
        "wrapper" => "shipping-service-card",
        "event" => "change",
      ],
    ];

    if ($options === [] && $this->currentValue($form_state, "field_so_config") !== "") {
      $card["body"]["field_so_service"]["#description"] = $this->t("No service is ticked on this connection. Tick the contracted services on the connection configuration first.");
    }

    $card["body"]["field_so_vehicle"] = $this->tune($form, "field_so_vehicle", [
      "#title" => $this->t("Vehicle"),
      "#wrapper_attributes" => ["class" => ["col-md-4"]],
    ]);

    $card["body"]["quote_actions"] = [
      "#type" => "container",
      "#attributes" => ["class" => ["col-12", "d-flex", "justify-content-end"]],
      "quote" => [
        "#type" => "submit",
        "#value" => $this->t("View fees of all services"),
        "#submit" => ["::quoteServices"],
        "#limit_validation_errors" => self::QUOTE_FIELDS,
        "#attributes" => ["class" => ["btn", "btn-sm", "btn-outline-primary"]],
        "#ajax" => [
          "callback" => "::refreshQuotes",
          "wrapper" => "shipping-service-quotes",
        ],
      ],
    ];

    $card["body"]["quotes"] = $this->quoteTable($form_state);
    $card["body"]["addons"] = $this->addonTable($form_state, $service);

    NestedArray::setValue($form, self::SERVICE_PATH, $this->ordered($card));
  }

  /**
   * Dựng bảng dịch vụ cộng thêm của SPDV đang chọn.
   *
   * Mỗi dòng là một dịch vụ GTGT: ô tích, tên, và cột thuộc tính chỉ hiện khi
   * dòng đã được tích. Dịch vụ nhóm "yêu cầu thêm" (GTG070, GTG071) nằm chung
   * bảng như màn của hãng, chỉ khác chỗ plugin gửi chúng trong additionRequest.
   *
   * @param FormStateInterface $form_state
   *   Trạng thái form.
   * @param string $service
   *   Mã SPDV đang chọn.
   *
   * @return array
   *   Phần form của bảng dịch vụ cộng thêm.
   */
  private function addonTable(FormStateInterface $form_state, string $service): array {
    $wrapper = [
      "#type" => "container",
      "#attributes" => ["class" => ["col-12", "shipping-addons"]],
    ];

    if ($service === "") {
      $wrapper["empty"] = $this->hint($this->t("Choose a service to see its addon services."));

      return $wrapper;
    }

    $addons = VnpostCatalog::addonsFor($service);

    if ($addons === []) {
      $wrapper["empty"] = $this->hint($this->t("This service has no addon service."));

      return $wrapper;
    }

    $current = $this->currentAddons($form_state);

    $wrapper["table"] = [
      "#type" => "table",
      "#tree" => TRUE,
      "#parents" => ["addons"],
      "#caption" => $this->t("Addon services"),
      "#attributes" => ["class" => ["table", "table-sm", "table-bordered", "align-middle", "mb-0", "shipping-addon-table"]],
    ];

    foreach ($addons as $code => $addon) {
      $wrapper["table"][$code] = $this->addonRow($code, $addon, $current[$code] ?? NULL);
    }

    return $wrapper;
  }

  /**
   * Dựng một dòng của bảng dịch vụ cộng thêm.
   *
   * @param string $code
   *   Mã dịch vụ GTGT.
   * @param array $addon
   *   Định nghĩa dịch vụ GTGT trong danh mục.
   * @param array|null $values
   *   Thuộc tính đang khai, NULL khi dịch vụ chưa được tích.
   *
   * @return array
   *   Các ô của dòng: tích chọn, tên dịch vụ, thuộc tính.
   */
  private function addonRow(string $code, array $addon, ?array $values): array {
    $row = [
      "enabled" => [
        "#type" => "checkbox",
        "#title" => $addon["label"],
        "#title_display" => "invisible",
        "#default_value" => $values !== NULL,
        "#wrapper_attributes" => ["class" => ["shipping-addon-check"]],
      ],
      "name" => [
        "#type" => "html_tag",
        "#tag" => "span",
        "#attributes" => ["title" => $code],
        "#value" => $addon["label"],
        "#wrapper_attributes" => ["class" => ["shipping-addon-name"]],
      ],
      "props" => [
        "#type" => "container",
        "#attributes" => ["class" => ["shipping-addon-props"]],
        "#wrapper_attributes" => ["class" => ["shipping-addon-props-cell"]],
      ],
    ];

    if ($code === VnpostCatalog::ADDON_COD) {
      $row["enabled"]["#attributes"]["class"][] = "shipping-cod-toggle";
    }

    $visible = [
      "visible" => [
        ':input[name="addons[' . $code . '][enabled]"]' => ["checked" => TRUE],
      ],
    ];

    foreach ($addon["props"] as $prop => $definition) {
      // Thuộc tính do hãng tự tính, module luôn gửi null nên không cho khai.
      if (!empty($definition["fixed"])) {
        continue;
      }

      $value = (string) ($values[$prop] ?? "");

      $input = match ($definition["type"]) {
        "number" => [
          "#type" => "number",
          "#min" => 0,
          "#default_value" => $value,
        ],
        "date" => [
          "#type" => "date",
          "#default_value" => $this->isoDate($value),
        ],
        "flag" => [
          "#type" => "checkbox",
          "#default_value" => $value === "1",
        ],
        default => [
          "#type" => "textfield",
          "#default_value" => $value,
        ],
      } + [
        "#title" => $definition["label"],
        "#wrapper_attributes" => ["class" => ["shipping-addon-prop"]],
        "#states" => $visible,
      ];

      // Bắt buộc chỉ khi đã tích dịch vụ nên không dùng #required, chỉ gắn
      // dấu sao cho nhãn rồi kiểm tra ở validateForm().
      if (!empty($definition["required"])) {
        $input["#label_attributes"]["class"] = ["js-form-required", "form-required"];
      }

      if ($prop === VnpostCatalog::PROP_COD_AMOUNT) {
        $input["#attributes"]["class"][] = "shipping-cod-input";
      }

      $row["props"][$prop] = $input;
    }

    return $row;
  }

  /**
   * Dựng khối thông tin hàng hoá.
   *
   * @param array $form
   *   Mảng form đang dựng.
   */
  private function buildParcelCard(array &$form): void {
    $card = $this->card($this->t("Parcel information"));

    $card["body"]["field_so_sale_code"] = $this->tune($form, "field_so_sale_code", [
      "#title" => $this->t("Order code"),
      "#wrapper_attributes" => ["class" => ["col-md-6"]],
    ]);
    $card["body"]["field_so_weight"] = $this->tune($form, "field_so_weight", [
      "#title" => $this->t("Total weight (gram)"),
      "#required" => TRUE,
      "#wrapper_attributes" => ["class" => ["col-md-6"]],
      "#attributes" => ["class" => ["shipping-weight-input"], "min" => 1],
    ]);

    $card["body"]["size"] = [
      "#type" => "html_tag",
      "#tag" => "div",
      "#attributes" => ["class" => ["col-12", "shipping-subheader"]],
      "#value" => $this->t("Size (cm)"),
    ];

    foreach (["length" => $this->t("Length"), "width" => $this->t("Width"), "height" => $this->t("Height")] as $key => $title) {
      $card["body"]["field_so_" . $key] = $this->tune($form, "field_so_" . $key, [
        "#title" => $title,
        "#wrapper_attributes" => ["class" => ["col-6", "col-md-3"]],
        "#attributes" => ["class" => ["shipping-size-input"], "min" => 0],
      ]);
    }

    // Khối lượng quy đổi tính ngay trên trình duyệt theo đúng công thức của
    // hãng, hãng sẽ trả lại con số chính thức khi tính cước.
    $card["body"]["dim_weight"] = [
      "#type" => "item",
      "#title" => $this->t("Converted weight (gram)"),
      "#wrapper_attributes" => ["class" => ["col-6", "col-md-3"]],
      "#markup" => '<span class="form-control-plaintext shipping-dim-weight">0</span>',
    ];

    $card["body"]["field_so_content"] = $this->tune($form, "field_so_content", [
      "#title" => $this->t("Content"),
      "#required" => TRUE,
      "#wrapper_attributes" => ["class" => ["col-12"]],
      "#rows" => 3,
    ]);

    NestedArray::setValue($form, ["layout", "right", "parcel"], $this->ordered($card));
  }

  /**
   * Dựng khối yêu cầu thêm khi gửi và phát hàng.
   *
   * @param array $form
   *   Mảng form đang dựng.
   */
  private function buildRequestCard(array &$form): void {
    $card = $this->card($this->t("Additional requests"));

    // Hai field này là danh sách chọn một, đổi sang nút tròn cho đúng màn khai
    // đơn của hãng mà vẫn dùng nguyên bộ giá trị hợp lệ của field.
    $card["body"]["field_so_send_type"] = $this->tune($form, "field_so_send_type", [
      "#type" => "radios",
      "#title" => $this->t("Sending method"),
      "#required" => TRUE,
      "#wrapper_attributes" => ["class" => ["col-md-6", "shipping-inline-radios"]],
    ]);
    $card["body"]["field_so_delivery_require"] = $this->tune($form, "field_so_delivery_require", [
      "#type" => "radios",
      "#title" => $this->t("Delivery requirement"),
      "#wrapper_attributes" => ["class" => ["col-md-6", "shipping-inline-radios"]],
    ]);
    $card["body"]["field_so_delivery_time"] = $this->tune($form, "field_so_delivery_time", [
      "#title" => $this->t("Preferred delivery time"),
      "#wrapper_attributes" => ["class" => ["col-md-6"]],
    ]);
    $card["body"]["field_so_is_broken"] = $this->tune($form, "field_so_is_broken", [
      "#title" => $this->t("Fragile goods"),
      "#wrapper_attributes" => ["class" => ["col-md-6", "shipping-checkbox-field"]],
    ]);
    $card["body"]["field_so_delivery_note"] = $this->tune($form, "field_so_delivery_note", [
      "#title" => $this->t("Delivery instruction"),
      "#wrapper_attributes" => ["class" => ["col-12"]],
      "#attributes" => ["class" => ["shipping-note-input"]],
      "#rows" => 3,
    ]);

    // Các câu hay dùng, bấm là chèn thẳng vào ô chỉ dẫn phát.
    $presets = [
      $this->t("Please handle gently, fragile goods."),
      $this->t("Deliver during office hours."),
      $this->t("Call the receiver before delivering."),
      $this->t("Partial delivery allowed, take back the rest."),
    ];

    $card["body"]["note_presets"] = [
      "#type" => "container",
      "#attributes" => ["class" => ["col-12", "d-flex", "flex-wrap", "gap-1", "shipping-note-presets"]],
    ];

    foreach ($presets as $index => $preset) {
      $card["body"]["note_presets"][$index] = [
        "#type" => "html_tag",
        "#tag" => "button",
        "#attributes" => [
          "type" => "button",
          "class" => ["btn", "btn-sm", "btn-outline-secondary", "shipping-note-preset"],
          "data-note" => $preset,
        ],
        "#value" => $preset,
      ];
    }

    $card["body"]["office"] = [
      "#type" => "html_tag",
      "#tag" => "div",
      "#attributes" => ["class" => ["col-12", "shipping-subheader"]],
      "#value" => $this->t("Post office codes"),
    ];

    $card["body"]["field_so_org_collect"] = $this->tune($form, "field_so_org_collect", [
      "#title" => $this->t("Collecting post office"),
      "#wrapper_attributes" => ["class" => ["col-md-6"]],
    ]);
    $card["body"]["field_so_org_accept"] = $this->tune($form, "field_so_org_accept", [
      "#title" => $this->t("Accepting post office"),
      "#wrapper_attributes" => ["class" => ["col-md-6"]],
    ]);

    NestedArray::setValue($form, ["layout", "right", "request"], $this->ordered($card));
  }

  /**
   * Dựng thanh tổng hợp cước dính đáy màn hình.
   *
   * Cước và khối lượng tính cước là số hãng trả về ở lần tính cước gần nhất,
   * còn tiền thu hộ chạy theo đúng ô COD người dùng đang gõ.
   *
   * @param array $form
   *   Mảng form đang dựng.
   */
  private function buildSummary(array &$form): void {
    $summary = [
      "price_weight" => [
        $this->t("Charged weight"),
        $this->number($this->fieldValue("field_so_price_weight")) . " gram",
        "",
      ],
      "total_fee" => [
        $this->t("Estimated total fee"),
        $this->number($this->fieldValue("field_so_total_fee")) . " đ",
        "",
      ],
      "cod" => [
        $this->t("COD to collect"),
        $this->number($this->fieldValue("field_so_cod")) . " đ",
        "shipping-cod-total",
      ],
      "synced" => [
        $this->t("Synced"),
        $this->timestamp($this->fieldValue("field_so_synced")),
        "",
      ],
    ];

    $form["summary"] = [
      "#type" => "container",
      "#attributes" => ["class" => ["shipping-form-summary", "d-flex", "flex-wrap", "gap-4"]],
      "#weight" => 90,
    ];

    foreach ($summary as $key => [$label, $value, $class]) {
      $form["summary"][$key] = [
        "#type" => "container",
        "#attributes" => ["class" => ["shipping-summary-item"]],
        "label" => [
          "#type" => "html_tag",
          "#tag" => "div",
          "#attributes" => ["class" => ["shipping-summary-label"]],
          "#value" => $label,
        ],
        "value" => [
          "#type" => "html_tag",
          "#tag" => "div",
          "#attributes" => ["class" => array_filter(["shipping-summary-value", $class])],
          "#value" => $value,
        ],
      ];
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state): array {
    $actions = parent::actions($form, $form_state);

    if (!$this->entity->hasField("field_so_service")) {
      return $actions;
    }

    $actions["#attributes"]["class"][] = "shipping-form-actions";

    // Nút nhấn mạnh dành cho lệnh tạo đơn, nút lưu tại chỗ để nhạt đi.
    unset($actions["submit"]["#button_type"]);
    $actions["submit"]["#value"] = $this->t("Save");
    $actions["submit"]["#attributes"]["class"] = ["btn", "btn-outline-secondary"];
    $actions["submit"]["#weight"] = 10;

    $actions["fee"] = $this->carrierButton($actions["submit"], "fee", $this->t("Calculate fee"), "btn-warning", 0);

    // Đơn đã nằm trên hệ thống hãng thì chỉ còn hiệu chỉnh, không tạo lại được.
    if ($this->fieldValue("field_so_item_code") === "") {
      $actions["create"] = $this->carrierButton($actions["submit"], "create", $this->t("Create order"), "btn-primary", 20);
      $actions["draft"] = $this->carrierButton($actions["submit"], "draft", $this->t("Save draft"), "btn-outline-primary", 30);
    }
    else {
      // Chỉ liệt kê loại mà trạng thái hiện tại của đơn được phép gửi.
      $types = VnpostProvider::correctionTypes($this->fieldValue("field_so_status"));

      if ($types !== []) {
        $actions["correction_type"] = [
          "#type" => "select",
          "#title" => $this->t("Correction type"),
          "#title_display" => "invisible",
          "#options" => $types,
          "#default_value" => array_key_first($types),
          "#weight" => 19,
          "#attributes" => ["class" => ["form-select", "w-auto", "shipping-correction-type"]],
        ];
      }

      $actions["correct"] = $this->carrierButton($actions["submit"], "correct", $this->t("Correct order"), "btn-primary", 20);
    }

    $actions["reset"] = [
      "#type" => "link",
      "#title" => $this->t("Reset"),
      "#url" => $this->entity->isNew() ? Url::fromRoute("<current>") : $this->entity->toUrl("edit-form"),
      "#attributes" => ["class" => ["btn", "btn-outline-danger"]],
      "#weight" => 40,
    ];

    if (isset($actions["delete"])) {
      $actions["delete"]["#weight"] = 50;
    }

    return $actions;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    if (!$this->entity->hasField("field_so_service")) {
      return;
    }

    $two_level = (bool) $form_state->getValue("two_level");

    foreach (["sender" => $this->t("sender"), "receiver" => $this->t("receiver")] as $party => $label) {
      if ($form_state->getValue("{$party}_province") === "") {
        $form_state->setErrorByName("{$party}_province", $this->t("Choose the province of the @party.", ["@party" => $label]));
      }

      if (!$two_level && $form_state->getValue("{$party}_district") === "") {
        $form_state->setErrorByName("{$party}_district", $this->t("Choose the district of the @party.", ["@party" => $label]));
      }

      if ($form_state->getValue("{$party}_commune") === "") {
        $form_state->setErrorByName("{$party}_commune", $this->t("Choose the ward of the @party.", ["@party" => $label]));
      }
    }

    $this->validateService($form_state);

    // Hãng từ chối đơn không có khối lượng nên chặn ngay tại form.
    $weight = (int) ($form_state->getValue(["field_so_weight", 0, "value"]) ?? 0);

    if ($weight <= 0) {
      $form_state->setErrorByName("field_so_weight", $this->t("The total weight must be greater than 0 gram."));
    }

    // Hãng che SĐT và địa chỉ bằng dấu "+" và không bao giờ trả lại bản thật,
    // mà lệnh hiệu chỉnh lại bắt buộc có đủ, nên người dùng phải gõ lại.
    if (($form_state->getTriggeringElement()["#shipping_action"] ?? "") === "correct") {
      foreach (["sender", "receiver"] as $party) {
        foreach (["phone", "address"] as $field) {
          $name = "field_so_{$party}_{$field}";
          $value = (string) ($form_state->getValue([$name, 0, "value"]) ?? "");

          if (VnpostProvider::isMasked($value)) {
            $form_state->setErrorByName($name, $this->t("This value is masked by VN-Post. Enter the full value before sending a correction."));
          }
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function copyFormValuesToEntity(EntityInterface $entity, array $form, FormStateInterface $form_state): void {
    parent::copyFormValuesToEntity($entity, $form, $form_state);

    if (!$entity instanceof FieldableEntityInterface || !$entity->hasField("field_so_service")) {
      return;
    }

    $two_level = (bool) $form_state->getValue("two_level");

    $entity->set("field_so_is_new_address", $two_level);
    $entity->set("field_so_config", $form_state->getValue("field_so_config") ?: NULL);
    $entity->set("field_so_service", $form_state->getValue("field_so_service") ?: NULL);
    $this->applyAddons($entity, $this->submittedAddons($form_state));

    foreach (["sender", "receiver"] as $party) {
      $entity->set("field_so_{$party}_province", $form_state->getValue("{$party}_province") ?: NULL);
      $entity->set("field_so_{$party}_commune", $form_state->getValue("{$party}_commune") ?: NULL);
      // Bộ địa chỉ hai cấp không có quận/huyện, plugin sẽ gửi hằng "VNPOST".
      $entity->set("field_so_{$party}_district", $two_level ? NULL : ($form_state->getValue("{$party}_district") ?: NULL));
    }

    // Hãng vận chuyển suy ra từ term kết nối để danh sách lọc đúng theo hãng.
    $config = $entity->get("field_so_config")->entity;

    if ($config !== NULL && $config->hasField("field_si_type") && !$config->get("field_si_type")->isEmpty()) {
      $entity->set("field_so_carrier", $config->get("field_si_type")->target_id);
    }

    // Nhãn tạm dùng mã đơn của khách, hãng trả số hiệu bưu gửi thì ghi đè.
    if ($entity->get("label")->isEmpty()) {
      $sale_code = (string) $entity->get("field_so_sale_code")->value;
      $entity->set("label", $sale_code !== "" ? $sale_code : "DH-" . date("YmdHis"));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);

    $message_args = ["%label" => $this->entity->toLink()->toString()];
    $logger_args = [
      "%label" => $this->entity->label(),
      "link" => $this->entity->toLink($this->t("View"))->toString(),
    ];

    switch ($result) {
      case SAVED_NEW:
        $this->messenger()->addStatus($this->t("New shipping order %label has been created.", $message_args));
        $this->logger("shipping_integration")->notice("New shipping order %label has been created.", $logger_args);
        break;

      case SAVED_UPDATED:
        $this->messenger()->addStatus($this->t("The shipping order %label has been updated.", $message_args));
        $this->logger("shipping_integration")->notice("The shipping order %label has been updated.", $logger_args);
        break;

      default:
        throw new \LogicException("Could not save the entity.");
    }

    $form_state->setRedirectUrl($this->entity->toUrl());

    return $result;
  }

  /**
   * Gọi lệnh tương ứng sang hãng sau khi đơn đã được lưu.
   *
   * Đơn luôn được lưu trước rồi mới đẩy đi, nhờ vậy lệnh thất bại thì dữ liệu
   * người dùng vừa gõ vẫn còn nguyên trên hệ thống.
   *
   * @param array $form
   *   Mảng form.
   * @param FormStateInterface $form_state
   *   Trạng thái form.
   */
  public function callCarrier(array $form, FormStateInterface $form_state): void {
    $action = $form_state->getTriggeringElement()["#shipping_action"] ?? "";

    $result = match ($action) {
      "fee" => $this->handleShipping->calculateFee($this->entity),
      "create" => $this->handleShipping->createOrder($this->entity, FALSE),
      "draft" => $this->handleShipping->createOrder($this->entity, TRUE),
      "correct" => $this->handleShipping->updateOrder(
        $this->entity,
        $form_state->get("shipping_previous"),
        (string) $form_state->getValue("correction_type", ""),
      ),
      default => NULL,
    };

    if ($result === NULL) {
      return;
    }

    if (!empty($result["success"])) {
      $this->messenger()->addStatus((string) ($result["message"] ?? ""));
    }
    else {
      $this->messenger()->addError((string) ($result["message"] ?? $this->t("Unknown error")));
    }

    // Tính cước và lệnh lỗi thì ở lại form để người dùng sửa tiếp, tạo đơn
    // thành công mới chuyển sang trang chi tiết.
    if ($action === "fee" || empty($result["success"])) {
      $form_state->setRedirectUrl($this->entity->toUrl("edit-form"));
    }
  }

  /**
   * Chụp thông tin đang lưu của đơn trước khi form ghi đè.
   *
   * @param array $form
   *   Mảng form.
   * @param FormStateInterface $form_state
   *   Trạng thái form.
   */
  public function rememberPrevious(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\shipping_integration\ShippingOrderInterface $order */
    $order = $this->entity;

    $form_state->set("shipping_previous", $this->handleShipping->snapshot($order));
  }

  /**
   * Trả về khối địa chỉ vừa dựng lại sau khi đổi cấp cha.
   *
   * @param array $form
   *   Mảng form đã dựng lại.
   * @param FormStateInterface $form_state
   *   Trạng thái form.
   *
   * @return array
   *   Phần form của khối địa chỉ.
   */
  public function refreshAddress(array $form, FormStateInterface $form_state): array {
    $path = $form_state->getTriggeringElement()["#address_path"] ?? [];

    return NestedArray::getValue($form, $path) ?? [];
  }

  /**
   * Dựng lại khối thông tin kết nối và thẻ dịch vụ sau khi đổi tài khoản.
   *
   * @param array $form
   *   Mảng form đã dựng lại.
   * @param FormStateInterface $form_state
   *   Trạng thái form.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   Lệnh thay khối thông tin kết nối và thẻ dịch vụ.
   */
  public function refreshConnection(array $form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand("#shipping-connection-info", NestedArray::getValue($form, self::CONNECTION_PATH) ?? []));
    $response->addCommand(new ReplaceCommand("#shipping-service-card", NestedArray::getValue($form, self::SERVICE_PATH) ?? []));

    return $response;
  }

  /**
   * Trả về thẻ dịch vụ vừa dựng lại sau khi đổi dịch vụ.
   *
   * @param array $form
   *   Mảng form đã dựng lại.
   * @param FormStateInterface $form_state
   *   Trạng thái form.
   *
   * @return array
   *   Phần form của thẻ dịch vụ.
   */
  public function refreshService(array $form, FormStateInterface $form_state): array {
    return NestedArray::getValue($form, self::SERVICE_PATH) ?? [];
  }

  /**
   * Hỏi hãng bảng cước của mọi dịch vụ đang mở cho tài khoản.
   *
   * Chỉ dựng một bản sao đơn hàng trong bộ nhớ từ những ô đã khai, không lưu
   * và không đụng tới đơn thật, nên bấm bao nhiêu lần cũng an toàn.
   *
   * @param array $form
   *   Mảng form.
   * @param FormStateInterface $form_state
   *   Trạng thái form.
   */
  public function quoteServices(array $form, FormStateInterface $form_state): void {
    $form_state->setRebuild();

    $order = clone $this->entity;
    $two_level = $this->twoLevel($form_state);

    $order->set("field_so_config", $form_state->getValue("field_so_config") ?: NULL);
    $order->set("field_so_is_new_address", $two_level);
    // Bỏ trống mã dịch vụ thì hãng trả cước của tất cả dịch vụ.
    $order->set("field_so_service", NULL);

    foreach (["weight", "length", "width", "height", "vehicle"] as $name) {
      $order->set("field_so_" . $name, $form_state->getValue(["field_so_" . $name, 0, "value"]) ?: NULL);
    }

    $this->applyAddons($order, $this->submittedAddons($form_state));

    foreach (["sender", "receiver"] as $party) {
      $order->set("field_so_{$party}_province", $form_state->getValue("{$party}_province") ?: NULL);
      $order->set("field_so_{$party}_commune", $form_state->getValue("{$party}_commune") ?: NULL);
      $order->set("field_so_{$party}_district", $two_level ? NULL : ($form_state->getValue("{$party}_district") ?: NULL));
    }

    $result = $this->handleShipping->calculateFee($order, FALSE);

    if (empty($result["success"])) {
      $this->messenger()->addError((string) ($result["message"] ?? $this->t("Unknown error")));
      $form_state->set("shipping_quotes", []);

      return;
    }

    $form_state->set("shipping_quotes", $result["data"]["services"] ?? []);
  }

  /**
   * Trả về bảng cước vừa dựng lại sau khi hỏi hãng.
   *
   * @param array $form
   *   Mảng form đã dựng lại.
   * @param FormStateInterface $form_state
   *   Trạng thái form.
   *
   * @return array
   *   Phần form của bảng cước.
   */
  public function refreshQuotes(array $form, FormStateInterface $form_state): array {
    return NestedArray::getValue($form, self::QUOTES_PATH) ?? [];
  }

  /**
   * Dựng bảng cước các dịch vụ của lần hỏi gần nhất.
   *
   * @param FormStateInterface $form_state
   *   Trạng thái form.
   *
   * @return array
   *   Phần form của bảng cước, rỗng ruột khi chưa hỏi lần nào.
   */
  private function quoteTable(FormStateInterface $form_state): array {
    $container = [
      "#type" => "container",
      "#attributes" => ["class" => ["col-12"], "id" => "shipping-service-quotes"],
    ];

    $quotes = $form_state->get("shipping_quotes") ?? [];

    if (!$quotes) {
      return $container;
    }

    // Hãng để trống tên dịch vụ nên lấy nhãn từ chính danh sách giá trị hợp lệ.
    $labels = $this->orderService->allowedValues($this->entity->bundle(), "field_so_service");

    $container["table"] = [
      "#type" => "table",
      "#attributes" => ["class" => ["table", "table-sm", "table-bordered", "mb-0"]],
      "#header" => [
        $this->t("Service"),
        $this->t("Charged weight"),
        $this->t("Main fee"),
        $this->t("Total fee"),
      ],
      "#empty" => $this->t("The carrier did not quote any service."),
    ];

    foreach ($quotes as $index => $quote) {
      $code = (string) ($quote["service"] ?? "");

      $container["table"][$index] = [
        "service" => ["#plain_text" => $labels[$code] ?? ($quote["service_name"] ?: $code)],
        "price_weight" => ["#plain_text" => $this->number((string) ($quote["price_weight"] ?? 0)) . " gram"],
        "main_fee" => ["#plain_text" => $this->number((string) ($quote["main_fee"] ?? 0)) . " đ"],
        "total_fee" => ["#plain_text" => $this->number((string) ($quote["total_fee"] ?? 0)) . " đ"],
      ];
    }

    return $container;
  }

  /**
   * Dựng ba ô chọn địa chỉ liên tầng của một bên.
   *
   * @param FormStateInterface $form_state
   *   Trạng thái form.
   * @param string $party
   *   "sender" hoặc "receiver".
   * @param bool $two_level
   *   TRUE thì chỉ hiện tỉnh và phường/xã.
   *
   * @return array
   *   Phần form của khối địa chỉ.
   */
  private function addressGroup(FormStateInterface $form_state, string $party, bool $two_level): array {
    $path = self::ADDRESS_PATH[$party];
    $wrapper = "shipping-address-" . $party;

    $province = $this->addressValue($form_state, $party, "province");

    $districts = $two_level ? [] : $this->addressOptions->districts($province);
    $district = $this->keepValid($form_state, "{$party}_district", $districts, $two_level);

    $communes = $this->addressOptions->communes($two_level ? $province : $district, $two_level);
    $commune = $this->keepValid($form_state, "{$party}_commune", $communes, FALSE);

    $ajax = [
      "callback" => "::refreshAddress",
      "wrapper" => $wrapper,
      "event" => "change",
    ];

    $group = [
      "#type" => "container",
      "#attributes" => ["class" => ["row", "g-2", "shipping-address-group"], "id" => $wrapper],
    ];

    $group["{$party}_province"] = [
      "#type" => "select",
      "#title" => $this->t("Province/City"),
      "#options" => $this->addressOptions->provinces($two_level),
      "#empty_option" => $this->t("- Select -"),
      "#default_value" => $province,
      "#required" => TRUE,
      "#parents" => ["{$party}_province"],
      "#wrapper_attributes" => ["class" => [$two_level ? "col-md-6" : "col-md-4"]],
      "#ajax" => $ajax,
      "#address_path" => $path,
    ];

    if (!$two_level) {
      $group["{$party}_district"] = [
        "#type" => "select",
        "#title" => $this->t("District"),
        "#options" => $districts,
        "#empty_option" => $this->t("- Select -"),
        "#default_value" => $district,
        "#required" => TRUE,
        "#parents" => ["{$party}_district"],
        "#wrapper_attributes" => ["class" => ["col-md-4"]],
        "#ajax" => $ajax,
        "#address_path" => $path,
      ];
    }

    $group["{$party}_commune"] = [
      "#type" => "select",
      "#title" => $this->t("Ward/Commune"),
      "#options" => $communes,
      "#empty_option" => $this->t("- Select -"),
      "#default_value" => $commune,
      "#required" => TRUE,
      "#parents" => ["{$party}_commune"],
      "#wrapper_attributes" => ["class" => [$two_level ? "col-md-6" : "col-md-4"]],
    ];

    return $group;
  }

  /**
   * Dựng dòng thông tin của tài khoản kết nối đang chọn.
   *
   * @param FormStateInterface $form_state
   *   Trạng thái form.
   *
   * @return array
   *   Phần form của khối thông tin kết nối.
   */
  private function connectionInfo(FormStateInterface $form_state): array {
    $id = $this->currentValue($form_state, "field_so_config");
    $config = $id === "" ? NULL : $this->entityTypeManager->getStorage("taxonomy_term")->load($id);

    $lines = [];

    if ($config !== NULL) {
      $lines[] = $this->t("Customer code: @code", [
        "@code" => $config->hasField("field_si_code") ? (string) $config->get("field_si_code")->value : "",
      ]);
      $lines[] = $this->t("Contract: @contract", [
        "@contract" => $config->hasField("field_si_contract") && (string) $config->get("field_si_contract")->value !== ""
          ? (string) $config->get("field_si_contract")->value
          : $this->t("none"),
      ]);
      $lines[] = $this->t("Payment method: @method", [
        "@method" => $this->paymentLabel($config),
      ]);
    }

    $info = [
      "#type" => "container",
      "#attributes" => [
        "class" => ["col-12", "shipping-connection-info", "small", "text-muted"],
        "id" => "shipping-connection-info",
      ],
    ];

    foreach ($lines as $index => $line) {
      $info[$index] = [
        "#type" => "html_tag",
        "#tag" => "span",
        "#attributes" => ["class" => ["shipping-connection-item"]],
        "#value" => $line,
      ];
    }

    return $info;
  }

  /**
   * Kiểm tra dịch vụ và dịch vụ cộng thêm đang khai.
   *
   * @param FormStateInterface $form_state
   *   Trạng thái form.
   */
  private function validateService(FormStateInterface $form_state): void {
    $service = (string) $form_state->getValue("field_so_service", "");

    // Đơn cũ giữ nguyên dịch vụ đang có thì cho qua, chỉ chặn khi chọn mới
    // một dịch vụ không được tích ở kết nối.
    if ($service !== "" && $service !== $this->fieldValue("field_so_service")
      && !in_array($service, $this->contractServices($form_state), TRUE)) {
      $form_state->setErrorByName("field_so_service", $this->t("The service @code is not ticked on this connection.", ["@code" => $service]));
    }

    foreach ($this->submittedAddons($form_state) as $code => $values) {
      foreach (VnpostCatalog::ADDONS[$code]["props"] ?? [] as $prop => $definition) {
        if (empty($definition["required"]) || !empty($definition["fixed"])) {
          continue;
        }

        $value = $values[$prop] ?? "";
        $missing = $definition["type"] === "number" ? (float) $value <= 0 : $value === "";

        if ($missing) {
          $form_state->setErrorByName("addons][{$code}][props][{$prop}", $this->t("@prop of the addon service @addon must not be empty.", [
            "@prop" => $definition["label"],
            "@addon" => VnpostCatalog::ADDONS[$code]["label"],
          ]));
        }
      }
    }
  }

  /**
   * Dịch vụ (SPDV) đã tích ở term kết nối đang chọn.
   *
   * @param FormStateInterface $form_state
   *   Trạng thái form.
   *
   * @return string[]
   *   Mã dịch vụ, rỗng khi chưa chọn kết nối hoặc kết nối chưa tích dịch vụ.
   */
  private function contractServices(FormStateInterface $form_state): array {
    $id = $this->currentValue($form_state, "field_so_config");
    $config = $id === "" ? NULL : $this->entityTypeManager->getStorage("taxonomy_term")->load($id);

    return $config instanceof FieldableEntityInterface
      ? GetConfigShipping::values($config, "field_si_services")
      : [];
  }

  /**
   * Dịch vụ GTGT đang khai, ưu tiên dữ liệu vừa gửi lên.
   *
   * @param FormStateInterface $form_state
   *   Trạng thái form.
   *
   * @return array
   *   Mảng mã dịch vụ GTGT => [mã thuộc tính => giá trị].
   */
  private function currentAddons(FormStateInterface $form_state): array {
    if (is_array($form_state->getValue("addons"))) {
      return $this->submittedAddons($form_state);
    }

    return VnpostCatalog::decode(
      $this->fieldValue("field_so_addons"),
      (float) $this->fieldValue("field_so_cod"),
      (float) $this->fieldValue("field_so_insurance"),
    );
  }

  /**
   * Đọc các dịch vụ GTGT đã tích trên form.
   *
   * Bảng chỉ có dòng cho những dịch vụ GTGT của SPDV đang chọn nên giá trị
   * gửi lên cũng chỉ gồm những mã đó.
   *
   * @param FormStateInterface $form_state
   *   Trạng thái form.
   *
   * @return array
   *   Mảng mã dịch vụ GTGT => [mã thuộc tính => giá trị].
   */
  private function submittedAddons(FormStateInterface $form_state): array {
    $submitted = $form_state->getValue("addons");
    $addons = [];

    foreach (is_array($submitted) ? $submitted : [] as $code => $values) {
      if (!isset(VnpostCatalog::ADDONS[$code]) || !is_array($values) || empty($values["enabled"])) {
        continue;
      }

      $props = [];

      foreach (VnpostCatalog::ADDONS[$code]["props"] as $prop => $definition) {
        if (!empty($definition["fixed"])) {
          continue;
        }

        $value = trim((string) ($values["props"][$prop] ?? ""));

        $value = match ($definition["type"]) {
          "flag" => $value !== "" && $value !== "0" ? "1" : "0",
          "number" => $value === "" ? "" : (string) (int) $value,
          "date" => $this->carrierDate($value),
          default => $value,
        };

        if ($value !== "") {
          $props[$prop] = $value;
        }
      }

      $addons[(string) $code] = $props;
    }

    return $addons;
  }

  /**
   * Ghi dịch vụ GTGT vào đơn, kèm COD và khai giá ở hai field riêng.
   *
   * Danh sách đơn, trang chi tiết và webhook vẫn đọc tiền thu hộ và giá trị
   * khai giá từ field_so_cod và field_so_insurance nên phải chép sang.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $order
   *   Đơn hàng.
   * @param array $addons
   *   Mảng mã dịch vụ GTGT => [mã thuộc tính => giá trị].
   */
  private function applyAddons(FieldableEntityInterface $order, array $addons): void {
    $order->set("field_so_addons", $addons === [] ? NULL : json_encode($addons, JSON_UNESCAPED_UNICODE));
    $order->set("field_so_cod", $addons[VnpostCatalog::ADDON_COD][VnpostCatalog::PROP_COD_AMOUNT] ?? NULL);
    $order->set("field_so_insurance", $addons[VnpostCatalog::ADDON_INSURANCE][VnpostCatalog::PROP_INSURANCE_VALUE] ?? NULL);
  }

  /**
   * Đổi ngày của ô chọn ngày (Y-m-d) sang dạng dd/mm/yyyy hãng dùng.
   *
   * @param string $value
   *   Ngày dạng Y-m-d.
   *
   * @return string
   *   Ngày dạng d/m/Y, rỗng khi không đọc được.
   */
  private function carrierDate(string $value): string {
    $date = \DateTime::createFromFormat("!Y-m-d", $value);

    return $date === FALSE ? "" : $date->format("d/m/Y");
  }

  /**
   * Đổi ngày dạng dd/mm/yyyy đã lưu về dạng Y-m-d cho ô chọn ngày.
   *
   * @param string $value
   *   Ngày dạng d/m/Y.
   *
   * @return string
   *   Ngày dạng Y-m-d, rỗng khi không đọc được.
   */
  private function isoDate(string $value): string {
    $date = \DateTime::createFromFormat("!d/m/Y", $value);

    return $date === FALSE ? "" : $date->format("Y-m-d");
  }

  /**
   * Dòng gợi ý nhỏ trong một thẻ.
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $text
   *   Nội dung gợi ý.
   *
   * @return array
   *   Phần tử render.
   */
  private function hint($text): array {
    return [
      "#type" => "html_tag",
      "#tag" => "div",
      "#attributes" => ["class" => ["col-12", "small", "text-muted"]],
      "#value" => $text,
    ];
  }

  /**
   * Nhãn loại thanh toán theo hợp đồng của term kết nối.
   *
   * Term cũ chưa khai loại thanh toán thì coi như thanh toán ngay, khớp với
   * giá trị mặc định của field.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $config
   *   Term cấu hình kết nối.
   *
   * @return string
   *   Nhãn hiển thị của loại thanh toán.
   */
  private function paymentLabel(FieldableEntityInterface $config): string {
    if (!$config->hasField("field_si_type_payment")) {
      return "";
    }

    $field = $config->get("field_si_type_payment");
    $value = ((string) $field->value) ?: GetConfigShipping::PAYMENT_DEFAULT;
    $options = $field->getFieldDefinition()
      ->getFieldStorageDefinition()
      ->getSetting("allowed_values");

    return (string) ($options[$value] ?? $value);
  }

  /**
   * Đánh lại thứ tự các trường trong một thẻ theo đúng thứ tự khai báo.
   *
   * Widget mang sẵn #weight của form display nên nếu không đánh lại thì các
   * tiêu đề nhỏ và ô tính toán không có weight sẽ bị đẩy hết lên đầu thẻ.
   *
   * @param array $card
   *   Thẻ vừa dựng.
   *
   * @return array
   *   Thẻ đã có thứ tự ổn định.
   */
  private function ordered(array $card): array {
    $weight = 0;

    foreach (array_keys($card["body"]) as $key) {
      if (str_starts_with((string) $key, "#") || !is_array($card["body"][$key])) {
        continue;
      }

      $card["body"][$key]["#weight"] = $weight++;
    }

    return $card;
  }

  /**
   * Dựng khung thẻ Bootstrap cho một nhóm trường.
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $title
   *   Tiêu đề thẻ.
   *
   * @return array
   *   Phần form của thẻ, các trường xếp vào khoá "body".
   */
  private function card($title): array {
    return [
      "#type" => "container",
      "#attributes" => ["class" => ["card", "mb-3", "shipping-card"]],
      "header" => [
        "#type" => "html_tag",
        "#tag" => "div",
        "#attributes" => ["class" => ["card-header", "fw-bold"]],
        "#value" => $title,
      ],
      "body" => [
        "#type" => "container",
        "#attributes" => ["class" => ["card-body", "row", "g-2"]],
      ],
    ];
  }

  /**
   * Lấy widget của một field ra khỏi form và chỉnh lại nhãn, ràng buộc.
   *
   * Widget bọc giá trị ở nhiều tầng khác nhau tuỳ kiểu field nên phải dò đúng
   * tầng chứa ô nhập rồi mới ghi đè thuộc tính.
   *
   * Riêng "#wrapper_attributes" gắn lên khung ngoài cùng của widget chứ không
   * gắn vào ô nhập, vì khung ngoài mới là con trực tiếp của hàng lưới; đặt lớp
   * cột vào trong thì Bootstrap không xếp được hai trường trên một hàng.
   *
   * @param array $form
   *   Mảng form, widget sẽ bị gỡ khỏi đây.
   * @param string $field
   *   Tên field.
   * @param array $settings
   *   Thuộc tính cần ghi đè lên ô nhập.
   *
   * @return array
   *   Widget đã chỉnh, rỗng nếu form không có field này.
   */
  private function tune(array &$form, string $field, array $settings): array {
    if (!isset($form[$field])) {
      return [];
    }

    $element = $form[$field];
    unset($form[$field]);

    if (isset($settings["#wrapper_attributes"])) {
      $element["#attributes"] = NestedArray::mergeDeep(
        $element["#attributes"] ?? [],
        $settings["#wrapper_attributes"],
      );
      unset($settings["#wrapper_attributes"]);
    }

    foreach ([["widget", 0, "value"], ["widget", 0, "target_id"], ["widget", "value"], ["widget"]] as $path) {
      $exists = FALSE;
      NestedArray::getValue($element, $path, $exists);

      if (!$exists) {
        continue;
      }

      $input = &NestedArray::getValue($element, $path);

      if (($settings["#type"] ?? "") === "radios") {
        unset($input["#options"]["_none"], $input["#empty_option"], $input["#empty_value"]);
      }

      foreach ($settings as $key => $value) {
        if ($key === "#attributes" || $key === "#wrapper_attributes") {
          $input[$key] = NestedArray::mergeDeep($input[$key] ?? [], $value);
          continue;
        }

        $input[$key] = $value;
      }

      unset($input);
      break;
    }

    return $element;
  }

  /**
   * Dựng một nút gọi lệnh sang hãng dựa trên nút lưu mặc định.
   *
   * @param array $submit
   *   Nút lưu do EntityForm dựng sẵn.
   * @param string $action
   *   Mã lệnh sẽ chạy sau khi lưu.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   Nhãn nút.
   * @param string $class
   *   Lớp Bootstrap của nút.
   * @param int $weight
   *   Thứ tự hiển thị.
   *
   * @return array
   *   Phần form của nút.
   */
  private function carrierButton(array $submit, string $action, $label, string $class, int $weight): array {
    $submit["#value"] = $label;
    $submit["#weight"] = $weight;
    $submit["#shipping_action"] = $action;
    $submit["#submit"] = [...($submit["#submit"] ?? []), "::callCarrier"];
    $submit["#attributes"]["class"] = ["btn", $class];

    // Chụp dữ liệu cũ trước bước lưu, để hãng từ chối thì còn trả đơn về.
    if ($action === "correct") {
      array_unshift($submit["#submit"], "::rememberPrevious");
    }

    if ($action === "create") {
      $submit["#button_type"] = "primary";
    }

    return $submit;
  }

  /**
   * Xác định đơn đang khai theo bộ địa chỉ hai cấp hay ba cấp.
   *
   * Đơn mới luôn dùng bộ hai cấp như màn khai đơn của hãng; đơn cũ đang giữ
   * địa chỉ ba cấp thì vẫn hiện đủ ba ô để không làm hỏng dữ liệu đã có.
   *
   * @param FormStateInterface $form_state
   *   Trạng thái form.
   *
   * @return bool
   *   TRUE nếu khai theo bộ hai cấp.
   */
  private function twoLevel(FormStateInterface $form_state): bool {
    if ($form_state->has("shipping_two_level")) {
      return (bool) $form_state->get("shipping_two_level");
    }

    $two_level = TRUE;

    if (!$this->entity->isNew()) {
      $stored = $this->addressOptions->isTwoLevel($this->fieldValue("field_so_receiver_province"))
        ?? $this->addressOptions->isTwoLevel($this->fieldValue("field_so_sender_province"));

      if ($stored !== NULL) {
        $two_level = $stored;
      }
    }

    $form_state->set("shipping_two_level", $two_level);

    return $two_level;
  }

  /**
   * Giữ lại lựa chọn địa chỉ nếu nó còn nằm trong danh sách vừa dựng lại.
   *
   * Đổi tỉnh là danh sách phường/xã đổi theo, giá trị cũ không còn hợp lệ nên
   * phải xoá khỏi cả dữ liệu người dùng gửi lên, nếu không ô select sẽ giữ
   * nguyên lựa chọn cũ và bị chặn ở bước kiểm tra giá trị hợp lệ.
   *
   * @param FormStateInterface $form_state
   *   Trạng thái form.
   * @param string $key
   *   Tên ô, ví dụ "sender_commune".
   * @param array $options
   *   Danh sách lựa chọn hiện có.
   * @param bool $skip
   *   TRUE thì bỏ hẳn ô này, dùng cho cấp quận/huyện của bộ địa chỉ hai cấp.
   *
   * @return string
   *   Giá trị còn hợp lệ, rỗng nếu phải chọn lại.
   */
  private function keepValid(FormStateInterface $form_state, string $key, array $options, bool $skip): string {
    if ($skip) {
      return "";
    }

    $submitted = $form_state->getValue($key);
    $value = $submitted !== NULL ? (string) $submitted : $this->fieldValue("field_so_" . $key);

    if ($value !== "" && isset($options[$value])) {
      return $value;
    }

    $input = $form_state->getUserInput();

    if (array_key_exists($key, $input)) {
      unset($input[$key]);
      $form_state->setUserInput($input);
    }

    $form_state->setValue($key, "");

    return "";
  }

  /**
   * Giá trị đang chọn của một ô địa chỉ.
   *
   * @param FormStateInterface $form_state
   *   Trạng thái form.
   * @param string $party
   *   "sender" hoặc "receiver".
   * @param string $level
   *   "province", "district" hoặc "commune".
   *
   * @return string
   *   Id địa chỉ, rỗng nếu chưa chọn.
   */
  private function addressValue(FormStateInterface $form_state, string $party, string $level): string {
    $submitted = $form_state->getValue("{$party}_{$level}");

    if ($submitted !== NULL) {
      return (string) $submitted;
    }

    return $this->fieldValue("field_so_{$party}_{$level}");
  }

  /**
   * Giá trị đang chọn của một field, ưu tiên dữ liệu vừa gửi lên.
   *
   * @param FormStateInterface $form_state
   *   Trạng thái form.
   * @param string $field
   *   Tên field.
   *
   * @return string
   *   Giá trị hiện tại, rỗng nếu chưa có.
   */
  private function currentValue(FormStateInterface $form_state, string $field): string {
    $submitted = $form_state->getValue($field);

    if ($submitted !== NULL && !is_array($submitted)) {
      return (string) $submitted;
    }

    return $this->fieldValue($field);
  }

  /**
   * Đọc một giá trị đơn trị của entity đang sửa.
   *
   * @param string $field
   *   Tên field.
   *
   * @return string
   *   Giá trị, rỗng nếu field trống hoặc không tồn tại.
   */
  private function fieldValue(string $field): string {
    if (!$this->entity->hasField($field) || $this->entity->get($field)->isEmpty()) {
      return "";
    }

    $item = $this->entity->get($field)->first();

    return (string) ($item->target_id ?? $item->value ?? "");
  }

  /**
   * Định dạng số theo kiểu tiền tệ trong nước.
   *
   * @param string $value
   *   Giá trị thô.
   *
   * @return string
   *   Chuỗi đã chấm phân cách hàng nghìn.
   */
  private function number(string $value): string {
    return number_format((float) $value, 0, ",", ".");
  }

  /**
   * Định dạng mốc thời gian đồng bộ gần nhất.
   *
   * @param string $value
   *   Dấu thời gian dạng Unix.
   *
   * @return string
   *   Chuỗi ngày giờ, gạch ngang nếu chưa từng đồng bộ.
   */
  private function timestamp(string $value): string {
    if ($value === "" || (int) $value === 0) {
      return "—";
    }

    return DrupalDateTime::createFromTimestamp((int) $value)->format("d/m/Y H:i");
  }

}
