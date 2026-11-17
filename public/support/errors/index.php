<?php
// public/support/errors/index.php — every error the system has seen, in one place:
// crashes, PHP warnings, failed requests and browser-side failures, with the account,
// page and time, plus the raw log files. Filter by source, account or page.
require_once __DIR__ . '/../_boot.php';

$pdo = Database::pdo();
Telemetry::ensure($pdo);
$self = public_url('support/errors/');

if (support_posted() && ($_POST['action'] ?? '') === 'clear') {
    $pdo->exec('DELETE FROM sys_errors');
    ErrorLog::clearLogs();
    support_flash('Error history cleared.');
    header('Location: ' . $self);
    exit;
}

$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$src = (string) ($_GET['src'] ?? '');
$q = trim((string) ($_GET['q'] ?? ''));
$where = '1=1'; $p = [];
if (in_array($src, ['php', 'js', 'fetch', 'page'], true)) { $where .= ' AND e.message LIKE ?'; $p[] = "[$src]%"; }
elseif ($src === 'crash') { $where .= " AND e.message NOT LIKE '[%'"; }
if ($q !== '') { $where .= ' AND (e.message LIKE ? OR e.path LIKE ?)'; $p[] = "%$q%"; $p[] = "%$q%"; }
$st = $pdo->prepare("SELECT e.*, (SELECT t.name FROM tenants t WHERE t.id = e.account_id) AS shop FROM sys_errors e WHERE $where ORDER BY e.id DESC LIMIT 300");
$st->execute($p);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
$byPath = $pdo->query("SELECT path, COUNT(*) c FROM sys_errors WHERE created_at >= NOW() - INTERVAL 1 DAY GROUP BY path ORDER BY c DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
$logs = ErrorLog::tail(120);

$nav = 'errors';
ob_start();
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h1 class="h5 mb-0">Errors</h1>
  <form method="post" onsubmit="return confirm('Clear all recorded errors and the log files?')"><?php echo support_csrf_field(); ?><input type="hidden" name="action" value="clear"><button class="btn btn-sm btn-outline-danger">Clear history</button></form>
</div>

<form class="row g-2 mb-3" method="get">
  <div class="col-auto"><select name="src" class="form-select form-select-sm" onchange="this.form.submit()">
    <?php foreach (['' => 'Everything', 'crash' => 'Crashes (page failed)', 'php' => 'PHP warnings', 'fetch' => 'Failed requests (browser)', 'js' => 'Script errors (browser)', 'page' => 'Pages that did not open (receipts…)'] as $k => $l): ?>
      <option value="<?php echo $k; ?>" <?php echo $src === (string) $k ? 'selected' : ''; ?>><?php echo $h($l); ?></option>
    <?php endforeach; ?></select></div>
  <div class="col"><input name="q" value="<?php echo $h($q); ?>" class="form-control form-control-sm" placeholder="Search a page or message, e.g. receipt"></div>
  <div class="col-auto"><button class="btn btn-sm btn-dark">Search</button></div>
</form>

<?php if ($byPath): ?>
<div class="s-card mb-3"><div class="small text-muted mb-1">Pages with most errors, last 24 hours</div>
  <?php foreach ($byPath as $b): ?><span class="badge text-bg-light border me-1 mb-1"><?php echo $h($b['path']); ?> · <?php echo (int) $b['c']; ?></span><?php endforeach; ?></div>
<?php endif; ?>

<div class="s-card mb-3 p-0"><div class="table-responsive"><table class="table table-sm align-middle mb-0 small">
  <thead><tr><th>When</th><th>Shop</th><th>Page</th><th>Code</th><th>What happened</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td class="text-nowrap"><?php echo $h(date('j M H:i:s', strtotime($r['created_at']))); ?></td>
      <td><?php echo $r['shop'] ? $h($r['shop']) : ((int) $r['account_id'] ? '#' . (int) $r['account_id'] : '<span class="text-muted">signed out</span>'); ?></td>
      <td class="text-break"><?php echo $h($r['method'] . ' ' . $r['path']); ?></td>
      <td><?php echo (int) $r['status'] ?: '—'; ?></td>
      <td class="text-break"><?php echo $h($r['message']); ?></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="5" class="text-center text-muted py-4">No errors recorded.</td></tr><?php endif; ?>
  </tbody></table></div></div>

<h2 class="h6">Log files</h2>
<?php foreach ($logs as $file => $lines): ?>
  <div class="s-card mb-3"><div class="small fw-semibold mb-1"><?php echo $h($file); ?> <span class="text-muted fw-normal">· newest first</span></div>
    <pre class="small mb-0" style="max-height:260px;overflow:auto;white-space:pre-wrap"><?php echo $h(implode("\n", $lines)); ?></pre></div>
<?php endforeach; if (!$logs): ?><p class="small text-muted">No log files yet — nothing has gone wrong since the last clear.</p><?php endif; ?>
<?php
$content = ob_get_clean();
include __DIR__ . '/../_layout.php';
