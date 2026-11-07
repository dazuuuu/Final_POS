<?php
// public/super/import/photos.php — add product photos in bulk (a zip or many
// images). Each photo finds its product by itself — see PhotoImport.
//
// Any size works, on any connection: the browser sends the file in small
// pieces (each far below the server's upload limit, retried if the
// connection blips), then the photos are matched a batch at a time (each
// request well inside the server's time limit). The JSON steps:
//   ?api=start   → a job for one file        ?api=chunk → one piece of it
//   ?api=process → match the next batch      (repeat until finished)
require_once __DIR__ . '/../../../app/app.php';
PageGuard::ownerCan(Capabilities::INVENTORY_EDIT);

$pdo = Database::pdo();
$tenantId = (int) TenantContext::tenantId();
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$_SESSION['photo_import_csrf'] ??= bin2hex(random_bytes(16));
$csrf = $_SESSION['photo_import_csrf'];
$work = ROOT_PATH . '/app/storage/imports';

$bytes = function (string $v): int {
    $n = (int) $v; $u = strtolower(substr(trim($v), -1));
    return $u === 'g' ? $n << 30 : ($u === 'm' ? $n << 20 : ($u === 'k' ? $n << 10 : $n));
};
$postMax = $bytes(ini_get('post_max_size') ?: '8M') ?: (8 << 20);
$chunkSize = max(256 << 10, min(1 << 20, (int) ($postMax / 2))); // 1 MB, smaller if the server only takes less

// ---------------------------------------------------------------- JSON steps
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    $send = function (int $code, array $body) { http_response_code($code); echo json_encode($body); exit; };
    if (!hash_equals($csrf, (string) ($_SERVER['HTTP_X_CSRF'] ?? ''))) $send(403, ['error' => 'This page expired — reload it and try again.']);
    session_write_close(); // never hold the session while a big file comes in
    @set_time_limit(120);
    if (!is_dir($work)) @mkdir($work, 0775, true);

    $id = preg_replace('/[^a-z0-9_]/', '', (string) ($_GET['id'] ?? ''));
    $dir = $work . '/' . $id;
    $stateFile = $dir . '/state.json';
    $load = function () use ($stateFile, $tenantId, $send) {
        $s = is_file($stateFile) ? json_decode((string) file_get_contents($stateFile), true) : null;
        if (!$s || (int) $s['tenant'] !== $tenantId) $send(404, ['error' => 'This upload was not found — please start again.']);
        return $s;
    };
    $save = fn(array $s) => file_put_contents($stateFile, json_encode($s), LOCK_EX);

    switch ($_GET['api']) {
        case 'start':
            // Tidy away uploads abandoned more than a day ago.
            foreach (glob($work . '/photos_*', GLOB_ONLYDIR) ?: [] as $old) {
                if (filemtime($old) < time() - 86400) { array_map('unlink', glob($old . '/*') ?: []); @rmdir($old); }
            }
            $name = basename((string) ($_POST['name'] ?? 'photos.zip'));
            $size = (int) ($_POST['size'] ?? 0);
            $isZip = strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'zip';
            if ($size <= 0) $send(400, ['error' => 'That file is empty.']);
            if (!$isZip && !in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), PhotoImport::EXT, true)) $send(400, ['error' => $name . ' is not a photo or a zip.']);
            $id = 'photos_' . $tenantId . '_' . bin2hex(random_bytes(8));
            $dir = $work . '/' . $id;
            @mkdir($dir, 0775, true);
            touch($dir . '/data.bin');
            file_put_contents($dir . '/state.json', json_encode([
                'tenant' => $tenantId, 'name' => $name, 'size' => $size, 'zip' => $isZip, 'received' => 0,
                'replace' => !empty($_POST['replace']), 'done' => [],
            ]));
            $send(200, ['id' => $id, 'chunk' => $chunkSize]);

        case 'chunk':
            $s = $load();
            $offset = (int) ($_GET['offset'] ?? -1);
            if ($offset < $s['received']) $send(200, ['received' => $s['received']]); // a piece sent twice (retry): already have it
            if ($offset > $s['received']) $send(409, ['received' => $s['received'], 'error' => 'Out of order']);
            $in = fopen('php://input', 'rb');
            $out = fopen($dir . '/data.bin', 'ab');
            $n = stream_copy_to_stream($in, $out);
            fclose($in); fclose($out);
            $s['received'] += (int) $n;
            $save($s);
            $send(200, ['received' => $s['received']]);

        case 'link':
            // The online store's database: which photo file belongs to which product.
            $f = $_FILES['sql'] ?? null;
            if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) $send(400, ['error' => 'The store database (.sql) did not upload.']);
            $parsed = StoreImport::parse((string) file_get_contents($f['tmp_name']));
            if (!$parsed['products']) $send(400, ['error' => 'No products were found in that .sql file.']);
            $send(200, PhotoImport::linkFromStore($pdo, $parsed) + ['in_file' => count($parsed['products'])]);

        case 'process':
            $s = $load();
            if ($s['received'] < $s['size']) $send(409, ['error' => 'The file has not finished uploading.', 'received' => $s['received']]);
            $offset = (int) ($_GET['offset'] ?? 0);
            try {
                $r = PhotoImport::runBatch($pdo, $dir . '/data.bin', (bool) $s['zip'], $offset, 25, (bool) $s['replace'], $s);
            } catch (\Throwable $e) {
                $send(500, ['error' => $e->getMessage()]);
            }
            $save($s);
            $res = ['next' => $r['next'], 'total' => $r['total'], 'out' => $s['out']];
            if ($r['next'] === null) {
                array_map('unlink', glob($dir . '/*') ?: []); @rmdir($dir);
                $res['still_missing'] = PhotoImport::stillMissing($pdo);
            }
            $send(200, $res);
    }
    $send(400, ['error' => 'Unknown step.']);
}

