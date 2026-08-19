<?php

declare(strict_types=1);

namespace Drupal\shipping_integration;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;

/**
 * Provides an interface defining a shipping order entity type.
 */
interface ShippingOrderInterface extends ContentEntityInterface, EntityChangedInterface {

}
