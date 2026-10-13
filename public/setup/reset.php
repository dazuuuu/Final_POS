<?php
// public/setup/reset.php
// Wipe the CURRENT database (drops every table and view) and optionally the
// support login, so the setup steps can be run again from scratch. The
// database connection settings in app/config/database.php are kept.

require_once __DIR__ . '/_setup.php';

$cfg = Database::config();
$dbname = (string) ($cfg['dbname'] ?? '');
$connectionError = Database::test($cfg);
$errors = [];
$done = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($dbname === '' || trim($_POST['confirm'] ?? '') !== $dbname) {
        $errors[] = 'Type the database name exactly to confirm.';
    }
    if ($connectionError !== null && !empty($_POST['wipe_db'])) {
        $errors[] = 'Cannot connect to the database, so it cannot be cleared. Untick "Clear the database" or fix the connection first.';
    }

    if (!$errors) {
        $dropped = 0;
        try {
            if (!empty($_POST['wipe_db'])) {
                $pdo = Database::pdo();
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
                $rows = $pdo->query(
                    'SELECT table_name, table_type FROM information_schema.tables WHERE table_schema = DATABASE()'
                )->fetchAll(PDO::FETCH_NUM);
                foreach ($rows as [$name, $type]) {
                    $kind = $type === 'VIEW' ? 'VIEW' : 'TABLE';
                    $pdo->exec("DROP $kind IF EXISTS `" . str_replace('`', '``', $name) . '`');
                    $dropped++;
                }
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            }
            $supportRemoved = false;
            if (!empty($_POST['wipe_support']) && is_file(SupportAccount::configPath())) {
                $supportRemoved = @unlink(SupportAccount::configPath());
                if (!$supportRemoved) {
                    $errors[] = 'Could not delete app/config/support.php — remove it by hand.';
                }
            }
            if ($supportRemoved) {
                unset($_SESSION['support_auth']);
            }
            // Shop sessions point at users that no longer exist.
            if (!empty($_POST['wipe_db'])) {
                foreach (array_keys($_SESSION) as $k) {
                    if ($k !== 'support_auth') {
                        unset($_SESSION[$k]);
                    }
                }
            }
            $done = ['dropped' => $dropped, 'support' => $supportRemoved];
                    } catch (\Throwable $e) {
            $errors[] = 'Reset failed: ' . $e->getMessage();
        }
    }
}

setup_render_header('Reset install', 'database');
?>
<?php if ($errors): ?>
  <div class="alert err"><ul><?php foreach ($errors as $e): ?><li><?php echo setup_h($e); ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<?php if ($done): ?>
  <div class="alert ok">
    Reset complete: dropped <?php echo (int) $done['dropped']; ?> table(s)<?php echo $done['support'] ? ' and removed the support account' : ''; ?>.
  </div>
  <div class="actions" style="margin-bottom:16px;">
    <a class="btn" href="<?php echo public_url('setup/database.php'); ?>">Start setup again</a>
  </div>
<?php else: ?>
  <div class="alert err">
    <strong>This permanently deletes everything</strong> in database <code><?php echo setup_h($dbname); ?></code>:
    every shop, owner, staff member, sale and stock record. There is no undo. Take a backup first if you need the data.
  </div>
  <?php if ($connectionError !== null): ?>
    <div class="alert warn">Cannot connect to the database right now: <?php echo setup_h($connectionError); ?></div>
  <?php endif; ?>

  <form method="post" autocomplete="off">
    <div class="card">
      <h2>What to reset</h2>
      <label style="font-weight:400;"><input type="checkbox" name="wipe_db" value="1" style="width:auto;min-height:0;margin:0 6px 0 0;" <?php echo $connectionError === null ? 'checked' : 'disabled'; ?>> Clear the database (drop all tables)</label>
      <label style="font-weight:400;"><input type="checkbox" name="wipe_support" value="1" style="width:auto;min-height:0;margin:0 6px 0 0;" checked> Remove the support account (setup unlocks)</label>
      <p class="lead" style="margin:6px 0 14px;">The database connection settings are kept.</p>

      <label for="confirm">Type the database name <code><?php echo setup_h($dbname); ?></code> to confirm</label>
      <input id="confirm" name="confirm" required>
    </div>
    <button class="btn" type="submit" style="background:#b91c1c;">Reset everything</button>
    <a class="btn secondary" href="<?php echo public_url('setup/database.php'); ?>">Cancel</a>
  </form>
<?php endif; ?>
<?php setup_render_footer(); ?>