// ---------------------------------------------------------------- the page
$tenant = (new Models\TenantModel($pdo))->find($tenantId);
$st = $pdo->prepare("SELECT COUNT(*) total, SUM(image_path IS NULL OR image_path = '') missing FROM products WHERE tenant_id = ? AND status <> 'archived'");
$st->execute([$tenantId]);
$counts = $st->fetch();

$page_title = 'Import photos';
ob_start();
?>
<div class="mb-3">
  <a href="<?php echo public_url('super/inventory/'); ?>" class="text-decoration-none text-muted">&larr; Inventory</a>
  <h1 class="h4 mt-1 mb-1">Import product photos</h1>
  <p class="text-muted mb-0">Upload many photos at once and each one finds its product by itself.
    <strong id="missingCount"><?php echo (int) $counts['missing']; ?></strong> of <?php echo (int) $counts['total']; ?> products in <?php echo $h($tenant['name'] ?? 'this shop'); ?> have no photo yet.</p>
</div>

<div id="piResult"></div>

<div class="row g-4">
  <div class="col-12 col-lg-7">
    <div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-4">
      <form id="piForm" data-no-loader>
        <div class="mb-3">
          <label class="form-label fw-semibold">Your online store's database (.sql) <span class="text-muted fw-normal">— for photos from the website</span></label>
          <input type="file" id="piSql" accept=".sql,text/plain" class="form-control">
          <div class="form-text">The same <code>.sql</code> you imported the products from. It says which photo belongs to which product (website photos have names like <code>00c6fef4de3124eca274.jpg</code>). Not needed for photos named after the product.</div>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">A zip of photos</label>
          <input type="file" name="zip" id="piZip" accept=".zip" class="form-control">
          <div class="form-text">Any size — e.g. your online store's <code>uploads.zip</code>. Folders inside are fine.</div>
        </div>
        <div class="text-center text-muted small my-2">— or —</div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Select photo files</label>
          <input type="file" name="photos" id="piFiles" accept="image/*" multiple class="form-control">
          <div class="form-text">As many as you like.</div>
        </div>
        <div class="form-check mb-3">
          <input class="form-check-input" type="checkbox" id="piReplace">
          <label class="form-check-label small" for="piReplace">Replace photos that products already have</label>
        </div>
        <button class="btn btn-primary" id="piGo"><i class="fas fa-images me-1"></i>Upload &amp; match photos</button>
      </form>

      <div id="piProgress" class="d-none">
        <div class="d-flex justify-content-between small mb-1"><strong id="piStage">Uploading…</strong><span id="piPct">0%</span></div>
        <div class="progress mb-2" style="height:12px;border-radius:8px;"><div id="piBar" class="progress-bar progress-bar-striped progress-bar-animated" style="width:0%"></div></div>
        <div class="small text-muted" id="piDetail"></div>
        <div class="small text-warning mt-2 d-none" id="piWarn"></div>
        <div class="small text-muted mt-2">You can keep this page open and carry on — it finishes by itself, even on a slow connection.</div>
      </div>
    </div></div>
  </div>
  <div class="col-12 col-lg-5">
    <div class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-4 small">
      <h2 class="h6 fw-bold">How photos find their product</h2>
      <ol class="mb-2 ps-3">
        <li><strong>Imported from your online store:</strong> the photo keeps its original file name, so it goes straight back to its product.</li>
        <li><strong>By barcode or code:</strong> <code>6161101234567.jpg</code>, <code>pentagon-001.png</code></li>
        <li><strong>By product name:</strong> <code>Maasai headgear.jpg</code> (capitals, spaces and dashes don't matter)</li>
      </ol>
      <div class="text-muted">Products that already have a photo keep it, unless you tick "Replace".</div>
    </div></div>
  </div>
</div>

<script>
(function () {
  var API = <?php echo json_encode(public_url('super/import/photos.php')); ?>;
  var CSRF = <?php echo json_encode($csrf); ?>;
  var $ = function (id) { return document.getElementById(id); };
  var form = $('piForm');

  function mb(n) { return (n / 1048576).toFixed(n < 10485760 ? 1 : 0) + ' MB'; }
  function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
  function online() {
    if (navigator.onLine !== false) return Promise.resolve();
    warn('No internet — waiting to reconnect. It carries on by itself.');
    return new Promise(function (r) { window.addEventListener('online', function f() { window.removeEventListener('online', f); warn(''); r(); }); });
  }
  function warn(t) { $('piWarn').textContent = t; $('piWarn').classList.toggle('d-none', !t); }
  function show(stage, pct, detail) {
    $('piStage').textContent = stage;
    $('piPct').textContent = Math.floor(pct) + '%';
    $('piBar').style.width = pct + '%';
    if (detail !== undefined) $('piDetail').textContent = detail;
  }

  // One step, retried patiently: a blip in the connection never ends the import.
  async function call(step, query, body, isForm) {
    for (var attempt = 1; ; attempt++) {
      await online();
      try {
        var r = await fetch(API + '?api=' + step + (query ? '&' + query : ''), {
          method: 'POST', body: body, credentials: 'same-origin',
          headers: Object.assign({ 'X-CSRF': CSRF }, isForm ? {} : { 'Content-Type': 'application/octet-stream' })
        });
        var data = await r.json().catch(function () { return {}; });
        if (r.ok || r.status === 409) { warn(''); return { status: r.status, data: data }; }
        if (r.status < 500 && r.status !== 429) throw new Error(data.error || ('The server said no (' + r.status + ').'));
        throw { retry: true, msg: data.error || ('Server busy (' + r.status + ')') };
      } catch (e) {
        if (!e.retry && !(e instanceof TypeError)) throw e; // a real refusal: stop and say why
        if (attempt >= 30) throw new Error('The connection kept failing. Press Upload again to continue.');
        warn('Connection problem — trying again (' + attempt + ')…');
        await sleep(Math.min(15000, 1000 * attempt));
      }
    }
  }

  async function importFile(file, replace, done, totalBytes) {
    var fd = new FormData();
    fd.append('name', file.name); fd.append('size', file.size);
    if (replace) fd.append('replace', '1');
    var job = (await call('start', '', fd, true)).data;

    // 1. Upload, a piece at a time (0–70% of the bar).
    var sent = 0;
    while (sent < file.size) {
      var res = await call('chunk', 'id=' + job.id + '&offset=' + sent, file.slice(sent, sent + job.chunk), false);
      sent = res.data.received;
      var all = done.bytes + sent;
      show('Uploading ' + file.name + '…', all / totalBytes * 70, mb(all) + ' of ' + mb(totalBytes) + ' sent');
    }
    done.bytes += file.size;

    // 2. Match the photos to products, a batch at a time (70–100%).
    var offset = 0, r;
    do {
      r = (await call('process', 'id=' + job.id + '&offset=' + offset, null, true)).data;
      if (r.next !== null && r.next !== undefined) {
        offset = r.next;
        show('Putting photos on their products…', 70 + (done.files + offset / r.total) / done.count * 30, offset + ' of ' + r.total + ' files checked in ' + file.name);
      }
    } while (r.next !== null && r.next !== undefined);
    done.files++;
    return r;
  }

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    var files = $('piZip').files.length ? [$('piZip').files[0]] : Array.prototype.slice.call($('piFiles').files);
    if (!files.length) { alert('Choose a zip of photos, or select the photo files.'); return; }
    var total = files.reduce(function (n, f) { return n + f.size; }, 0) || 1;
    form.classList.add('d-none'); $('piProgress').classList.remove('d-none'); $('piResult').innerHTML = '';
    window.onbeforeunload = function () { return 'Photos are still uploading.'; };
    var sum = { matched: 0, skipped_has_photo: 0, unmatched: [], bad: [] }, missing = null, state = { bytes: 0, files: 0, count: files.length }, link = null;
    try {
      if ($('piSql').files.length) {
        show('Reading the store database…', 0, 'Linking each website photo to its product');
        var fd = new FormData(); fd.append('sql', $('piSql').files[0]);
        link = (await call('link', '', fd, true)).data;
      }
      for (var i = 0; i < files.length; i++) {
        var r = await importFile(files[i], $('piReplace').checked, state, total);
        sum.matched += r.out.matched; sum.skipped_has_photo += r.out.skipped_has_photo;
        sum.unmatched = sum.unmatched.concat(r.out.unmatched); sum.bad = sum.bad.concat(r.out.bad);
        missing = r.still_missing;
      }
      show('Done', 100, '');
      $('piBar').classList.remove('progress-bar-animated');
      $('missingCount').textContent = missing;
      var esc = function (t) { var d = document.createElement('div'); d.textContent = t; return d.innerHTML; };
      $('piResult').innerHTML = (link ? '<div class="alert alert-info small">Store database: ' + link.linked + ' of ' + link.in_file + ' products linked to their website photo.'
          + (link.not_found.length ? ' Not found in this shop: ' + esc(link.not_found.slice(0, 15).join(', ')) + (link.not_found.length > 15 ? ' …' : '') : '') + '</div>' : '')
        + '<div class="alert alert-success"><strong>' + sum.matched + ' product' + (sum.matched === 1 ? '' : 's') + ' got a photo.</strong> '
        + (sum.skipped_has_photo ? sum.skipped_has_photo + ' already had one and were left as they were. ' : '')
        + missing + ' product' + (missing === 1 ? ' is' : 's are') + ' still without a photo. <a href="<?php echo public_url('super/inventory/'); ?>">Open inventory →</a></div>'
        + (sum.unmatched.length ? '<div class="alert alert-light border small"><strong>' + sum.unmatched.length + ' other photo(s) in the upload matched no product</strong> — usually extra pictures (second photos, banners, logos). To use one, name it after the product\'s name or barcode and upload it again: ' + esc(sum.unmatched.slice(0, 30).join(', ')) + (sum.unmatched.length > 30 ? ' …' : '') + '</div>' : '')
        + (sum.bad.length ? '<div class="alert alert-warning small">Not a readable image (or over 8 MB): ' + esc(sum.bad.slice(0, 20).join(', ')) + '</div>' : '');
    } catch (err) {
      $('piResult').innerHTML = '<div class="alert alert-danger">' + (err.message || 'The import stopped.') + '</div>';
    }
    window.onbeforeunload = null;
    form.reset(); form.classList.remove('d-none'); $('piProgress').classList.add('d-none'); warn('');
  });
})();
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/tenants/layout.php';
