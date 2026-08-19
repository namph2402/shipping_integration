<?php

declare(strict_types=1);

namespace Drupal\shipping_integration\Controller;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Drupal\Core\Controller\ControllerBase;
use Drupal\taxonomy\Entity\Term;
use Drupal\shipping_integration\Service\HandleShipping;
use Drupal\shipping_integration\Service\SynchronizeAddresses;

/**
 * Returns responses for shipping_integration routes.
 */
final class ShippingController extends ControllerBase {

  /**
   * The controller constructor.
   */
  public function __construct(
    protected RequestStack $request_stack,
    protected HandleShipping $handleShipping,
    protected SynchronizeAddresses $synchronizeAddresses,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('request_stack'),
      $container->get('shipping_integration.handle_shipping'),
      $container->get('shipping_integration.synchronize_addresses')
    );
  }

  /**
   * Danh sách đơn vận.
   */
  public function listShippingOrder(Request $request, $uuid) {
    $data = [];

    $stores = $this->entityTypeManager()
      ->getStorage('commerce_store')
      ->loadByProperties([
        'uuid' => $uuid,
      ]);

    if (!$store = reset($stores)) {
      throw new NotFoundHttpException();
    }

    $start_date = !empty($request->query->get('start_date'))
      ? $request->query->get('start_date')
      : date('Y-m-01');

    $end_date = !empty($request->query->get('end_date'))
      ? $request->query->get('end_date')
      : date('Y-m-d');

    $start = new \DateTime($start_date . ' 00:00:00');
    $end = new \DateTime($end_date . ' 23:59:59');
    $status = $request->query->get('status') ?? '';

    $invoiceManage = $this->entityTypeManager()->getStorage('invoice');

    $invoices_query = $invoiceManage->getQuery()
      ->condition('bundle', 'input_invoices')
      ->condition('field_invoice_store', $store->id())
      ->condition('field_invoice_date.value', $start->format('Y-m-d\TH:i:s'), '>=')
      ->condition('field_invoice_date.value', $end->format('Y-m-d\TH:i:s'), '<=')
      ->sort('field_invoice_date', 'DESC')
      ->sort('created', 'DESC')
      ->pager(20)
      ->accessCheck(FALSE);

    if ($status != '') {
      $invoices_query->condition('field_invoice_status', $status);
    }

    $invoice_ids = $invoices_query->execute();
    $list_invoices = $invoiceManage->loadMultiple($invoice_ids);

    $allowed_values = $this->invoiceSv->allowedValueStatus('output_invoices');

    /** @var \Drupal\e_invoice\Entity\Invoice $invoice */
    foreach ($list_invoices as $invoice) {
      $invoice_no = $invoice->get('field_invoice_no')->value;
      $invoice_seller = $invoice->get('field_invoice_seller_name')->value ?? '';
      $invoice_seller_taxcode = $invoice->get('field_invoice_seller_taxcode')->value ?? '';
      $invoice_buyer = $invoice->get('field_invoice_buyer_name')->value ?? '';
      $invoice_buyer_taxcode = $invoice->get('field_invoice_buyer_taxcode')->value ?? '';
      $invoice_amount = $invoice->get('field_invoice_amount')->value ?? 0;
      $invoice_discount_amount = $invoice->get('field_invoice_discount_amount')->value ?? 0;
      $invoice_amount_without_vat = $invoice->get('field_invoice_amount_without_vat')->value ?? 0;
      $invoice_vat_amount = $invoice->get('field_invoice_vat_amount')->value ?? 0;
      $invoice_total_amount = $invoice->get('field_invoice_total_amount')->value ?? 0;
      $invoice_accountant = $invoice->get('field_invoice_accountant')->value ?? '';
      $invoice_accountant_date = $invoice->get('field_invoice_accounting_date')->value ?? '';
      $invoice_pdf = $invoice->get('field_invoice_pdf')->entity ?? NULL;
      $invoice_xml = $invoice->get('field_invoice_xml')->entity ?? NULL;

      $status_value = $invoice->get('field_invoice_status')->value;
      $invoice_status = $allowed_values[$status_value];

      $date = new DrupalDateTime($invoice->get('field_invoice_date')->value, 'UTC');

      /** @var \Drupal\file\Entity\File $invoice_pdf */
      if ($invoice_pdf) {
        $invoice_pdf = $this->fileUrlGenerator->generateAbsoluteString($invoice_pdf->getFileUri());
      }

      /** @var \Drupal\file\Entity\File $invoice_xml */
      if ($invoice_xml) {
        $invoice_xml = $this->fileUrlGenerator->generateAbsoluteString($invoice_xml->getFileUri());
      }

      $data[] = [
        'uuid' => $invoice->uuid(),
        'invoice_name' => $invoice->label(),
        'invoice_no' => $invoice_no,
        'invoice_date' => $date->setTimezone(new \DateTimeZone('Asia/Ho_Chi_Minh')),
        'invoice_seller' => $invoice_seller,
        'invoice_seller_taxcode' => $invoice_seller_taxcode,
        'invoice_buyer' => $invoice_buyer,
        'invoice_buyer_taxcode' => $invoice_buyer_taxcode,
        'invoice_amount_without_vat' => $invoice_amount_without_vat,
        'invoice_discount_amount' => $invoice_discount_amount,
        'invoice_vat_amount' => $invoice_vat_amount,
        'invoice_total_amount' => $invoice_total_amount,
        'invoice_accountant' => $invoice_accountant,
        'invoice_accountant_date' => $invoice_accountant_date,
        'invoice_pdf' => $invoice_pdf,
        'invoice_xml' => $invoice_xml,
      ];
    }

    return [
      '#theme' => 'list_input_invoice',
      '#data' => $data,
      '#filter' => [
        'store_id' => $store->id(),
        'date' => [
          'start' => $start_date ? date('Y-m-d', strtotime($start_date)) : NULL,
          'end' => $end_date ? date('Y-m-d', strtotime($end_date)) : NULL,
        ],
        'status' => $status,
        'option_status' => $allowed_values,
        'destination' => $this->request_stack->getCurrentRequest()->getRequestUri(),
        'current_user' => $this->currentUser()->getAccountName(),
        'pager' => [
          '#type' => 'pager',
        ],
      ],
      '#attached' => [
        'library' => [
          'tekshot_invoice/e_invoice',
        ],
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Đồng bộ địa chỉ.
   */
  public function synchronizeAddresses(Term $config): array {

    // $config = $this->entityTypeManager()
    //   ->getStorage('taxonomy_term')
    //   ->load(349);

    $synchronize_addresses = $this->synchronizeAddresses->synchronize($config);

    $build['content'] = [
      '#type' => 'item',
      '#markup' => $this->t('It works!'),
    ];

    return $build;
  }



}
