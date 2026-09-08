<?php

declare(strict_types=1);

namespace Drupal\shipping_integration\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\shipping_integration\Service\GetConfigShipping;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Các thao tác trên một cấu hình kết nối hãng vận chuyển.
 */
final class ShippingController extends ControllerBase {

  /**
   * The controller constructor.
   */
  public function __construct(
    protected GetConfigShipping $getConfig,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get("shipping_integration.get_config"),
    );
  }

  /**
   * Lấy lại token cho một cấu hình kết nối.
   *
   * @param TermInterface $taxonomy_term
   *   Term cấu hình kết nối.
   * @param Request $request
   *   Request hiện tại.
   *
   * @return RedirectResponse
   *   Quay lại trang term.
   */
  public function refreshToken(TermInterface $taxonomy_term, Request $request): RedirectResponse {
    if ($this->getConfig->refreshToken($taxonomy_term) !== NULL) {
      $taxonomy_term->skip_call_token = TRUE;
      $taxonomy_term->save();
    }

    return new RedirectResponse(
      $request->query->get("destination") ?: $taxonomy_term->toUrl()->toString()
    );
  }

}
