<?php
// public/components/staff_pin.php — the staff PIN pad (sign-in page "Staff" tab and the PIN terminal).
// Expects $__pinAction (where the PIN is sent: the shared or the shop's terminal).
// Tap the keys or type on the keyboard: digits, Backspace, Esc (clear), Enter (Login).
$__pinAction = $__pinAction ?? public_url('terminal/');
?>
<style>
.pin-disp{display:flex;justify-content:center;gap:12px;margin:4px 0 20px;min-height:18px;}
.pin-disp i{width:14px;height:14px;border-radius:50%;border:2px solid #cfd5da;transition:background .12s,border-color .12s;}
.pin-disp i.on{background:var(--pos-green);border-color:var(--pos-green);}
.pin-hint{text-align:center;color:var(--mute);font-size:.8rem;margin:-8px 0 16px;}
.pin-keys{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:16px;}
.pin-key{height:54px;border:1px solid #e4e8eb;background:#fff;border-radius:2px;font:500 1.25rem Archivo,system-ui,sans-serif;color:var(--pos-ink);cursor:pointer;transition:background .1s,border-color .1s;}
.pin-key:hover{border-color:var(--pos-green);}
.pin-key:active,.pin-key.hit{background:var(--pos-green);border-color:var(--pos-green);color:#fff;}
.pin-key.fn{color:var(--mute);font-size:1rem;}
.pin-acts{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:8px;}
</style>
<form method="post" action="<?php echo htmlspecialchars($__pinAction); ?>" id="pinForm" autocomplete="off">
  <input type="hidden" name="pin" id="pinValue"><input type="hidden" name="action" id="pinAction" value="login">
  <div class="pin-disp" id="pinDots" aria-live="polite"><?php for ($i = 0; $i < 4; $i++): ?><i></i><?php endfor; ?></div>
  <div class="pin-hint">Enter your PIN — tap the keys or use your keyboard</div>
  <div class="pin-keys" id="pinKeys">
    <?php foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9] as $n): ?><button type="button" class="pin-key" data-d="<?php echo $n; ?>"><?php echo $n; ?></button><?php endforeach; ?>
    <button type="button" class="pin-key fn" data-fn="clear" title="Clear (Esc)">Clear</button>
    <button type="button" class="pin-key" data-d="0">0</button>
    <button type="button" class="pin-key fn" data-fn="back" title="Delete (Backspace)"><i class="fas fa-delete-left"></i></button>
  </div>
  <button type="submit" class="btn-auth" data-act="login"><i class="fas fa-right-to-bracket me-1"></i> Login</button>
  <div class="pin-acts">
    <button type="submit" class="btn-line" data-act="clock_in"><i class="fas fa-clock me-1"></i> Clock in</button>
    <button type="submit" class="btn-line" data-act="clock_out"><i class="fas fa-clock me-1"></i> Clock out</button>
  </div>
</form>
<script>
(function () {
  var pin = '', MAX = 6, form = document.getElementById('pinForm'), dots = document.getElementById('pinDots');
  function render() {
    var n = Math.max(4, pin.length), html = '';
    for (var i = 0; i < n; i++) html += '<i class="' + (i < pin.length ? 'on' : '') + '"></i>';
    dots.innerHTML = html;
  }
  function flash(sel) { var k = document.querySelector('#pinKeys ' + sel); if (k) { k.classList.add('hit'); setTimeout(function () { k.classList.remove('hit'); }, 120); } }
  function press(d) { if (pin.length < MAX) { pin += d; render(); } }
  document.getElementById('pinKeys').addEventListener('click', function (e) {
    var k = e.target.closest('.pin-key'); if (!k) return;
    if (k.dataset.d !== undefined) press(k.dataset.d);
    else if (k.dataset.fn === 'back') { pin = pin.slice(0, -1); render(); }
    else { pin = ''; render(); }
  });
  function submit(act) {
    if (pin.length < 4) { dots.animate([{ transform: 'translateX(0)' }, { transform: 'translateX(-8px)' }, { transform: 'translateX(8px)' }, { transform: 'translateX(0)' }], { duration: 260 }); return false; }
    document.getElementById('pinValue').value = pin; document.getElementById('pinAction').value = act; return true;
  }
  form.querySelectorAll('button[type=submit]').forEach(function (b) {
    b.addEventListener('click', function (e) { if (!submit(b.dataset.act)) e.preventDefault(); });
  });
  // Keyboard: only while the PIN pad is on screen (not when typing in another field).
  document.addEventListener('keydown', function (e) {
    if (form.offsetParent === null || /input|textarea|select/i.test((document.activeElement || {}).tagName || '')) return;
    if (/^[0-9]$/.test(e.key)) { press(e.key); flash('[data-d="' + e.key + '"]'); e.preventDefault(); }
    else if (e.key === 'Backspace') { pin = pin.slice(0, -1); render(); flash('[data-fn="back"]'); e.preventDefault(); }
    else if (e.key === 'Escape') { pin = ''; render(); flash('[data-fn="clear"]'); }
    else if (e.key === 'Enter') { e.preventDefault(); if (submit('login')) form.submit(); }
  });
  render();
})();
</script>
