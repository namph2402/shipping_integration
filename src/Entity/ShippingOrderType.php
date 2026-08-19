<?php

declare(strict_types=1);

namespace Drupal\shipping_integration\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBundleBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityDeleteForm;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\shipping_integration\Form\ShippingOrderTypeForm;
use Drupal\shipping_integration\ShippingOrderTypeListBuilder;

/**
 * Defines the Shipping order type configuration entity.
 */
#[ConfigEntityType(
  id: 'shipping_order_type',
  label: new TranslatableMarkup('Shipping order type'),
  label_collection: new TranslatableMarkup('Shipping order types'),
  label_singular: new TranslatableMarkup('shipping order type'),
  label_plural: new TranslatableMarkup('shipping orders types'),
  config_prefix: 'shipping_order_type',
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'uuid' => 'uuid',
  ],
  handlers: [
    'list_builder' => ShippingOrderTypeListBuilder::class,
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
    'form' => [
      'add' => ShippingOrderTypeForm::class,
      'edit' => ShippingOrderTypeForm::class,
      'delete' => EntityDeleteForm::class,
    ],
  ],
  links: [
    'add-form' => '/admin/structure/shipping_order_types/add',
    'edit-form' => '/admin/structure/shipping_order_types/manage/{shipping_order_type}',
    'delete-form' => '/admin/structure/shipping_order_types/manage/{shipping_order_type}/delete',
    'collection' => '/admin/structure/shipping_order_types',
  ],
  admin_permission: 'administer shipping_order types',
  bundle_of: 'shipping_order',
  label_count: [
    'singular' => '@count shipping order type',
    'plural' => '@count shipping orders types',
  ],
  config_export: [
    'id',
    'label',
    'uuid',
  ],
)]
final class ShippingOrderType extends ConfigEntityBundleBase {

  /**
   * The machine name of this shipping order type.
   */
  protected string $id;

  /**
   * The human-readable name of the shipping order type.
   */
  protected string $label;

}
