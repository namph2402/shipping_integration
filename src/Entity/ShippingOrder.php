<?php

declare(strict_types=1);

namespace Drupal\shipping_integration\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Form\DeleteMultipleForm;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\shipping_integration\Form\ShippingOrderForm;
use Drupal\shipping_integration\ShippingOrderInterface;
use Drupal\shipping_integration\ShippingOrderListBuilder;
use Drupal\views\EntityViewsData;

/**
 * Defines the shipping order entity class.
 */
#[ContentEntityType(
  id: 'shipping_order',
  label: new TranslatableMarkup('Shipping order'),
  label_collection: new TranslatableMarkup('Shipping orders'),
  label_singular: new TranslatableMarkup('shipping order'),
  label_plural: new TranslatableMarkup('shipping orders'),
  entity_keys: [
    'id' => 'id',
    'bundle' => 'bundle',
    'label' => 'label',
    'published' => 'status',
    'uuid' => 'uuid',
  ],
  handlers: [
    'list_builder' => ShippingOrderListBuilder::class,
    'views_data' => EntityViewsData::class,
    'form' => [
      'add' => ShippingOrderForm::class,
      'edit' => ShippingOrderForm::class,
      'delete' => ContentEntityDeleteForm::class,
      'delete-multiple-confirm' => DeleteMultipleForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  links: [
    'collection' => '/admin/content/shipping-order',
    'add-form' => '/shipping-order/add/{shipping_order_type}',
    'add-page' => '/shipping-order/add',
    'canonical' => '/shipping-order/{shipping_order}',
    'edit-form' => '/shipping-order/{shipping_order}/edit',
    'delete-form' => '/shipping-order/{shipping_order}/delete',
    'delete-multiple-form' => '/admin/content/shipping-order/delete-multiple',
  ],
  admin_permission: 'administer shipping_order types',
  bundle_entity_type: 'shipping_order_type',
  bundle_label: new TranslatableMarkup('Shipping order type'),
  base_table: 'shipping_order',
  label_count: [
    'singular' => '@count shipping orders',
    'plural' => '@count shipping orders',
  ],
  field_ui_base_route: 'entity.shipping_order_type.edit_form',
)]
class ShippingOrder extends ContentEntityBase implements ShippingOrderInterface {

  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {

    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Label'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'string',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['status'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Status'))
      ->setDefaultValue(TRUE)
      ->setSetting('on_label', 'Enabled')
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'settings' => [
          'display_label' => FALSE,
        ],
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'type' => 'boolean',
        'label' => 'above',
        'weight' => 0,
        'settings' => [
          'format' => 'enabled-disabled',
        ],
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Authored on'))
      ->setDescription(t('The time that the shipping order was created.'))
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'timestamp',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('form', [
        'type' => 'datetime_timestamp',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time that the shipping order was last edited.'));

    return $fields;
  }

}
