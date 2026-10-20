<?php
// public/components/landing/page.php — the public landing page at the plain
// domain (included by public/index.php for signed-out visitors). Shop
// addresses (domain/<slug>/), the owner login (/admin/) and the shared staff
// terminal (/terminal/) are separate pages and unchanged.
//
// Sections (one component each, in this folder):
//   header · hero · features · showcase · packages · demo · start (3 ways to
//   get a package + quote form) · footer
// POST actions here: demo (start a live demo) and quote (request a quotation).

require_once ROOT_PATH . '/app/helpers/uploads.php'; // csrf_token() / csrf_field() / csrf_verify()

$brand = Branding::platform();
$catalog = Features::catalog();
$demoPackages = Demo::packages();
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$lpUrl = public_url('');
$flash = $_SESSION['landing_flash'] ?? null;
unset($_SESSION['landing_flash']);
$old = $_SESSION['landing_old'] ?? [];
unset($_SESSION['landing_old']);

// Package the visitor is looking at: from a button (?pkg=), their last demo, or the Shop POS.
$demoDone = $_SESSION['demo_done'] ?? null;
$selected = (string) ($_GET['pkg'] ?? $old['package'] ?? '');
if (!isset($catalog[$selected])) {
    $selected = Features::SHOP_POS;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['lp'] ?? '';
    $back = $lpUrl;
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['landing_flash'] = ['err', 'Your session expired — please try again.', $act === 'quote' ? 'start' : 'demo'];
    } elseif ($act === 'demo') {
        $err = Demo::start((string) ($_POST['email'] ?? ''), (string) ($_POST['package'] ?? ''));
        if ($err === null) {
            unset($_SESSION['demo_done']);
            header('Location: ' . public_url('super/dashboard/'));
            exit;
        }
        $_SESSION['landing_flash'] = ['err', $err, 'demo'];
        $_SESSION['landing_old'] = ['email' => $_POST['email'] ?? '', 'package' => $_POST['package'] ?? ''];
        $back .= '#demo';
    } elseif ($act === 'quote') {
        $err = Quote::submit($_POST);
        if ($err === null) {
            $_SESSION['landing_flash'] = ['ok', 'Thank you! Your request was received — we have emailed you a confirmation and will call you shortly.', 'start'];
        } else {
            $_SESSION['landing_flash'] = ['err', $err, 'start'];
            $_SESSION['landing_old'] = array_intersect_key($_POST, array_flip(['name', 'email', 'phone', 'package', 'shop_name', 'business']));
        }
        $back .= '#start';
    }
    header('Location: ' . $back);
    exit;
}
Demo::cleanup(); // tidy demo shops left open by closed tabs

/** Feature bullets from a package description ("Prefix: a, b and c. (Note.)"). */
$bullets = function (string $desc): array {
    $out = [];
    $body = trim(preg_replace('/\([^)]*\)/', '', $desc));
    if (stripos($body, 'Everything in the Shop POS') === 0) { $out[] = 'Everything in Shop POS'; }
    $list = strpos($body, ':') !== false ? substr($body, strrpos($body, ':') + 1) : $body;
    $list = preg_split('/\.\s/', $list)[0]; // first sentence: the feature list
    foreach (preg_split('/,|\band\b/', rtrim($list, '. ')) as $f) {
        if (($f = trim($f)) !== '') { $out[] = ucfirst($f); }
    }
    return array_slice($out, 0, 7);
};
$tierOf = fn(string $desc) => preg_match('/\(([^)]*)\)/', $desc, $m) && strlen($m[1]) <= 24 ? rtrim($m[1], '.') : 'Pro';
$demoEmail = (string) ($old['email'] ?? $_GET['email'] ?? ($demoDone['email'] ?? ''));
$C = __DIR__;
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?php echo $h($brand['name']); ?> — Point of sale for Kenyan shops, salons and electronics stores</title>
<meta name="description" content="<?php echo $h($brand['name']); ?>: sell, track stock, give credit, pay commissions and print receipts. Try a live demo with your email.">
<meta name="theme-color" content="#0c3d26">
<link rel="icon" type="image/png" href="<?php echo $h(public_url('assets/icons/favicon-32.png')); ?>">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="<?php echo $h(public_url('assets/css/landing.css')); ?>">
</head>
<body class="lp">
<?php
include $C . '/header.php';
include $C . '/hero.php';
include $C . '/features.php';
include $C . '/showcase.php';
include $C . '/packages.php';
include $C . '/demo.php';
include $C . '/start.php';
include $C . '/footer.php';
?>
<script>
(function () {
  // Sign-in menu + mobile nav
  var si = document.querySelector('.signin');
  if (si) {
    si.querySelector('button').addEventListener('click', function (e) { e.stopPropagation(); si.classList.toggle('open'); });
    document.addEventListener('click', function () { si.classList.remove('open'); });
  }
  var mb = document.querySelector('.menu-btn');
  if (mb) mb.addEventListener('click', function () { document.querySelector('.lp-head').classList.toggle('open'); });

  // Package buttons fill the demo / quote forms without reloading.
  var info = <?php echo json_encode(array_map(fn($r) => ['name' => $r[0], 'price' => 'KES ' . number_format($r[1]), 'desc' => trim(preg_replace('/\s*\([^)]*\)/', '', $r[2]))], $catalog), JSON_UNESCAPED_UNICODE); ?>;
  function pickQuote(k) {
    var sel = document.getElementById('qPackage'); if (!sel || !info[k]) return;
    sel.value = k;
    document.getElementById('qName').textContent = info[k].name;
    document.getElementById('qPrice').textContent = info[k].price;
    document.getElementById('qDesc').textContent = info[k].desc;
    document.querySelectorAll('[data-get-label]').forEach(function (el) { el.textContent = info[k].name; });
  }
  function pickDemo(k) {
    var r = document.querySelector('#demo input[name="package"][value="' + k + '"]'); if (r) r.checked = true;
  }
  document.querySelectorAll('[data-pick]').forEach(function (a) {
    a.addEventListener('click', function () { pickQuote(a.dataset.pick); pickDemo(a.dataset.pick); });
  });
  var q = document.getElementById('qPackage');
  if (q) q.addEventListener('change', function () { pickQuote(q.value); pickDemo(q.value); });
  document.querySelectorAll('#demo input[name="package"]').forEach(function (r) {
    r.addEventListener('change', function () { pickQuote(r.value); });
  });

  // Hero email → the demo form.
  var hf = document.getElementById('heroForm');
  if (hf) hf.addEventListener('submit', function (e) {
    var v = hf.querySelector('input').value, d = document.getElementById('demoEmail');
    if (d) { e.preventDefault(); d.value = v; location.hash = 'demo'; d.focus(); }
  });

  // "Request a quotation" option opens the form.
  document.querySelectorAll('[data-open-quote]').forEach(function (a) {
    a.addEventListener('click', function () {
      document.querySelectorAll('.opt').forEach(function (o) { o.classList.remove('on'); });
      a.classList.add('on');
      setTimeout(function () { var n = document.querySelector('#quoteForm input[name="name"]'); if (n) n.focus(); }, 350);
    });
  });
})();
</script>
</body>
</html>
