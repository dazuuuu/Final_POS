<?php
// public/super/api/index.php — Website API (custom add-on), owner side.
// Not switched on yet → what it does + "Request a quotation" (goes to Support → Quote requests).
// Switched on by Support → tabs: Keys · Test · Orders · Comments · Insights · Settings · Docs.
require_once __DIR__ . '/../../../app/app.php';
PageGuard::tenant(); // owners / admins only
require_once APP_ROOT . '/helpers/uploads.php'; // csrf_field() / csrf_verify()

$pdo = Database::pdo();
$tid = (int) TenantContext::tenantId();
$account = Features::accountId($tid);
$self = public_url('super/api/');
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$S = ShopApi::settings($account);
$on = (int) $S['enabled'] === 1;
$shops = ShopContext::shops(true);
$shopIds = array_map(fn($s) => (int) $s['id'], $shops);
$tab = in_array($_GET['tab'] ?? '', ['keys', 'test', 'orders', 'comments', 'insights', 'settings', 'docs'], true) ? $_GET['tab'] : 'keys';
$flash = $_SESSION['api_flash'] ?? null;
unset($_SESSION['api_flash']);
/** Redirect to a tab with an optional message. */
function api_back(string $t, ?string $msg = null, string $kind = 'success'): void
{
    if ($msg) $_SESSION['api_flash'] = [$kind, $msg];
    header('Location: ' . public_url('super/api/') . '?tab=' . $t);
    exit;
}
$back = 'api_back';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) $back($tab, 'Your session expired — try again.', 'danger');
    $act = $_POST['act'] ?? '';
    if ($act === 'request') {
        $me = $pdo->prepare('SELECT username, email FROM users WHERE id = ?');
        $me->execute([TenantContext::userId()]);
        $u = $me->fetch(PDO::FETCH_ASSOC) ?: [];
        $shop = (new Models\TenantModel($pdo))->find($account) ?: [];
        $err = Quote::submit(['name' => $u['username'] ?? 'Owner', 'email' => $u['email'] ?? '', 'phone' => trim((string) ($_POST['phone'] ?? '')),
            'package' => 'website_api', 'shop_name' => $shop['name'] ?? '', 'business' => trim('Website: ' . trim((string) ($_POST['website'] ?? '')) . '. ' . trim((string) ($_POST['needs'] ?? '')))]);
        if ($err === null) ShopApi::save($account, ['requested_at' => date('Y-m-d H:i:s')]);
        $back('keys', $err ?? 'Request sent — we will call you with a quotation.', $err ? 'danger' : 'success');
    }
    if (!$on) $back('keys', 'The Website API is not switched on for this account yet.', 'danger');
    switch ($act) {
        case 'create_key':
            $shopId = (int) ($_POST['shop'] ?? $tid);
            if (!in_array($shopId, $shopIds, true)) $shopId = $tid;
            [$plain] = ShopApi::createKey($account, $shopId, (string) ($_POST['name'] ?? ''), (array) ($_POST['scopes'] ?? []), (string) ($_POST['origins'] ?? ''), TenantContext::userId());
            $_SESSION['api_new_key'] = $plain;
            $back('keys');
        case 'revoke':
            ShopApi::revokeKey($account, (int) ($_POST['id'] ?? 0));
            $back('keys', 'Key revoked — it stops working straight away.');
        case 'settings':
            ShopApi::save($account, ['comments_on' => !empty($_POST['comments_on']) ? 1 : 0, 'comments_auto' => !empty($_POST['comments_auto']) ? 1 : 0,
                'likes_on' => !empty($_POST['likes_on']) ? 1 : 0, 'orders_on' => !empty($_POST['orders_on']) ? 1 : 0,
                'order_note' => mb_substr(trim((string) ($_POST['order_note'] ?? '')), 0, 255) ?: null]);
            $back('settings', 'Saved.');
        case 'comment':
            $id = (int) ($_POST['id'] ?? 0);
            $in = implode(',', $shopIds ?: [0]);
            $do = $_POST['do'] ?? '';
            if ($do === 'delete') $pdo->prepare("DELETE FROM shop_api_comments WHERE id = ? AND tenant_id IN ($in)")->execute([$id]);
            elseif (in_array($do, ['approved', 'hidden'], true)) $pdo->prepare("UPDATE shop_api_comments SET status = ? WHERE id = ? AND tenant_id IN ($in)")->execute([$do, $id]);
            $back('comments');
        case 'order':
            $id = (int) ($_POST['id'] ?? 0);
            $new = (string) ($_POST['status'] ?? '');
            $in = implode(',', $shopIds ?: [0]);
            $st = $pdo->prepare("SELECT * FROM shop_web_orders WHERE id = ? AND tenant_id IN ($in)");
            $st->execute([$id]);
            $o = $st->fetch(PDO::FETCH_ASSOC);
            if (!$o || !isset(ShopApi::ORDER_STATUSES[$new]) || $new === $o['status']) $back('orders');
            // Stock leaves the shelf when the order is confirmed, and comes back if a confirmed order is cancelled.
            $took = in_array($o['status'], ['confirmed', 'delivered'], true);
            $takes = in_array($new, ['confirmed', 'delivered'], true);
            $pdo->beginTransaction();
            if ($took !== $takes) {
                $items = $pdo->prepare('SELECT product_id, quantity FROM shop_web_order_items WHERE order_id = ?');
                $items->execute([$id]);
                $upd = $pdo->prepare('UPDATE products SET quantity = quantity ' . ($takes ? '-' : '+') . ' ? WHERE id = ? AND tenant_id = ?');
                foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $it) if ($it['product_id']) $upd->execute([(float) $it['quantity'], (int) $it['product_id'], (int) $o['tenant_id']]);
            }
            $pdo->prepare('UPDATE shop_web_orders SET status = ? WHERE id = ?')->execute([$new, $id]);
            $pdo->commit();
            $back('orders', $o['number'] . ' → ' . ShopApi::ORDER_STATUSES[$new] . ($took !== $takes ? ($takes ? ' (stock taken off)' : ' (stock returned)') : '') . '.');
    }
    $back($tab);
}

