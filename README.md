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
kéo đơn từ hệ thống hãng về, xem hành trình. Tất cả gom trong một ô chọn kèm
nút **Thao tác** ở đầu trang: chọn lệnh, tích các dòng cần chạy (số dòng đang
chọn hiện ngay cạnh nút) rồi bấm **Thao tác**.

Luồng nháp gồm hai bước: **Save draft** đẩy đơn sang hãng ở trạng thái *Lưu
nháp* (`orderCreationStatus = 0`) — đơn đã nằm trên hệ thống hãng nhưng chưa
vào khai thác, còn xóa được; **Publish draft** mới thực sự phát hành bưu gửi.
Lệnh phát hành trên trang chi tiết chỉ hiện trong ô chọn khi đơn còn nháp; trên
trang danh sách thì đơn không còn nháp sẽ bị bỏ qua kèm thông báo.

## Form khai đơn

`/shipping-order/add/vnpost` và `/shipping-order/{id}/edit` dùng lại bố cục màn
khai đơn của MyVNPost: cột trái là **Người gửi**, **Người nhận**, **Chọn dịch
vụ**; cột phải là **Thông tin hàng hoá** và **Yêu cầu thêm**; đáy màn hình là
thanh dính hiển thị khối lượng tính cước, tổng cước tạm tính, tổng tiền thu hộ
kèm nhóm nút lệnh.

- **Địa chỉ** dùng select liên tầng, mặc định bộ hai cấp (Tỉnh/Thành phố →
  Phường/Xã) đúng như hãng đang khai. Mở lại đơn cũ còn giữ địa chỉ ba cấp thì
  form tự hiện thêm ô Quận/Huyện để dữ liệu cũ không bị hỏng.
- **Quy đổi (gram)** tính ngay trên trình duyệt theo `dài × rộng × cao / 6`,
  con số chính thức lấy từ `weightConvert` hãng trả về khi tính cước.
- **Tính cước** lưu đơn rồi gọi `/ServicesCharge` cho đúng dịch vụ đang chọn và
  ghi lại cước vào đơn. **Xem cước các dịch vụ** hỏi cùng endpoint nhưng bỏ
  trống `serviceCode` nên hãng trả bảng cước của mọi dịch vụ đang mở cho tài
  khoản; lệnh này chỉ dựng đơn tạm trong bộ nhớ, không lưu gì.
- **Tạo đơn** / **Lưu nháp** lưu đơn rồi đẩy sang hãng (`orderCreationStatus`
  1 và 0). Đơn đã có số hiệu bưu gửi thì hai nút này nhường chỗ cho **Hiệu
  chỉnh đơn**. **Lưu** chỉ ghi tại chỗ, không gọi hãng.
- Các trường bắt buộc bám đúng cột *mustHave* của tài liệu `/CreateOrder`:
  người gửi và người nhận (tên, điện thoại, địa chỉ), dịch vụ, nội dung, khối
  lượng, hình thức gửi.

Những khối có trên cổng MyVNPost nhưng API public không nhận — bảng *Chi tiết
hàng hoá*, *Ảnh đính kèm*, *Loại hàng*, *Tủ PUDO*, *Hợp đồng C* — cố tình không
dựng, để không sinh ra dữ liệu mà hãng không bao giờ đọc. Hợp đồng lấy từ term
kết nối (`field_si_contract`) và hiện ngay dưới ô chọn kết nối.

Field hệ thống (số hiệu bưu gửi, trạng thái, cước, hành trình, vận đơn, dữ liệu
thô…) bị gỡ khỏi form: chỉ hãng và module ghi vào đó.

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
| Webhook hãng gọi về | `POST /shipping/webhook` (chiều ngược lại, xem mục Webhook) |

Xác thực bằng header `token` (không phải `Authorization: Bearer`). Khi hãng trả
401/403, `HandleShipping` xin token mới rồi gọi lại đúng một lần.

Đơn khai theo địa chỉ 2 cấp (`field_so_is_new_address`) thì mã quận/huyện gửi
lên là hằng `"VNPOST"` theo đúng quy định của tài liệu; đơn 3 cấp gửi mã huyện
thật.

Dịch vụ cộng thêm được sinh từ field của đơn: COD → `GTG021`/`PROP0018`, khai
giá → `GTG008`/`PROP0026`.

