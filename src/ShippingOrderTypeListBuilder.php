<?php

declare(strict_types=1);

namespace Drupal\shipping_integration;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Url;

/**
 * Defines a class to build a listing of shipping order type entities.
 *
 * @see \Drupal\shipping_integration\Entity\ShippingOrderType
 */
final class ShippingOrderTypeListBuilder extends ConfigEntityListBuilder {

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
      'No shipping order types available. <a href=":link">Add shipping order type</a>.',
      [':link' => Url::fromRoute('entity.shipping_order_type.add_form')->toString()],
    );

    return $build;
  }

}
