<?php
// public/components/db_down.php — shown when the database can't be reached for
// a moment (server busy, too many connections, restarting) and, through
// app.php's error handler, when a page fails unexpectedly. Never shows
// technical details; it retries on its own and returns to the same page.
if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
}
$__local = in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1'], true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#0f766e">
<title>Reconnecting…</title>
<style>
  body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f7f7fb;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#1f2330;padding:16px}
  .card{background:#fff;border:1px solid #e6e8ec;border-radius:18px;padding:30px 26px;max-width:400px;width:100%;text-align:center;box-shadow:0 10px 30px rgba(15,23,42,.06)}
  .spin{width:44px;height:44px;border-radius:50%;border:4px solid #d1fae5;border-top-color:#16a34a;margin:0 auto 16px;animation:s 1s linear infinite}
  @keyframes s{to{transform:rotate(360deg)}}
  h1{font-size:1.1rem;margin:0 0 6px}
  p{color:#64748b;font-size:.9rem;margin:0 0 14px;line-height:1.5}
  button{border:0;padding:10px 20px;border-radius:10px;background:#16a34a;color:#fff;font-weight:700;font-size:.92rem;cursor:pointer}
  code{background:#f1f5f9;padding:2px 6px;border-radius:6px;font-size:.85rem}
</style>
</head>
<body>
  <div class="card">
    <div class="spin" aria-hidden="true"></div>
    <h1>Reconnecting…</h1>
    <p>The system is busy for a moment. This page will reload by itself in <b id="n">5</b>&nbsp;s.</p>
    <?php if ($__local): ?><p>On this Mac, check MySQL is running: <code>brew services start mysql</code></p><?php endif; ?>
    <button type="button" onclick="location.reload()">Try again now</button>
    <?php if (!empty($__errorDetail)): // only shown to signed-in Support ?>
      <div data-err style="margin-top:16px;text-align:left;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:10px;padding:10px 12px;font:12px/1.5 ui-monospace,Menlo,monospace;word-break:break-word">
        <b style="font-family:system-ui">Support only — error:</b><br><?php echo htmlspecialchars($__errorDetail); ?>
      </div>
    <?php endif; ?>
  </div>
<script>
  // GET pages reload as-is; never re-send a form.
  var n = 5, el = document.getElementById('n');
  if (!document.querySelector('[data-err]')) setInterval(function () { n--; if (n <= 0) { location.replace(location.href); } else { el.textContent = n; } }, 1000);
</script>
</body>
</html>
