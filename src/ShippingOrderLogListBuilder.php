<?php

declare(strict_types=1);

namespace Drupal\shipping_integration;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;

/**
 * Bảng liệt kê nhật ký đơn vận chuyển, mới nhất lên trước.
 */
final class ShippingOrderLogListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  protected function getEntityIds(): array {
    return $this->getStorage()->getQuery()
      ->accessCheck(TRUE)
      ->sort('id', 'DESC')
      ->pager(50)
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    return [
      'created' => $this->t('Time'),
      'item_code' => $this->t('Item code'),
      'source' => $this->t('Source'),
      'status' => $this->t('Status change'),
      'message' => $this->t('Message'),
      'actor' => $this->t('Performed by'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\shipping_integration\Entity\ShippingOrderLog $entity */
    $from = (string) $entity->get('status_from')->value;
    $to = (string) $entity->get('status_to')->value;

    $user = $entity->get('uid')->entity;
    $ip = (string) $entity->get('ip')->value;

    $row['created'] = \Drupal::service('date.formatter')
      ->format((int) $entity->get('created')->value, 'short');
    $row['item_code'] = (string) $entity->get('item_code')->value;
    $row['source'] = $entity->sourceLabel()
      . (empty($entity->get('succeeded')->value) ? ' — ' . $this->t('failed') : '');
    $row['status'] = $from === $to || $to === '' ? $to : $from . ' → ' . $to;
    $row['message'] = (string) $entity->get('message')->value;
    $row['actor'] = $user !== NULL
      ? $user->getAccountName()
      : ($ip !== '' ? $ip : $this->t('system'));

    return $row + parent::buildRow($entity);
  }

}
