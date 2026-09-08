<?php

declare(strict_types=1);

namespace Drupal\shipping_integration\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\shipping_integration\Service\GetConfigShipping;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Sinh một nút đồng bộ địa chỉ cho mỗi cấu hình kết nối.
 *
 * Route đồng bộ nhận term cấu hình làm tham số, mà trang danh sách địa chỉ lại
 * không có term nào trong route match, nên tham số phải được nhúng sẵn vào
 * từng derivative.
 */
final class SynchronizeAddressesLocalAction extends DeriverBase implements ContainerDeriverInterface {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id) {
    return new static(
      $container->get("entity_type.manager"),
      $container->get("string_translation"),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition): array {
    $terms = $this->entityTypeManager
      ->getStorage("taxonomy_term")
      ->loadByProperties(["vid" => GetConfigShipping::VOCABULARY]);

    foreach ($terms as $term) {
      $this->derivatives[$term->id()] = [
        "title" => $this->t("Synchronize address: @config", ["@config" => $term->label()]),
        "route_parameters" => ["taxonomy_term" => $term->id()],
      ] + $base_plugin_definition;
    }

    return $this->derivatives;
  }

}
