<?php
// public/support/tickets/index.php — every ticket raised from the POS.
// Filter by status, search, open one to reply / resolve.
require_once __DIR__ . '/../_boot.php';

$pdo = Database::pdo();
$filter = $_GET['s'] ?? 'active';
$q = trim($_GET['q'] ?? '');
$where = [
    'active'  => "t.status IN ('open','in_progress','waiting')",
    'new'     => 't.unread_by_support = 1',
    'done'    => "t.status IN ('resolved','closed')",
    'all'     => '1=1',
][$filter] ?? "t.status IN ('open','in_progress','waiting')";
$sql = "SELECT t.*, a.name AS account_name, s.name AS shop_name,
               (SELECT body FROM support_ticket_messages m WHERE m.ticket_id = t.id ORDER BY m.id DESC LIMIT 1) AS last_msg
          FROM support_tickets t
          JOIN tenants a ON a.id = t.account_id
          LEFT JOIN tenants s ON s.id = t.tenant_id
         WHERE $where";
$args = [];
if ($q !== '') {
    $sql .= ' AND (t.subject LIKE ? OR a.name LIKE ? OR t.user_name LIKE ? OR t.id = ?)';
    $like = '%' . $q . '%';
    $args = [$like, $like, $like, (int) preg_replace('/\D/', '', $q)];
}
$sql .= " ORDER BY t.unread_by_support DESC, FIELD(t.priority,'urgent','high','normal','low'), t.updated_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($args);
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
$counts = $pdo->query(
    "SELECT SUM(status IN ('open','in_progress','waiting')) a, SUM(unread_by_support = 1) n, SUM(status IN ('resolved','closed')) d, COUNT(*) t FROM support_tickets"
)->fetch(PDO::FETCH_NUM);
$chips = ['active' => ['Active', $counts[0]], 'new' => ['Needs reply', $counts[1]], 'done' => ['Resolved', $counts[2]], 'all' => ['All', $counts[3]]];

$page_title = 'Tickets';
$nav = 'tickets';
ob_start();
echo SupportTickets::css();
?>
<style>
.tchips{display:flex;gap:8px;overflow-x:auto;margin-bottom:12px;}
.tchips a{white-space:nowrap;border-radius:999px;padding:7px 14px;background:#fff;border:1px solid #e6e9f0;color:#334155;text-decoration:none;font-weight:600;font-size:.84rem;}
.tchips a.on{background:#0f172a;color:#fff;border-color:#0f172a;}
.tchips b{margin-left:6px;opacity:.6;font-weight:700;}
.tlist a{display:flex;gap:14px;align-items:flex-start;background:#fff;border:1px solid #e6e9f0;border-radius:18px;padding:14px 16px;margin-bottom:10px;color:inherit;text-decoration:none;transition:transform .12s, box-shadow .12s;}
.tlist a:hover{box-shadow:0 8px 20px rgba(15,23,42,.06);}
.tlist a:active{transform:scale(.985);}
.tlist a.unread{border-left:4px solid #2563eb;}
.tav{width:44px;height:44px;border-radius:14px;display:grid;place-items:center;color:#fff;font-weight:800;flex-shrink:0;background:linear-gradient(135deg,#2563eb,#7c3aed);}
.tmeta{font-size:.74rem;color:#64748b;}
.tlast{font-size:.84rem;color:#475569;display:-webkit-box;-webkit-line-clamp:1;-webkit-box-orient:vertical;overflow:hidden;}
</style>
<form class="mb-3" method="get"><input type="hidden" name="s" value="<?php echo htmlspecialchars($filter); ?>">
  <input class="form-control" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Search by ticket no., subject, business or person">
</form>
<div class="tchips">
  <?php foreach ($chips as $k => [$l, $n]): ?><a class="<?php echo $filter === $k ? 'on' : ''; ?>" href="?s=<?php echo $k; ?>"><?php echo $l; ?><b><?php echo (int) $n; ?></b></a><?php endforeach; ?>
</div>
<div class="tlist">
  <?php if (!$tickets): ?><div class="s-card text-center text-muted py-5"><i class="fas fa-inbox fa-2x d-block mb-2"></i>No tickets here.</div><?php endif; ?>
  <?php foreach ($tickets as $t): [$cat, $icon] = SupportTickets::CATEGORIES[$t['category']] ?? SupportTickets::CATEGORIES['general']; ?>
    <a class="<?php echo $t['unread_by_support'] ? 'unread' : ''; ?>" href="<?php echo public_url('support/tickets/view.php?id=' . (int) $t['id']); ?>">
      <span class="tav"><?php echo htmlspecialchars(strtoupper(mb_substr($t['account_name'], 0, 1))); ?></span>
      <span class="flex-grow-1" style="min-width:0;">
        <span class="d-flex justify-content-between gap-2">
          <strong class="text-truncate"><?php echo htmlspecialchars($t['subject']); ?></strong>
          <?php echo SupportTickets::statusBadge($t['status']); ?>
        </span>
        <span class="tmeta d-block">
          <?php echo SupportTickets::ref((int) $t['id']); ?> · <?php echo htmlspecialchars($t['account_name']); ?><?php echo $t['shop_name'] && $t['tenant_id'] != $t['account_id'] ? ' / ' . htmlspecialchars($t['shop_name']) : ''; ?>
          · <i class="fas <?php echo $icon; ?>"></i> <?php echo htmlspecialchars($cat); ?>
          · <span class="tk-pri-<?php echo $t['priority']; ?>"><?php echo SupportTickets::PRIORITIES[$t['priority']]; ?></span>
          · <?php echo date('d M H:i', strtotime($t['updated_at'])); ?>
        </span>
        <span class="tlast"><?php echo htmlspecialchars($t['last_msg'] ?? ''); ?></span>
      </span>
    </a>
  <?php endforeach; ?>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../_layout.php';
