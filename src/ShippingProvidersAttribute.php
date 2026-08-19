<?php

namespace Drupal\shipping_integration;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines an address provider item annotation object.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class ShippingProvidersAttribute extends Plugin {

  /**
   * Constructs a shipping attribute.
   *
   * @param string $id
   *   The plugin ID.
   * @param TranslatableMarkup|null $label
   *   The administrative label of the provider.
   */
  public function __construct(
    public readonly string $id,
    public ?TranslatableMarkup $label = NULL,
  ) {}

}
