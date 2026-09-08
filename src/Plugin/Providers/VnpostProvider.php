<?php

namespace Drupal\shipping_integration\Plugin\Providers;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\shipping_integration\Exception\ShippingTokenException;
use Drupal\shipping_integration\ShippingProvidersAttribute;
use Drupal\shipping_integration\ShippingProvidersInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Tích hợp chuyển phát MyVNPost của Tổng công ty Bưu điện Việt Nam.
 *
 * Toàn bộ endpoint nằm trên cùng một host cấu hình trong term kết nối
 * (UAT: https://my-uat.vnpost.vn/MYVNP_API, thật: https://connect-my.vnpost.vn)
 * và xác thực bằng header "token" chứ không phải Authorization Bearer.
 *
 * Quy ước phản hồi của MyVNPost không đồng nhất nên mỗi nhóm được bóc riêng:
 * - /GetAccessToken trả phong bì {success, errorMessage, token}.
 * - Nhóm danh mục địa chỉ trả thẳng mảng bản ghi.
 * - /CreateOrder trả thẳng bản ghi đơn hàng, đôi khi bọc trong "data".
 * - /getOrder trả mảng lồng [{results: [...]}], /GetListOrder trả {results: []}.
 * - Nhóm hiệu chỉnh/hủy trả {Type, Message, CaseId} với Type = "00" là thành
 *   công, các mã khác là từ chối hoặc chờ xử lý chứ không phải lỗi hệ thống.
 *
 * Plugin chỉ phục vụ đơn hàng trong nước; các endpoint quốc tế của MyVNPost
 * cố tình không được cài đặt.
 *
 * @see https://my-uat.vnpost.vn/static/
 */
#[ShippingProvidersAttribute(
  id: "vnpost",
  label: new TranslatableMarkup("VN-Post"),
)]
class VnpostProvider extends PluginBase implements ShippingProvidersInterface, ContainerFactoryPluginInterface {

  /**
   * Mã dịch vụ cộng thêm phát hàng thu tiền hộ.
   */
  private const ADDON_COD = "GTG021";

  /**
   * Mã thuộc tính số tiền thu hộ của dịch vụ COD.
   */
  private const PROP_COD_AMOUNT = "PROP0018";

  /**
   * Mã dịch vụ cộng thêm khai giá hàng hóa.
   */
  private const ADDON_INSURANCE = "GTG008";

  /**
   * Mã thuộc tính giá trị khai giá.
   */
  private const PROP_INSURANCE_VALUE = "PROP0026";

  /**
   * Giá trị mã quận/huyện bắt buộc khi khai địa chỉ hai cấp.
   */
  private const DISTRICT_TWO_LEVEL = "VNPOST";

  /**
   * Phạm vi vận chuyển trong nước khi tính cước.
   */
  private const SCOPE_DOMESTIC = 1;

  /**
   * Số bưu gửi tối đa cho một lần gọi in vận đơn.
   */
  private const LABEL_BATCH_SIZE = 100;

  /**
   * Số bản ghi tối đa MyVNPost cho phép lấy mỗi trang.
   */
  private const PAGE_SIZE = 500;

  /**
   * Chặn trên số trang khi kéo danh sách đơn, phòng khi hãng trả sai tổng.
   */
  private const MAX_PAGES = 100;

