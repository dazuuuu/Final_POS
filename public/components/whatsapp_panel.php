<?php
// public/components/whatsapp_panel.php — "Send to WhatsApp" card for a receipt page.
// Needs: $waText (the message), $waPhone (client's number if known). Hidden in the receipt popup,
// where the popup's own WhatsApp button uses window.TasoWaMsg instead.
require_once ROOT_PATH . '/app/helpers/uploads.php';
$__waMine = WhatsApp::mine();
$__waPhone = WhatsApp::normalize($waPhone ?? '');
$__waCsrf = csrf_token();
?>
<div class="card border-0 shadow-sm mb-2" style="border-radius:12px;" id="waCard"><div class="card-body p-3">
  <label class="form-label small mb-1"><i class="fab fa-whatsapp text-success me-1"></i>Send to the client's WhatsApp</label>
  <div class="input-group">
    <input type="tel" id="waTo" class="form-control" placeholder="Client's number, e.g. 0712 345 678" value="<?php echo htmlspecialchars($__waPhone ? '0' . substr($__waPhone, 3) : ''); ?>" inputmode="tel">
    <button type="button" class="btn btn-success" id="waGo"><i class="fas fa-paper-plane me-1"></i>Send</button>
  </div>
  <div class="small text-muted mt-2" id="waFrom">
    <span id="waFromTxt"><?php echo $__waMine ? 'Replies come to your WhatsApp +' . htmlspecialchars($__waMine) : 'It opens in the WhatsApp on this device.'; ?></span>
    · <a href="#" id="waSet"><?php echo $__waMine ? 'change my number' : 'set my WhatsApp number'; ?></a>
  </div>
</div></div>
<script src="<?php echo htmlspecialchars(public_url('assets/js/whatsapp.js')); ?>"></script>
<script>
(function () {
  var text = <?php echo json_encode($waText, JSON_UNESCAPED_UNICODE); ?>, csrf = <?php echo json_encode($__waCsrf); ?>, ep = <?php echo json_encode(public_url('api/whatsapp/save.php')); ?>;
  window.TasoWaMsg = { text: text, phone: <?php echo json_encode($__waPhone); ?>, mine: <?php echo json_encode($__waMine); ?>, csrf: csrf, endpoint: ep };
  var to = document.getElementById('waTo');
  document.getElementById('waGo').addEventListener('click', function () { TasoWa.send(to.value, text); });
  to.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); TasoWa.send(to.value, text); } });
  document.getElementById('waSet').addEventListener('click', function (e) {
    e.preventDefault();
    var v = prompt('Your own WhatsApp number (the one clients can reply to). Leave empty to remove it.', window.TasoWaMsg.mine ? '0' + window.TasoWaMsg.mine.slice(3) : '');
    if (v === null) return;
    TasoWa.saveMine(v, csrf, ep, function (r) {
      if (!r.ok) { alert(r.error || 'Could not save.'); return; }
      window.TasoWaMsg.mine = r.number || '';
      location.reload();
    });
  });
})();
</script>
