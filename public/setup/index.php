<?php
// public/setup/index.php
// Step 1: install the schema snapshot into a brand-new database.

require_once __DIR__ . '/_setup.php';

$schemaFile = APP_ROOT . '/databases/full_schema.sql';
$error = '';
$connectionError = '';
$alreadyInstalled = false;
$roleCount = 0;
$tableCount = 0;
$pdo = null;

try {
    $pdo = Database::pdo();
    $tableCount = setup_table_count($pdo);
    $roleCount = setup_table_exists($pdo, 'roles') ? (int) $pdo->query('SELECT COUNT(*) FROM roles')->fetchColumn() : 0;
    $alreadyInstalled = setup_schema_ready($pdo);
} catch (\Throwable $e) {
    $connectionError = $e->getMessage();
}

function setup_split_sql(string $sql): array
{
    $parts = preg_split('/;\s*\n/', $sql);
    $statements = [];
    foreach ($parts as $part) {
        $trimmed = trim($part);
        if ($trimmed === '') {
            continue;
        }
        $withoutComments = trim(preg_replace('/^\s*--.*$/m', '', $trimmed));
        if ($withoutComments === '') {
            continue;
        }
        $statements[] = $trimmed;
    }
    return $statements;
}

/**
 * What an existing database is missing compared with full_schema.sql:
 * whole tables (plus their seed rows) and columns on tables that exist.
 * Additive only — never drops or alters an existing column.
 */
function setup_missing_changes(PDO $pdo, array $statements): array
{
    $tables = $pdo->query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
    $tables = array_flip(array_map('strtolower', $tables));
    $colStmt = $pdo->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?');
    $changes = [];
    $newTables = [];
    foreach ($statements as $stmt) {
        if (preg_match('/^CREATE TABLE (?:IF NOT EXISTS )?`?(\w+)`?\s*\((.*)\)[^)]*$/is', $stmt, $m)) {
            $table = $m[1];
            if (!isset($tables[strtolower($table)])) {
                $changes[] = ['label' => "Create table $table", 'sql' => $stmt];
                $newTables[strtolower($table)] = true;
                continue;
            }
            $colStmt->execute([$table]);
            $have = array_flip(array_map('strtolower', $colStmt->fetchAll(PDO::FETCH_COLUMN)));
            foreach (preg_split('/\n/', $m[2]) as $line) {
                if (preg_match('/^\s*`(\w+)`\s+(.+?),?\s*$/', $line, $c) && !isset($have[strtolower($c[1])])) {
                    $changes[] = ['label' => "Add column $table.{$c[1]}", 'sql' => "ALTER TABLE `$table` ADD COLUMN `{$c[1]}` {$c[2]}"];
                }
            }
        } elseif (preg_match('/^INSERT INTO `?(\w+)`?/i', $stmt, $m) && isset($newTables[strtolower($m[1])])) {
            $changes[] = ['label' => "Seed {$m[1]}", 'sql' => $stmt];
        }
    }
    return $changes;
}

const SETUP_TOLERABLE_SQL_CODES = [1050, 1060, 1061, 1062, 1826];

$missing = [];
if ($alreadyInstalled && is_file($schemaFile)) {
    $missing = setup_missing_changes($pdo, setup_split_sql(file_get_contents($schemaFile)));
}

