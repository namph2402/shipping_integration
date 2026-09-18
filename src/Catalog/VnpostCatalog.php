<?php

declare(strict_types=1);

namespace Drupal\shipping_integration\Catalog;

/**
 * Danh mục dịch vụ trong nước của MyVNPost.
 *
 * Gồm ba tầng đúng như tài liệu hãng cấp: sản phẩm dịch vụ (serviceCode), dịch
 * vụ GTGT được phép đi kèm từng sản phẩm, và thuộc tính của từng dịch vụ GTGT.
 * Dịch vụ GTGT chia hai nhóm theo tài liệu /CreateOrder: nhóm "Dịch vụ cộng
 * thêm" gửi trong addonService, nhóm "Yêu cầu thêm" gửi trong additionRequest.
 *
 * Hãng không có API tra danh mục này nên module giữ bản khai tay; hãng mở thêm
 * dịch vụ thì bổ sung vào các hằng dưới đây. Dịch vụ quốc tế cố tình không
 * khai vì module chỉ phục vụ đơn trong nước.
 */
final class VnpostCatalog {

  /**
   * Nhóm dịch vụ cộng thêm, gửi trong addonService.
   */
  public const GROUP_ADDON = "addon";

  /**
   * Nhóm yêu cầu thêm, gửi trong additionRequest.
   */
  public const GROUP_REQUEST = "request";

  /**
   * Mã dịch vụ cộng thêm phát hàng thu tiền hộ.
   */
  public const ADDON_COD = "GTG021";

  /**
   * Mã thuộc tính số tiền thu hộ của dịch vụ COD.
   */
  public const PROP_COD_AMOUNT = "PROP0018";

  /**
   * Mã dịch vụ cộng thêm khai giá hàng hóa.
   */
  public const ADDON_INSURANCE = "GTG008";

  /**
   * Mã thuộc tính giá trị khai giá.
   */
  public const PROP_INSURANCE_VALUE = "PROP0026";

  /**
   * Sản phẩm dịch vụ trong nước: mã => [tên, nhóm].
   */
  public const SERVICES = [
    "CTN001" => ["Tiết kiệm", "Thương mại điện tử"],
    "CTN007" => ["Tiết kiệm", "Thương mại điện tử"],
    "CTN009" => ["Tiêu chuẩn", "Thương mại điện tử"],
    "ETN011" => ["Nhanh", "Tài liệu hàng hóa"],
    "ETN013" => ["Hỏa tốc", "Hỏa tốc"],
    "ETN031" => ["Nhanh", "Tài liệu hàng hóa"],
    "ETN037" => ["Nhanh", "Thương mại điện tử"],
    "PTN001" => ["Logistic (trên 30kg)", "Hàng khối lượng lớn"],
  ];