  /**
   * Timeout (giây) cho các lệnh nặng: tạo đơn, kéo danh mục, in vận đơn.
   */
  private const LONG_TIMEOUT = 120;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected ClientInterface $client,
    protected LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get("http_client"),
      $container->get("logger.channel.shipping_integration"),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getToken(array $config): array {
    $response = $this->request($config, "/GetAccessToken", [], [
      "username" => (string) ($config["shipping_username"] ?? ""),
      "password" => (string) ($config["shipping_password"] ?? ""),
      "customerCode" => (string) ($config["shipping_code"] ?? ""),
    ], "POST", FALSE);

    if (!is_array($response) || empty($response["success"])) {
      $message = is_array($response)
        ? (string) ($response["errorMessage"] ?? "")
        : "";

      throw new \DomainException(
        $message !== "" ? $message : "VN-Post không cấp được token"
      );
    }

    $token = (string) ($response["token"] ?? "");

    if ($token === "") {
      throw new \DomainException("VN-Post không trả về token");
    }

    return [
      "success" => TRUE,
      "token" => $token,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function synchronizeAddresses(array $config): array {
    $data = [];

    // Mã huyện cũ ánh xạ sang mã tỉnh cũ. Endpoint /getAllCommune chỉ trả về
    // mã huyện nên không có bảng này thì xã cũ mất liên kết tới tỉnh.
    $district_provinces = [];

    // Thứ tự tỉnh trước, huyện sau, xã cuối để phía lưu trữ tra được cha của
    // từng bản ghi ngay trong cùng một lượt chạy.
    foreach ($this->request($config, "/getAllProvince", [], NULL, "GET", TRUE, self::LONG_TIMEOUT) ?: [] as $row) {
      $data[] = [
        "bundle" => "province",
        "is_new" => 0,
        "code" => (string) ($row["provinceCode"] ?? ""),
        "label" => (string) ($row["provinceName"] ?? ""),
        "province_code" => "",
        "district_code" => "",
      ];
    }

    foreach ($this->request($config, "/getAllDistrict", [], NULL, "GET", TRUE, self::LONG_TIMEOUT) ?: [] as $row) {
      $code = (string) ($row["districtCode"] ?? "");
      $province_code = (string) ($row["provinceCode"] ?? "");
      $district_provinces[$code] = $province_code;

      $data[] = [
        "bundle" => "district",
        "is_new" => 0,
        "code" => $code,
        "label" => (string) ($row["districtName"] ?? ""),
        "province_code" => $province_code,
        "district_code" => "",
      ];
    }

    foreach ($this->request($config, "/getAllCommune", [], NULL, "GET", TRUE, self::LONG_TIMEOUT) ?: [] as $row) {
      $district_code = (string) ($row["districtCode"] ?? "");

      $data[] = [
        "bundle" => "commune",
        "is_new" => 0,
        "code" => (string) ($row["communeCode"] ?? ""),
        "label" => (string) ($row["communeName"] ?? ""),
        "province_code" => (string) ($row["provinceCode"] ?? $district_provinces[$district_code] ?? ""),
        "district_code" => $district_code,
      ];
    }

    foreach ($this->request($config, "/getNewProvinceAll", [], NULL, "GET", TRUE, self::LONG_TIMEOUT) ?: [] as $row) {
      $data[] = [
        "bundle" => "province",
        "is_new" => 1,
        "code" => (string) ($row["provinceCode"] ?? ""),
        "label" => (string) ($row["provinceName"] ?? ""),
        "province_code" => "",
        "district_code" => "",
      ];
    }

    // Danh mục hai cấp không còn quận/huyện: cha của xã là tỉnh, nhưng
    // MyVNPost vẫn đặt mã tỉnh dưới khoá "districtCode" của bản ghi cũ.
    foreach ($this->request($config, "/getNewCommuneAll", [], NULL, "GET", TRUE, self::LONG_TIMEOUT) ?: [] as $row) {
      $data[] = [
        "bundle" => "commune",
        "is_new" => 1,
        "code" => (string) ($row["communeCode"] ?? ""),
        "label" => (string) ($row["communeName"] ?? ""),
        "province_code" => (string) ($row["provinceCode"] ?? $row["districtCode"] ?? ""),
        "district_code" => "",
      ];
    }

    $data = array_values(array_filter($data, static fn (array $row): bool => $row["code"] !== ""));

    return [
      "success" => TRUE,
      "data" => $data,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function createOrder(array $config, array $order): array {
    $payload = [
      "orderCreationStatus" => !empty($order["draft"]) ? 0 : 1,
      "type" => (string) ($order["type"] ?? "GUI"),
      "customerCode" => (string) ($config["shipping_code"] ?? ""),
      "contractCode" => $this->orNull($config["shipping_contract"] ?? ""),
      "informationOrder" => $this->buildInformationOrder($order),
    ];

    $response = $this->request($config, "/CreateOrder", [], $payload, "POST", TRUE, self::LONG_TIMEOUT);

    return $this->orderRecord($response, "VN-Post không tạo được đơn hàng");
  }

  /**
   * {@inheritdoc}
   */
  public function updateOrder(array $config, array $order): array {
    $original_id = (string) ($order["original_id"] ?? "");

    if ($original_id === "") {
      throw new \DomainException("Đơn hàng chưa có ID gốc để hiệu chỉnh");
    }

    $response = $this->request(
      $config,
      "/orderCorrection",
      [],
      $this->buildCorrection($config, $order),
      "POST",
      TRUE,
      self::LONG_TIMEOUT
    );

    return $this->caseResult($response);
  }

  /**
   * {@inheritdoc}
   */
  public function cancelOrder(array $config, string $original_id): array {
    if ($original_id === "") {
      throw new \DomainException("Đơn hàng chưa có ID gốc để hủy");
    }

    $response = $this->request($config, "/orderCancel", [], [
      "OriginalId" => $original_id,
    ]);

    return $this->caseResult($response);
  }

  /**
   * {@inheritdoc}
   */
  public function approvalResult(array $config, string $original_id, string $case_id): array {
    $response = $this->request($config, "/orderCorrection/updateCase", [], NULL, "GET", TRUE, 30, [
      "OriginalId" => $original_id,
      "CaseId" => $case_id,
    ]);

    // Endpoint này trả danh sách kết quả, mỗi phần tử ứng với một ID gốc.
    $rows = is_array($response) && array_is_list($response) ? $response : [$response];

    return array_values(array_filter(array_map(
      fn ($row): array => is_array($row) ? $this->caseResult($row) : [],
      $rows
    )));
  }

  /**
   * {@inheritdoc}
   */
  public function calculateFee(array $config, array $order): array {
    $sender = $order["sender"] ?? [];
    $receiver = $order["receiver"] ?? [];
    $two_level = !empty($order["is_new_address"]);

    $payload = [
      "scope" => self::SCOPE_DOMESTIC,
      "customerCode" => (string) ($config["shipping_code"] ?? ""),
      "contractNumber" => $this->orNull($config["shipping_contract"] ?? ""),
      "data" => [
        "senderProvinceCode" => (string) ($sender["province_code"] ?? ""),
        "senderDistrictCode" => $this->districtCode($sender, $two_level),
        "senderCommuneCode" => (string) ($sender["commune_code"] ?? ""),
        "receiverProvinceCode" => (string) ($receiver["province_code"] ?? ""),
        "receiverDistrictCode" => $this->districtCode($receiver, $two_level),
        "receiverCommuneCode" => (string) ($receiver["commune_code"] ?? ""),
        "receiverNational" => "VN",
        "receiverCity" => NULL,
        "receiverPostCode" => "",
        "orgCodeAccept" => $this->orNull($order["org_accept"] ?? ""),
        "weight" => (int) ($order["weight"] ?? 0),
        "width" => $this->orNull($order["width"] ?? ""),
        "length" => $this->orNull($order["length"] ?? ""),
        "height" => $this->orNull($order["height"] ?? ""),
        "serviceCode" => $this->orNull($order["service"] ?? ""),
        "addonService" => $this->buildAddonService($order),
        "additionRequest" => [],
        "vehicle" => (string) ($order["vehicle"] ?? "BO"),
      ],
    ];

    $response = $this->request($config, "/ServicesCharge", [], $payload, "POST", TRUE, self::LONG_TIMEOUT);

    if (!is_array($response)) {
      throw new \DomainException("VN-Post không tính được cước phí");
    }

    $response = is_array($response["data"] ?? NULL) ? $response["data"] : $response;

    // Hãng luôn trả về một mảng bảng cước, mỗi dịch vụ một dòng: khai rõ mã
    // dịch vụ thì mảng chỉ có đúng dòng đó, bỏ trống mã thì có cước của mọi
    // dịch vụ đang mở cho tài khoản.
    $records = array_values(array_filter(
      array_is_list($response) ? $response : [$response],
      "is_array",
    ));

    $wanted = (string) ($order["service"] ?? "");
    $matched = [];

    foreach ($records as $record) {
      if ($wanted === "" || (string) ($record["serviceCode"] ?? "") === $wanted) {
        $matched = $record;
        break;
      }
    }

    $quote = static fn(array $record): array => [
      "service" => (string) ($record["serviceCode"] ?? ""),
      "service_name" => (string) ($record["serviceName"] ?? ""),
      "main_fee" => (float) ($record["mainFee"] ?? 0),
      "vas_fee" => (float) ($record["vasfee"] ?? $record["vasFee"] ?? 0),
      "total_fee" => (float) ($record["totalFee"] ?? 0),
      "price_weight" => (int) ($record["priceWeight"] ?? 0),
      "dim_weight" => (int) ($record["weightConvert"] ?? 0),
      "addon_service" => $record["addonService"] ?? [],
    ];

    return $quote($matched) + ["services" => array_map($quote, $records)];
  }

  /**
   * {@inheritdoc}
   */
  public function getOrder(array $config, string $code, string $type = "1"): array {
    $response = $this->request($config, "/getOrder", [], NULL, "GET", TRUE, 30, [
      "type" => $type,
      "code" => $code,
    ]);

    foreach ($this->flattenResults($response) as $row) {
      return $row;
    }

    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function listOrders(array $config, array $params): array {
    $size = min((int) ($params["size"] ?? self::PAGE_SIZE), self::PAGE_SIZE);
    $size = $size > 0 ? $size : self::PAGE_SIZE;

    $query = [
      "lastUpdateFrom" => $this->apiDate($params["from"] ?? ""),
      "lastUpdateTo" => $this->apiDate($params["to"] ?? ""),
      "type" => (string) ($params["type"] ?? "GUI"),
      "size" => $size,
      // Khoá cứng đơn trong nước, module không phục vụ đơn quốc tế.
      "isInternational" => "false",
    ];

    $orders = [];
    $page = (int) ($params["page"] ?? 0);
    $single_page = isset($params["page"]);

    for ($index = 0; $index < self::MAX_PAGES; $index++) {
      $response = $this->request(
        $config,
        "/GetListOrder",
        [],
        NULL,
        "GET",
        TRUE,
        self::LONG_TIMEOUT,
        $query + ["page" => $page]
      );

      $rows = $this->flattenResults($response);
      $orders = array_merge($orders, $rows);

      // Trang cuối cùng luôn ngắn hơn kích thước yêu cầu, dừng ở đó để khỏi
      // gọi thừa một lượt trả về rỗng.
      if ($single_page || count($rows) < $size) {
        break;
      }

      $page++;
    }

    return $orders;
  }

  /**
   * {@inheritdoc}
   */
  public function orderHistory(array $config, string $code, string $type = "1"): array {
    $response = $this->request($config, "/GetStatusHistoryOrder", [], NULL, "GET", TRUE, 30, [
      "type" => $type,
      "code" => $code,
    ]);

    $data = is_array($response) ? ($response["data"] ?? $response) : [];
    $history = $data["statusHistory"] ?? [];

    $result = [];

    foreach (is_array($history) ? $history : [] as $row) {
      $result[] = [
        "date" => (string) ($row["createdDate"] ?? ""),
        "hour" => (string) ($row["createdHour"] ?? ""),
        "status_code" => (string) ($row["statusCode"] ?? ""),
        "status_name" => (string) ($row["statusName"] ?? ""),
      ];
    }

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function printLabel(array $config, array $item_codes): array {
    $item_codes = array_values(array_unique(array_filter($item_codes)));

    if (empty($item_codes)) {
      throw new \DomainException("Không có số hiệu bưu gửi để in vận đơn");
    }

    $files = [];

    // Tài liệu giới hạn 100 bưu gửi mỗi lần gọi.
    foreach (array_chunk($item_codes, self::LABEL_BATCH_SIZE) as $chunk) {
      $response = $this->request($config, "/exportListReport", [], $chunk, "POST", TRUE, self::LONG_TIMEOUT);

      foreach (is_array($response) ? $response : [$response] as $item) {
        $binary = $this->toBinary($item);

        if ($binary !== "") {
          $files[] = $binary;
        }
      }
    }

    if (empty($files)) {
      throw new \DomainException("VN-Post chưa sinh được file vận đơn");
    }

    return $files;
  }

  /**
   * {@inheritdoc}
   */
  public function confirmDraft(array $config, string $code, string $type = "1"): array {
    $response = $this->request($config, "/createOrderByDraft", [], NULL, "GET", TRUE, self::LONG_TIMEOUT, [
      "type" => $type,
      "code" => $code,
    ]);

    return $this->orderRecord($response, "VN-Post không phát hành được đơn nháp");
  }

  /**
   * {@inheritdoc}
   */
  public function deleteDraft(array $config, string $code, string $type = "1"): array {
    $response = $this->request($config, "/deleteOrderByDraft", [], NULL, "GET", TRUE, 30, [
      "type" => $type,
      "code" => $code,
    ]);

    if (!is_array($response) || empty($response["success"])) {
      $message = is_array($response) ? (string) ($response["message"] ?? "") : "";

      throw new \DomainException(
        $message !== "" ? $message : "VN-Post không xóa được đơn nháp"
      );
    }

    return [
      "success" => TRUE,
      "message" => (string) ($response["message"] ?? ""),
    ];
  }

  /**
   * Dựng khối informationOrder của lệnh tạo đơn.
   *
   * @param array $order
   *   Đơn hàng đã chuẩn hoá.
   *
   * @return array
   *   Khối informationOrder.
   */
  private function buildInformationOrder(array $order): array {
    $sender = $order["sender"] ?? [];
    $receiver = $order["receiver"] ?? [];
    $two_level = !empty($order["is_new_address"]);

    $this->assertParty($sender, "người gửi");
    $this->assertParty($receiver, "người nhận");

    if (empty($order["service"])) {
      throw new \DomainException("Đơn hàng chưa chọn dịch vụ vận chuyển");
    }

    if ((int) ($order["weight"] ?? 0) <= 0) {
      throw new \DomainException("Đơn hàng chưa khai khối lượng");
    }

    return [
      "senderName" => (string) ($sender["name"] ?? ""),
      "senderPhone" => (string) ($sender["phone"] ?? ""),
      "senderEmail" => $this->orNull($sender["email"] ?? ""),
      "senderAddress" => (string) ($sender["address"] ?? ""),
      "senderProvinceCode" => (string) ($sender["province_code"] ?? ""),
      "senderDistrictCode" => $this->districtCode($sender, $two_level),
      "senderCommuneCode" => (string) ($sender["commune_code"] ?? ""),
      "receiverName" => (string) ($receiver["name"] ?? ""),
      "receiverPhone" => (string) ($receiver["phone"] ?? ""),
      "receiverEmail" => $this->orNull($receiver["email"] ?? ""),
      "receiverAddress" => (string) ($receiver["address"] ?? ""),
      "receiverProvinceCode" => (string) ($receiver["province_code"] ?? ""),
      "receiverDistrictCode" => $this->districtCode($receiver, $two_level),
      "receiverCommuneCode" => (string) ($receiver["commune_code"] ?? ""),
      "serviceCode" => (string) $order["service"],
      "addonService" => $this->buildAddonService($order),
      "additionRequest" => [],
      "orgCodeCollect" => $this->orNull($order["org_collect"] ?? ""),
      "orgCodeAccept" => $this->orNull($order["org_accept"] ?? ""),
      "saleOrderCode" => $this->orNull($order["sale_code"] ?? ""),
      "contentNote" => (string) ($order["content"] ?? ""),
      "weight" => (string) (int) $order["weight"],
      "width" => $this->orNull($order["width"] ?? ""),
      "length" => $this->orNull($order["length"] ?? ""),
      "height" => $this->orNull($order["height"] ?? ""),
      "vehicle" => (string) ($order["vehicle"] ?? "BO"),
      "sendType" => (string) ($order["send_type"] ?? "1"),
      "isBroken" => !empty($order["is_broken"]) ? "1" : "0",
      "deliveryTime" => $this->orNull($order["delivery_time"] ?? ""),
      "deliveryRequire" => $this->orNull($order["delivery_require"] ?? ""),
      "deliveryInstruction" => $this->orNull($order["delivery_note"] ?? ""),
    ];
  }

  /**
   * Dựng payload của lệnh hiệu chỉnh đơn hàng.
   *
   * Nhóm endpoint hiệu chỉnh dùng quy ước đặt tên và cấu trúc lồng khác hẳn
   * lệnh tạo đơn nên không dùng lại được buildInformationOrder().
   *
   * @param array $config
   *   Cấu hình kết nối.
   * @param array $order
   *   Đơn hàng đã chuẩn hoá.
   *
   * @return array
   *   Payload của /orderCorrection.
   */
  private function buildCorrection(array $config, array $order): array {
    $sender = $order["sender"] ?? [];
    $receiver = $order["receiver"] ?? [];
    $two_level = !empty($order["is_new_address"]);

    $addons = [];

    if ((float) ($order["cod"] ?? 0) > 0) {
      $addons[] = [
        "ServiceCode" => self::ADDON_COD,
        "Props" => [
          [
            "PropCode" => self::PROP_COD_AMOUNT,
            "PropValue" => (string) (int) $order["cod"],
          ],
        ],
      ];
    }

    return [
      "OriginalId" => (string) $order["original_id"],
      "SourceCode" => "MYVNP",
      "ServiceCode" => (string) ($order["service"] ?? ""),
      "ItemCode" => (string) ($order["item_code"] ?? ""),
      // 02 = hiệu chỉnh thông tin đơn hàng.
      "AffairType" => "02",
      "OrderCode" => (string) ($order["item_code"] ?? ""),
      "FlagConfig" => "1",
      "Contents" => (string) ($order["content"] ?? ""),
      "Vehicle" => (string) ($order["vehicle"] ?? "BO"),
      "UndeliverGuide" => (string) ($order["delivery_note"] ?? ""),
      "POPickupCode" => (string) ($order["org_collect"] ?? ""),
      "PickupDateTime" => "",
      "CODAmount" => (int) ($order["cod"] ?? 0),
      "Sender" => [
        "OrgCode" => (string) ($config["shipping_code"] ?? ""),
        "ContractNumber" => (string) ($config["shipping_contract"] ?? ""),
        "Phone" => (string) ($sender["phone"] ?? ""),
        "Fullname" => (string) ($sender["name"] ?? ""),
        "Address" => (string) ($sender["address"] ?? ""),
        "Province" => (string) ($sender["province_code"] ?? ""),
        "District" => $this->districtCode($sender, $two_level),
        "Commune" => (string) ($sender["commune_code"] ?? ""),
        "Postcode" => (string) ($sender["commune_code"] ?? ""),
      ],
      "Receiver" => [
        "OrgCode" => "",
        "Phone" => (string) ($receiver["phone"] ?? ""),
        "Fullname" => (string) ($receiver["name"] ?? ""),
        "Address" => (string) ($receiver["address"] ?? ""),
        "Province" => (string) ($receiver["province_code"] ?? ""),
        "District" => $this->districtCode($receiver, $two_level),
        "Commune" => (string) ($receiver["commune_code"] ?? ""),
        "Postcode" => (string) ($receiver["commune_code"] ?? ""),
      ],
      "Addons" => $addons,
      "Package" => [
        "Weight" => (string) (int) ($order["weight"] ?? 0),
        "Length" => (string) ($order["length"] ?? ""),
        "Width" => (string) ($order["width"] ?? ""),
        "Height" => (string) ($order["height"] ?? ""),
        "PriceWeight" => (string) (int) ($order["weight"] ?? 0),
        "IsVolume" => FALSE,
      ],
      "useBCP" => 1,
    ];
  }

  /**
   * Dựng mảng dịch vụ cộng thêm từ đơn hàng đã chuẩn hoá.
   *
   * @param array $order
   *   Đơn hàng đã chuẩn hoá.
   *
   * @return array
   *   Mảng addonService, rỗng khi đơn không dùng dịch vụ cộng thêm nào.
   */
  private function buildAddonService(array $order): array {
    $addons = [];

    if ((float) ($order["cod"] ?? 0) > 0) {
      $addons[] = [
        "code" => self::ADDON_COD,
        "propValue" => self::PROP_COD_AMOUNT . ":" . (int) $order["cod"],
      ];
    }

    if ((float) ($order["insurance"] ?? 0) > 0) {
      $addons[] = [
        "code" => self::ADDON_INSURANCE,
        "propValue" => self::PROP_INSURANCE_VALUE . ":" . (int) $order["insurance"],
      ];
    }

    return $addons;
  }

  /**
   * Mã quận/huyện gửi cho MyVNPost.
   *
   * Danh mục địa chỉ hai cấp đã bỏ cấp quận/huyện nhưng API vẫn bắt buộc có
   * trường này, tài liệu quy định truyền hằng "VNPOST".
   *
   * @param array $party
   *   Nhánh sender hoặc receiver.
   * @param bool $two_level
   *   TRUE khi đơn khai theo danh mục địa chỉ hai cấp.
   *
   * @return string
   *   Mã quận/huyện.
   */
  private function districtCode(array $party, bool $two_level): string {
    return $two_level
      ? self::DISTRICT_TWO_LEVEL
      : (string) ($party["district_code"] ?? "");
  }

  /**
   * Kiểm tra một bên gửi hoặc nhận đã đủ thông tin bắt buộc chưa.
   *
   * @param array $party
   *   Nhánh sender hoặc receiver.
   * @param string $label
   *   Tên bên dùng trong thông báo lỗi.
   */
  private function assertParty(array $party, string $label): void {
    foreach (["name" => "họ tên", "phone" => "số điện thoại", "address" => "địa chỉ"] as $key => $name) {
      if (trim((string) ($party[$key] ?? "")) === "") {
        throw new \DomainException("Đơn hàng thiếu {$name} {$label}");
      }
    }

    if (trim((string) ($party["province_code"] ?? "")) === "") {
      throw new \DomainException("Đơn hàng thiếu tỉnh/thành phố {$label}");
    }

    if (trim((string) ($party["commune_code"] ?? "")) === "") {
      throw new \DomainException("Đơn hàng thiếu phường/xã {$label}");
    }
  }

  /**
   * Bóc bản ghi đơn hàng khỏi phản hồi.
   *
   * @param mixed $response
   *   Phản hồi đã giải mã.
   * @param string $fallback
   *   Thông điệp dùng khi hãng không nói rõ lỗi.
   *
   * @return array
   *   Bản ghi đơn hàng.
   */
  private function orderRecord(mixed $response, string $fallback): array {
    if (!is_array($response)) {
      throw new \DomainException($fallback);
    }

    // Tùy endpoint mà bản ghi nằm thẳng ở gốc hoặc bọc thêm một lớp "data".
    $record = $response;

    if (empty($record["orderHdrID"]) && is_array($response["data"] ?? NULL)) {
      $record = $response["data"];
    }

    if (empty($record["orderHdrID"]) && empty($record["itemCode"])) {
      $message = (string) ($response["message"] ?? $response["Message"] ?? $response["errorMessage"] ?? "");
      throw new \DomainException($message !== "" ? $message : $fallback);
    }

    return $record;
  }

  /**
   * Chuẩn hoá phản hồi của nhóm hiệu chỉnh và hủy đơn.
   *
   * Nhóm này viết hoa chữ cái đầu ở đa số endpoint nhưng /orderCorrection lại
   * trả về khoá viết thường, nên đọc cả hai kiểu.
   *
   * @param mixed $response
   *   Phản hồi đã giải mã.
   *
   * @return array
   *   Kết quả gồm type, message, note, original_id, case_id, success.
   */
  private function caseResult(mixed $response): array {
    if (!is_array($response)) {
      throw new \DomainException("VN-Post không phản hồi kết quả xử lý");
    }

    $type = (string) ($response["Type"] ?? $response["type"] ?? "");

    return [
      // "00" là chấp nhận, "02" là còn chờ duyệt, còn lại là bị từ chối.
      "success" => $type === "00",
      "pending" => $type === "02",
      "type" => $type,
      "message" => (string) ($response["Message"] ?? $response["message"] ?? ""),
      "note" => (string) ($response["Note"] ?? $response["note"] ?? ""),
      "original_id" => (string) ($response["OriginalId"] ?? $response["originalId"] ?? ""),
      "case_id" => (string) ($response["CaseId"] ?? $response["caseId"] ?? ""),
    ];
  }

  /**
   * Gộp các bản ghi đơn hàng nằm rải trong phong bì "results".
   *
   * /getOrder trả mảng lồng [{results: []}] còn /GetListOrder trả thẳng
   * {results: []}, hàm này nhận cả hai dạng.
   *
   * @param mixed $response
   *   Phản hồi đã giải mã.
   *
   * @return array
   *   Danh sách bản ghi đơn hàng.
   */
  private function flattenResults(mixed $response): array {
    if (!is_array($response)) {
      return [];
    }

    if (isset($response["results"]) && is_array($response["results"])) {
      return array_values(array_filter($response["results"], "is_array"));
    }

    $result = [];

    foreach ($response as $item) {
      if (is_array($item)) {
        $result = array_merge($result, $this->flattenResults($item));
      }
    }

    return $result;
  }

  /**
   * Đổi ngày dạng Y-m-d sang định dạng d-m-Y mà MyVNPost yêu cầu.
   *
   * @param string $date
   *   Ngày cần đổi.
   *
   * @return string
   *   Ngày theo định dạng của hãng, chuỗi rỗng khi không đọc được.
   */
  private function apiDate(string $date): string {
    $timestamp = strtotime($date);

    return $timestamp === FALSE ? "" : date("d-m-Y", $timestamp);
  }

  /**
   * Đổi phản hồi dạng base64 sang chuỗi nhị phân.
   *
   * @param mixed $value
   *   Một phần tử của phản hồi in vận đơn.
   *
   * @return string
   *   Nội dung nhị phân, chuỗi rỗng khi không giải mã được.
   */
  private function toBinary(mixed $value): string {
    if (!is_string($value) || $value === "") {
      return "";
    }

    $binary = base64_decode($value, TRUE);

    return $binary === FALSE ? "" : $binary;
  }

  /**
   * Đổi chuỗi rỗng thành NULL cho các trường tài liệu yêu cầu truyền null.
   *
   * @param mixed $value
   *   Giá trị gốc.
   *
   * @return mixed
   *   Giá trị đã chuẩn hoá.
   */
  private function orNull(mixed $value): mixed {
    return ($value === "" || $value === NULL) ? NULL : $value;
  }

  /**
   * Gọi một endpoint của MyVNPost.
   *
   * @param array $config
   *   Cấu hình kết nối.
   * @param string $path
   *   Đường dẫn endpoint.
   * @param array $headers
   *   Header bổ sung.
   * @param array|null $payload
   *   Body JSON; NULL nghĩa là không gửi body.
   * @param string $method
   *   Phương thức HTTP.
   * @param bool $authenticate
   *   TRUE thì gắn header token của cấu hình.
   * @param int $timeout
   *   Timeout tính bằng giây.
   * @param array $query
   *   Tham số querystring.
   *
   * @return mixed
   *   Dữ liệu đã giải mã JSON, hoặc chuỗi thô khi phản hồi không phải JSON.
   *
   * @throws \DomainException
   *   Khi không kết nối được hoặc hãng trả mã lỗi HTTP.
   * @throws \Drupal\shipping_integration\Exception\ShippingTokenException
   *   Khi hãng từ chối vì token.
   */
  private function request(
    array $config,
    string $path,
    array $headers = [],
    ?array $payload = NULL,
    string $method = "POST",
    bool $authenticate = TRUE,
    int $timeout = 30,
    array $query = [],
  ): mixed {
    $base = rtrim((string) ($config["shipping_host"] ?? ""), "/");

    if ($base === "") {
      throw new \DomainException("Chưa cấu hình địa chỉ máy chủ VN-Post");
    }

    if ($authenticate) {
      $token = (string) ($config["shipping_token"] ?? "");

      if ($token === "") {
        throw new ShippingTokenException("Chưa có token kết nối VN-Post");
      }

      $headers["token"] = $token;
    }

    $options = [
      "headers" => ["Content-Type" => "application/json"] + $headers,
      "timeout" => $timeout,
      "http_errors" => FALSE,
    ];

    if ($payload !== NULL) {
      $options["json"] = $payload;
    }

    if (!empty($query)) {
      $options["query"] = $query;
    }

    $url = $base . "/" . ltrim($path, "/");

    try {
      $response = $this->client->request($method, $url, $options);
    }
    catch (GuzzleException $e) {
      throw new \DomainException("Không kết nối được VN-Post: " . $e->getMessage(), 0, $e);
    }

    $status = $response->getStatusCode();
    $body = (string) $response->getBody();
    $decoded = json_decode($body, TRUE);

    if ($status === 401 || $status === 403) {
      throw new ShippingTokenException("VN-Post từ chối token (HTTP {$status})");
    }

    if ($status >= 400) {
      $message = is_array($decoded)
        ? (string) ($decoded["errorMessage"] ?? $decoded["message"] ?? $decoded["Message"] ?? "")
        : "";

      $this->logger->error("VN-Post @method @url trả về HTTP @status: @body", [
        "@method" => $method,
        "@url" => $url,
        "@status" => $status,
        "@body" => mb_substr($body, 0, 1000),
      ]);

      throw new \DomainException(
        $message !== "" ? $message : "VN-Post trả về lỗi HTTP {$status}"
      );
    }

    return $decoded === NULL && $body !== "" ? $body : $decoded;
  }

}
