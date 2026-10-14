<?php
// public/support/tickets/view.php — one ticket: the conversation, who raised
// it (account, shop, person, page), reply, and change status.
require_once __DIR__ . '/../_boot.php';

$pdo = Database::pdo();
$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT t.*, a.name AS account_name, s.name AS shop_name, u.email AS user_email, o.email AS owner_email
       FROM support_tickets t
       JOIN tenants a ON a.id = t.account_id
       LEFT JOIN tenants s ON s.id = t.tenant_id
       LEFT JOIN users u ON u.id = t.user_id
       LEFT JOIN users o ON o.id = a.owner_user_id
      WHERE t.id = ?'
);
$stmt->execute([$id]);
$t = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$t) {
    support_flash('Ticket not found.', 'danger');
    header('Location: ' . public_url('support/tickets/'));
    exit;
}
$self = public_url('support/tickets/view.php?id=' . $id);

if (support_posted()) {
    $body = trim($_POST['body'] ?? '');
    $status = $_POST['status'] ?? $t['status'];
    if ($body !== '') {
        SupportTickets::addMessage($id, 'support', $body);
    }
    if (isset(SupportTickets::STATUSES[$status]) && $status !== $t['status']) {
        $pdo->prepare('UPDATE support_tickets SET status = ?, unread_by_user = 1 WHERE id = ?')->execute([$status, $id]);
    }
    support_flash($body !== '' ? 'Reply sent.' : 'Ticket updated.');
    header('Location: ' . $self);
    exit;
}
if ($t['unread_by_support']) {
    $pdo->prepare('UPDATE support_tickets SET unread_by_support = 0 WHERE id = ?')->execute([$id]);
}
$msgs = SupportTickets::messages($id);
[$cat, $icon] = SupportTickets::CATEGORIES[$t['category']] ?? SupportTickets::CATEGORIES['general'];

$page_title = SupportTickets::ref($id);
$nav = 'tickets';
ob_start();
echo SupportTickets::css();
?>
<style>
.th{display:flex;flex-direction:column;gap:10px;margin:14px 0;}
.tm{max-width:85%;padding:12px 14px;border-radius:18px;font-size:.92rem;line-height:1.5;white-space:pre-wrap;word-wrap:break-word;}
.tm small{display:block;font-size:.7rem;opacity:.7;margin-top:6px;white-space:normal;}
.tm.user{align-self:flex-start;background:#fff;border:1px solid #e6e9f0;border-bottom-left-radius:6px;}
.tm.support{align-self:flex-end;background:linear-gradient(135deg,#2563eb,#4f46e5);color:#fff;border-bottom-right-radius:6px;}
.tinfo div{display:flex;justify-content:space-between;gap:10px;padding:7px 0;border-bottom:1px solid #f1f5f9;font-size:.86rem;}
.tinfo div span:first-child{color:#64748b;}
.tinfo div span:last-child{text-align:right;word-break:break-word;}
.treply{position:sticky;bottom:calc(var(--tab-h) + var(--safe-b) + 20px);z-index:5;}
.treply textarea{border-radius:16px;}
</style>
<a class="small text-decoration-none" href="<?php echo public_url('support/tickets/'); ?>"><i class="fas fa-arrow-left me-1"></i>All tickets</a>
<div class="row g-3 mt-1">
  <div class="col-lg-8 order-2 order-lg-1">
    <div class="s-card">
      <div class="d-flex justify-content-between align-items-start gap-2">
        <div><div class="small text-muted"><i class="fas <?php echo $icon; ?> me-1"></i><?php echo htmlspecialchars($cat); ?></div>
          <h2 class="h5 fw-bold mb-0 mt-1"><?php echo htmlspecialchars($t['subject']); ?></h2></div>
        <?php echo SupportTickets::statusBadge($t['status']); ?>
      </div>
    </div>
    <div class="th">
      <?php foreach ($msgs as $m): ?>
        <div class="tm <?php echo $m['author']; ?>"><?php echo htmlspecialchars($m['body']); ?>
          <small><?php echo htmlspecialchars($m['author'] === 'support' ? 'You (support)' : ($m['author_name'] ?? 'User')); ?> · <?php echo date('d M Y, H:i', strtotime($m['created_at'])); ?></small></div>
      <?php endforeach; ?>
    </div>
    <form method="post" class="s-card treply">
      <?php echo support_csrf_field(); ?>
      <textarea class="form-control mb-2" name="body" rows="3" placeholder="Write a reply to <?php echo htmlspecialchars($t['user_name'] ?? 'the user'); ?>…"></textarea>
      <div class="d-flex gap-2 flex-wrap">
        <select class="form-select" name="status" style="max-width:220px;">
          <?php foreach (SupportTickets::STATUSES as $k => [$l]): ?><option value="<?php echo $k; ?>" <?php echo $t['status'] === $k ? 'selected' : ''; ?>><?php echo $l; ?></option><?php endforeach; ?>
        </select>
        <button class="btn btn-primary flex-grow-1"><i class="fas fa-paper-plane me-1"></i>Send / update</button>
      </div>
      <div class="small text-muted mt-2">Tip: reply and set <strong>Waiting for you</strong> when you need more info, or <strong>Resolved</strong> once fixed. A user reply re-opens it.</div>
    </form>
  </div>
  <div class="col-lg-4 order-1 order-lg-2">
    <div class="s-card tinfo">
      <h3 class="h6 fw-bold mb-2">Raised by</h3>
      <div><span>Business</span><span><a href="<?php echo public_url('support/accounts/view.php?id=' . (int) $t['account_id']); ?>"><?php echo htmlspecialchars($t['account_name']); ?></a></span></div>
      <div><span>Shop</span><span><?php echo htmlspecialchars($t['shop_name'] ?? '—'); ?></span></div>
      <div><span>Person</span><span><?php echo htmlspecialchars(($t['user_name'] ?? '—') . ' (' . ($t['user_role'] === 'tenant_owner' ? 'owner' : ($t['user_role'] ?? '?')) . ')'); ?></span></div>
      <div><span>Email</span><span><?php echo htmlspecialchars($t['user_email'] ?: ($t['owner_email'] ?? '—')); ?></span></div>
      <div><span>Priority</span><span class="tk-pri-<?php echo $t['priority']; ?>"><?php echo SupportTickets::PRIORITIES[$t['priority']]; ?></span></div>
      <div><span>Opened</span><span><?php echo date('d M Y, H:i', strtotime($t['created_at'])); ?></span></div>
      <?php if ($t['page_url']): ?><div><span>From page</span><span class="small"><?php echo htmlspecialchars(parse_url($t['page_url'], PHP_URL_PATH) ?: $t['page_url']); ?></span></div><?php endif; ?>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../_layout.php';
