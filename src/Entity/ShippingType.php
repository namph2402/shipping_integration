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
use Drupal\shipping_integration\Form\ShippingTypeForm;
use Drupal\shipping_integration\ShippingTypeInterface;
use Drupal\shipping_integration\ShippingTypeListBuilder;
use Drupal\views\EntityViewsData;

/**
 * Defines the shipping type entity class.
 */
#[ContentEntityType(
  id: 'shipping_type',
  label: new TranslatableMarkup('Shipping type'),
  label_collection: new TranslatableMarkup('Shipping types'),
  label_singular: new TranslatableMarkup('shipping type'),
  label_plural: new TranslatableMarkup('shipping types'),
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'published' => 'status',
    'uuid' => 'uuid',
  ],
  handlers: [
    'list_builder' => ShippingTypeListBuilder::class,
    'views_data' => EntityViewsData::class,
    'form' => [
      'add' => ShippingTypeForm::class,
      'edit' => ShippingTypeForm::class,
      'delete' => ContentEntityDeleteForm::class,
      'delete-multiple-confirm' => DeleteMultipleForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  links: [
    'collection' => '/admin/content/shipping-type',
    'add-form' => '/shipping-type/add',
    'canonical' => '/shipping-type/{shipping_type}',
    'edit-form' => '/shipping-type/{shipping_type}/edit',
    'delete-form' => '/shipping-type/{shipping_type}/delete',
    'delete-multiple-form' => '/admin/content/shipping-type/delete-multiple',
  ],
  admin_permission: 'administer shipping_type',
  base_table: 'shipping_type',
  label_count: [
    'singular' => '@count shipping types',
    'plural' => '@count shipping types',
  ],
  field_ui_base_route: 'entity.shipping_type.settings',
)]
class ShippingType extends ContentEntityBase implements ShippingTypeInterface {

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
      ->setDescription(t('The time that the shipping type was created.'))
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
      ->setDescription(t('The time that the shipping type was last edited.'));

    return $fields;
  }

}