## Webhook

Hãng đẩy về mọi thay đổi thông tin và trạng thái của đơn, module nhận tại:

```
POST https://<tên miền site>/shipping/webhook
```

Đường dẫn này hiện sẵn ngay trên form của term kết nối để copy đi khai báo.

**Đăng ký với VNPost** (hãng không có API đăng ký, phải khai tay):

1. Khách hàng: khai URL tại chức năng *Cấu hình Webhook* trên cổng MyVNPost.
   Đối tác: URL được khai lúc khởi tạo thông tin đối tác.
2. Gửi yêu cầu để VNPost **add whitelist** cho URL và IP của site. Chưa được
   whitelist thì hãng không gọi về. Yêu cầu này phải gửi trước thời điểm golive.

**Xác thực**: gói tin có dạng `{data: [đơn...], sendDate, signature}`, chữ ký là
`RSASHA256("MYVNP" + sendDate + itemCode + status)` với `itemCode` và `status`
của bưu gửi đầu tiên trong mảng. Module kiểm tra chữ ký bằng khoá công khai RSA
2048 lưu ở `field_si_webhook_key` của term kết nối (mặc định là khoá trong tài
liệu MyVNPost, môi trường thật có thể khác). Ký sai là bỏ toàn bộ gói tin và
trả 401, không ghi gì vào cơ sở dữ liệu; để trống khoá cũng đồng nghĩa từ chối
mọi lời gọi.

**Xử lý**: mỗi bản ghi được tra theo số hiệu bưu gửi rồi tới ID gốc. Đơn chưa
có trên hệ thống thì bỏ qua và ghi log chứ không tạo đơn rỗng, vì webhook không
mang đủ thông tin người gửi, người nhận. Đơn tra được sẽ cập nhật trạng thái,
cước, tiền thu hộ, khối lượng tính cước, bưu cục phục vụ, mã đơn và nội dung;
bản ghi thô giữ nguyên trong `field_so_payload` để tra những mục module không
có cột lưu (lý do không phát được, người nhận thực tế, trạng thái thanh toán).

Hình thức gửi và yêu cầu khi phát cố tình **không** nhận từ webhook: gói tin mô
tả chúng bằng bộ mã khác (`TGTN`, `GHTBC`) với danh sách giá trị hợp lệ của
field, ghi vào sẽ làm hỏng dữ liệu đang có.

Phản hồi trả về hãng luôn là JSON `{success, message, updated}` kèm mã HTTP 200
khi nhận được, 400 khi gói tin sai định dạng, 401 khi chữ ký không hợp lệ.

## Thêm một hãng vận chuyển mới

1. Tạo `src/Plugin/Providers/<Ten>Provider.php` với attribute
   `#[ShippingProvidersAttribute(id: "ma_plugin", label: ...)]` và cài đặt
   `ShippingProvidersInterface`.
2. Thêm bundle `shipping_order_type` trùng tên plugin, kèm bộ field riêng của
   hãng.
3. Tạo entity `shipping_type` với `field_code` = mã plugin, rồi tạo term kết
   nối trỏ tới nó.

## Bản dịch tiếng Việt

Chuỗi giao diện của module nằm ở `translations/shipping_integration.vi.po`,
`shipping_integration.info.yml` đã khai báo `interface translation project` và
`interface translation server pattern` để locale tìm tới file này. File chỉ có tác dụng khi site đã cài
ngôn ngữ tiếng Việt (module `language` + `locale`, thêm tiếng Việt tại
`/admin/config/regional/language`); site chỉ có tiếng Anh thì giao diện vẫn hiện
chuỗi gốc. Sau khi bật tiếng Việt, nạp bản dịch bằng `drush locale:import vi
modules/custorm/shipping_integration/translations/shipping_integration.vi.po`
(hoặc `drush locale:check && drush locale:update`), rồi `drush cr`. Thêm chuỗi
mới thì bổ sung cặp `msgid`/`msgstr` vào file rồi import lại.

## Giới hạn

Module chỉ phục vụ đơn hàng trong nước. Các endpoint quốc tế của MyVNPost
(`create-international`, `update-international`, danh mục quốc gia và thành phố
quốc tế) cố tình không được cài đặt.

Tài liệu API: <https://my-uat.vnpost.vn/static/>
