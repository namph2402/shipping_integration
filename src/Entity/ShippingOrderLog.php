<?php

declare(strict_types=1);

namespace Drupal\shipping_integration\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\shipping_integration\ShippingOrderLogListBuilder;

/**
 * Một dòng nhật ký ghi lại một thay đổi của đơn vận chuyển.
 *
 * Đơn hàng chỉ giữ giá trị mới nhất của từng trường nên mỗi lần webhook gọi về
 * hay mỗi lệnh đồng bộ đều đè mất giá trị cũ. Entity này ghi lại từng lần thay
 * đổi kèm nguồn gốc gây ra nó, để về sau còn truy được đơn đã đi qua những
 * trạng thái nào, do đâu mà đổi, và gói tin gốc của hãng ra sao.
 *
 * Bản ghi chỉ thêm chứ không sửa: hai webhook đến cùng lúc vẫn ra hai dòng
 * riêng, không tranh chấp như khi cùng nối vào một trường JSON trên đơn.
 */
#[ContentEntityType(
  id: 'shipping_order_log',
  label: new TranslatableMarkup('Shipping order log'),
  label_collection: new TranslatableMarkup('Shipping order logs'),
  label_singular: new TranslatableMarkup('shipping order log'),
  label_plural: new TranslatableMarkup('shipping order logs'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'message',
  ],
  handlers: [
    'list_builder' => ShippingOrderLogListBuilder::class,
    'views_data' => 'Drupal\views\EntityViewsData',
  ],
  links: [
    'collection' => '/admin/content/shipping-order-log',
  ],
  admin_permission: 'access shipping order',
  base_table: 'shipping_order_log',
  label_count: [
    'singular' => '@count shipping order logs',
    'plural' => '@count shipping order logs',
  ],
)]
class ShippingOrderLog extends ContentEntityBase {

  /**
   * Các nguồn có thể gây ra thay đổi, dùng chung cho cả ghi lẫn lọc.
   */
  public const SOURCES = [
    'create' => 'Tạo đơn',
    'draft' => 'Tạo đơn nháp',
    'confirm_draft' => 'Phát hành đơn nháp',
    'update' => 'Hiệu chỉnh đơn',
    'cancel' => 'Hủy đơn',
    'approval' => 'Kết quả phê duyệt',
    'fee' => 'Tính cước phí',
    'label' => 'In vận đơn',
    'sync' => 'Đồng bộ đơn',
    'history' => 'Tra hành trình',
    'pull' => 'Kéo đơn về',
    'webhook' => 'Webhook hãng đẩy về',
    'manual' => 'Sửa tay trên form',
  ];

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['order_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Shipping order'))
      ->setSetting('target_type', 'shipping_order')
      ->setDescription(new TranslatableMarkup('Đơn hàng mà dòng nhật ký này thuộc về.'));

    // Giữ riêng số hiệu bưu gửi để nhật ký còn đọc được khi đơn đã bị xóa, và
    // để tra theo mã mà không phải nạp entity đơn hàng.
    $fields['item_code'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Item code'))
      ->setSetting('max_length', 64)
      ->setSetting('is_ascii', TRUE);

    $fields['source'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Source'))
      ->setSetting('max_length', 32)
      ->setSetting('is_ascii', TRUE)
      ->setDescription(new TranslatableMarkup('Thao tác đã gây ra thay đổi.'));

    $fields['status_from'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Status before'))
      ->setSetting('max_length', 16);

    $fields['status_to'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Status after'))
      ->setSetting('max_length', 16);

    $fields['succeeded'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Succeeded'))
      ->setDefaultValue(TRUE);

    $fields['message'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Message'))
      ->setSetting('max_length', 255);

    $fields['payload'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Payload'))
      ->setDescription(new TranslatableMarkup('Dữ liệu gốc của thao tác, giữ nguyên để đối chiếu về sau.'));

    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('User'))
      ->setSetting('target_type', 'user')
      ->setDescription(new TranslatableMarkup('Người thực hiện, để trống khi do hãng hoặc cron gây ra.'));

    $fields['ip'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Client IP'))
      ->setSetting('max_length', 45)
      ->setSetting('is_ascii', TRUE);

    // Webhook chưa xác thực được chữ ký vẫn phải ghi lại, nhưng phải phân biệt
    // được với gói tin đã kiểm, nếu không thì nhật ký tự nó cũng không đáng tin.
    $fields['verified'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Signature verified'))
      ->setDefaultValue(FALSE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'));

    return $fields;
  }

  /**
   * Tên nguồn hiển thị cho người đọc.
   *
   * @return string
   *   Nhãn tiếng Việt của nguồn, trả lại mã gốc khi không có nhãn.
   */
  public function sourceLabel(): string {
    $source = (string) $this->get('source')->value;

    return self::SOURCES[$source] ?? $source;
  }

}
