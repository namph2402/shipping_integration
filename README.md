# Shipping integration

Tích hợp các hãng vận chuyển và quản lý đơn hàng **trong nước**. Module dựng
theo đúng lối của `e_invoice` (plugin nhà cung cấp + service nghiệp vụ và
template quản lý danh sách gộp trong một module duy nhất).

Hiện đã cài đặt sẵn nhà cung cấp **VN-Post (MyVNPost)**. Thêm hãng khác chỉ cần
viết thêm một plugin, không phải sửa gì ở tầng entity hay giao diện.

## Cấu trúc dữ liệu

| Thực thể | Vai trò |
| --- | --- |
| `shipping_type` (content) | Danh sách hãng: VN-Post, Giao hàng nhanh… `field_code` giữ mã plugin (`vnpost`). |
| `shipping_order` + `shipping_order_type` | Đơn vận chuyển. Mỗi hãng một bundle riêng (`vnpost`) nên field của hãng này không lẫn vào hãng kia. |
| `shipping_address` + `shipping_address_type` | Danh mục địa chỉ đồng bộ từ hãng. Bundle `province` / `district` / `commune`, `field_is_new_address` phân biệt bộ 2 cấp (sau sáp nhập) với bộ 3 cấp cũ. |
| Từ vựng `shipping_integration` | Mỗi term là một tài khoản kết nối: trỏ tới `shipping_type`, giữ host, tài khoản, mã khách hàng, hợp đồng và token. |

## Cài đặt và cấu hình

1. Chạy `drush updb` (module đã bật từ trước nên bộ cấu hình mới nạp qua
   `shipping_integration_update_10001()`), rồi `drush cr`.
2. Tạo hãng tại `/admin/content/shipping-type`, đặt **Mã provider** = `vnpost`.
3. Tạo term kết nối tại từ vựng *Config shipping integration* với:
   - **Host**: `https://my-uat.vnpost.vn/MYVNP_API` (UAT) hoặc
     `https://connect-my.vnpost.vn` (thật).
   - **Username / Password**: tài khoản MyVNPost.
   - **Mã khách hàng**: mã KH CMS VNPost cấp (ví dụ `T000180585`).
   - **Mã hợp đồng**: để trống nếu không có.
   Lưu term là module tự gọi `/GetAccessToken` và ghi token vào `field_si_token`.
4. Đồng bộ danh mục địa chỉ: vào `/admin/content/shipping-address` và bấm nút
   **Đồng bộ địa chỉ: {tên term}** ở đầu trang (mỗi term cấu hình kết nối có
   một nút riêng), hoặc mở thẳng
   `/admin/shipping/config/{term}/synchronize-addresses` rồi xác nhận. Lệnh này
   kéo cả 5 danh mục (tỉnh/huyện/xã cũ và tỉnh/xã mới), chạy lại nhiều lần vẫn
   an toàn vì bản ghi đã có sẽ bị bỏ qua.

## Màn hình quản lý

- `/admin/shipping/orders` — danh sách đơn trong nước: lọc theo ngày, tài
  khoản kết nối, trạng thái, từ khóa; tổng hợp COD và cước; chọn nhiều dòng để
  thao tác hàng loạt; ẩn hiện cột, sắp xếp, phân trang và xuất Excel chạy hoàn
  toàn phía trình duyệt.
- `/admin/shipping/order/{id}` — chi tiết một đơn kèm hành trình bưu gửi.

Các lệnh trên hai màn hình này: tạo đơn, lưu nháp, phát hành đơn nháp, hiệu
chỉnh, hủy, lấy kết quả phê duyệt, đồng bộ trạng thái, tính cước, in vận đơn,
kéo đơn từ hệ thống hãng về, xem hành trình.

Luồng nháp gồm hai bước: **Save draft** đẩy đơn sang hãng ở trạng thái *Lưu
nháp* (`orderCreationStatus = 0`) — đơn đã nằm trên hệ thống hãng nhưng chưa
vào khai thác, còn xóa được; **Publish draft** mới thực sự phát hành bưu gửi.
Nút phát hành trên trang chi tiết chỉ hiện khi đơn còn ở trạng thái nháp; trên
trang danh sách thì đơn không còn nháp sẽ bị bỏ qua kèm thông báo.

## Ánh xạ sang API MyVNPost

| Chức năng | Endpoint |
| --- | --- |
| Lấy token | `POST /GetAccessToken` |
| Tạo đơn trong nước / lưu nháp | `POST /CreateOrder` (`orderCreationStatus` 1/0) |
| Hiệu chỉnh đơn | `POST /orderCorrection` |
| Hủy đơn | `POST /orderCancel` |
| Kết quả phê duyệt | `GET /orderCorrection/updateCase` |
| Tính cước | `POST /ServicesCharge` (`scope = 1`) |
| Chi tiết đơn | `GET /getOrder` |
| Danh sách đơn | `GET /GetListOrder` (`isInternational = false`) |
| Hành trình | `GET /GetStatusHistoryOrder` |
| In vận đơn | `POST /exportListReport` |
| Phát hành / xóa đơn nháp | `GET /createOrderByDraft`, `GET /deleteOrderByDraft` |
| Danh mục địa chỉ | `getAllProvince`, `getAllDistrict`, `getAllCommune`, `getNewProvinceAll`, `getNewCommuneAll` |

Xác thực bằng header `token` (không phải `Authorization: Bearer`). Khi hãng trả
401/403, `HandleShipping` xin token mới rồi gọi lại đúng một lần.

Đơn khai theo địa chỉ 2 cấp (`field_so_is_new_address`) thì mã quận/huyện gửi
lên là hằng `"VNPOST"` theo đúng quy định của tài liệu; đơn 3 cấp gửi mã huyện
thật.

Dịch vụ cộng thêm được sinh từ field của đơn: COD → `GTG021`/`PROP0018`, khai
giá → `GTG008`/`PROP0026`.

## Thêm một hãng vận chuyển mới

1. Tạo `src/Plugin/Providers/<Ten>Provider.php` với attribute
   `#[ShippingProvidersAttribute(id: "ma_plugin", label: ...)]` và cài đặt
   `ShippingProvidersInterface`.
2. Thêm bundle `shipping_order_type` trùng tên plugin, kèm bộ field riêng của
   hãng.
3. Tạo entity `shipping_type` với `field_code` = mã plugin, rồi tạo term kết
   nối trỏ tới nó.

## Giới hạn

Module chỉ phục vụ đơn hàng trong nước. Các endpoint quốc tế của MyVNPost
(`create-international`, `update-international`, danh mục quốc gia và thành phố
quốc tế) cố tình không được cài đặt.

Tài liệu API: <https://my-uat.vnpost.vn/static/>
