<?php

namespace Drupal\shipping_integration;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;

/**
 * Shipping providers plugin manager.
 */
class ShippingProvidersPluginManager extends DefaultPluginManager {

  /**
   * Constructs the object.
   */
  public function __construct(\Traversable $namespaces,
    CacheBackendInterface $cache_backend,
    ModuleHandlerInterface $module_handler) {
    parent::__construct(
      'Plugin/Providers',
      $namespaces,
      $module_handler,
      'Drupal\shipping_integration\ShippingProvidersInterface',
      'Drupal\shipping_integration\ShippingProvidersAttribute',
    );
    $this->setCacheBackend($cache_backend, 'shipping_providers_plugins');
  }

}