$newKey = $_SESSION['api_new_key'] ?? null;
unset($_SESSION['api_new_key']);
$apiBase = (((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'))
         . rtrim((string) BASE_URL, '/') . '/api/v1/';

$page_title = 'Website API';
ob_start();
?>
<style>
.api-tabs{display:flex;gap:4px;flex-wrap:wrap;border-bottom:1px solid #e4e4e7;margin-bottom:18px;}
.api-tabs a{padding:8px 14px;text-decoration:none;color:#52525b;font-weight:500;font-size:.88rem;border-bottom:2px solid transparent;margin-bottom:-1px;}
.api-tabs a.on{color:#09090b;border-bottom-color:#09090b;font-weight:600;}
.api-card{background:#fff;border:1px solid #e4e4e7;border-radius:12px;padding:18px;margin-bottom:16px;}
.api-card h2{font-size:1rem;font-weight:600;margin:0 0 4px;}
.api-mono{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.8rem;}
.api-key{background:#09090b;color:#fafafa;border-radius:8px;padding:12px 14px;word-break:break-all;}
.api-pre{background:#fafafa;border:1px solid #e4e4e7;border-radius:8px;padding:12px;max-height:420px;overflow:auto;white-space:pre-wrap;word-break:break-word;margin:0;}
.api-ep{border-top:1px solid #f4f4f5;padding:12px 0;}
.api-ep:first-child{border-top:0;}
.api-verb{display:inline-block;min-width:46px;text-align:center;border-radius:5px;font-size:.7rem;font-weight:700;padding:2px 6px;border:1px solid #e4e4e7;margin-right:6px;}
.api-tag{display:inline-block;border:1px solid #e4e4e7;border-radius:6px;font-size:.7rem;padding:1px 6px;margin:1px;color:#3f3f46;}
.api-table{width:100%;font-size:.85rem;}
.api-table th{font-weight:500;color:#71717a;font-size:.75rem;padding:8px;border-bottom:1px solid #e4e4e7;white-space:nowrap;}
.api-table td{padding:9px 8px;border-bottom:1px solid #f4f4f5;vertical-align:top;}
</style>

<?php if ($flash): ?><div class="alert alert-<?php echo $h($flash[0]); ?> py-2"><?php echo $h($flash[1]); ?></div><?php endif; ?>

<?php if (!$on): ?>
<!-- ======================= not switched on yet ======================= -->
<div class="row g-3">
  <div class="col-lg-7">
    <div class="api-card">
      <span class="api-tag">Premium · custom</span>
      <h2 class="mt-2" style="font-size:1.3rem">Connect your website to your POS</h2>
      <p class="text-muted">Your website shows your real shop, live: the products you sell in the POS — with their pictures, names, categories, prices and offers — and whether they are in stock, updated the moment you sell or restock.</p>
      <ul class="small" style="line-height:1.9">
        <li>Products, categories and offers straight from your inventory — only your shop, nobody else's</li>
        <li>Customers can comment on and like products; you approve comments before they show</li>
        <li>Views, likes and comments per product, so you see what people look at</li>
        <li>Optional: customers order on your website (name, phone, delivery address, note) and the order lands here in your POS</li>
        <li>Your own private API keys, limited to your websites, that you can switch off any time</li>
      </ul>
      <p class="small text-muted">See what it can do in the <a href="<?php echo $h(public_url('docs/website-api.php')); ?>" target="_blank" rel="noopener">Website API guide</a>.</p>
      <p class="small text-muted mb-0">This is set up per business. Send a request and we'll call you with a quotation; once it's switched on you can create your keys here.</p>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="api-card">
      <h2>Request a quotation</h2>
      <?php if ($S['requested_at']): ?><p class="small text-muted">You asked on <?php echo date('j M Y', strtotime($S['requested_at'])); ?> — we'll be in touch. You can send another request below.</p><?php endif; ?>
      <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="act" value="request">
        <label class="form-label small mb-1">Your website <span class="text-muted">(if you have one)</span></label>
        <input class="form-control mb-2" name="website" placeholder="https://myshop.co.ke">
        <label class="form-label small mb-1">Phone for our call</label>
        <input class="form-control mb-2" name="phone" placeholder="0712 345 678">
        <label class="form-label small mb-1">What should the website do? <span class="text-muted">(optional)</span></label>
        <textarea class="form-control mb-3" name="needs" rows="3" placeholder="e.g. show all products with prices, take orders for delivery in Nairobi"></textarea>
        <button class="btn btn-primary w-100">Send request</button>
      </form>
    </div>
  </div>
</div>
<?php else: ?>
<!-- ======================= switched on ======================= -->
<nav class="api-tabs">
  <?php foreach (['keys' => 'Keys', 'test' => 'Test', 'orders' => 'Orders', 'comments' => 'Comments', 'insights' => 'Insights', 'settings' => 'Settings', 'docs' => 'Docs'] as $k => $l): ?>
    <a class="<?php echo $tab === $k ? 'on' : ''; ?>" href="<?php echo $self . '?tab=' . $k; ?>"><?php echo $l; ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($tab === 'keys'): $keys = ShopApi::keys($account); ?>
  <?php if ($newKey): ?>
  <div class="api-card" style="border-color:#09090b">
    <h2>Your new API key</h2>
    <p class="small text-muted">Copy it now — for your safety it is never shown again. If you lose it, revoke it and create another.</p>
    <div class="d-flex gap-2 align-items-stretch">
      <div class="api-key api-mono flex-grow-1" id="newKey"><?php echo $h($newKey); ?></div>
      <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('newKey').textContent);this.textContent='Copied'">Copy</button>
    </div>
    <a class="small d-inline-block mt-2" href="<?php echo $self; ?>?tab=test" onclick="try{sessionStorage.setItem('dzApiKey', <?php echo $h(json_encode($newKey)); ?>)}catch(e){}">Test it now →</a>
  </div>
  <?php endif; ?>
  <div class="row g-3">
    <div class="col-lg-5"><div class="api-card">
      <h2>Create a key</h2>
      <p class="small text-muted">One key per website. A key sees one shop's inventory only.</p>
      <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="act" value="create_key">
        <label class="form-label small mb-1">Name</label>
        <input class="form-control mb-2" name="name" placeholder="e.g. Main website" required maxlength="80">
        <?php if (count($shops) > 1): ?>
          <label class="form-label small mb-1">Shop</label>
          <select class="form-select mb-2" name="shop"><?php foreach ($shops as $s): ?><option value="<?php echo (int) $s['id']; ?>" <?php echo (int) $s['id'] === $tid ? 'selected' : ''; ?>><?php echo $h($s['name']); ?></option><?php endforeach; ?></select>
        <?php endif; ?>
        <label class="form-label small mb-1">Permissions</label>
        <?php foreach (ShopApi::SCOPES as $k => $l): ?>
          <label class="form-check small"><input class="form-check-input" type="checkbox" name="scopes[]" value="<?php echo $k; ?>" <?php echo $k === 'insights' ? '' : 'checked'; ?>>
            <span class="form-check-label"><b><?php echo $k; ?></b> — <?php echo $h($l); ?></span></label>
        <?php endforeach; ?>
        <label class="form-label small mb-1 mt-2">Allowed websites <span class="text-muted">(optional)</span></label>
        <input class="form-control mb-1" name="origins" placeholder="https://myshop.co.ke, https://www.myshop.co.ke">
        <div class="form-text mb-3">Browsers on other websites are refused. Leave empty while testing.</div>
        <button class="btn btn-primary w-100">Create key</button>
      </form>
    </div></div>
    <div class="col-lg-7"><div class="api-card">
      <h2>Keys</h2>
      <?php if (!$keys): ?><p class="small text-muted mb-0">No keys yet.</p><?php else: ?>
      <div class="table-responsive"><table class="api-table">
        <thead><tr><th>Key</th><th>Permissions</th><th>Use</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($keys as $k): $dead = (bool) $k['revoked_at']; ?>
          <tr style="<?php echo $dead ? 'opacity:.5' : ''; ?>">
            <td><b><?php echo $h($k['name']); ?></b><div class="api-mono text-muted"><?php echo $h($k['prefix']); ?>…</div>
              <div class="small text-muted"><?php echo $h($k['shop_name'] ?? ''); ?><?php echo $k['origins'] ? ' · ' . $h(str_replace(',', ', ', $k['origins'])) : ' · any website'; ?></div></td>
            <td><?php foreach (explode(',', $k['scopes']) as $sc): ?><span class="api-tag"><?php echo $h($sc); ?></span><?php endforeach; ?></td>
            <td class="small text-nowrap"><?php echo number_format((int) $k['requests']); ?> calls<br><span class="text-muted"><?php echo $k['last_used_at'] ? 'last ' . date('j M H:i', strtotime($k['last_used_at'])) : 'never used'; ?></span></td>
            <td class="text-end text-nowrap"><?php if ($dead): ?><span class="small text-muted">revoked</span><?php else: $full = ShopApi::reveal($k); ?>
              <?php if ($full): ?>
                <button type="button" class="btn btn-sm btn-primary mb-1 api-copy" data-k="<?php echo $h($full); ?>" title="Copy the full key"><i class="fas fa-copy me-1"></i>Copy</button>
                <a class="btn btn-sm btn-outline-secondary mb-1" href="<?php echo $self; ?>?tab=test" onclick="try{sessionStorage.setItem('dzApiKey', <?php echo $h(json_encode($full)); ?>)}catch(e){}" title="Open the tester with this key"><i class="fas fa-flask"></i></a>
              <?php else: ?><span class="small text-muted d-block mb-1" title="Keys made before copying was added can't be shown again — create a new one">re-create to copy</span><?php endif; ?>
              <form method="post" onsubmit="return confirm('Revoke this key? Websites using it stop working at once.')"><?php echo csrf_field(); ?><input type="hidden" name="act" value="revoke"><input type="hidden" name="id" value="<?php echo (int) $k['id']; ?>">
                <button class="btn btn-sm btn-outline-danger">Revoke</button></form><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div></div>
  </div>

  <script>
  document.querySelectorAll('.api-copy').forEach(function (b) {
    b.addEventListener('click', function () {
      var done = function () { b.innerHTML = '<i class="fas fa-check me-1"></i>Copied'; setTimeout(function () { b.innerHTML = '<i class="fas fa-copy me-1"></i>Copy'; }, 1600); };
      if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(b.dataset.k).then(done); return; }
      var t = document.createElement('textarea'); t.value = b.dataset.k; document.body.appendChild(t); t.select(); document.execCommand('copy'); t.remove(); done();
    });
  });
  </script>

<?php elseif ($tab === 'test'): ?>
  <div class="api-card">
    <h2>Test the API</h2>
    <p class="small text-muted">Calls go to the real API with your key, exactly as your website will make them.</p>
    <div class="row g-2">
      <div class="col-md-6"><label class="form-label small mb-1">API key</label><input class="form-control api-mono" id="tKey" placeholder="dz_live_…"></div>
      <div class="col-md-6"><label class="form-label small mb-1">Endpoint</label>
        <select class="form-select" id="tEp">
          <?php foreach ([
              ['GET', 'shop.php', '', ''], ['GET', 'categories.php', '', ''], ['GET', 'products.php', 'per_page=12&stats=1', ''], ['GET', 'products.php', 'on_offer=1', ''],
              ['GET', 'product.php', 'id=', ''], ['GET', 'comments.php', 'product_id=', ''],
              ['POST', 'comments.php', '', '{"product_id": 0, "name": "Test customer", "comment": "Great product!", "rating": 5}'],
              ['POST', 'like.php', '', '{"product_id": 0, "visitor": "test-visitor-1"}'],
              ['POST', 'orders.php', '', '{"name": "Test customer", "phone": "0712345678", "address": "Ruiru, near the stage", "note": "Call on arrival", "items": [{"product_id": 0, "quantity": 1}]}'],
              ['GET', 'insights.php', 'days=30', ''],
          ] as $i => [$m, $ep, $q, $body]): ?>
            <option value="<?php echo $i; ?>" data-m="<?php echo $m; ?>" data-ep="<?php echo $ep; ?>" data-q="<?php echo $h($q); ?>" data-body="<?php echo $h($body); ?>"><?php echo $m . ' ' . $ep . ($q ? '?' . $q : ''); ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="col-12"><label class="form-label small mb-1">Query (after ?)</label><input class="form-control api-mono" id="tQ"></div>
      <div class="col-12" id="tBodyWrap" hidden><label class="form-label small mb-1">JSON body</label><textarea class="form-control api-mono" id="tBody" rows="5"></textarea></div>
      <div class="col-12"><button class="btn btn-primary" id="tSend" type="button"><i class="fas fa-paper-plane me-1"></i>Send</button> <span class="small text-muted ms-2" id="tStatus"></span></div>
    </div>
    <div class="api-mono small text-muted mt-3 mb-1" id="tUrl"></div>
    <pre class="api-pre api-mono" id="tOut">Pick an endpoint and press Send.</pre>
  </div>
  <script>
  (function () {
    var BASE = <?php echo json_encode($apiBase); ?>, $ = function (id) { return document.getElementById(id); };
    try { $('tKey').value = sessionStorage.getItem('dzApiKey') || ''; } catch (e) {}
    $('tKey').addEventListener('change', function () { try { sessionStorage.setItem('dzApiKey', $('tKey').value.trim()); } catch (e) {} });
    function pick() {
      var o = $('tEp').selectedOptions[0];
      $('tQ').value = o.dataset.q; $('tBody').value = o.dataset.body; $('tBodyWrap').hidden = o.dataset.m !== 'POST';
    }
    $('tEp').addEventListener('change', pick); pick();
    $('tSend').addEventListener('click', function () {
      var o = $('tEp').selectedOptions[0], url = BASE + o.dataset.ep + ($('tQ').value.trim() ? '?' + $('tQ').value.trim() : '');
      var opt = { method: o.dataset.m, headers: { 'X-Api-Key': $('tKey').value.trim() }, credentials: 'omit' };
      if (o.dataset.m === 'POST') { opt.headers['Content-Type'] = 'application/json'; opt.body = $('tBody').value; }
      $('tUrl').textContent = o.dataset.m + ' ' + url; $('tStatus').textContent = 'Sending…'; var t0 = performance.now();
      fetch(url, opt).then(function (r) {
        $('tStatus').textContent = r.status + ' ' + r.statusText + ' · ' + Math.round(performance.now() - t0) + ' ms';
        return r.text();
      }).then(function (txt) {
        try { $('tOut').textContent = JSON.stringify(JSON.parse(txt), null, 2); } catch (e) { $('tOut').textContent = txt; }
      }).catch(function (e) { $('tStatus').textContent = 'Failed'; $('tOut').textContent = String(e); });
    });
  })();
  </script>

<?php elseif ($tab === 'orders'):
    $in = implode(',', $shopIds ?: [0]);
    $orders = $pdo->query("SELECT o.*, t.name AS shop FROM shop_web_orders o LEFT JOIN tenants t ON t.id = o.tenant_id WHERE o.tenant_id IN ($in) ORDER BY o.status = 'new' DESC, o.id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
    $items = $pdo->query("SELECT * FROM shop_web_order_items WHERE tenant_id IN ($in) AND order_id IN (" . (implode(',', array_map(fn($o) => (int) $o['id'], $orders)) ?: '0') . ')')->fetchAll(PDO::FETCH_ASSOC);
    $byOrder = [];
    foreach ($items as $it) $byOrder[(int) $it['order_id']][] = $it; ?>
  <div class="api-card">
    <h2>Website orders</h2>
    <p class="small text-muted"><?php echo $S['orders_on'] ? 'Your website can take orders.' : 'Orders from the website are off — switch them on under Settings.'; ?> Confirming an order takes its stock off the shelf; cancelling a confirmed order puts it back.</p>
    <?php if (!$orders): ?><p class="small text-muted mb-0">No orders yet.</p><?php else: ?>
    <div class="table-responsive"><table class="api-table">
      <thead><tr><th>Order</th><th>Customer</th><th>Items</th><th class="text-end">Total</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($orders as $o): ?>
        <tr>
          <td class="text-nowrap"><b><?php echo $h($o['number']); ?></b><div class="small text-muted"><?php echo date('j M, H:i', strtotime($o['created_at'])); ?><?php echo count($shops) > 1 ? ' · ' . $h($o['shop']) : ''; ?></div></td>
          <td><?php echo $h($o['customer_name']); ?><div class="small"><a href="tel:<?php echo $h(preg_replace('/[^0-9+]/', '', $o['phone'])); ?>"><?php echo $h($o['phone']); ?></a></div>
            <div class="small text-muted"><?php echo $h($o['address']); ?></div><?php if ($o['note']): ?><div class="small fst-italic">“<?php echo $h($o['note']); ?>”</div><?php endif; ?></td>
          <td class="small"><?php foreach ($byOrder[(int) $o['id']] ?? [] as $it): ?><div><?php echo (float) $it['quantity']; ?> × <?php echo $h($it['name']); ?></div><?php endforeach; ?></td>
          <td class="text-end text-nowrap">KES <?php echo number_format((float) $o['total'], 0); ?></td>
          <td><form method="post"><?php echo csrf_field(); ?><input type="hidden" name="act" value="order"><input type="hidden" name="id" value="<?php echo (int) $o['id']; ?>">
            <select class="form-select form-select-sm" name="status" onchange="this.form.submit()" style="min-width:130px">
              <?php foreach (ShopApi::ORDER_STATUSES as $k => $l): ?><option value="<?php echo $k; ?>" <?php echo $o['status'] === $k ? 'selected' : ''; ?>><?php echo $l; ?></option><?php endforeach; ?>
            </select></form></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>

<?php elseif ($tab === 'comments'):
    $in = implode(',', $shopIds ?: [0]);
    $rows = $pdo->query("SELECT c.*, p.name AS product FROM shop_api_comments c LEFT JOIN products p ON p.id = c.product_id
                          WHERE c.tenant_id IN ($in) ORDER BY c.status = 'pending' DESC, c.id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC); ?>
  <div class="api-card">
    <h2>Comments</h2>
    <p class="small text-muted"><?php echo $S['comments_auto'] ? 'New comments show straight away.' : 'New comments wait here until you approve them.'; ?></p>
    <?php if (!$rows): ?><p class="small text-muted mb-0">No comments yet.</p><?php endif; ?>
    <?php foreach ($rows as $c): ?>
      <div class="api-ep d-flex gap-3 align-items-start">
        <div class="flex-grow-1">
          <div><b><?php echo $h($c['name']); ?></b> <span class="small text-muted">on <?php echo $h($c['product'] ?? 'a deleted product'); ?> · <?php echo date('j M, H:i', strtotime($c['created_at'])); ?></span>
            <?php if ($c['rating']): ?><span class="small ms-1"><?php echo str_repeat('★', (int) $c['rating']) . str_repeat('☆', 5 - (int) $c['rating']); ?></span><?php endif; ?>
            <span class="api-tag ms-1"><?php echo $h($c['status']); ?></span></div>
          <div class="small mt-1"><?php echo nl2br($h($c['comment'])); ?></div>
        </div>
        <form method="post" class="d-flex gap-1"><?php echo csrf_field(); ?><input type="hidden" name="act" value="comment"><input type="hidden" name="id" value="<?php echo (int) $c['id']; ?>">
          <?php if ($c['status'] !== 'approved'): ?><button class="btn btn-sm btn-primary" name="do" value="approved">Approve</button><?php endif; ?>
          <?php if ($c['status'] !== 'hidden'): ?><button class="btn btn-sm btn-outline-secondary" name="do" value="hidden">Hide</button><?php endif; ?>
          <button class="btn btn-sm btn-outline-danger" name="do" value="delete" onclick="return confirm('Delete this comment?')"><i class="fas fa-trash"></i></button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>

<?php elseif ($tab === 'insights'):
    $in = implode(',', $shopIds ?: [0]);
    $since = date('Y-m-d', strtotime('-29 days'));
    $daily = $pdo->query("SELECT day, SUM(views) FROM shop_api_views WHERE tenant_id IN ($in) AND day >= '$since' GROUP BY day")->fetchAll(PDO::FETCH_KEY_PAIR);
    $top = $pdo->query("SELECT p.id, p.name, p.tenant_id, p.image_path, COALESCE(SUM(v.views),0) views FROM products p
                          JOIN shop_api_views v ON v.tenant_id = p.tenant_id AND v.product_id = p.id AND v.day >= '$since'
                         WHERE p.tenant_id IN ($in) GROUP BY p.id ORDER BY views DESC LIMIT 25")->fetchAll(PDO::FETCH_ASSOC);
    $likes = $pdo->query("SELECT product_id, COUNT(*) FROM shop_api_likes WHERE tenant_id IN ($in) GROUP BY product_id")->fetchAll(PDO::FETCH_KEY_PAIR);
    $comm = $pdo->query("SELECT product_id, COUNT(*) FROM shop_api_comments WHERE tenant_id IN ($in) AND status = 'approved' GROUP BY product_id")->fetchAll(PDO::FETCH_KEY_PAIR);
    $days = [];
    for ($i = 29; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("-$i days")); $days[$d] = (int) ($daily[$d] ?? 0); }
    $max = max(1, max($days)); ?>
  <div class="api-card">
    <h2>Product views on your website</h2>
    <p class="small text-muted"><?php echo number_format(array_sum($days)); ?> views in the last 30 days · <?php echo number_format(array_sum($likes)); ?> likes · <?php echo number_format(array_sum($comm)); ?> comments</p>
    <div style="display:flex;align-items:flex-end;gap:3px;height:120px">
      <?php foreach ($days as $d => $n): ?><i title="<?php echo date('j M', strtotime($d)) . ': ' . $n; ?> views" style="flex:1;display:block;background:#18181b;border-radius:3px 3px 0 0;height:<?php echo max(2, $n / $max * 100); ?>%"></i><?php endforeach; ?>
    </div>
  </div>
  <div class="api-card">
    <h2>Most viewed products</h2>
    <?php if (!$top): ?><p class="small text-muted mb-0">No views yet — they are counted each time your website opens a product.</p><?php else: ?>
    <table class="api-table"><thead><tr><th>Product</th><th class="text-end">Views</th><th class="text-end">Likes</th><th class="text-end">Comments</th></tr></thead><tbody>
      <?php foreach ($top as $p): ?><tr><td><?php echo $h($p['name']); ?></td><td class="text-end"><?php echo number_format($p['views']); ?></td>
        <td class="text-end"><?php echo (int) ($likes[$p['id']] ?? 0); ?></td><td class="text-end"><?php echo (int) ($comm[$p['id']] ?? 0); ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
  </div>

<?php elseif ($tab === 'settings'): ?>
  <div class="api-card" style="max-width:640px">
    <h2>What your website may do</h2>
    <form method="post" class="mt-3"><?php echo csrf_field(); ?><input type="hidden" name="act" value="settings">
      <label class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="comments_on" value="1" <?php echo $S['comments_on'] ? 'checked' : ''; ?>><span class="form-check-label">Customers can comment on products</span></label>
      <label class="form-check form-switch mb-2 ms-4"><input class="form-check-input" type="checkbox" name="comments_auto" value="1" <?php echo $S['comments_auto'] ? 'checked' : ''; ?>><span class="form-check-label">Show comments without my approval</span></label>
      <label class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="likes_on" value="1" <?php echo $S['likes_on'] ? 'checked' : ''; ?>><span class="form-check-label">Customers can like products</span></label>
      <label class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="orders_on" value="1" <?php echo $S['orders_on'] ? 'checked' : ''; ?>><span class="form-check-label">Customers can place orders on my website</span></label>
      <label class="form-label small mt-2 mb-1">Note shown to customers when ordering <span class="text-muted">(optional)</span></label>
      <input class="form-control mb-3" name="order_note" maxlength="255" value="<?php echo $h($S['order_note']); ?>" placeholder="e.g. Delivery within Nairobi only. We call to confirm.">
      <button class="btn btn-primary">Save</button>
    </form>
  </div>

<?php else: /* docs */ ?>
  <div class="api-card d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div><h2>Full guide</h2><div class="small text-muted">Step-by-step instructions, copy-paste examples and troubleshooting. Send this link to whoever builds your website.</div></div>
    <div class="d-flex gap-2"><a class="btn btn-primary" href="<?php echo $h(public_url('docs/website-api.php')); ?>" target="_blank" rel="noopener"><i class="fas fa-book-open me-1"></i>Open the guide</a>
      <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(new URL(<?php echo $h(json_encode(public_url('docs/website-api.php'))); ?>, location.href).href);this.textContent='Link copied'">Copy link</button></div>
  </div>
  <div class="api-card">
    <h2>API reference</h2>
    <p class="small text-muted">Base URL <span class="api-mono"><?php echo $h($apiBase); ?></span>. Send your key in the <span class="api-mono">X-Api-Key</span> header. Every answer is JSON:
      <span class="api-mono">{"ok":true,"data":…}</span> or <span class="api-mono">{"ok":false,"error":{"code","message"}}</span>. Limit: <?php echo ShopApi::RATE_PER_MINUTE; ?> requests a minute per key.</p>
    <?php foreach ([
        ['GET', 'shop.php', 'products', 'Shop name, logo, currency, contacts and which features are on.'],
        ['GET', 'categories.php', 'products', 'Categories with subcategories and product counts.'],
        ['GET', 'products.php', 'products', 'Live products. Filters: q, category, subcategory, in_stock=1, on_offer=1, ids=1,2,3. sort=name|price|price_desc|newest|popular. page, per_page (≤100), stats=1 for views/likes/comments.'],
        ['GET', 'product.php?id=ID', 'products', 'One product with stats and its latest approved comments. Counts a view (add view=0 to skip).'],
        ['GET', 'comments.php?product_id=ID', 'comments', 'Approved comments, newest first (page=…).'],
        ['POST', 'comments.php', 'comments', 'Body: product_id, name, comment, rating (1–5, optional). Waits for your approval unless auto-approve is on.'],
        ['GET', 'like.php?product_id=ID&visitor=V', 'likes', 'Whether this visitor liked it, and the like count.'],
        ['POST', 'like.php', 'likes', 'Body: product_id, visitor (a random id your site stores per visitor), like (true/false; omit to toggle).'],
        ['POST', 'orders.php', 'orders', 'Body: name, phone, address, note (optional), items:[{product_id, quantity}]. Prices are taken from the POS. Returns the order number.'],
        ['GET', 'orders.php?number=WEB-000001&phone=07…', 'orders', 'Order status for a “track my order” page.'],
        ['GET', 'insights.php?days=30', 'insights', 'Views per day, top products with likes and comments, website orders. Call from your server only.'],
    ] as [$m, $ep, $scope, $desc]): ?>
      <div class="api-ep"><span class="api-verb"><?php echo $m; ?></span><span class="api-mono"><?php echo $h($ep); ?></span> <span class="api-tag"><?php echo $scope; ?></span>
        <div class="small text-muted mt-1"><?php echo $h($desc); ?></div></div>
    <?php endforeach; ?>
  </div>
  <div class="api-card">
    <h2>Example — show products on your website</h2>
<pre class="api-pre api-mono">&lt;div id="products"&gt;&lt;/div&gt;
&lt;script&gt;
fetch('<?php echo $h($apiBase); ?>products.php?in_stock=1&amp;per_page=24', {
  headers: { 'X-Api-Key': 'YOUR_KEY' }
})
  .then(r =&gt; r.json())
  .then(res =&gt; {
    document.getElementById('products').innerHTML = res.data.map(p =&gt; `
      &lt;div class="product"&gt;
        &lt;img src="${p.image || ''}" alt=""&gt;
        &lt;h3&gt;${p.name}&lt;/h3&gt;
        &lt;p&gt;${p.on_offer ? '&lt;s&gt;' + p.regular_price + '&lt;/s&gt; ' : ''}KES ${p.price}&lt;/p&gt;
        &lt;small&gt;${p.in_stock ? 'In stock' : 'Out of stock'}&lt;/small&gt;
      &lt;/div&gt;`).join('');
  });
&lt;/script&gt;</pre>
    <h2 class="mt-3">Example — from a terminal</h2>
<pre class="api-pre api-mono">curl -H "X-Api-Key: YOUR_KEY" "<?php echo $h($apiBase); ?>products.php?on_offer=1"

curl -X POST -H "X-Api-Key: YOUR_KEY" -H "Content-Type: application/json" \
  -d '{"name":"Jane","phone":"0712345678","address":"Ruiru","items":[{"product_id":12,"quantity":2}]}' \
  "<?php echo $h($apiBase); ?>orders.php"</pre>
  </div>
<?php endif; ?>
<?php endif; ?>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/tenants/layout.php';
