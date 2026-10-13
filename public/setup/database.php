<?php
// public/setup/database.php
// Step 1: enter the database details. They're tested first, then written to
// app/config/database.php (the one config file), so on hosting where you
// can't edit files outside public/ you never have to.

require_once __DIR__ . '/_setup.php';

$current = Database::config();
$errors = [];
$success = '';
$writeFailed = '';
$old = [
    'host'     => $current['host'] ?? 'localhost',
    'dbname'   => $current['dbname'] ?? '',
    'username' => $current['username'] ?? '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new = [
        'host'     => trim($_POST['host'] ?? '') ?: 'localhost',
        'dbname'   => trim($_POST['dbname'] ?? ''),
        'username' => trim($_POST['username'] ?? ''),
        'password' => (string) ($_POST['password'] ?? ''),
    ];
    $old = $new;

    if ($new['dbname'] === '') { $errors[] = 'Database name is required.'; }
    if ($new['username'] === '') { $errors[] = 'Database username is required.'; }
    if ($new['dbname'] !== '' && !preg_match('/^[A-Za-z0-9_$-]+$/', $new['dbname'])) { $errors[] = 'Database name can only contain letters, numbers, _ and -.'; }

    if (!$errors) {
        $err = Database::test($new);
        if ($err !== null) {
            $errors[] = 'Could not connect with those details: ' . $err;
        } elseif (Database::saveConfig($new)) {
            $success = 'Connected and saved. The app now uses this database.';
            $current = Database::config();
        } else {
            $writeFailed = "<?php\nreturn " . var_export($new + ['charset' => 'utf8mb4'], true) . ";\n";
        }
    }
}

$status = Database::test(Database::config());

setup_render_header('Database', 'database');
?>
<?php if ($success): ?>
  <div class="alert ok"><?php echo setup_h($success); ?></div>
  <div class="actions" style="margin-bottom:16px;"><a class="btn" href="<?php echo public_url('setup/'); ?>">Next: run migrations</a></div>
<?php endif; ?>
<?php if ($errors): ?>
  <div class="alert err"><?php foreach ($errors as $e): ?><div><?php echo setup_h($e); ?></div><?php endforeach; ?></div>
<?php endif; ?>
<?php if ($writeFailed): ?>
  <div class="alert warn">
    <strong>The details work, but the server wouldn't let us save the file.</strong><br>
    Give <code>app/config/database.php</code> write permission (e.g. 664) and submit again — or paste this into that file yourself:
    <pre style="white-space:pre-wrap;background:#fff;padding:10px;border-radius:6px;margin:8px 0 0;"><?php echo setup_h($writeFailed); ?></pre>
  </div>
<?php endif; ?>

<?php if (!$success): ?>
<div class="alert <?php echo $status === null ? 'ok' : 'info'; ?>">
  <?php if ($status === null): ?>
    The current settings connect fine (database <code><?php echo setup_h($current['dbname'] ?? ''); ?></code> on <code><?php echo setup_h($current['host'] ?? ''); ?></code>).
    You only need this step to switch databases. <a href="<?php echo public_url('setup/'); ?>">Go to migrations</a>
  <?php else: ?>
    The app can't reach its database yet. Create an empty MySQL database and user in your hosting panel (cPanel → MySQL Databases), give the user ALL PRIVILEGES on it, then enter the details below.
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
  <h2>Database connection</h2>
  <form method="post" autocomplete="off">
    <div class="grid">
      <div>
        <label for="host">Host</label>
        <input id="host" name="host" value="<?php echo setup_h($old['host']); ?>" placeholder="localhost">
      </div>
      <div>
        <label for="dbname">Database name</label>
        <input id="dbname" name="dbname" value="<?php echo setup_h($old['dbname']); ?>" placeholder="e.g. cpaneluser_pos" required>
      </div>
      <div>
        <label for="username">Username</label>
        <input id="username" name="username" value="<?php echo setup_h($old['username']); ?>" placeholder="e.g. cpaneluser_posuser" required>
      </div>
      <div>
        <label for="password">Password</label>
        <input id="password" name="password" type="password" value="">
      </div>
    </div>
    <p class="lead" style="margin:0 0 14px;">On most shared hosting the host is <code>localhost</code> and the database and user names start with your cPanel username.</p>
    <button class="btn" type="submit">Test &amp; save</button>
  </form>
</div>
<div class="card">
  <h2>Start over</h2>
  <p class="lead" style="margin:0 0 14px;">Clear this database and the support account, then run setup again from step 1.</p>
  <a class="btn secondary" style="color:#b91c1c;" href="<?php echo public_url('setup/reset.php'); ?>">Reset install…</a>
</div>
<?php setup_render_footer(); ?>
