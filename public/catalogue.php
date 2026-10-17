<?php
// public/catalogue.php[?shop=<slug>]
// Public, read-only product catalogue the owner shares as a link. Shows the
// shop's logo and what's in stock right now: photo, price, how many are
// left and the product description. Visitors can't change anything — they
// can only like a product or leave a comment on it.
require_once __DIR__ . '/../app/app.php';

$pdo = Database::pdo();

// ---- Resolve tenant: by slug, or the single shop on this install ----
$shopSlug = trim($_GET['shop'] ?? '');
if ($shopSlug !== '') {
    $stmtT = $pdo->prepare("SELECT * FROM tenants WHERE slug = ? AND status = 'active' LIMIT 1");
    $stmtT->execute([$shopSlug]);
    $tenant = $stmtT->fetch(PDO::FETCH_ASSOC) ?: null;
} else {
    $tenant = (new Models\TenantModel($pdo))->primary();
}
if (!$tenant) {
    http_response_code(404);
    die('<h1>Shop not found.</h1>');
}
$tenantId = (int) $tenant['id'];
$shopSlug = $tenant['slug'] ?? $shopSlug;

$products = (new Models\ProductModel($pdo))->catalogueForTenant($tenantId);
$F = new Models\CatalogueFeedbackModel($pdo);
$visitor = preg_replace('/[^a-f0-9]/', '', (string) ($_COOKIE['cat_visitor'] ?? ''));
$likes = $F->likeSummary($tenantId, $visitor);
$commentCounts = $F->commentCounts($tenantId);

$shopName = $tenant['name'] ?? 'Our Shop';
$shopPhone = $tenant['phone'] ?? '';
$shopAddr = $tenant['address'] ?? '';
$currency = $tenant['currency'] ?? 'KES';
$logoUrl = Branding::shopLogo($tenant);
$categories = array_values(array_unique(array_filter(array_map(fn($p) => $p['category_name'] ?? '', $products))));
sort($categories);