  /**
   * Dịch vụ GTGT trong nước.
   *
   * Mỗi mục gồm tên, nhóm và danh sách thuộc tính. Thuộc tính khai kiểu ô nhập
   * (number, text, date, flag), cờ bắt buộc, và "fixed" cho thuộc tính hãng
   * bắt buộc có mặt nhưng tự tính giá trị, module luôn gửi null.
   */
  public const ADDONS = [
    "GTG001" => [
      "label" => "Bảo phát",
      "group" => self::GROUP_ADDON,
      "props" => [
        "PROP0009" => ["label" => "Họ tên người báo phát", "type" => "text"],
        "PROP0045" => ["label" => "Bưu cục gốc", "type" => "text"],
      ],
    ],
    "GTG005" => [
      "label" => "Phát đồng kiểm số lượng",
      "group" => self::GROUP_ADDON,
      "props" => [
        "PROP0024" => ["label" => "Phát đồng kiểm số lượng", "type" => "number"],
        "PROP0067" => ["label" => "Chuyển trả", "type" => "flag"],
        "PROP0068" => ["label" => "Lập chứng từ cho từng BG trong lô", "type" => "flag"],
        "PROP0069" => ["label" => "Ghi chú", "type" => "text"],
      ],
    ],
    "GTG008" => [
      "label" => "Khai giá",
      "group" => self::GROUP_ADDON,
      "props" => [
        "PROP0026" => ["label" => "Giá trị khai giá", "type" => "number", "required" => TRUE],
      ],
    ],
    "GTG015" => [
      "label" => "Hóa đơn",
      "group" => self::GROUP_ADDON,
      "props" => [
        "PROP0023S" => ["label" => "Số hóa đơn", "type" => "text", "required" => TRUE],
        "PROP0023N" => ["label" => "Ngày hóa đơn", "type" => "date", "required" => TRUE],
      ],
    ],
    "GTG016" => [
      "label" => "Lưu kho",
      "group" => self::GROUP_ADDON,
      "props" => [
        "PROP0015" => ["label" => "Lưu kho", "type" => "number"],
      ],
    ],
    "GTG021" => [
      "label" => "Phát hàng thu tiền COD",
      "group" => self::GROUP_ADDON,
      "props" => [
        "PROP0018" => ["label" => "Số tiền COD", "type" => "number", "required" => TRUE],
      ],
    ],
    "GTG047" => [
      "label" => "Thay đổi tên, địa chỉ người nhận",
      "group" => self::GROUP_ADDON,
      "props" => [],
    ],
    "GTG067" => [
      "label" => "Phát một phần chiều đi",
      "group" => self::GROUP_ADDON,
      "props" => [],
    ],
    "GTG068" => [
      "label" => "Phát hàng một phần chiều đến",
      "group" => self::GROUP_ADDON,
      "props" => [],
    ],
    "GTG070" => [
      "label" => "Thu phí hủy đơn",
      "group" => self::GROUP_REQUEST,
      "props" => [
        "PROP0078" => ["label" => "Tổng tiền phí hủy đơn hàng", "type" => "number", "required" => TRUE],
      ],
    ],
    "GTG071" => [
      "label" => "Thu hộ phí ship",
      "group" => self::GROUP_REQUEST,
      // Tài liệu /CreateOrder bắt buộc có đủ hai mã này, giá trị do hãng tính.
      "props" => [
        "PROP0080" => ["label" => "PROP0080", "type" => "text", "fixed" => TRUE],
        "PROP0081" => ["label" => "PROP0081", "type" => "text", "fixed" => TRUE],
      ],
    ],
  ];

  /**
   * Dịch vụ GTGT được phép đi kèm từng sản phẩm dịch vụ.
   *
   * Bảng danh mục của hãng ghi nhóm logistic là PIN001 trong khi danh mục sản
   * phẩm ghi PTN001; module theo mã PTN001 là mã dùng khi tạo đơn.
   */
  public const SERVICE_ADDONS = [
    "CTN001" => ["GTG001", "GTG005", "GTG008", "GTG015", "GTG016", "GTG021", "GTG047", "GTG067", "GTG068"],
    "CTN007" => ["GTG008", "GTG015", "GTG021", "GTG047", "GTG067", "GTG068", "GTG070", "GTG071"],
    "CTN009" => ["GTG001"],
    "ETN011" => ["GTG008", "GTG015", "GTG021", "GTG047", "GTG067", "GTG068", "GTG070", "GTG071"],
    "ETN013" => ["GTG005", "GTG008", "GTG015", "GTG021", "GTG047"],
    "ETN031" => ["GTG001", "GTG021"],
    "ETN037" => ["GTG001", "GTG005", "GTG008", "GTG015", "GTG016", "GTG021", "GTG067", "GTG068", "GTG070", "GTG071"],
    "PTN001" => ["GTG005", "GTG008", "GTG016", "GTG021", "GTG047", "GTG067"],
  ];

  /**
   * Danh sách chọn sản phẩm dịch vụ.
   *
   * @param string[]|null $allowed
   *   Mã dịch vụ cần lấy, NULL là lấy cả danh mục.
   *
   * @return array
   *   Mảng mã => nhãn.
   */
  public static function serviceOptions(?array $allowed = NULL): array {
    $options = [];

    foreach (self::SERVICES as $code => [$name, $group]) {
      if ($allowed === NULL || in_array($code, $allowed, TRUE)) {
        $options[$code] = "{$code} - {$name} ({$group})";
      }
    }

    return $options;
  }

  /**
   * Dịch vụ GTGT dùng được cho một sản phẩm dịch vụ.
   *
   * @param string $service
   *   Mã sản phẩm dịch vụ.
   *
   * @return array
   *   Mảng mã => định nghĩa dịch vụ GTGT, theo thứ tự danh mục.
   */
  public static function addonsFor(string $service): array {
    $addons = [];

    foreach (self::SERVICE_ADDONS[$service] ?? [] as $code) {
      if (isset(self::ADDONS[$code])) {
        $addons[$code] = self::ADDONS[$code];
      }
    }

    return $addons;
  }