$results = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upgrade' && !$connectionError && $missing) {
    $results = ['created' => 0, 'skipped' => 0, 'errors' => []];
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($missing as $change) {
        try {
            $pdo->exec($change['sql']);
            $results['created']++;
        } catch (\PDOException $e) {
            if (in_array((int) ($e->errorInfo[1] ?? 0), SETUP_TOLERABLE_SQL_CODES, true)) {
                $results['skipped']++;
                continue;
            }
            $results['errors'][] = ['statement' => mb_substr($change['sql'], 0, 220), 'message' => $e->getMessage()];
            break;
        }
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    try { Database::normalizeCollation($pdo); } catch (\Throwable $e) { $results['errors'][] = ['statement' => 'collation', 'message' => $e->getMessage()]; }
    $tableCount = setup_table_count($pdo);
    $roleCount = (int) $pdo->query('SELECT COUNT(*) FROM roles')->fetchColumn();
    $missing = setup_missing_changes($pdo, setup_split_sql(file_get_contents($schemaFile)));
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run' && !$connectionError) {
    $alreadyInstalled = setup_schema_ready($pdo);
    if ($alreadyInstalled) {
        $error = 'The schema already looks installed. Nothing was changed.';
    } elseif (!is_file($schemaFile)) {
        $error = 'Could not find databases/full_schema.sql.';
    } else {
        $statements = setup_split_sql(file_get_contents($schemaFile));
        $results = ['created' => 0, 'skipped' => 0, 'errors' => []];

        foreach ($statements as $stmt) {
            try {
                $pdo->exec($stmt);
                $results['created']++;
            } catch (\PDOException $e) {
                $code = (int) ($e->errorInfo[1] ?? 0);
                if (in_array($code, SETUP_TOLERABLE_SQL_CODES, true)) {
                    $results['skipped']++;
                    continue;
                }
                $results['errors'][] = [
                    'statement' => mb_substr($stmt, 0, 220),
                    'message' => $e->getMessage(),
                ];
                break;
            }
        }

        $tableCount = setup_table_count($pdo);
        $roleCount = setup_table_exists($pdo, 'roles') ? (int) $pdo->query('SELECT COUNT(*) FROM roles')->fetchColumn() : 0;
        $alreadyInstalled = setup_schema_ready($pdo);
    }
}

setup_render_header('Website setup', 'migrations');
?>
<?php if ($connectionError && !is_file(Database::configPath())): ?>
  <div class="alert info">Start with the <a href="<?php echo public_url('setup/database.php'); ?>">Database step</a>.</div>
<?php elseif ($connectionError): ?>
  <div class="alert err">
    <strong>Cannot connect to the database.</strong><br>
    <?php echo setup_h($connectionError); ?><br><br>
    Create the empty database in your hosting panel, then enter its details on the
    <a href="<?php echo public_url('setup/database.php'); ?>">Database step</a> — no need to edit any file by hand.
  </div>
<?php elseif ($results): ?>
  <?php if ($results['errors']): ?>
    <div class="alert err">
      <strong>Migration stopped at a database error.</strong><br>
      Statement: <code><?php echo setup_h($results['errors'][0]['statement']); ?>...</code><br>
      Error: <?php echo setup_h($results['errors'][0]['message']); ?>
    </div>
  <?php else: ?>
    <div class="alert ok">Migrations complete. <?php echo (int) $results['created']; ?> statement<?php echo $results['created'] === 1 ? '' : 's'; ?> ran<?php echo $results['skipped'] ? ', with ' . (int) $results['skipped'] . ' already in place' : ''; ?>.</div>
  <?php endif; ?>
  <div class="card">
    <div class="stat"><b><?php echo (int) $tableCount; ?></b><span>tables</span></div>
    <div class="stat"><b><?php echo (int) $roleCount; ?></b><span>roles</span></div>
  </div>
  <?php if ($alreadyInstalled && !$results['errors']): ?>
    <div class="actions">
      <a class="btn" href="<?php echo public_url('setup/account.php'); ?>">Next: create support account</a>
    </div>
  <?php endif; ?>
<?php elseif ($error): ?>
  <div class="alert err"><?php echo setup_h($error); ?></div>
<?php elseif ($alreadyInstalled && $missing): ?>
  <div class="alert warn">
    This database was installed from an older schema and is missing <strong><?php echo count($missing); ?> item(s)</strong>
    (e.g. <?php echo setup_h(implode(', ', array_slice(array_column($missing, 'label'), 0, 4))); ?>).
    Updating only adds what's missing — existing data is not touched.
  </div>
  <form method="post">
    <input type="hidden" name="action" value="upgrade">
    <button class="btn" type="submit">Update database</button>
  </form>
<?php elseif ($alreadyInstalled): ?>
  <div class="alert info">The schema is already ready: <strong><?php echo (int) $tableCount; ?> tables</strong> and <strong><?php echo (int) $roleCount; ?> roles</strong> found.</div>
  <div class="actions">
    <a class="btn" href="<?php echo public_url('setup/account.php'); ?>">Continue to support account</a>
  </div>
<?php else: ?>
  <div class="card">
    <h2>Step 1</h2>
    <p style="margin-top:0;">This will install the full application schema from <code>databases/full_schema.sql</code> into the configured database.</p>
    <form method="post">
      <input type="hidden" name="action" value="run">
      <button class="btn" type="submit">Run migrations</button>
    </form>
  </div>
<?php endif; ?>
<?php setup_render_footer(); ?>
