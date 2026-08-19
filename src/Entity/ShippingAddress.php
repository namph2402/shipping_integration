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
use Drupal\shipping_integration\Form\ShippingAddressForm;
use Drupal\shipping_integration\ShippingAddressInterface;
use Drupal\shipping_integration\ShippingAddressListBuilder;
use Drupal\views\EntityViewsData;

/**
 * Defines the shipping address entity class.
 */
#[ContentEntityType(
  id: 'shipping_address',
  label: new TranslatableMarkup('Shipping address'),
  label_collection: new TranslatableMarkup('Shipping addresses'),
  label_singular: new TranslatableMarkup('shipping address'),
  label_plural: new TranslatableMarkup('shipping addresses'),
  entity_keys: [
    'id' => 'id',
    'bundle' => 'bundle',
    'label' => 'label',
    'published' => 'status',
    'uuid' => 'uuid',
  ],
  handlers: [
    'list_builder' => ShippingAddressListBuilder::class,
    'views_data' => EntityViewsData::class,
    'form' => [
      'add' => ShippingAddressForm::class,
      'edit' => ShippingAddressForm::class,
      'delete' => ContentEntityDeleteForm::class,
      'delete-multiple-confirm' => DeleteMultipleForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  links: [
    'collection' => '/admin/content/shipping-address',
    'add-form' => '/shipping-address/add/{shipping_address_type}',
    'add-page' => '/shipping-address/add',
    'canonical' => '/shipping-address/{shipping_address}',
    'edit-form' => '/shipping-address/{shipping_address}/edit',
    'delete-form' => '/shipping-address/{shipping_address}/delete',
    'delete-multiple-form' => '/admin/content/shipping-address/delete-multiple',
  ],
  admin_permission: 'administer shipping_address types',
  bundle_entity_type: 'shipping_address_type',
  bundle_label: new TranslatableMarkup('Shipping address type'),
  base_table: 'shipping_address',
  label_count: [
    'singular' => '@count shipping addresses',
    'plural' => '@count shipping addresses',
  ],
  field_ui_base_route: 'entity.shipping_address_type.edit_form',
)]
class ShippingAddress extends ContentEntityBase implements ShippingAddressInterface {

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
      ->setDescription(t('The time that the shipping address was created.'))
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
      ->setDescription(t('The time that the shipping address was last edited.'));

    return $fields;
  }

}
