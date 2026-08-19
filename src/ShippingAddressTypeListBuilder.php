<?php

declare(strict_types=1);

namespace Drupal\shipping_integration;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Url;

/**
 * Defines a class to build a listing of shipping address type entities.
 *
 * @see \Drupal\shipping_integration\Entity\ShippingAddressType
 */
final class ShippingAddressTypeListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['label'] = $this->t('Label');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    $row['label'] = $entity->label();
    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    $build = parent::render();

    $build['table']['#empty'] = $this->t(
      'No shipping address types available. <a href=":link">Add shipping address type</a>.',
      [':link' => Url::fromRoute('entity.shipping_address_type.add_form')->toString()],
    );

    return $build;
  }

}
