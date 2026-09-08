<?php

declare(strict_types=1);

namespace Drupal\shipping_integration\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Dựng danh sách chọn địa chỉ theo từng cấp cho form đơn vận chuyển.
 *
 * Danh mục địa chỉ có hơn mười nghìn bản ghi nên mọi truy vấn đều lọc theo cấp
 * cha và chỉ nạp đúng cấp đang cần, không bao giờ nạp cả bảng.
 *
 * Bộ địa chỉ hai cấp (sau sáp nhập) nối phường/xã thẳng vào tỉnh qua
 * field_province, còn bộ ba cấp cũ nối qua field_district.
 */
final class AddressOptions {

  /**
   * Khởi tạo service.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Trình quản lý entity.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Danh sách tỉnh/thành phố.
   *
   * @param bool $two_level
   *   TRUE lấy bộ địa chỉ hai cấp, FALSE lấy bộ ba cấp cũ.
   *
   * @return array
   *   Mảng id địa chỉ => tên hiển thị.
   */
  public function provinces(bool $two_level): array {
    return $this->options("province", ["field_is_new_address" => (int) $two_level]);
  }

  /**
   * Danh sách quận/huyện của một tỉnh, chỉ có ở bộ địa chỉ ba cấp.
   *
   * @param string|null $province
   *   Id tỉnh đang chọn.
   *
   * @return array
   *   Mảng id địa chỉ => tên hiển thị, rỗng nếu chưa chọn tỉnh.
   */
  public function districts(?string $province): array {
    if ($province === NULL || $province === "") {
      return [];
    }

    return $this->options("district", [
      "field_is_new_address" => 0,
      "field_province" => $province,
    ]);
  }

  /**
   * Danh sách phường/xã của cấp cha đang chọn.
   *
   * @param string|null $parent
   *   Id tỉnh (bộ hai cấp) hoặc id quận/huyện (bộ ba cấp).
   * @param bool $two_level
   *   TRUE lấy bộ địa chỉ hai cấp, FALSE lấy bộ ba cấp cũ.
   *
   * @return array
   *   Mảng id địa chỉ => tên hiển thị, rỗng nếu chưa chọn cấp cha.
   */
  public function communes(?string $parent, bool $two_level): array {
    if ($parent === NULL || $parent === "") {
      return [];
    }

    $conditions = $two_level
      ? ["field_is_new_address" => 1, "field_province" => $parent]
      : ["field_is_new_address" => 0, "field_district" => $parent];

    return $this->options("commune", $conditions);
  }

  /**
   * Kiểm tra một địa chỉ có thuộc bộ hai cấp hay không.
   *
   * Dùng khi mở lại đơn cũ để biết nên hiện hai hay ba cấp lựa chọn.
   *
   * @param string|null $id
   *   Id địa chỉ cần kiểm tra.
   *
   * @return bool|null
   *   TRUE là địa chỉ hai cấp, FALSE là ba cấp, NULL nếu không tra được.
   */
  public function isTwoLevel(?string $id): ?bool {
    if ($id === NULL || $id === "") {
      return NULL;
    }

    $address = $this->entityTypeManager->getStorage("shipping_address")->load($id);

    if ($address === NULL || !$address->hasField("field_is_new_address")) {
      return NULL;
    }

    return (bool) $address->get("field_is_new_address")->value;
  }

  /**
   * Truy vấn một cấp địa chỉ.
   *
   * @param string $bundle
   *   Bundle địa chỉ: province, district hoặc commune.
   * @param array $conditions
   *   Điều kiện lọc thêm, khoá là tên field.
   *
   * @return array
   *   Mảng id địa chỉ => tên hiển thị.
   */
  private function options(string $bundle, array $conditions): array {
    $storage = $this->entityTypeManager->getStorage("shipping_address");

    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition("bundle", $bundle)
      ->sort("label");

    foreach ($conditions as $field => $value) {
      $query->condition($field, $value);
    }

    $ids = $query->execute();

    if (!$ids) {
      return [];
    }

    $options = [];

    foreach ($storage->loadMultiple($ids) as $address) {
      $options[$address->id()] = $address->label();
    }

    return $options;
  }

}