$fmtQty = fn($q) => rtrim(rtrim(number_format((float) $q, 2), '0'), '.');
$cards = [];
foreach ($products as $p) {
    $pid = (int) $p['id'];
    $cards[] = [
        'id' => $pid,
        'name' => $p['name'],
        'size' => Models\ProductModel::sizeLabel($p),
        'category' => $p['category_name'] ?? '',
        'price' => (float) $p['price'],
        'regular' => (float) $p['regular_price'],
        'on_offer' => (bool) $p['on_offer'],
        'stock' => $fmtQty($p['quantity']),
        'image' => $p['image_path'] ?? '',
        'description' => $p['description'] ?? '',
        'likes' => $likes[$pid]['likes'] ?? 0,
        'liked' => $likes[$pid]['mine'] ?? false,
        'comments' => $commentCounts[$pid] ?? 0,
    ];
}
$feedbackApi = public_url('api/catalogue/feedback.php');
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $h($shopName); ?> — In stock now</title>
<meta name="description" content="See what <?php echo $h($shopName); ?> has in stock, with prices.">
<meta property="og:title" content="<?php echo $h($shopName); ?> — what we have for you in stock">
<meta property="og:description" content="<?php echo count($cards); ?> products in stock. Tap to see prices and details.">
<?php if ($logoUrl): ?><meta property="og:image" content="<?php echo $h($logoUrl); ?>"><?php endif; ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; }
  :root {
    --brand: #0f766e; --brand-dark: #115e59; --brand-light: #ecfdf9;
    --text: #0f172a; --muted: #64748b; --bg: #f6f7f9; --card: #fff; --line: #e5e7eb;
    --heart: #e11d48; --offer: #f59e0b;
  }
  body { margin: 0; font-family: 'Inter', -apple-system, 'Segoe UI', Roboto, sans-serif; background: var(--bg); color: var(--text); }
  .hero { background: var(--card); border-bottom: 1px solid var(--line); }
  .hero-inner { max-width: 1140px; margin: 0 auto; padding: 28px 16px 22px; display: flex; gap: 18px; align-items: center; }
  .logo { width: 76px; height: 76px; border-radius: 18px; object-fit: contain; background: #fff; border: 1px solid var(--line); padding: 6px; flex-shrink: 0; }
  .logo-ph { width: 76px; height: 76px; border-radius: 18px; background: var(--brand-light); color: var(--brand); display: flex; align-items: center; justify-content: center; font-size: 1.8rem; flex-shrink: 0; }
  .shop { font-size: clamp(1.35rem, 4vw, 2rem); font-weight: 800; line-height: 1.15; margin: 0; }
  .tagline { margin: 4px 0 0; color: var(--brand); font-weight: 700; font-size: .95rem; }
  .meta { color: var(--muted); font-size: .82rem; margin-top: 6px; display: flex; gap: 14px; flex-wrap: wrap; }
  .meta a { color: inherit; }
  main { max-width: 1140px; margin: 0 auto; padding: 18px 16px 60px; }
  .tools { display: flex; flex-direction: column; gap: 10px; margin-bottom: 18px; }
  .search { position: relative; }
  .search i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--muted); }
  .search input { width: 100%; padding: 13px 14px 13px 40px; border: 1px solid var(--line); border-radius: 12px; font-size: 16px; background: #fff; outline: none; }
  .search input:focus { border-color: var(--brand); box-shadow: 0 0 0 3px rgba(15,118,110,.12); }
  .chips { display: flex; gap: 8px; overflow-x: auto; padding-bottom: 2px; }
  .chip { flex: 0 0 auto; border: 1px solid var(--line); background: #fff; color: var(--muted); border-radius: 999px; padding: 8px 14px; font-size: .82rem; font-weight: 600; cursor: pointer; min-height: 38px; }
  .chip.active { border-color: var(--brand); background: var(--brand-light); color: var(--brand-dark); }
  .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 16px; }
  @media (max-width: 520px) { .grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; } .hero-inner { padding: 20px 16px 16px; } .logo, .logo-ph { width: 60px; height: 60px; } }
  .card { background: var(--card); border: 1px solid var(--line); border-radius: 16px; overflow: hidden; display: flex; flex-direction: column; cursor: pointer; transition: box-shadow .15s, transform .15s; min-width: 0; }
  .card:hover { box-shadow: 0 8px 24px rgba(15,23,42,.08); transform: translateY(-2px); }
  .card:focus-visible { outline: 3px solid var(--brand); outline-offset: 2px; }
  .img { aspect-ratio: 1; background: #f1f5f9; position: relative; }
  .img img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .img .ph { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; color: #cbd5e1; font-size: 2.4rem; }
  .ribbon { position: absolute; top: 8px; left: 8px; background: var(--offer); color: #fff; font-size: .66rem; font-weight: 800; padding: 3px 8px; border-radius: 999px; letter-spacing: .03em; }
  .body { padding: 12px 12px 10px; display: flex; flex-direction: column; flex: 1; gap: 4px; }
  .cat { font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--brand); }
  .name { font-weight: 700; font-size: .9rem; line-height: 1.3; overflow-wrap: anywhere; }
  .name small { color: var(--muted); font-weight: 500; }
  .price { font-weight: 800; font-size: 1.05rem; margin-top: auto; }
  .price s { color: var(--muted); font-weight: 500; font-size: .78rem; margin-right: 4px; }
  .stock { font-size: .76rem; color: var(--brand-dark); font-weight: 600; }
  .stock.low { color: #b45309; }
  .foot { display: flex; gap: 6px; padding: 0 8px 8px; }
  .act { border: 0; background: none; color: var(--muted); font-size: .82rem; font-weight: 600; padding: 8px; min-height: 40px; border-radius: 10px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
  .act:hover { background: #f1f5f9; }
  .act.liked { color: var(--heart); }
  .empty { text-align: center; padding: 60px 16px; color: var(--muted); }
  .empty i { font-size: 2.6rem; color: #cbd5e1; display: block; margin-bottom: 10px; }
  footer { text-align: center; padding: 22px 16px; font-size: .78rem; color: var(--muted); border-top: 1px solid var(--line); }
  /* Product details sheet */
  .sheet-bg { position: fixed; inset: 0; background: rgba(15,23,42,.5); display: none; align-items: flex-end; justify-content: center; z-index: 50; }
  .sheet-bg.show { display: flex; }
  @media (min-width: 700px) { .sheet-bg { align-items: center; } }
  .sheet { background: #fff; width: 100%; max-width: 640px; max-height: 92vh; overflow-y: auto; border-radius: 20px 20px 0 0; }
  @media (min-width: 700px) { .sheet { border-radius: 20px; } }
  .sheet-img { aspect-ratio: 4/3; background: #f1f5f9; position: relative; }
  .sheet-img img { width: 100%; height: 100%; object-fit: contain; }
  .sheet-img .ph { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; color: #cbd5e1; font-size: 3rem; }
  .close { position: absolute; top: 12px; right: 12px; width: 40px; height: 40px; border-radius: 50%; border: 0; background: rgba(255,255,255,.95); font-size: 1.1rem; cursor: pointer; box-shadow: 0 2px 8px rgba(0,0,0,.15); }
  .sheet-body { padding: 18px 18px 22px; }
  .sheet h2 { margin: 2px 0 6px; font-size: 1.25rem; }
  .desc { white-space: pre-line; color: #334155; line-height: 1.55; margin: 12px 0; font-size: .93rem; }
  .desc.none { color: var(--muted); font-style: italic; }
  .comments h3 { font-size: .9rem; margin: 18px 0 8px; }
  .comment { border-top: 1px solid var(--line); padding: 10px 0; font-size: .88rem; }
  .comment b { font-size: .82rem; }
  .comment span { color: var(--muted); font-size: .74rem; margin-left: 6px; }
  .comment p { margin: 3px 0 0; white-space: pre-line; overflow-wrap: anywhere; }
  .cform { display: grid; gap: 8px; margin-top: 10px; }
  .cform input, .cform textarea { width: 100%; border: 1px solid var(--line); border-radius: 10px; padding: 10px 12px; font: inherit; font-size: 16px; }
  .cform button { background: var(--brand); color: #fff; border: 0; border-radius: 10px; padding: 12px; font-weight: 700; font-size: .92rem; cursor: pointer; min-height: 44px; }
  .cform button:disabled { opacity: .6; }
  .cmsg { font-size: .8rem; color: #b91c1c; min-height: 1em; }
</style>
</head>
<body>

<header class="hero">
  <div class="hero-inner">
    <?php if ($logoUrl): ?>
      <img class="logo" src="<?php echo $h($logoUrl); ?>" alt="<?php echo $h($shopName); ?> logo">
    <?php else: ?>
      <div class="logo-ph"><i class="fas fa-store"></i></div>
    <?php endif; ?>
    <div>
      <h1 class="shop"><?php echo $h($shopName); ?></h1>
      <p class="tagline">What we have for you in stock</p>
      <div class="meta">
        <?php if ($shopPhone): ?><span><i class="fas fa-phone"></i> <a href="tel:<?php echo $h(preg_replace('/\s+/', '', $shopPhone)); ?>"><?php echo $h($shopPhone); ?></a></span><?php endif; ?>
        <?php if ($shopAddr): ?><span><i class="fas fa-location-dot"></i> <?php echo $h($shopAddr); ?></span><?php endif; ?>
        <span><i class="fas fa-box"></i> <?php echo count($cards); ?> product<?php echo count($cards) === 1 ? '' : 's'; ?></span>
      </div>
    </div>
  </div>
</header>

<main>
  <?php if (!$cards): ?>
    <div class="empty"><i class="fas fa-box-open"></i>Nothing in stock right now — check back soon.</div>
  <?php else: ?>
  <div class="tools">
    <div class="search"><i class="fas fa-search"></i><input type="search" id="q" placeholder="Search products…" autocomplete="off"></div>
    <?php if ($categories): ?>
    <div class="chips" id="chips">
      <button type="button" class="chip active" data-cat="">All</button>
      <?php foreach ($categories as $c): ?><button type="button" class="chip" data-cat="<?php echo $h($c); ?>"><?php echo $h($c); ?></button><?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="grid" id="grid">
    <?php foreach ($cards as $c): ?>
    <article class="card" tabindex="0" data-id="<?php echo $c['id']; ?>" data-name="<?php echo $h(mb_strtolower($c['name'] . ' ' . $c['category'])); ?>" data-cat="<?php echo $h($c['category']); ?>">
      <div class="img">
        <?php if ($c['image']): ?><img src="<?php echo $h($c['image']); ?>" alt="<?php echo $h($c['name']); ?>" loading="lazy"><?php else: ?><div class="ph"><i class="fas fa-box"></i></div><?php endif; ?>
        <?php if ($c['on_offer']): ?><span class="ribbon">OFFER</span><?php endif; ?>
      </div>
      <div class="body">
        <?php if ($c['category']): ?><div class="cat"><?php echo $h($c['category']); ?></div><?php endif; ?>
        <div class="name"><?php echo $h($c['name']); ?><?php echo $c['size'] ? ' <small>' . $h($c['size']) . '</small>' : ''; ?></div>
        <div class="price"><?php if ($c['on_offer'] && $c['regular'] > $c['price']): ?><s><?php echo $h($currency) . ' ' . number_format($c['regular'], 0); ?></s><?php endif; ?><?php echo $h($currency) . ' ' . number_format($c['price'], 0); ?></div>
        <div class="stock<?php echo (float) $c['stock'] <= 5 ? ' low' : ''; ?>"><?php echo $h($c['stock']); ?> in stock</div>
      </div>
      <div class="foot">
        <button type="button" class="act like<?php echo $c['liked'] ? ' liked' : ''; ?>" data-like="<?php echo $c['id']; ?>" aria-label="Like"><i class="<?php echo $c['liked'] ? 'fas' : 'far'; ?> fa-heart"></i><span><?php echo $c['likes']; ?></span></button>
        <button type="button" class="act" aria-label="Comments"><i class="far fa-comment"></i><span class="ccount"><?php echo $c['comments']; ?></span></button>
      </div>
    </article>
    <?php endforeach; ?>
  </div>
  <div class="empty" id="noMatch" style="display:none;"><i class="fas fa-search"></i>No products match your search.</div>
  <?php endif; ?>
</main>

<footer><?php echo $h($shopName); ?> · prices and stock are live · Powered by Dazu Modern POS</footer>

<div class="sheet-bg" id="sheetBg" aria-hidden="true">
  <div class="sheet" role="dialog" aria-modal="true" aria-labelledby="sName">
    <div class="sheet-img" id="sImg"></div>
    <div class="sheet-body">
      <div class="cat" id="sCat"></div>
      <h2 id="sName"></h2>
      <div class="price" id="sPrice"></div>
      <div class="stock" id="sStock"></div>
      <div class="desc" id="sDesc"></div>
      <button type="button" class="act like" id="sLike"><i class="far fa-heart"></i><span>0</span> <em style="font-style:normal;">Like</em></button>
      <div class="comments">
        <h3>Comments</h3>
        <div id="sComments"></div>
        <form class="cform" id="cForm">
          <input type="text" name="name" maxlength="60" placeholder="Your name (optional)">
          <textarea name="body" rows="2" maxlength="500" placeholder="Ask a question or leave a comment…" required></textarea>
          <div class="cmsg" id="cMsg"></div>
          <button type="submit">Post comment</button>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var PRODUCTS = <?php echo json_encode(array_column($cards, null, 'id'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
  var API = <?php echo json_encode($feedbackApi); ?>;
  var SHOP = <?php echo json_encode($shopSlug); ?>;
  var CUR = <?php echo json_encode($currency); ?>;
  var grid = document.getElementById('grid');
  if (!grid) return;
  var current = null;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
  function money(n) { return CUR + ' ' + Math.round(n).toLocaleString(); }
  function post(data) {
    var body = new URLSearchParams(Object.assign({ shop: SHOP }, data));
    return fetch(API, { method: 'POST', body: body, credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }

  // Search + category filter
  var q = document.getElementById('q'), activeCat = '';
  function filter() {
    var term = q.value.toLowerCase().trim(), any = false;
    grid.querySelectorAll('.card').forEach(function (c) {
      var show = (!term || c.dataset.name.indexOf(term) !== -1) && (!activeCat || c.dataset.cat === activeCat);
      c.style.display = show ? '' : 'none';
      if (show) any = true;
    });
    document.getElementById('noMatch').style.display = any ? 'none' : 'block';
  }
  q.addEventListener('input', filter);
  var chips = document.getElementById('chips');
  if (chips) chips.addEventListener('click', function (e) {
    var b = e.target.closest('.chip'); if (!b) return;
    chips.querySelectorAll('.chip').forEach(function (x) { x.classList.toggle('active', x === b); });
    activeCat = b.dataset.cat; filter();
  });

  function paintLike(id) {
    var p = PRODUCTS[id];
    document.querySelectorAll('[data-like="' + id + '"]').forEach(function (b) {
      b.classList.toggle('liked', p.liked);
      b.querySelector('i').className = (p.liked ? 'fas' : 'far') + ' fa-heart';
      b.querySelector('span').textContent = p.likes;
    });
    if (current === id) {
      var s = document.getElementById('sLike');
      s.classList.toggle('liked', p.liked);
      s.querySelector('i').className = (p.liked ? 'fas' : 'far') + ' fa-heart';
      s.querySelector('span').textContent = p.likes;
    }
  }
  function toggleLike(id) {
    var p = PRODUCTS[id];
    p.liked = !p.liked; p.likes += p.liked ? 1 : -1; paintLike(id); // optimistic
    post({ product: id, action: 'like' }).then(function (r) {
      if (r && r.ok) { p.liked = r.liked; p.likes = r.likes; paintLike(id); }
    }).catch(function () {});
  }

  function renderComments(list) {
    var box = document.getElementById('sComments');
    box.innerHTML = list.length ? list.map(function (c) {
      return '<div class="comment"><b>' + esc(c.name || 'Customer') + '</b><span>' + esc(c.created_at.slice(0, 16)) + '</span><p>' + esc(c.body) + '</p></div>';
    }).join('') : '<div class="comment" style="color:#64748b;">No comments yet — be the first.</div>';
  }

  function open(id) {
    var p = PRODUCTS[id]; if (!p) return;
    current = id;
    document.getElementById('sImg').innerHTML = (p.image ? '<img src="' + esc(p.image) + '" alt="">' : '<div class="ph"><i class="fas fa-box"></i></div>')
      + '<button type="button" class="close" id="sClose" aria-label="Close"><i class="fas fa-xmark"></i></button>';
    document.getElementById('sCat').textContent = p.category || '';
    document.getElementById('sName').textContent = p.name + (p.size ? ' — ' + p.size : '');
    document.getElementById('sPrice').innerHTML = (p.on_offer && p.regular > p.price ? '<s>' + esc(money(p.regular)) + '</s>' : '') + esc(money(p.price));
    document.getElementById('sStock').textContent = p.stock + ' in stock';
    var d = document.getElementById('sDesc');
    d.textContent = p.description || 'No description yet.';
    d.classList.toggle('none', !p.description);
    document.getElementById('sLike').dataset.id = id;
    paintLike(id);
    document.getElementById('cMsg').textContent = '';
    renderComments([]);
    document.getElementById('sComments').innerHTML = '<div class="comment" style="color:#64748b;">Loading…</div>';
    fetch(API + '?shop=' + encodeURIComponent(SHOP) + '&product=' + id, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); }).then(function (r) { if (current === id) renderComments(r.comments || []); }).catch(function () {});
    var bg = document.getElementById('sheetBg');
    bg.classList.add('show'); bg.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    document.getElementById('sClose').focus();
  }
  function close() {
    var bg = document.getElementById('sheetBg');
    bg.classList.remove('show'); bg.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    current = null;
  }

  grid.addEventListener('click', function (e) {
    var like = e.target.closest('[data-like]');
    if (like) { e.stopPropagation(); toggleLike(parseInt(like.dataset.like, 10)); return; }
    var card = e.target.closest('.card'); if (card) open(parseInt(card.dataset.id, 10));
  });
  grid.addEventListener('keydown', function (e) {
    if ((e.key === 'Enter' || e.key === ' ') && e.target.classList.contains('card')) { e.preventDefault(); open(parseInt(e.target.dataset.id, 10)); }
  });
  document.getElementById('sheetBg').addEventListener('click', function (e) {
    if (e.target.id === 'sheetBg' || e.target.closest('#sClose')) close();
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && current) close(); });
  document.getElementById('sLike').addEventListener('click', function () { toggleLike(parseInt(this.dataset.id, 10)); });

  document.getElementById('cForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var f = this, btn = f.querySelector('button'), id = current;
    btn.disabled = true;
    post({ product: id, action: 'comment', name: f.elements['name'].value, body: f.elements['body'].value }).then(function (r) {
      btn.disabled = false;
      if (!r.ok) { document.getElementById('cMsg').textContent = r.error || 'Could not post that.'; return; }
      f.elements['body'].value = '';
      document.getElementById('cMsg').textContent = '';
      renderComments(r.comments || []);
      PRODUCTS[id].comments = (r.comments || []).length;
      var cc = grid.querySelector('.card[data-id="' + id + '"] .ccount'); if (cc) cc.textContent = PRODUCTS[id].comments;
    }).catch(function () { btn.disabled = false; document.getElementById('cMsg').textContent = 'Could not post that — check your connection.'; });
  });
})();
</script>
</body>
</html>
