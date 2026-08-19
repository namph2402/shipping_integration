<?php

namespace Drupal\shipping_integration;

/**
 * Interface for Shipping providers plugins.
 */
interface ShippingProvidersInterface {

    /**
     * Get token.
     *
     * @param array $config
     *   Configuration array.
     *
     * @return mixed
     *   Return token.
     */
    public function getToken(array $config);

    /**
     * Đồng bộ danh mục địa chỉ cũ và mới.
     *
     * @param array $config
     *   Configuration array.
     *
     * @return array
     *   Mảng gồm success và data, mỗi phần tử data có bundle, is_new, code,
     *   label, province_code, district_code.
     */
    public function synchronizeAddresses(array $config): array;
}