  /**
   * Đọc dịch vụ GTGT đã khai của đơn.
   *
   * Đơn tạo trước khi có field_so_addons chỉ giữ tiền COD và khai giá ở hai
   * field riêng, nên dựng lại hai dịch vụ tương ứng từ đó.
   *
   * @param string $json
   *   Giá trị field_so_addons.
   * @param float $cod
   *   Tiền thu hộ của đơn.
   * @param float $insurance
   *   Giá trị khai giá của đơn.
   *
   * @return array
   *   Mảng mã dịch vụ GTGT => [mã thuộc tính => giá trị].
   */
  public static function decode(string $json, float $cod = 0, float $insurance = 0): array {
    $addons = json_decode($json, TRUE);

    if (is_array($addons) && $addons !== []) {
      return array_map(
        static fn (mixed $props): array => is_array($props) ? array_map("strval", $props) : [],
        $addons
      );
    }

    $addons = [];

    if ($cod > 0) {
      $addons[self::ADDON_COD] = [self::PROP_COD_AMOUNT => (string) (int) $cod];
    }

    if ($insurance > 0) {
      $addons[self::ADDON_INSURANCE] = [self::PROP_INSURANCE_VALUE => (string) (int) $insurance];
    }

    return $addons;
  }

  /**
   * Dựng mảng gửi hãng của một nhóm dịch vụ GTGT.
   *
   * Tài liệu yêu cầu gửi đủ mọi mã thuộc tính của dịch vụ, mã nào không có giá
   * trị thì ghi "null": [Mã 1]:[Giá trị 1];[Mã 2]:null.
   *
   * @param array $addons
   *   Mảng mã dịch vụ GTGT => [mã thuộc tính => giá trị].
   * @param string $group
   *   Nhóm cần dựng, xem GROUP_ADDON và GROUP_REQUEST.
   *
   * @return array
   *   Mảng phần tử {code, propValue}.
   */
  public static function payload(array $addons, string $group): array {
    $result = [];

    foreach ($addons as $code => $values) {
      $definition = self::ADDONS[$code] ?? NULL;

      // Mã hãng trả về mà danh mục chưa có thì vẫn gửi đi ở nhóm cộng thêm
      // với đúng thuộc tính đang giữ, để đơn kéo về hiệu chỉnh không mất dịch vụ.
      $definition_group = $definition["group"] ?? self::GROUP_ADDON;

      if ($definition_group !== $group) {
        continue;
      }

      $props = $definition !== NULL ? array_keys($definition["props"]) : array_keys($values);
      $pairs = [];

      foreach ($props as $prop) {
        $value = trim((string) ($values[$prop] ?? ""));
        $pairs[] = $prop . ":" . ($value === "" ? "null" : $value);
      }

      $result[] = [
        "code" => (string) $code,
        "propValue" => $pairs === [] ? NULL : implode(";", $pairs),
      ];
    }

    return $result;
  }

  /**
   * Đọc dịch vụ GTGT từ bản ghi đơn hãng trả về.
   *
   * @param array $record
   *   Bản ghi đơn hàng của hãng.
   *
   * @return array
   *   Mảng mã dịch vụ GTGT => [mã thuộc tính => giá trị].
   */
  public static function fromRecord(array $record): array {
    $addons = [];

    foreach (["addonService", "additionRequest"] as $key) {
      foreach (is_array($record[$key] ?? NULL) ? $record[$key] : [] as $row) {
        $code = is_array($row) ? (string) ($row["code"] ?? "") : "";

        if ($code === "") {
          continue;
        }

        $props = [];
        $list = $row["propList"] ?? $row["proplist"] ?? [];

        foreach (is_array($list) ? $list : [] as $prop) {
          $prop_code = (string) ($prop["propCode"] ?? "");
          $value = (string) ($prop["propValue"] ?? "");

          if ($prop_code !== "" && $value !== "" && $value !== "null") {
            $props[$prop_code] = $value;
          }
        }

        $addons[$code] = $props;
      }
    }

    return $addons;
  }

}
