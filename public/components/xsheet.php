<?php
// public/components/xsheet.php — spreadsheet look for tables (class="xsheet"),
// an advanced search box and "Sheet 1, Sheet 2…" pages. Include once per page;
// see assets/js/xsheet.js for the search syntax.
//
//   xsheet_search('#saleTable', 'Search sales…', ['Cash' => 'cash', 'M-Pesa' => 'm-pesa']);

if (!function_exists('xsheet_search')) {
    /** Search box for one or more tables (CSS selector), with optional quick-filter chips label => query term. */
    function xsheet_search(string $tables, string $placeholder = 'Search…', array $chips = []): void
    { ?>
<div class="xsheet-searchbar no-print">
  <div class="xsheet-input">
    <i class="fas fa-magnifying-glass"></i>
    <input type="search" data-xsheet-search="<?php echo htmlspecialchars($tables); ?>" placeholder="<?php echo htmlspecialchars($placeholder); ?>" autocomplete="off">
    <span class="xsheet-help" tabindex="0" title="Type words to find rows containing all of them.&#10;&quot;two words&quot; = exact phrase · -word = leave out&#10;column:text = search one column (e.g. supplier:bidco)&#10;column:<5, column:>=100, column:10..50 = number tests"><i class="far fa-circle-question"></i></span>
  </div>
  <?php foreach ($chips as $label => $term): ?>
    <button type="button" class="xsheet-chip" data-xsheet-chip="<?php echo htmlspecialchars($term); ?>"><?php echo htmlspecialchars($label); ?></button>
  <?php endforeach; ?>
</div>
<?php }
}
if (!empty($GLOBALS['__xsheet_loaded'])) {
    return;
}
$GLOBALS['__xsheet_loaded'] = true;
?>
<style>
.xsheet-searchbar{display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin-bottom:10px;}
.xsheet-input{position:relative;flex:1 1 260px;max-width:460px;}
.xsheet-input>i{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:.85rem;pointer-events:none;}
.xsheet-input input{width:100%;border:1px solid #cbd5e1;border-radius:8px;padding:7px 34px 7px 32px;font-size:.88rem;background:#fff;}
.xsheet-input input:focus{outline:none;border-color:#16a34a;box-shadow:0 0 0 3px rgba(22,163,74,.15);}
.xsheet-help{position:absolute;right:10px;top:50%;transform:translateY(-50%);color:#94a3b8;cursor:help;}
.xsheet-chip{border:1px solid #cbd5e1;background:#fff;color:#334155;border-radius:999px;padding:4px 12px;font-size:.8rem;}
.xsheet-chip.on{background:#16a34a;border-color:#16a34a;color:#fff;}

table.xsheet{width:100%;border-collapse:collapse;background:#fff;font-size:.86rem;margin:0;}
table.xsheet th, table.xsheet td{border:1px solid #d4d9e1 !important;padding:6px 9px !important;vertical-align:middle;}
table.xsheet thead th{background:#f1f4f8 !important;color:#334155;font-weight:700;font-size:.75rem;text-transform:uppercase;
  letter-spacing:.02em;white-space:nowrap;position:sticky;top:0;z-index:1;}
table.xsheet tbody tr:hover td{background:#f5f9ff;}
table.xsheet tfoot td{background:#f8fafc;font-weight:700;}
table.xsheet tr.xsheet-group td{background:#e8f1fb !important;color:#0f172a;font-weight:600;}
table.xsheet tr.xsheet-empty td{text-align:center;color:#94a3b8;padding:18px !important;}
table.xsheet td.xsheet-n, table.xsheet th.xsheet-n{background:#f1f4f8 !important;color:#64748b;text-align:center;width:42px;font-size:.75rem;}

.xsheet-tabs{display:flex;flex-wrap:wrap;align-items:center;gap:2px;margin-top:-1px;border-top:2px solid #d4d9e1;padding-top:0;}
.xsheet-tabs button{border:1px solid #d4d9e1;border-top:0;background:#f1f4f8;color:#475569;font-size:.78rem;padding:4px 12px;
  border-radius:0 0 6px 6px;}
.xsheet-tabs button.on{background:#fff;color:#15803d;font-weight:700;border-bottom:2px solid #16a34a;}
.xsheet-tabs button:disabled{opacity:.4;}
.xsheet-count{margin-left:auto;font-size:.76rem;color:#64748b;padding:4px 2px;}
@media print{.xsheet-tabs,.xsheet-searchbar{display:none !important;} table.xsheet tbody tr:not(.xsheet-empty){display:table-row !important;} table.xsheet tr.xsheet-empty{display:none !important;}}
</style>
<script src="<?php echo public_url('assets/js/xsheet.js'); ?>" defer></script>
