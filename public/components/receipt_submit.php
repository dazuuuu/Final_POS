<?php
// public/components/receipt_submit.php — "Mark as submitted" button + status,
// for a receipt page. Set $printEntity (e.g. 'sale:123') before including.
if (empty($printEntity)) return;
[$rt_type, $rt_id] = explode(':', $printEntity, 2) + [1 => 0];
$rt_status = class_exists('ReceiptTracking') ? ReceiptTracking::status($rt_type, (int) $rt_id) : [];
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
?>
<div class="card border-0 shadow-sm mb-2 noprint" style="border-radius:12px;">
  <div class="card-body p-3">
    <div class="small text-muted mb-2">
      Printed <?php echo (int) ($rt_status['print_count'] ?? 0); ?> time<?php echo (int) ($rt_status['print_count'] ?? 0) === 1 ? '' : 's'; ?>
      <?php if (!empty($rt_status['last_printed_at'])): ?> · last on <?php echo date('j M, H:i', strtotime($rt_status['last_printed_at'])); ?><?php endif; ?>
    </div>
    <?php if (!empty($rt_status['submitted_at'])): ?>
      <div class="small text-success mb-2"><i class="fas fa-circle-check me-1"></i>Submitted to <?php echo $h($rt_status['submitted_to']); ?> on <?php echo date('j M, H:i', strtotime($rt_status['submitted_at'])); ?></div>
    <?php endif; ?>
    <div class="input-group input-group-sm">
      <input type="text" id="rtSubmitTo" class="form-control" placeholder="Given to (e.g. owner, customer name)" value="<?php echo $h($rt_status['submitted_to'] ?? ''); ?>">
      <button class="btn btn-outline-success" onclick="rtMarkSubmitted()"><i class="fas fa-check me-1"></i>Mark as submitted</button>
    </div>
  </div>
</div>
<script>
function rtMarkSubmitted() {
  var to = document.getElementById('rtSubmitTo').value.trim();
  if (!to) { alert('Say who this receipt was given to.'); return; }
  var csrf = document.querySelector('meta[name="csrf-token"]');
  fetch('<?php echo htmlspecialchars(public_url('api/print/submit.php')); ?>', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ entity: '<?php echo $h($printEntity); ?>', to: to, csrf_token: csrf ? csrf.content : '' })
  }).then(function (r) { return r.json(); }).then(function (j) {
    if (j.ok) { location.reload(); } else { alert(j.error || 'Could not save.'); }
  }).catch(function () { alert('Network error.'); });
}
</script>
