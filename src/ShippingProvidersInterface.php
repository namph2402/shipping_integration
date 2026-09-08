<?php

namespace Drupal\shipping_integration;

/**
 * Hợp đồng chung cho mọi hãng vận chuyển được tích hợp.
 *
 * Mỗi hãng có quy ước API riêng, tầng plugin chịu trách nhiệm dịch mảng đơn
 * hàng đã chuẩn hoá của module sang payload của hãng và ngược lại. Tầng gọi
 * (service, controller) chỉ làm việc với cấu trúc chuẩn hoá mô tả dưới đây.
 *
 * Mảng $config do \Drupal\shipping_integration\Service\GetConfigShipping dựng,
 * gồm các khoá: shipping_config_id, shipping_provider, shipping_host,
 * shipping_username, shipping_password, shipping_code, shipping_contract,
 * shipping_token, shipping_expiration, shipping_type_id.
 *
 * Mảng $order đã chuẩn hoá gồm: draft, type, sale_code, content, weight,
 * length, width, height, service, vehicle, send_type, is_broken,
 * delivery_time, delivery_require, delivery_note, org_collect, org_accept,
 * cod, insurance, is_new_address, original_id, item_code và hai nhánh con
 * sender/receiver (name, phone, email, address, province_code, province_name,
 * district_code, district_name, commune_code, commune_name).
 */
interface ShippingProvidersInterface {

  /**
   * Lấy token kết nối.
   *
   * @param array $config
   *   Cấu hình kết nối.
   *
   * @return array
   *   Mảng gồm success, token và message khi thất bại.
   */
  public function getToken(array $config): array;

  /**
   * Đồng bộ danh mục địa chỉ cũ và mới.
   *
   * @param array $config
   *   Cấu hình kết nối.
   *
   * @return array
   *   Mảng gồm success và data, mỗi phần tử data có bundle, is_new, code,
   *   label, province_code, district_code.
   */
  public function synchronizeAddresses(array $config): array;

  /**
   * Tạo đơn hàng trong nước.
   *
   * @param array $config
   *   Cấu hình kết nối.
   * @param array $order
   *   Đơn hàng đã chuẩn hoá.
   *
   * @return array
   *   Bản ghi đơn hàng do hãng trả về.
   */
  public function createOrder(array $config, array $order): array;

  /**
   * Hiệu chỉnh đơn hàng trong nước đã tạo.
   *
   * @param array $config
   *   Cấu hình kết nối.
   * @param array $order
   *   Đơn hàng đã chuẩn hoá, bắt buộc có original_id và item_code.
   *
   * @return array
   *   Kết quả hiệu chỉnh gồm type, message, case_id.
   */
  public function updateOrder(array $config, array $order): array;

  /**
   * Hủy đơn hàng.
   *
   * @param array $config
   *   Cấu hình kết nối.
   * @param string $original_id
   *   ID gốc của bưu gửi.
   *
   * @return array
   *   Kết quả hủy gồm type, message, note, case_id.
   */
  public function cancelOrder(array $config, string $original_id): array;

  /**
   * Lấy kết quả phê duyệt hiệu chỉnh hoặc hủy đơn.
   *
   * @param array $config
   *   Cấu hình kết nối.
   * @param string $original_id
   *   ID gốc của bưu gửi.
   * @param string $case_id
   *   ID hiệu chỉnh/hủy do hãng sinh ra.
   *
   * @return array
   *   Danh sách kết quả, mỗi phần tử gồm type, message, case_id.
   */
  public function approvalResult(array $config, string $original_id, string $case_id): array;

  /**
   * Tính cước phí cho một đơn hàng trong nước.
   *
   * @param array $config
   *   Cấu hình kết nối.
   * @param array $order
   *   Đơn hàng đã chuẩn hoá.
   *
   * @return array
   *   Bảng cước gồm main_fee, vas_fee, total_fee, price_weight, service.
   */
  public function calculateFee(array $config, array $order): array;

  /**
   * Lấy chi tiết một đơn hàng.
   *
   * @param array $config
   *   Cấu hình kết nối.
   * @param string $code
   *   Giá trị tra cứu.
   * @param string $type
   *   Kiểu tra cứu: 1 số hiệu bưu gửi, 2 mã đơn hàng, 3 ID gốc.
   *
   * @return array
   *   Bản ghi đơn hàng, mảng rỗng khi không tìm thấy.
   */
  public function getOrder(array $config, string $code, string $type = "1"): array;

  /**
   * Lấy danh sách đơn hàng theo khoảng ngày cập nhật.
   *
   * @param array $config
   *   Cấu hình kết nối.
   * @param array $params
   *   Tham số lọc: from, to (Y-m-d), type (GUI|NHAN), page, size.
   *
   * @return array
   *   Danh sách bản ghi đơn hàng.
   */
  public function listOrders(array $config, array $params): array;

  /**
   * Lấy hành trình của một đơn hàng.
   *
   * @param array $config
   *   Cấu hình kết nối.
   * @param string $code
   *   Giá trị tra cứu.
   * @param string $type
   *   Kiểu tra cứu: 1 số hiệu bưu gửi, 2 mã đơn hàng, 3 ID gốc.
   *
   * @return array
   *   Danh sách mốc hành trình gồm date, hour, status_code, status_name.
   */
  public function orderHistory(array $config, string $code, string $type = "1"): array;

  /**
   * Lấy file vận đơn của các bưu gửi.
   *
   * @param array $config
   *   Cấu hình kết nối.
   * @param array $item_codes
   *   Danh sách số hiệu bưu gửi.
   *
   * @return array
   *   Danh sách nội dung nhị phân của từng file PDF.
   */
  public function printLabel(array $config, array $item_codes): array;

  /**
   * Xác nhận phát hành một đơn nháp.
   *
   * @param array $config
   *   Cấu hình kết nối.
   * @param string $code
   *   Giá trị tra cứu.
   * @param string $type
   *   Kiểu tra cứu: 1 số hiệu bưu gửi, 2 mã đơn hàng, 3 ID gốc.
   *
   * @return array
   *   Bản ghi đơn hàng sau khi phát hành.
   */
  public function confirmDraft(array $config, string $code, string $type = "1"): array;

  /**
   * Xóa một đơn nháp.
   *
   * @param array $config
   *   Cấu hình kết nối.
   * @param string $code
   *   Giá trị tra cứu.
   * @param string $type
   *   Kiểu tra cứu: 1 số hiệu bưu gửi, 2 mã đơn hàng, 3 ID gốc.
   *
   * @return array
   *   Kết quả xóa gồm success và message.
   */
  public function deleteDraft(array $config, string $code, string $type = "1"): array;

}
