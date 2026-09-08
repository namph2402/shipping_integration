/**
 * @file
 * Tương tác trên trang danh sách và trang chi tiết đơn vận chuyển.
 */

(function (Drupal, once) {
  'use strict';

  /**
   * Thu thập id các đơn đang được tích chọn trong bảng.
   *
   * @param {HTMLElement} scope
   *   Vùng chứa bảng danh sách.
   *
   * @return {string[]}
   *   Danh sách id.
   */
  function selectedIds(scope) {
    return Array.from(scope.querySelectorAll('.shipping-check:checked')).map(function (input) {
      return input.value;
    });
  }

  Drupal.behaviors.shippingOrderFilter = {
    attach: function (context) {
      once('shipping-filter', '.list-shipping-order .header-wrapper', context).forEach(function (wrapper) {
        var form = wrapper.querySelector('form[method="GET"]');

        if (form) {
          // Nút lọc nhanh theo ngày và ô lọc thủ công dùng chung querystring,
          // nên phải xoá search_date khi người dùng tự chọn khoảng ngày.
          form.addEventListener('submit', function (event) {
            event.preventDefault();

            var url = new URL(window.location.href);
            var params = new URLSearchParams(new FormData(form));

            params.delete('search_date');
            url.search = params.toString();
            window.location.href = url.toString();
          });
        }

        wrapper.querySelectorAll('.btn-search-date').forEach(function (button) {
          button.addEventListener('click', function () {
            var url = new URL(window.location.href);
            var params = new URLSearchParams(url.search);

            params.set('search_date', this.dataset.searchDate);
            params.delete('start_date');
            params.delete('end_date');

            url.search = params.toString();
            window.location.href = url.toString();
          });
        });
      });
    }
  };

  Drupal.behaviors.shippingOrderSelection = {
    attach: function (context) {
      once('shipping-selection', '.shipping-table', context).forEach(function (table) {
        var checkAll = table.querySelector('.shipping-check-all');

        if (!checkAll) {
          return;
        }

        checkAll.addEventListener('change', function () {
          // Chỉ tích các dòng đang hiển thị, để người dùng lọc rồi chọn tất cả
          // mà không vô tình gửi cả những dòng đã bị ẩn ở trang khác.
          table.querySelectorAll('tbody tr').forEach(function (row) {
            var input = row.querySelector('.shipping-check');

            if (input && row.style.display !== 'none') {
              input.checked = checkAll.checked;
            }
          });
        });
      });
    }
  };

  Drupal.behaviors.shippingOrderAction = {
    attach: function (context) {
      once('shipping-action', '.btn-shipping-action', context).forEach(function (button) {
        button.addEventListener('click', function () {
          var scope = button.closest('.list-shipping-order, .detail-shipping-order');
          var form = scope ? scope.querySelector('.shipping-action-form') : null;

          if (!form) {
            return;
          }

          // Trang chi tiết chỉ thao tác trên đúng một đơn nên gắn sẵn id vào
          // nút, còn trang danh sách thì lấy theo ô tích chọn.
          var ids = button.dataset.ids
            ? button.dataset.ids.split(',')
            : selectedIds(scope);

          if (!ids.length) {
            window.alert(Drupal.t('Please choose at least one order.'));
            return;
          }

          if (button.dataset.confirm && !window.confirm(button.dataset.confirm)) {
            return;
          }

          form.action = button.dataset.action;
          form.querySelector('input[name="order_ids"]').value = ids.join(',');
          form.querySelector('input[name="draft"]').value = button.dataset.draft || '0';
          form.submit();
        });
      });
    }
  };

  Drupal.behaviors.shippingOrderHistory = {
    attach: function (context) {
      once('shipping-history', '.btn-shipping-history', context).forEach(function (button) {
        button.addEventListener('click', function () {
          var modal = document.getElementById('historyShippingOrderModal');

          if (!modal) {
            return;
          }

          var body = modal.querySelector('.shipping-history-body');
          var code = modal.querySelector('.shipping-history-code');

          code.textContent = button.dataset.itemCode || '';
          body.innerHTML = '<p class="text-muted mb-0">' + Drupal.t('Loading...') + '</p>';

          fetch(button.dataset.url, { headers: { Accept: 'application/json' } })
            .then(function (response) {
              return response.json();
            })
            .then(function (result) {
              if (!result.success || !result.data || !result.data.length) {
                body.innerHTML = '<p class="text-muted mb-0">'
                  + Drupal.checkPlain(result.message || Drupal.t('No tracking history yet.'))
                  + '</p>';
                return;
              }

              var rows = result.data.map(function (step) {
                return '<li><span class="shipping-timeline-date">'
                  + Drupal.checkPlain(step.date + ' ' + step.hour)
                  + '</span><span class="shipping-timeline-status">'
                  + Drupal.checkPlain(step.status_name)
                  + '</span></li>';
              });

              body.innerHTML = '<ul class="shipping-timeline mb-0">' + rows.join('') + '</ul>';
            })
            .catch(function () {
              body.innerHTML = '<p class="text-danger mb-0">'
                + Drupal.t('Cannot load the tracking history.')
                + '</p>';
            });
        });
      });
    }
  };
})(Drupal, once);
