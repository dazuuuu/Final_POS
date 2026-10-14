<?php
// public/components/db_down.php — shown by app/app.php when the database is
// configured but the MySQL server can't be reached. Retries on its own.
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="refresh" content="10">
<title>Database offline</title>
<style>
  body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f7f7fb;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#1f2330;padding:16px}
  .card{background:#fff;border:1px solid #e6e8ec;border-radius:16px;padding:28px;max-width:440px;text-align:center;box-shadow:0 10px 30px rgba(15,23,42,.06)}
  .ic{width:56px;height:56px;border-radius:14px;background:#fef3c7;color:#b45309;display:grid;place-items:center;margin:0 auto 14px;font-size:26px}
  h1{font-size:1.15rem;margin:0 0 6px}
  p{color:#64748b;font-size:.92rem;margin:0 0 10px;line-height:1.5}
  code{background:#f1f5f9;padding:2px 6px;border-radius:6px;font-size:.85rem}
  a{display:inline-block;margin-top:8px;padding:9px 18px;border-radius:10px;background:#16a34a;color:#fff;text-decoration:none;font-weight:600}
</style>
</head>
<body>
  <div class="card">
    <div class="ic">⚠</div>
    <h1>The database server isn't running</h1>
    <p>The POS is set up, but it can't reach MySQL right now. Start the database server and this page reloads by itself.</p>
    <?php if (in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1'], true)): ?>
    <p>On this Mac: <code>brew services start mysql</code></p>
    <?php else: ?>
    <p>If it stays like this, contact support.</p>
    <?php endif; ?>
    <a href="">Try again</a>
  </div>
</body>
</html>
