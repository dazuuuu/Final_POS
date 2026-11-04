<?php
// public/docs/website-api.php — the Website API guide (public; share it with
// whoever builds the shop's website). Every example uses this server's address.
require_once __DIR__ . '/../../app/app.php';
$brand = Branding::platform();
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http';
$base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim((string) BASE_URL, '/') . '/api/v1/';
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$toc = [
    'start' => 'Before you start', 'quick' => 'Quick start (5 minutes)', 'auth' => 'Your API key', 'responses' => 'Answers & errors',
    'endpoints' => 'Endpoints', 'recipes' => 'Recipes', 'safety' => 'Keeping it safe', 'trouble' => 'Troubleshooting',
];
/** A code block with a Copy button. */
$code = function (string $text, string $lang = '') use ($h): string {
    return '<div class="code"><button type="button" class="cp">Copy</button><pre><code>' . $h($text) . '</code></pre></div>';
};
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Website API guide — <?php echo $h($brand['name']); ?></title>
<meta name="description" content="How to connect a shop's website to its <?php echo $h($brand['name']); ?> POS: products, offers, stock, comments, likes, views and orders.">
<link rel="icon" type="image/png" href="<?php echo $h(public_url('assets/icons/favicon-32.png')); ?>">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root{--ink:#09090b;--ink2:#3f3f46;--mute:#71717a;--line:#e4e4e7;--soft:#f4f4f5;--bg:#fff;--code:#fafafa;}
*{box-sizing:border-box}
body{margin:0;font:15px/1.65 Inter,system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;color:var(--ink);background:var(--bg);}
a{color:var(--ink);}
.top{border-bottom:1px solid var(--line);position:sticky;top:0;background:rgba(255,255,255,.96);z-index:5;}
.top .in{max-width:1180px;margin:0 auto;padding:12px 20px;display:flex;align-items:center;gap:12px;}
.top img{width:30px;height:30px;object-fit:contain;}
.top b{font-weight:600;}
.top span{color:var(--mute);font-size:.88rem;}
.wrap{max-width:1180px;margin:0 auto;padding:0 20px;display:grid;grid-template-columns:230px 1fr;gap:48px;}
nav.toc{position:sticky;top:70px;align-self:start;padding:28px 0;font-size:.88rem;}
nav.toc a{display:block;padding:5px 0;color:var(--ink2);text-decoration:none;}
nav.toc a:hover{color:var(--ink);}
main{padding:28px 0 80px;min-width:0;}
h1{font-size:2.1rem;line-height:1.15;letter-spacing:-.03em;margin:0 0 10px;font-weight:700;}
h2{font-size:1.35rem;letter-spacing:-.02em;margin:52px 0 12px;padding-top:8px;font-weight:600;}
h3{font-size:1.02rem;margin:28px 0 8px;font-weight:600;}
p,li{color:var(--ink2);}
.lead{font-size:1.05rem;color:var(--ink2);max-width:720px;}
.box{border:1px solid var(--line);border-radius:12px;padding:16px 18px;margin:16px 0;}
.box.note{background:var(--soft);border-color:var(--soft);}
.steps{counter-reset:s;list-style:none;padding:0;}
.steps li{counter-increment:s;position:relative;padding-left:40px;margin:0 0 14px;}
.steps li::before{content:counter(s);position:absolute;left:0;top:0;width:26px;height:26px;border-radius:50%;background:var(--ink);color:#fff;font-size:.8rem;font-weight:600;display:grid;place-items:center;}
code,.mono{font-family:'JetBrains Mono',ui-monospace,Menlo,monospace;font-size:.84em;font-variant-ligatures:none;}
p code,li code,td code{background:var(--soft);border-radius:5px;padding:1px 5px;}
.code{position:relative;margin:12px 0 18px;}
.code pre{background:var(--code);border:1px solid var(--line);border-radius:10px;padding:14px 16px;overflow:auto;margin:0;font-size:.82rem;line-height:1.6;}
.code .cp{position:absolute;top:8px;right:8px;border:1px solid var(--line);background:#fff;border-radius:6px;font:500 .74rem Inter,sans-serif;padding:3px 9px;cursor:pointer;}
table{width:100%;border-collapse:collapse;font-size:.88rem;margin:10px 0 18px;}
th{text-align:left;font-weight:500;color:var(--mute);font-size:.78rem;padding:8px 10px;border-bottom:1px solid var(--line);}
td{padding:9px 10px;border-bottom:1px solid var(--soft);vertical-align:top;color:var(--ink2);}
.ep{border:1px solid var(--line);border-radius:12px;padding:16px 18px;margin:14px 0;}
.ep h3{margin:0 0 6px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.verb{font:600 .7rem 'JetBrains Mono',monospace;border:1px solid var(--ink);border-radius:5px;padding:2px 7px;}
.verb.post{background:var(--ink);color:#fff;}
.scope{font-size:.72rem;border:1px solid var(--line);border-radius:6px;padding:1px 7px;color:var(--ink2);font-weight:500;}
@media (max-width:900px){.wrap{grid-template-columns:1fr;gap:0}nav.toc{position:static;padding:16px 0 0;border-bottom:1px solid var(--line);columns:2}h1{font-size:1.7rem}}
</style>
</head>
<body>
<header class="top"><div class="in"><img src="<?php echo $h($brand['logo']); ?>" alt=""><b><?php echo $h($brand['name']); ?></b><span>· Website API guide</span></div></header>
<div class="wrap">
<nav class="toc"><?php foreach ($toc as $id => $label): ?><a href="#<?php echo $id; ?>"><?php echo $h($label); ?></a><?php endforeach; ?></nav>
<main>

<h1>Connect your website to your POS</h1>
<p class="lead">Your website can show your shop exactly as it is in the POS — products with their pictures, names, categories, prices and offers, and whether they are in stock — and let customers comment, like and order. When you sell or restock at the counter, the website changes too. This guide is for the shop owner and for whoever builds the website.</p>
<div class="box note"><b>Base address for every call</b><br><code><?php echo $h($base); ?></code></div>

<h2 id="start">Before you start</h2>
<ol class="steps">
  <li><b>Ask for the Website API.</b> It's a custom add-on. In your POS open <b>Business → Website API</b> and send a request — we call you with a quotation and switch it on for your account.</li>
  <li><b>Create a key.</b> Once it's on, <b>Business → Website API → Keys → Create a key</b>. Give it a name (e.g. "Main website"), pick the shop whose products it shows, tick what it may do, and — when your site is live — list your website's address under <i>Allowed websites</i>. Press <b>Copy</b> to copy the key.</li>
  <li><b>Choose what customers may do.</b> Under <b>Settings</b> switch comments, likes and website orders on or off, and decide if comments need your approval first.</li>
  <li><b>Try it.</b> The <b>Test</b> tab calls every endpoint with your key, so you see exactly what your website will get.</li>
</ol>
<table>
  <tr><th>Permission</th><th>Lets the key…</th></tr>
  <tr><td><code>products</code></td><td>Read products, categories and shop details</td></tr>
  <tr><td><code>comments</code></td><td>Read approved comments and post new ones</td></tr>
  <tr><td><code>likes</code></td><td>Like / unlike products</td></tr>
  <tr><td><code>orders</code></td><td>Place orders and look up their status</td></tr>
  <tr><td><code>insights</code></td><td>Read views, likes, comments and order totals — use this key on a server only</td></tr>
</table>

<h2 id="quick">Quick start (5 minutes)</h2>
<p>Paste this into any web page, put your key in place of <code>YOUR_KEY</code> and open the page. Your in-stock products appear with their pictures, prices and offers.</p>
<?php echo $code('<div id="shop" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:16px"></div>
<script>
const API = "' . $base . '";
const KEY = "YOUR_KEY";

fetch(API + "products.php?in_stock=1&per_page=24", { headers: { "X-Api-Key": KEY } })
  .then(r => r.json())
  .then(res => {
    if (!res.ok) throw new Error(res.error.message);
    document.getElementById("shop").innerHTML = res.data.map(p => `
      <div style="border:1px solid #eee;border-radius:12px;padding:12px">
        ${p.image ? `<img src="${p.image}" alt="" style="width:100%;aspect-ratio:1;object-fit:cover;border-radius:8px">` : ""}
        <h3 style="font-size:15px;margin:10px 0 4px">${p.name}</h3>
        <div>${p.on_offer ? `<s>KES ${p.regular_price}</s> ` : ""}<b>KES ${p.price}</b></div>
        <small>${p.in_stock ? "In stock" : "Out of stock"}</small>
      </div>`).join("");
  })
  .catch(e => document.getElementById("shop").textContent = e.message);
</script>'); ?>

<h2 id="auth">Your API key</h2>
<p>Send the key with every call in the <code>X-Api-Key</code> header (or <code>Authorization: Bearer &lt;key&gt;</code>). Keys look like <code>dz_live_…</code>. A key only ever sees the one shop it was made for.</p>
<?php echo $code('curl -H "X-Api-Key: YOUR_KEY" "' . $base . 'products.php?per_page=5"'); ?>
<p>Lost a key, or think someone copied it? <b>Revoke</b> it on the Keys tab — it stops working at once — and create a new one.</p>

<h2 id="responses">Answers &amp; errors</h2>
<p>Every answer is JSON. Success: <code>{"ok": true, "data": …}</code> (lists also have <code>"meta"</code> with page numbers). Problems: <code>{"ok": false, "error": {"code": "…", "message": "…"}}</code> — the message is written so you can show it to a customer.</p>
<table>
  <tr><th>Status</th><th>Code</th><th>What it means</th></tr>
  <tr><td>401</td><td><code>unauthorized</code></td><td>No key, a wrong or revoked key, or the API is switched off for the account</td></tr>
  <tr><td>403</td><td><code>scope_missing</code></td><td>The key doesn't have that permission</td></tr>
  <tr><td>403</td><td><code>origin_not_allowed</code></td><td>Called from a website that isn't in the key's allowed websites</td></tr>
  <tr><td>403</td><td><code>comments_off</code> · <code>likes_off</code> · <code>orders_off</code></td><td>The shop switched that feature off</td></tr>
  <tr><td>404</td><td><code>not_found</code></td><td>No such product (or order) in this shop</td></tr>
  <tr><td>409</td><td><code>out_of_stock</code></td><td>Not enough stock for an order line</td></tr>
  <tr><td>422</td><td><code>invalid_…</code></td><td>A field is missing or wrong (name, phone, address, quantity…)</td></tr>
  <tr><td>429</td><td><code>rate_limited</code></td><td>Too many calls — <?php echo ShopApi::RATE_PER_MINUTE; ?> a minute per key; wait and retry</td></tr>
</table>

<h2 id="endpoints">Endpoints</h2>

<div class="ep"><h3><span class="verb">GET</span><code>shop.php</code><span class="scope">products</span></h3>
<p>The shop's name, logo, currency, phone and address, and which features are on (<code>comments</code>, <code>likes</code>, <code>orders</code>) — use it to show or hide those buttons.</p></div>

<div class="ep"><h3><span class="verb">GET</span><code>categories.php</code><span class="scope">products</span></h3>
<p>Categories with their subcategories and how many products each has (and how many are in stock). Empty categories are left out.</p></div>

<div class="ep"><h3><span class="verb">GET</span><code>products.php</code><span class="scope">products</span></h3>
<p>The shop's live products. Every query string option is optional:</p>
<table>
  <tr><th>Option</th><th>Example</th><th>Does</th></tr>
  <tr><td><code>q</code></td><td><code>q=sugar</code></td><td>Search names, barcodes and categories</td></tr>
  <tr><td><code>category</code> · <code>subcategory</code></td><td><code>category=12</code></td><td>Only that (sub)category</td></tr>
  <tr><td><code>in_stock</code></td><td><code>in_stock=1</code></td><td>Only what's on the shelf</td></tr>
  <tr><td><code>on_offer</code></td><td><code>on_offer=1</code></td><td>Only products on offer right now</td></tr>
  <tr><td><code>ids</code></td><td><code>ids=4,9,15</code></td><td>Exactly these products (e.g. a basket)</td></tr>
  <tr><td><code>sort</code></td><td><code>sort=popular</code></td><td><code>name</code> (default), <code>price</code>, <code>price_desc</code>, <code>newest</code>, <code>popular</code> (most viewed)</td></tr>
  <tr><td><code>page</code> · <code>per_page</code></td><td><code>page=2&amp;per_page=24</code></td><td>Pages (up to 100 per page)</td></tr>
  <tr><td><code>stats</code></td><td><code>stats=1</code></td><td>Adds views, likes and comment counts to each product</td></tr>
</table>
<p>Each product looks like this (prices already include any live offer; cost prices are never sent):</p>
<?php echo $code('{
  "id": 406,
  "name": "Maize flour 2kg",
  "description": null,
  "category": { "id": 12, "name": "Groceries" },
  "subcategory": null,
  "image": "' . $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/assets/uploads/products/maize.jpg",
  "barcode": "6161100123456",
  "unit": "piece",
  "price": 189.0,
  "regular_price": 199.0,
  "on_offer": true,
  "offer_ends_at": "2026-10-31 23:59:00",
  "in_stock": true,
  "stock": 42.0,
  "low_stock": false,
  "updated_at": "2026-10-20 14:31:53",
  "stats": { "views": 120, "likes": 8, "comments": 3 }
}'); ?></div>

<div class="ep"><h3><span class="verb">GET</span><code>product.php?id=ID</code><span class="scope">products</span></h3>
<p>One product, its stats and its latest approved comments. Each call counts as a view — add <code>view=0</code> when your own server reads it, so the counts stay honest.</p></div>

<div class="ep"><h3><span class="verb">GET</span><code>comments.php?product_id=ID</code><span class="scope">comments</span></h3>
<p>Approved comments, newest first, 20 per page (<code>page=2</code> for more).</p>
<h3><span class="verb post">POST</span><code>comments.php</code><span class="scope">comments</span></h3>
<?php echo $code('{ "product_id": 406, "name": "Jane", "comment": "Fresh and well packed!", "rating": 5 }'); ?>
<p><code>rating</code> (1–5) is optional. Unless the shop auto-approves, the answer's <code>status</code> is <code>"pending"</code> — show "Thanks, it will appear once approved".</p></div>

<div class="ep"><h3><span class="verb">GET</span><code>like.php?product_id=ID&amp;visitor=V</code> <span class="verb post">POST</span><code>like.php</code><span class="scope">likes</span></h3>
<p><code>visitor</code> is any random id your website keeps for each visitor (see the recipe below) — one like per visitor. POST <code>{"product_id": 406, "visitor": "…"}</code> toggles the like; add <code>"like": true</code> or <code>false</code> to set it. The answer has <code>liked</code> and the new <code>likes</code> count.</p></div>

<div class="ep"><h3><span class="verb post">POST</span><code>orders.php</code><span class="scope">orders</span></h3>
<?php echo $code('{
  "name": "Jane Wanjiru",
  "phone": "0712 345 678",
  "address": "Ruiru, Kamakis road, house 14",
  "note": "Call when you reach the gate",
  "items": [ { "product_id": 406, "quantity": 2 }, { "product_id": 753, "quantity": 1 } ]
}'); ?>
<p>Prices always come from the POS (today's price, offers applied) — never from the website. The answer gives the order <code>number</code> (e.g. <code>WEB-000123</code>) and <code>total</code>. The order arrives in the POS under <b>Website API → Orders</b> as <i>New</i>; the shop calls the customer, and stock comes off the shelf when the shop marks it <i>Confirmed</i>.</p>
<h3><span class="verb">GET</span><code>orders.php?number=WEB-000123&amp;phone=0712345678</code><span class="scope">orders</span></h3>
<p>The order's status (<code>new</code>, <code>confirmed</code>, <code>delivered</code>, <code>cancelled</code>) and its lines — for a "track my order" page. The phone must match the order.</p></div>

<div class="ep"><h3><span class="verb">GET</span><code>insights.php?days=30</code><span class="scope">insights</span></h3>
<p>Views per day, the most viewed products with their likes and comments, website orders by status and comments waiting for approval. <b>Call it from your server</b> — never put an insights key in a web page.</p></div>

<h2 id="recipes">Recipes</h2>
<h3>A product page with like and comments</h3>
<?php echo $code('<div id="product"></div>
<script>
const API = "' . $base . '", KEY = "YOUR_KEY";
const id = new URLSearchParams(location.search).get("id");
// One stable id per visitor, kept in this browser
let visitor = localStorage.getItem("visitor");
if (!visitor) { visitor = crypto.randomUUID(); localStorage.setItem("visitor", visitor); }
const call = (path, body) => fetch(API + path, {
  method: body ? "POST" : "GET",
  headers: Object.assign({ "X-Api-Key": KEY }, body ? { "Content-Type": "application/json" } : {}),
  body: body ? JSON.stringify(body) : undefined
}).then(r => r.json());

call("product.php?id=" + id).then(({ data: p }) => {
  document.getElementById("product").innerHTML = `
    <h1>${p.name}</h1>
    <p><b>KES ${p.price}</b> · ${p.in_stock ? "In stock" : "Out of stock"}</p>
    <button id="like">♥ ${p.stats.likes}</button>
    <h3>Comments</h3>
    ${p.comments.map(c => `<p><b>${c.name}</b>: ${c.comment}</p>`).join("") || "<p>No comments yet.</p>"}
    <form id="cf"><input name="name" placeholder="Your name" required>
      <textarea name="comment" placeholder="Your comment" required></textarea><button>Send</button></form>`;
  document.getElementById("like").onclick = () =>
    call("like.php", { product_id: p.id, visitor }).then(r => like.textContent = "♥ " + r.data.likes);
  document.getElementById("cf").onsubmit = e => {
    e.preventDefault();
    const f = new FormData(e.target);
    call("comments.php", { product_id: p.id, name: f.get("name"), comment: f.get("comment") })
      .then(r => alert(r.ok ? r.data.message : r.error.message));
  };
});
</script>'); ?>

<h3>An order form</h3>
<?php echo $code('<form id="order">
  <input name="name" placeholder="Full name" required>
  <input name="phone" placeholder="Phone" required>
  <input name="address" placeholder="Delivery address" required>
  <textarea name="note" placeholder="Note (optional)"></textarea>
  <button>Place order</button>
</form>
<script>
// basket = [{ product_id: 406, quantity: 2 }, …] — from your cart
document.getElementById("order").onsubmit = e => {
  e.preventDefault();
  const f = new FormData(e.target);
  fetch("' . $base . 'orders.php", {
    method: "POST",
    headers: { "X-Api-Key": "YOUR_KEY", "Content-Type": "application/json" },
    body: JSON.stringify({ name: f.get("name"), phone: f.get("phone"), address: f.get("address"),
                           note: f.get("note"), items: basket })
  }).then(r => r.json()).then(r => {
    alert(r.ok ? "Thank you! Order " + r.data.number + " — total KES " + r.data.total + ". We will call you to confirm."
               : r.error.message);
  });
};
</script>'); ?>

<h3>A daily report on your server (PHP)</h3>
<?php echo $code('<?php
// Keep this key on the server only (it has the "insights" permission).
$ch = curl_init("' . $base . 'insights.php?days=7");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["X-Api-Key: YOUR_SERVER_KEY"]]);
$res = json_decode(curl_exec($ch), true);
foreach ($res["data"]["products"] as $p) {
    echo $p["name"] . ": " . $p["views"] . " views, " . $p["likes"] . " likes\\n";
}'); ?>

<h2 id="safety">Keeping it safe</h2>
<ul>
  <li>A key used in a web page can be seen by anyone who opens the page's source. That's normal — so give browser keys only <code>products</code>, <code>comments</code>, <code>likes</code> and <code>orders</code>, and <b>list your website under Allowed websites</b> so other sites are refused.</li>
  <li>Use a separate key with <code>insights</code> on your server only.</li>
  <li>One key per website or app — then you can revoke one without touching the others.</li>
  <li>The API never sends cost prices, profit, sales totals, staff or customer lists.</li>
  <li>Customers' orders, comments and likes are limited per device to stop spam; comments wait for your approval unless you turn on auto-approve.</li>
</ul>

<h2 id="trouble">Troubleshooting</h2>
<table>
  <tr><th>You see</th><th>Try</th></tr>
  <tr><td><code>401 unauthorized</code></td><td>Check the key was copied whole (starts with <code>dz_live_</code>) and is sent in <code>X-Api-Key</code>. Revoked keys and switched-off accounts also give 401.</td></tr>
  <tr><td><code>origin_not_allowed</code></td><td>Add your site's exact address (with <code>https://</code>, and the <code>www.</code> version if you use it) to the key's allowed websites.</td></tr>
  <tr><td>"blocked by CORS" in the browser console</td><td>Usually the same as above, or calling with a key from a page on <code>file://</code> — test from a real web address or from the POS Test tab.</td></tr>
  <tr><td><code>image</code> is <code>null</code></td><td>The product has no picture in the POS yet — add one in Products and it appears straight away.</td></tr>
  <tr><td>A product is missing</td><td>Only active products are sent. Drafts and archived products are left out; out-of-stock products are included unless you ask for <code>in_stock=1</code>.</td></tr>
  <tr><td>Comments don't appear</td><td>They wait for approval — the shop approves them under Website API → Comments.</td></tr>
  <tr><td><code>orders_off</code></td><td>The shop hasn't switched on website orders (Website API → Settings).</td></tr>
</table>
<p style="margin-top:40px;color:var(--mute);font-size:.85rem">Questions? Open a support ticket from your POS (Help → Support tickets).</p>
</main>
</div>
<script>
document.querySelectorAll('.code .cp').forEach(function (b) {
  b.addEventListener('click', function () {
    var t = b.nextElementSibling.textContent, done = function () { b.textContent = 'Copied'; setTimeout(function () { b.textContent = 'Copy'; }, 1500); };
    if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(t).then(done);
    else { var a = document.createElement('textarea'); a.value = t; document.body.appendChild(a); a.select(); document.execCommand('copy'); a.remove(); done(); }
  });
});
</script>
</body>
</html>
