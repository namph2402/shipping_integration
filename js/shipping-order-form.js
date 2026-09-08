/**
 * @file
 * Tương tác trên form tạo và sửa đơn vận chuyển.
 */

(function (Drupal, once) {
  'use strict';

  /**
   * Định dạng số theo kiểu tiền tệ trong nước.
   *
   * @param {number} value
   *   Giá trị cần định dạng.
   *
   * @return {string}
   *   Chuỗi đã chấm phân cách hàng nghìn.
   */
  function formatNumber(value) {
    return new Intl.NumberFormat('vi-VN').format(Math.round(value || 0));
  }

  Drupal.behaviors.shippingOrderFormSize = {
    attach: function (context) {
      once('shipping-form-size', '.shipping-order-form', context).forEach(function (form) {
        var output = form.querySelector('.shipping-dim-weight');
        var inputs = form.querySelectorAll('.shipping-size-input');

        if (!output || !inputs.length) {
          return;
        }

        function refresh() {
          var volume = 1;
          var filled = 0;

          inputs.forEach(function (input) {
            var size = parseFloat(input.value);

            if (size > 0) {
              volume *= size;
              filled++;
            }
          });

          // Hãng quy đổi theo dài × rộng × cao / 6000 ra kilogram, đổi sang
          // gram thành chia 6. Thiếu một chiều thì không quy đổi được.
          output.textContent = formatNumber(filled === inputs.length ? volume / 6 : 0);
        }

        inputs.forEach(function (input) {
          input.addEventListener('input', refresh);
        });

        refresh();
      });
    }
  };

  Drupal.behaviors.shippingOrderFormCod = {
    attach: function (context) {
      once('shipping-form-cod', '.shipping-order-form', context).forEach(function (form) {
        var input = form.querySelector('.shipping-cod-input');
        var output = form.querySelector('.shipping-cod-total');

        if (!input || !output) {
          return;
        }

        function refresh() {
          output.textContent = formatNumber(parseFloat(input.value)) + ' đ';
        }

        input.addEventListener('input', refresh);
        refresh();
      });
    }
  };

  Drupal.behaviors.shippingOrderFormNote = {
    attach: function (context) {
      once('shipping-form-note', '.shipping-note-preset', context).forEach(function (button) {
        button.addEventListener('click', function () {
          var form = button.closest('.shipping-order-form');
          var note = form ? form.querySelector('.shipping-note-input') : null;
          var text = button.dataset.note || '';

          if (!note || !text) {
            return;
          }

          var current = note.value.trim();

          // Bấm lại câu đã có thì bỏ qua, tránh chèn trùng vào chỉ dẫn phát.
          if (current.indexOf(text) !== -1) {
            return;
          }

          note.value = current ? current + ' ' + text : text;
          note.focus();
        });
      });
    }
  };
})(Drupal, once);
