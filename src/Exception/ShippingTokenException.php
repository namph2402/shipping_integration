<?php

namespace Drupal\shipping_integration\Exception;

/**
 * Ném ra khi hãng vận chuyển từ chối token.
 *
 * Tầng nghiệp vụ bắt ngoại lệ này để xin token mới rồi gọi lại đúng một lần,
 * thay vì trả lỗi thẳng cho người dùng.
 */
class ShippingTokenException extends \RuntimeException {

}
