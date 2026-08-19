<?php

namespace Drupal\shipping_integration\Plugin\Providers;

use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Component\Uuid\UuidInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Drupal\shipping_integration\ShippingProvidersAttribute;
use Drupal\shipping_integration\ShippingProvidersInterface;

/**
 * Provider for invoicing integration using Misa.
 */
#[ShippingProvidersAttribute(
  id: "vnpost",
  label: new TranslatableMarkup("VnPost"),
)]

class VnPostProvider extends PluginBase implements ShippingProvidersInterface, ContainerFactoryPluginInterface {

  /**
   * Danh sách endpoint địa chỉ, cấp cha luôn đứng trước cấp con.
   *
   * Địa chỉ cũ có 3 cấp (tỉnh - huyện - xã), địa chỉ mới chỉ còn 2 cấp
   * (tỉnh - xã) nên xã mới trả thẳng mã tỉnh.
   */
  private const ADDRESS_ENDPOINTS = [
    ['endpoint' => '/getAllProvince', 'bundle' => 'province', 'is_new' => 0],
    ['endpoint' => '/getAllDistrict', 'bundle' => 'district', 'is_new' => 0],
    ['endpoint' => '/getAllCommune', 'bundle' => 'commune', 'is_new' => 0],
    ['endpoint' => '/getNewProvinceAll', 'bundle' => 'province', 'is_new' => 1],
    ['endpoint' => '/getNewCommuneAll', 'bundle' => 'commune', 'is_new' => 1],
  ];

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected UuidInterface $uuid,
    protected FileSystemInterface $fileSystem,
    protected ClientInterface $client,
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
      $container->get("uuid"),
      $container->get("file_system"),
      $container->get("http_client"),
    );
  }

  /**
   * Call cUrl.
   */
  private function callApi(
    string $url,
    array $headers,
    array $payload = [],
    string $method = "POST",
    int $timeout = 30,
  ): mixed {
    try {
      $response = $this->client->request($method, $url, [
        "headers" => $headers,
        "json" => $payload,
        "timeout" => $timeout,
      ]);

      $body = $response->getBody()->getContents();
      return json_decode($body, TRUE);
    }
    catch (RequestException $e) {
      throw new \RuntimeException("Connection error: " . $e->getMessage(), 0, $e);
    }
  }

  /**
   * Lấy token.
   */
  public function getToken(array $config) {
    return $this->callApi(
      $config["shipping_host"] . "/GetAccessToken",
      [
        "Content-Type" => "application/json",
      ],
      [
        "username" => $config["shipping_username"],
        "password" => $config["shipping_password"],
        "customerCode" => $config["shipping_code"],
      ]
    );
  }

  /**
   * Đồng bộ địa chỉ cũ và mới.
   */
  public function synchronizeAddresses(array $config): array {
    $host = rtrim($config['shipping_host'] ?? '', '/');
    $token = $config['shipping_token'] ?? '';

    if (empty($host) || empty($token)) {
      return [
        'success' => FALSE,
        'message' => 'Shipping configuration is incomplete',
      ];
    }

    $addresses = [];

    // Mã huyện => mã tỉnh của địa chỉ cũ, dùng để suy ra tỉnh cho xã cũ vì
    // /getAllCommune chỉ trả về mã huyện.
    $district_provinces = [];

    foreach (self::ADDRESS_ENDPOINTS as $endpoint) {
      $responses = $this->callApi(
        $host . $endpoint['endpoint'],
        ['token' => $token],
        [],
        'GET',
        120
      );

      if (empty($responses) || !is_array($responses)) {
        continue;
      }

      foreach ($responses as $response) {
        if (!is_array($response)) {
          continue;
        }

        $address = $this->normalizeAddress(
          $response,
          $endpoint['bundle'],
          $endpoint['is_new'],
          $district_provinces
        );

        if ($address['code'] === NULL) {
          continue;
        }

        if ($address['bundle'] === 'district') {
          $district_provinces[$address['code']] = $address['province_code'];
        }

        $addresses[] = $address;
      }
    }

    return [
      'success' => TRUE,
      'data' => $addresses,
    ];
  }

  /**
   * Chuẩn hoá một bản ghi địa chỉ về chung một cấu trúc.
   */
  private function normalizeAddress(
    array $response,
    string $bundle,
    int $is_new,
    array $district_provinces,
  ): array {
    $address = [
      'bundle' => $bundle,
      'is_new' => $is_new,
      'code' => NULL,
      'label' => 'No name',
      'province_code' => NULL,
      'district_code' => NULL,
    ];

    switch ($bundle) {
      case 'province':
        $address['code'] = $response['provinceCode'] ?? NULL;
        $address['label'] = $response['provinceName'] ?? 'No name';
        break;

      case 'district':
        $address['code'] = $response['districtCode'] ?? NULL;
        $address['label'] = $response['districtName'] ?? 'No name';
        $address['province_code'] = $response['provinceCode'] ?? NULL;
        break;

      case 'commune':
        $address['code'] = $response['communeCode'] ?? NULL;
        $address['label'] = $response['communeName'] ?? 'No name';
        $address['district_code'] = $response['districtCode'] ?? NULL;

        // Xã mới trả thẳng mã tỉnh, xã cũ phải suy ra qua huyện.
        $address['province_code'] = $response['provinceCode']
          ?? ($district_provinces[$address['district_code']] ?? NULL);
        break;
    }

    if ($address['code'] !== NULL) {
      $address['code'] = (string) $address['code'];
    }

    return $address;
  }

  public function createShippingOrder() {
    return [
      "orderCreationStatus" => 0,
      "type" => "GUI",
      "customerCode" => "T000180585",
      "contractCode" => "000123023",
      "informationOrder" => [
        "senderName" => "Nguyễn Hoàng Anh Thư",
        "senderPhone" => "0980001478",
        "senderMail" => "anhthuhn1234@yahoo.com.vn",
        "senderAddress" => "số 18, ngõ 78/9",
        "senderProvinceCode" => "10",
        "senderProvinceName" => "Hà Nội",
        "senderDistrictCode" => "1100",
        "senderDistrictName" => "Hoàn kiếm",
        "senderCommuneCode" => "11022",
        "senderCommuneName" => "Tràng tiền",
        "receiverName" => "Trần Anh Vũ",
        "receiverAddress" => "1/1/1 Đường Cô Bắc",
        "receiverProvinceCode" => "90",
        "receiverProvinceName" => "An Giang",
        "receiverDistrictCode" => "9010",
        "receiverDistrictName" => "Long Xuyên",
        "receiverCommuneCode" => "90107",
        "receiverCommuneName" => "Mỹ Bình",
        "receiverPhone" => "0439448688",
        "receiverEmail" => "null",
        "serviceCode" => "ETN011",
        "addonService" => [
          [
            "code" => "GTG021",
            "propValue" => "PROP0018:200000;PROP0019:3;PROP0020:null"
          ],
          [
            "code" => "GTG008",
            "propValue" => "PROP0026:45000"
          ]
        ],
        "additionRequest" => [],
      "orgCodeCollect" => null,
      "orgCodeAccept" => null,
      "saleOrderCode" => "DHVN00039",
      "contentNote" => "Kem dưỡng trắng da chống nắng đa chức năng ngày Hương Thị Facial White Day Cream 30gr",
      "weight" => "1000",
      "width" => null,
      "length" => null,
      "height" => null,
      "vehicle" => "BO",
      "sendType" => "1",
      "isBroken" => "0",
      "deliveryTime" => "N",
      "deliveryRequire" => "1",
      "deliveryInstruction" => "Gửi bảo vệ khi không liên lạc được với người nhận. Cảm ơn."
    ]
    ];

  }

}
