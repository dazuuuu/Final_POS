/* xsheet.js — spreadsheet-style tables with search and sheets (pages).
 *
 *   <input data-xsheet-search="#myTable" placeholder="Search…">   (optional; one box may drive several tables:
 *                                                                   data-xsheet-search="#a, #b")
 *   <table class="xsheet" id="myTable" data-page-size="20"> … </table>
 *
 * Every <tbody> row is a data row except rows with class "xsheet-group"
 * (a heading such as a day or a category): those show only while one of the
 * rows under them is on the current sheet. A row is searched by its
 * data-search attribute when present, else by its text.
 *
 * Search (all terms must match, any order, case-insensitive):
 *   coke 500            rows containing both words
 *   "coca cola"         an exact phrase
 *   -diet               rows NOT containing "diet"
 *   supplier:bidco      only that column (by header name; prefix is enough: sup:bidco)
 *   balance:<5  retail:>=100  total:100..500   number tests on a column
 * Buttons with data-xsheet-chip="<query>" next to the search box add / remove
 * that term (e.g. "balance:<=0" for out of stock).
 */
(function () {
  'use strict';

  function norm(s) { return String(s || '').toLowerCase().replace(/\s+/g, ' ').trim(); }
  function num(s) {
    var m = String(s || '').replace(/,/g, '').match(/-?\d+(\.\d+)?/);
    return m ? parseFloat(m[0]) : NaN;
  }

  /* "coke -diet sup:bidco total:>100 \"coca cola\"" → term objects */
  function parse(q) {
    var out = [], re = /(-)?(?:([a-z][\w-]*):)?(?:"([^"]*)"|(\S+))/gi, m;
    while ((m = re.exec(q))) {
      var val = (m[3] !== undefined ? m[3] : m[4] || '').toLowerCase();
      if (!val && !m[2]) continue;
      var t = { neg: !!m[1], col: m[2] ? m[2].toLowerCase() : null, val: val, test: null };
      var r;
      if ((r = val.match(/^(<=|>=|<|>|=)(-?[\d.,]+)$/))) {
        var op = r[1], n = num(r[2]);
        t.test = function (x) {
          return op === '<' ? x < n : op === '>' ? x > n : op === '<=' ? x <= n : op === '>=' ? x >= n : x === n;
        };
      } else if ((r = val.match(/^(-?[\d.,]+)\.\.(-?[\d.,]+)$/))) {
        var lo = num(r[1]), hi = num(r[2]);
        t.test = function (x) { return x >= lo && x <= hi; };
      }
      out.push(t);
    }
    return out;
  }

  function Sheet(table) {
    this.table = table;
    this.size = parseInt(table.getAttribute('data-page-size') || '20', 10) || 20;
    this.page = 0;
    this.terms = [];
    this.heads = [];
    var ths = table.tHead ? table.tHead.rows[table.tHead.rows.length - 1].cells : [];
    for (var i = 0; i < ths.length; i++) this.heads.push(norm(ths[i].textContent));
    this.rows = [];
    var group = null, trs = table.tBodies.length ? table.tBodies[0].rows : [];
    for (var j = 0; j < trs.length; j++) {
      var tr = trs[j];
      if (tr.classList.contains('xsheet-group')) { group = tr; continue; }
      if (tr.classList.contains('xsheet-skip')) continue;
      this.rows.push({ tr: tr, group: group, text: norm(tr.getAttribute('data-search') || tr.textContent) });
    }
    this.groups = table.querySelectorAll('tbody tr.xsheet-group');
    this.bar = document.createElement('div');
    this.bar.className = 'xsheet-tabs';
    var wrap = table.closest('.table-responsive') || table;
    wrap.parentNode.insertBefore(this.bar, wrap.nextSibling);
    this.render();
  }

  Sheet.prototype.colIndex = function (name) {
    for (var i = 0; i < this.heads.length; i++) if (this.heads[i] && this.heads[i].indexOf(name) === 0) return i;
    return -1;
  };

  Sheet.prototype.match = function (row) {
    for (var i = 0; i < this.terms.length; i++) {
      var t = this.terms[i], text = row.text, hit;
      if (t.col) {
        var c = this.colIndex(t.col);
        if (c < 0) continue; // a column this table doesn't have: ignore the term here
        var cell = row.tr.cells[c];
        text = cell ? norm(cell.textContent) : '';
      }
      hit = t.test ? t.test(num(text)) : text.indexOf(t.val) !== -1;
      if (hit === t.neg) return false;
    }
    return true;
  };

  Sheet.prototype.search = function (q) {
    this.terms = parse(q);
    this.page = 0;
    this.render();
  };

  Sheet.prototype.render = function () {
    var self = this, hits = this.rows.filter(function (r) { return self.match(r); });
    var pages = Math.max(1, Math.ceil(hits.length / this.size));
    if (this.page >= pages) this.page = pages - 1;
    var from = this.page * this.size, to = from + this.size, shownGroups = [];
    this.rows.forEach(function (r) { r.tr.style.display = 'none'; });
    hits.slice(from, to).forEach(function (r) {
      r.tr.style.display = '';
      if (r.group && shownGroups.indexOf(r.group) === -1) shownGroups.push(r.group);
    });
    Array.prototype.forEach.call(this.groups, function (g) { g.style.display = shownGroups.indexOf(g) === -1 ? 'none' : ''; });

    var empty = this.table.querySelector('tbody tr.xsheet-empty');
    if (!hits.length) {
      if (!empty) {
        empty = document.createElement('tr');
        empty.className = 'xsheet-empty';
        empty.innerHTML = '<td colspan="' + Math.max(1, this.heads.length) + '">No rows match your search.</td>';
        this.table.tBodies[0].appendChild(empty);
      }
      empty.style.display = '';
    } else if (empty) {
      empty.style.display = 'none';
    }

    var html = '';
    if (pages > 1) {
      html += '<button type="button" data-p="' + (this.page - 1) + '"' + (this.page ? '' : ' disabled') + ' aria-label="Previous sheet">&lsaquo;</button>';
      for (var p = 0; p < pages; p++) {
        html += '<button type="button" data-p="' + p + '" class="' + (p === this.page ? 'on' : '') + '">Sheet ' + (p + 1) + '</button>';
      }
      html += '<button type="button" data-p="' + (this.page + 1) + '"' + (this.page < pages - 1 ? '' : ' disabled') + ' aria-label="Next sheet">&rsaquo;</button>';
    }
    html += '<span class="xsheet-count">' + (hits.length ? (from + 1) + '–' + Math.min(to, hits.length) + ' of ' : '') + hits.length +
      (hits.length === this.rows.length ? ' rows' : ' of ' + this.rows.length + ' rows') + '</span>';
    this.bar.innerHTML = html;
    Array.prototype.forEach.call(this.bar.querySelectorAll('button[data-p]'), function (b) {
      b.addEventListener('click', function () { self.page = parseInt(b.getAttribute('data-p'), 10); self.render(); });
    });
  };

  function init() {
    document.querySelectorAll('table.xsheet').forEach(function (t) { if (!t._xsheet) t._xsheet = new Sheet(t); });
    document.querySelectorAll('[data-xsheet-search]').forEach(function (inp) {
      var sel = inp.getAttribute('data-xsheet-search');
      var sheets = function () {
        return Array.prototype.map.call(document.querySelectorAll(sel), function (t) { return t._xsheet; }).filter(Boolean);
      };
      var timer;
      var run = function () { sheets().forEach(function (s) { s.search(inp.value); }); syncChips(); };
      inp.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(run, 120); });

      var box = inp.closest('.xsheet-searchbar') || inp.parentNode;
      var chips = box.querySelectorAll('[data-xsheet-chip]');
      function syncChips() {
        Array.prototype.forEach.call(chips, function (c) {
          c.classList.toggle('on', (' ' + inp.value + ' ').indexOf(' ' + c.getAttribute('data-xsheet-chip') + ' ') !== -1);
        });
      }
      Array.prototype.forEach.call(chips, function (c) {
        c.addEventListener('click', function () {
          var term = c.getAttribute('data-xsheet-chip'), v = ' ' + inp.value + ' ';
          inp.value = (v.indexOf(' ' + term + ' ') !== -1 ? v.replace(' ' + term + ' ', ' ') : v + term).replace(/\s+/g, ' ').trim();
          run();
          inp.focus();
        });
      });
      if (inp.value) run();
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
