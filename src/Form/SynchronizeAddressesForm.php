<?php

declare(strict_types=1);

namespace Drupal\shipping_integration\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\shipping_integration\Service\GetConfigShipping;
use Drupal\shipping_integration\Service\SynchronizeAddresses;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Xác nhận trước khi đồng bộ danh mục địa chỉ của một cấu hình kết nối.
 *
 * Lệnh đồng bộ kéo hàng chục nghìn bản ghi và chạy đồng bộ ngay trong request
 * nên hỏi lại một bước; bước hỏi này cũng là thứ cấp cho form token, thay cho
 * _csrf_token vốn khiến link không thể mở thẳng từ trình duyệt.
 */
final class SynchronizeAddressesForm extends ConfirmFormBase {

  /**
   * Term cấu hình kết nối đang đồng bộ.
   */
  protected TermInterface $configEntity;

  /**
   * The form constructor.
   */
  public function __construct(
    protected SynchronizeAddresses $synchronizeAddresses,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get("shipping_integration.synchronize_addresses"),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return "shipping_integration_synchronize_addresses";
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t("Synchronize address ?", [
      "@config" => $this->configEntity->label(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t("Address categories can have tens of thousands of records, so this command takes a long time to run. Existing addresses will be skipped, so you can run it multiple times.");
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t("Synchronize");
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute("entity.shipping_address.collection");
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?TermInterface $taxonomy_term = NULL): array {
    if (!$taxonomy_term instanceof TermInterface || $taxonomy_term->bundle() !== GetConfigShipping::VOCABULARY) {
      throw new NotFoundHttpException();
    }

    $this->configEntity = $taxonomy_term;

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $result = $this->synchronizeAddresses->synchronize($this->configEntity);

    if (empty($result["success"])) {
      $this->messenger()->addError($result["message"] ?? $this->t("Synchronize addresses failed"));
    }
    else {
      $this->messenger()->addStatus($result["message"]);
    }

    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
