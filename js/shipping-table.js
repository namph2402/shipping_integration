/**
 * @file
 * Sắp xếp, phân trang, ẩn hiện cột và xuất Excel cho bảng đơn vận chuyển.
 *
 * Toàn bộ dòng đã có sẵn trong HTML nên mọi thao tác chạy trên trình duyệt,
 * người dùng đổi số dòng mỗi trang hay sắp xếp lại không phải tải lại trang.
 */

(function (Drupal, once) {
  'use strict';

  /**
   * Đọc giá trị dùng để so sánh của một ô.
   *
   * Ô số và ô ngày ghi sẵn giá trị thô trong data-value vì phần hiển thị đã
   * được định dạng theo kiểu Việt Nam, so sánh trực tiếp sẽ sai thứ tự.
   *
   * @param {HTMLElement} cell
   *   Ô cần đọc.
   *
   * @return {string|number}
   *   Giá trị so sánh.
   */
  function cellValue(cell) {
    if (!cell) {
      return '';
    }

    if (typeof cell.dataset.value !== 'undefined') {
      var raw = cell.dataset.value;
      return raw !== '' && !isNaN(raw) ? parseFloat(raw) : raw;
    }

    return cell.textContent.trim();
  }

  /**
   * Bảng đơn vận chuyển với đầy đủ thao tác phía trình duyệt.
   *
   * @param {HTMLTableElement} table
   *   Bảng cần khởi tạo.
   */
  function ShippingTable(table) {
    this.table = table;
    this.wrapper = table.closest('.d-flex.flex-column') || table.parentElement;
    this.rows = Array.from(table.querySelectorAll('tbody tr'));
    this.filtered = this.rows.slice();
    this.page = 1;
    this.pageSize = this.readPageSize();
    this.sortIndex = null;
    this.sortAsc = true;

    this.buildColumnMenu();
    this.bindSorting();
    this.bindPageSize();
    this.bindExport();
    this.render();
  }

  ShippingTable.prototype.control = function (selector) {
    return this.wrapper ? this.wrapper.querySelector(selector) : null;
  };

  ShippingTable.prototype.readPageSize = function () {
    var select = this.control('.shipping-page-size');
    return select ? parseInt(select.value, 10) : 50;
  };

  /**
   * Dựng danh sách cột cho phép ẩn hiện.
   */
  ShippingTable.prototype.buildColumnMenu = function () {
    var menu = this.control('.shipping-column-menu');

    if (!menu) {
      return;
    }

    var self = this;
    var headers = Array.from(this.table.querySelectorAll('thead th'));

    headers.forEach(function (header, index) {
      // Cột chọn dòng, số thứ tự và cột thao tác luôn hiển thị.
      if (header.dataset.colLock === '1') {
        return;
      }

      var item = document.createElement('li');
      var label = document.createElement('label');
      var input = document.createElement('input');

      input.type = 'checkbox';
      input.checked = true;
      input.className = 'form-check-input me-2';
      input.addEventListener('change', function () {
        self.toggleColumn(index, input.checked);
      });

      label.className = 'dropdown-item d-flex align-items-center';
      label.appendChild(input);
      label.appendChild(document.createTextNode(header.textContent.trim()));
      item.appendChild(label);
      menu.appendChild(item);
    });
  };

  ShippingTable.prototype.toggleColumn = function (index, visible) {
    var selector = 'tr > *:nth-child(' + (index + 1) + ')';

    this.table.querySelectorAll(selector).forEach(function (cell) {
      cell.style.display = visible ? '' : 'none';
    });
  };

  /**
   * Cho phép bấm tiêu đề để sắp xếp.
   */
  ShippingTable.prototype.bindSorting = function () {
    var self = this;

    Array.from(this.table.querySelectorAll('thead th')).forEach(function (header, index) {
      if (header.dataset.colSort === '0') {
        return;
      }

      header.classList.add('shipping-sortable');
      header.addEventListener('click', function () {
        self.sortAsc = self.sortIndex === index ? !self.sortAsc : true;
        self.sortIndex = index;
        self.sort();
        self.render();
      });
    });
  };

  ShippingTable.prototype.sort = function () {
    var index = this.sortIndex;
    var direction = this.sortAsc ? 1 : -1;

    this.filtered.sort(function (left, right) {
      var a = cellValue(left.children[index]);
      var b = cellValue(right.children[index]);

      if (typeof a === 'number' && typeof b === 'number') {
        return (a - b) * direction;
      }

      return String(a).localeCompare(String(b), 'vi') * direction;
    });
  };

  ShippingTable.prototype.bindPageSize = function () {
    var self = this;
    var select = this.control('.shipping-page-size');

    if (!select) {
      return;
    }

    select.addEventListener('change', function () {
      self.pageSize = parseInt(select.value, 10);
      self.page = 1;
      self.render();
    });
  };

  /**
   * Hiển thị đúng các dòng của trang hiện tại rồi vẽ lại thanh phân trang.
   */
  ShippingTable.prototype.render = function () {
    var size = this.pageSize > 0 ? this.pageSize : this.filtered.length;
    var pages = size > 0 ? Math.ceil(this.filtered.length / size) : 1;

    this.page = Math.min(Math.max(this.page, 1), Math.max(pages, 1));

    var start = (this.page - 1) * size;
    var end = start + size;
    var body = this.table.querySelector('tbody');

    this.rows.forEach(function (row) {
      row.style.display = 'none';
    });

    this.filtered.forEach(function (row, index) {
      body.appendChild(row);
      row.style.display = index >= start && index < end ? '' : 'none';
    });

    this.renderRange(start, Math.min(end, this.filtered.length));
    this.renderPages(pages);
  };

  ShippingTable.prototype.renderRange = function (start, end) {
    var range = this.control('.shipping-range');

    if (range) {
      range.textContent = Drupal.t('@from-@to of @total', {
        '@from': this.filtered.length ? start + 1 : 0,
        '@to': end,
        '@total': this.filtered.length
      });
    }
  };

  ShippingTable.prototype.renderPages = function (pages) {
    var list = this.control('.shipping-pages');

    if (!list) {
      return;
    }

    list.innerHTML = '';

    if (pages <= 1) {
      return;
    }

    var self = this;

    // Chỉ vẽ cửa sổ hai trang quanh trang hiện tại, danh sách vài chục trang
    // mà vẽ hết thì thanh phân trang dài hơn cả bảng.
    for (var page = 1; page <= pages; page++) {
      if (page !== 1 && page !== pages && Math.abs(page - this.page) > 2) {
        if (Math.abs(page - this.page) === 3) {
          list.appendChild(self.pageItem('…', null, true));
        }
        continue;
      }

      list.appendChild(self.pageItem(String(page), page, false));
    }
  };

  ShippingTable.prototype.pageItem = function (label, page, disabled) {
    var self = this;
    var item = document.createElement('li');
    var link = document.createElement('button');

    item.className = 'page-item'
      + (page === this.page ? ' active' : '')
      + (disabled ? ' disabled' : '');

    link.type = 'button';
    link.className = 'page-link';
    link.textContent = label;

    if (!disabled) {
      link.addEventListener('click', function () {
        self.page = page;
        self.render();
      });
    }

    item.appendChild(link);

    return item;
  };

  /**
   * Xuất bảng ra file CSV mở được bằng Excel.
   */
  ShippingTable.prototype.bindExport = function () {
    var self = this;
    var button = this.control('.btn-export-excel');

    if (!button) {
      return;
    }

    button.addEventListener('click', function () {
      var headers = Array.from(self.table.querySelectorAll('thead th'));
      var keep = headers.map(function (header) {
        return header.dataset.colExport !== '0' && header.style.display !== 'none';
      });

      var lines = [self.csvLine(headers, keep, true)];

      self.filtered.forEach(function (row) {
        lines.push(self.csvLine(Array.from(row.children), keep, false));
      });

      // BOM để Excel nhận đúng UTF-8, thiếu nó tiếng Việt sẽ hiển thị sai.
      var blob = new Blob(['﻿' + lines.join('\n')], { type: 'text/csv;charset=utf-8;' });
      var link = document.createElement('a');

      link.href = URL.createObjectURL(blob);
      link.download = (self.table.dataset.exportName || 'shipping-orders') + '.csv';
      link.click();
      URL.revokeObjectURL(link.href);
    });
  };

  ShippingTable.prototype.csvLine = function (cells, keep, isHeader) {
    return cells
      .filter(function (cell, index) {
        return keep[index];
      })
      .map(function (cell) {
        var value = isHeader ? cell.textContent.trim() : cellValue(cell);
        return '"' + String(value).replace(/"/g, '""') + '"';
      })
      .join(',');
  };

  Drupal.behaviors.shippingOrderTable = {
    attach: function (context) {
      once('shipping-table', '.shipping-table', context).forEach(function (table) {
        new ShippingTable(table);
      });
    }
  };
})(Drupal, once);
