<?php

declare(strict_types=1);

namespace Drupal\shipping_integration\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBundleBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityDeleteForm;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\shipping_integration\Form\ShippingAddressTypeForm;
use Drupal\shipping_integration\ShippingAddressTypeListBuilder;

/**
 * Defines the Shipping address type configuration entity.
 */
#[ConfigEntityType(
  id: 'shipping_address_type',
  label: new TranslatableMarkup('Shipping address type'),
  label_collection: new TranslatableMarkup('Shipping address types'),
  label_singular: new TranslatableMarkup('shipping address type'),
  label_plural: new TranslatableMarkup('shipping addresses types'),
  config_prefix: 'shipping_address_type',
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'uuid' => 'uuid',
  ],
  handlers: [
    'list_builder' => ShippingAddressTypeListBuilder::class,
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
    'form' => [
      'add' => ShippingAddressTypeForm::class,
      'edit' => ShippingAddressTypeForm::class,
      'delete' => EntityDeleteForm::class,
    ],
  ],
  links: [
    'add-form' => '/admin/structure/shipping_address_types/add',
    'edit-form' => '/admin/structure/shipping_address_types/manage/{shipping_address_type}',
    'delete-form' => '/admin/structure/shipping_address_types/manage/{shipping_address_type}/delete',
    'collection' => '/admin/structure/shipping_address_types',
  ],
  admin_permission: 'administer shipping_address types',
  bundle_of: 'shipping_address',
  label_count: [
    'singular' => '@count shipping address type',
    'plural' => '@count shipping addresses types',
  ],
  config_export: [
    'id',
    'label',
    'uuid',
  ],
)]
final class ShippingAddressType extends ConfigEntityBundleBase {

  /**
   * The machine name of this shipping address type.
   */
  protected string $id;

  /**
   * The human-readable name of the shipping address type.
   */
  protected string $label;

}
