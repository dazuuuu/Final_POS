<?php
// public/super/settings/menu.php — the owner arranges their own menu: drag
// groups and links into the order they want, pin links to "Quick access"
// (right under Home), or hide links they never use. Saved for this owner
// only (PosMenu::savePrefs); staff and other shops are not affected.
require_once __DIR__ . '/../../../app/app.php';
require_once ROOT_PATH . '/app/helpers/uploads.php';
PageGuard::auth();
if (TenantContext::role() !== 'tenant_owner') {
    header('Location: ' . public_url('super/dashboard/'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash']['error'] = 'Session expired — reload the page and try again.';
    } elseif (($_POST['action'] ?? '') === 'reset') {
        PosMenu::resetPrefs();
        $_SESSION['flash']['success'] = 'Menu back to the standard order.';
    } else {
        $prefs = json_decode((string) ($_POST['prefs'] ?? ''), true);
        if (is_array($prefs)) {
            PosMenu::savePrefs($prefs);
            $_SESSION['flash']['success'] = 'Menu saved. It now appears in this order on every device you sign in on.';
        }
    }
    header('Location: ' . public_url('super/settings/menu.php'));
    exit;
}

$prefs = PosMenu::prefs();
// Start from the standard menu, put groups and links in the saved order, and
// put pinned links back in their own group so they can be un-pinned.
$groups = PosMenu::groups(false);
$byGroup = array_column($groups, null, 'key');
$gOrder = array_flip($prefs['groups']);
$idx = array_flip(array_keys($byGroup));
uasort($byGroup, fn($a, $b) => ($a['key'] === 'home' ? -1 : ($gOrder[$a['key']] ?? 1000 + $idx[$a['key']]))
                            <=> ($b['key'] === 'home' ? -1 : ($gOrder[$b['key']] ?? 1000 + $idx[$b['key']])));
foreach ($byGroup as $k => $g) {
    $order = array_flip($prefs['links'][$k] ?? []);
    $links = $g['links'];
    uksort($links, fn($a, $b) => ($order[$links[$a][2]] ?? 1000 + $a) <=> ($order[$links[$b][2]] ?? 1000 + $b));
    $byGroup[$k]['links'] = array_values($links);
}
$locked = ['super/dashboard/', 'super/settings/menu.php'];

$page_title = 'Navigation menu';
ob_start();
?>
<style>
.am-group{border:1px solid #d4d9e1;border-radius:12px;background:#fff;margin-bottom:12px;}
.am-head{display:flex;align-items:center;gap:10px;padding:10px 14px;background:#f1f4f8;border-radius:12px 12px 0 0;font-weight:700;}
.am-head .am-grip{cursor:grab;color:#94a3b8;}
.am-links{list-style:none;margin:0;padding:6px;}
.am-link{display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:8px;border:1px solid transparent;}
.am-link:hover{background:#f8fafc;border-color:#e2e8f0;}
.am-link .am-grip{cursor:grab;color:#94a3b8;}
.am-link .am-name{flex:1;}
.am-link.is-hidden .am-name{color:#94a3b8;text-decoration:line-through;}
.am-link .btn{padding:2px 8px;font-size:.75rem;}
.am-pin.on{background:#f59e0b;border-color:#f59e0b;color:#fff;}
.am-ghost{opacity:.4;background:#e8f1fb;}
.am-fixed .am-grip{visibility:hidden;}
</style>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div>
    <h1 class="h5 fw-bold mb-1">Navigation menu</h1>
    <p class="text-muted small mb-0">Drag <i class="fas fa-grip-vertical"></i> to reorder groups and the pages inside them.
      <strong>Pin</strong> puts a page in <em>Quick access</em> right under Home. <strong>Hide</strong> removes it from your menu. Only your account changes.</p>
  </div>
  <div class="d-flex gap-2">
    <form method="post" onsubmit="return confirm('Put the menu back to the standard order?');">
      <?php echo csrf_field(); ?><input type="hidden" name="action" value="reset">
      <button class="btn btn-sm btn-outline-secondary"><i class="fas fa-rotate-left me-1"></i>Reset</button>
    </form>
    <form method="post" id="amForm">
      <?php echo csrf_field(); ?><input type="hidden" name="prefs" id="amPrefs">
      <button class="btn btn-sm btn-primary"><i class="fas fa-floppy-disk me-1"></i>Save menu</button>
    </form>
  </div>
</div>

<div class="row">
  <div class="col-12 col-lg-8">
    <div id="amGroups">
      <?php foreach ($byGroup as $k => $g): $fixed = $k === 'home'; ?>
      <div class="am-group <?php echo $fixed ? 'am-fixed' : ''; ?>" data-key="<?php echo htmlspecialchars($k); ?>">
        <div class="am-head"><i class="fas fa-grip-vertical am-grip"></i><i class="fas <?php echo $g['icon']; ?> text-primary"></i><?php echo htmlspecialchars($g['label']); ?>
          <?php if ($fixed): ?><span class="text-muted small fw-normal ms-auto">Always first</span><?php endif; ?></div>
        <ul class="am-links">
          <?php foreach ($g['links'] as $l): $isLocked = in_array($l[2], $locked, true);
              $pin = in_array($l[2], $prefs['pinned'], true); $hid = !$isLocked && in_array($l[2], $prefs['hidden'], true); ?>
          <li class="am-link <?php echo $hid ? 'is-hidden' : ''; ?>" data-path="<?php echo htmlspecialchars($l[2]); ?>">
            <i class="fas fa-grip-vertical am-grip"></i>
            <i class="fas <?php echo $l[1]; ?> text-muted" style="width:18px;text-align:center;"></i>
            <span class="am-name"><?php echo htmlspecialchars($l[0]); ?></span>
            <?php if (!$fixed): ?>
              <button type="button" class="btn btn-outline-warning am-pin <?php echo $pin ? 'on' : ''; ?>"><i class="fas fa-star me-1"></i><?php echo $pin ? 'Pinned' : 'Pin'; ?></button>
            <?php endif; ?>
            <?php if (!$isLocked): ?>
              <button type="button" class="btn btn-outline-secondary am-hide"><?php echo $hid ? 'Show' : 'Hide'; ?></button>
            <?php endif; ?>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="col-12 col-lg-4">
    <div class="card border-0 shadow-sm" style="border-radius:12px;position:sticky;top:12px;">
      <div class="card-body">
        <h2 class="h6 fw-bold"><i class="fas fa-star text-warning me-1"></i>Quick access</h2>
        <p class="text-muted small mb-2">Pinned pages, in the order you pinned them. On phones this group is the first tab of the bottom bar.</p>
        <ol class="small mb-0 ps-3" id="amPinned"></ol>
        <p class="text-muted small mb-0" id="amPinnedEmpty">Nothing pinned yet — e.g. pin <em>Reports</em> to keep it one tap away.</p>
      </div>
    </div>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.0/Sortable.min.js"></script>
<script>
(function () {
  var pinned = <?php echo json_encode(array_values($prefs['pinned'])); ?>;
  var root = document.getElementById('amGroups');

  function names() {
    var m = {};
    root.querySelectorAll('.am-link').forEach(function (li) { m[li.dataset.path] = li.querySelector('.am-name').textContent; });
    return m;
  }
  function drawPinned() {
    var m = names(), ol = document.getElementById('amPinned');
    pinned = pinned.filter(function (p) { return m[p]; });
    ol.innerHTML = pinned.map(function (p) { return '<li>' + m[p].replace(/</g, '&lt;') + '</li>'; }).join('');
    document.getElementById('amPinnedEmpty').style.display = pinned.length ? 'none' : '';
  }

  if (window.Sortable) {
    new Sortable(root, { handle: '.am-head .am-grip', animation: 150, ghostClass: 'am-ghost', filter: '.am-fixed',
      onMove: function (e) { return !e.related.classList.contains('am-fixed'); } });
    root.querySelectorAll('.am-links').forEach(function (ul) {
      new Sortable(ul, { handle: '.am-grip', animation: 150, ghostClass: 'am-ghost' });
    });
  }

  root.addEventListener('click', function (e) {
    var pin = e.target.closest('.am-pin'), hide = e.target.closest('.am-hide');
    if (pin) {
      var p = pin.closest('.am-link').dataset.path, on = !pin.classList.contains('on');
      pin.classList.toggle('on', on);
      pin.innerHTML = '<i class="fas fa-star me-1"></i>' + (on ? 'Pinned' : 'Pin');
      pinned = pinned.filter(function (x) { return x !== p; });
      if (on) pinned.push(p);
      drawPinned();
    }
    if (hide) {
      var li = hide.closest('.am-link'), h = !li.classList.contains('is-hidden');
      li.classList.toggle('is-hidden', h);
      hide.textContent = h ? 'Show' : 'Hide';
    }
  });

  document.getElementById('amForm').addEventListener('submit', function () {
    var prefs = { groups: [], links: {}, pinned: pinned, hidden: [] };
    root.querySelectorAll('.am-group').forEach(function (g) {
      prefs.groups.push(g.dataset.key);
      prefs.links[g.dataset.key] = Array.prototype.map.call(g.querySelectorAll('.am-link'), function (li) {
        if (li.classList.contains('is-hidden')) prefs.hidden.push(li.dataset.path);
        return li.dataset.path;
      });
    });
    document.getElementById('amPrefs').value = JSON.stringify(prefs);
  });

  drawPinned();
})();
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/tenants/layout.php';
