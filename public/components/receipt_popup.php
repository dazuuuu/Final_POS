<?php
// public/components/receipt_popup.php — small receipt preview over the current
// page (included by the tenant and staff layouts). After checkout the sale page
// sets $_SESSION['receipt_popup'] to the receipt URL and comes back to itself;
// the receipt then opens here in a frame, with one Print button — no new tab,
// no full-page receipt. Any link with data-receipt-popup opens the same way,
// and scripts can call TasoReceipt.open(url).
$__rp = $_SESSION['receipt_popup'] ?? null;
unset($_SESSION['receipt_popup']);
?>
<style>
#rpBack{position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:2000;display:none;align-items:center;justify-content:center;padding:16px;}
#rpBack.on{display:flex;}
#rpBox{background:#fff;border-radius:14px;width:100%;max-width:400px;max-height:92vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 20px 50px rgba(0,0,0,.3);}
#rpBox iframe{border:0;width:100%;flex:1;min-height:360px;background:#f1f5f9;}
#rpBar{display:flex;gap:8px;padding:10px;border-top:1px solid #e5e7eb;}
#rpBar button{flex:1;padding:11px;border-radius:9px;font-weight:700;border:1px solid #d4d4d8;background:#fff;}
#rpBar #rpPrint{background:#1f9a5f;border-color:#1f9a5f;color:#fff;}
</style>
<div id="rpBack" aria-hidden="true"><div id="rpBox" role="dialog" aria-label="Receipt">
  <iframe id="rpFrame" title="Receipt"></iframe>
  <div id="rpBar"><button type="button" id="rpClose">Close</button><button type="button" id="rpPrint"><i class="fas fa-print me-1"></i>Print</button></div>
</div></div>
<script>
(function () {
  var back = document.getElementById('rpBack'), frame = document.getElementById('rpFrame');
  function open(url) {
    url += (url.indexOf('?') < 0 ? '?' : '&') + 'embed=1';
    frame.src = url; back.classList.add('on');
  }
  function close() { back.classList.remove('on'); frame.src = 'about:blank'; }
  document.getElementById('rpClose').onclick = close;
  back.addEventListener('click', function (e) { if (e.target === back) close(); });
  document.getElementById('rpPrint').onclick = function () {
    var w = frame.contentWindow;
    try { if (w.TasoPrinter) { w.TasoPrinter.printClick(); } else { w.focus(); w.print(); } } catch (e) { w.print(); }
  };
  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a[data-receipt-popup]');
    if (a) { e.preventDefault(); open(a.href); }
  });
  window.TasoReceipt = { open: open, close: close };
  <?php if ($__rp): ?>open(<?php echo json_encode($__rp); ?>);<?php endif; ?>
})();
</script>
