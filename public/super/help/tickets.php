<?php
// public/super/help/tickets.php — Support tickets from inside the POS.
// Owners and staff raise a ticket (subject, area, priority, details) and
// follow the conversation with support here. ?id=N opens one ticket;
// ?new=1 opens the form (Help centre links pass ?new=1&subject=…).
require_once __DIR__ . '/../../../app/app.php';
require_once APP_ROOT . '/helpers/uploads.php';
PageGuard::auth();

$isStaffViewer = TenantContext::role() === 'staff';
$self = public_url('super/help/tickets.php');
$errors = [];
$in = [
    'subject'  => trim($_GET['subject'] ?? ''),
    'category' => $_GET['category'] ?? 'general',
    'priority' => 'normal',
    'body'     => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (($_POST['action'] ?? '') === 'create') {
        $in = [
            'subject'  => trim($_POST['subject'] ?? ''),
            'category' => $_POST['category'] ?? 'general',
            'priority' => $_POST['priority'] ?? 'normal',
            'body'     => trim($_POST['body'] ?? ''),
        ];
        if (mb_strlen($in['subject']) < 4) $errors[] = 'Give the ticket a short title (at least 4 characters).';
        if (mb_strlen($in['body']) < 10) $errors[] = 'Describe the problem in a sentence or two so support can help.';
        if (!$errors) {
            $id = SupportTickets::create(mb_substr($in['subject'], 0, 200), $in['category'], $in['priority'], $in['body'], $_POST['page_url'] ?? null);
            $_SESSION['flash']['success'] = 'Ticket ' . SupportTickets::ref($id) . ' sent. Support will reply here.';
            header('Location: ' . $self . '?id=' . $id);
            exit;
        }
    } elseif (($_POST['action'] ?? '') === 'reply') {
        $t = SupportTickets::forAccount((int) ($_POST['id'] ?? 0));
        $body = trim($_POST['body'] ?? '');
        if ($t && $body !== '' && $t['status'] !== 'closed') {
            SupportTickets::addMessage((int) $t['id'], 'user', $body);
            $_SESSION['flash']['success'] = 'Reply sent.';
        }
        header('Location: ' . $self . '?id=' . (int) ($_POST['id'] ?? 0));
        exit;
    }
}

$ticket = isset($_GET['id']) ? SupportTickets::forAccount((int) $_GET['id']) : null;
if ($ticket && $ticket['unread_by_user']) {
    Database::pdo()->prepare('UPDATE support_tickets SET unread_by_user = 0 WHERE id = ?')->execute([$ticket['id']]);
}
$showForm = $errors || !empty($_GET['new']);

$stmt = Database::pdo()->prepare(
    'SELECT t.*, (SELECT COUNT(*) FROM support_ticket_messages m WHERE m.ticket_id = t.id) AS msgs
       FROM support_tickets t WHERE t.account_id = ? ORDER BY t.updated_at DESC'
);
$stmt->execute([Features::accountId((int) TenantContext::tenantId())]);
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = $ticket ? SupportTickets::ref((int) $ticket['id']) : 'Support tickets';
ob_start();
echo SupportTickets::css();
?>
<style>
.tk-hero{background:linear-gradient(135deg,#16a34a,#0d9488);color:#fff;border-radius:18px;padding:20px;display:flex;gap:16px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin-bottom:16px;}
.tk-hero h2{font-size:1.15rem;font-weight:800;margin:0 0 4px;}
.tk-hero p{margin:0;opacity:.9;font-size:.86rem;}
.tk-hero .btn{background:#fff;color:#15803d;font-weight:700;border-radius:12px;}
.tk-list a{display:flex;gap:12px;align-items:center;background:#fff;border:1px solid #eef0f4;border-radius:16px;padding:14px 16px;margin-bottom:10px;text-decoration:none;color:#1f2330;transition:transform .12s;}
.tk-list a:active{transform:scale(.98);}
.tk-ic{width:42px;height:42px;border-radius:13px;background:#f0fdf4;color:#15803d;display:grid;place-items:center;flex-shrink:0;}
.tk-list .meta{font-size:.75rem;color:#8a8f96;}
.tk-new{width:9px;height:9px;border-radius:50%;background:#ef4444;flex-shrink:0;}
.tk-thread{display:flex;flex-direction:column;gap:10px;margin:14px 0;}
.tk-msg{max-width:85%;padding:12px 14px;border-radius:18px;font-size:.9rem;line-height:1.5;white-space:pre-wrap;word-wrap:break-word;}
.tk-msg small{display:block;font-size:.7rem;opacity:.75;margin-top:6px;white-space:normal;}
.tk-msg.user{align-self:flex-end;background:#16a34a;color:#fff;border-bottom-right-radius:6px;}
.tk-msg.support{align-self:flex-start;background:#fff;border:1px solid #e5e7eb;border-bottom-left-radius:6px;}
.tk-msg.support b{color:#15803d;}
.tk-reply{position:sticky;bottom:calc(84px + env(safe-area-inset-bottom));background:#fff;border:1px solid #e5e7eb;border-radius:18px;padding:8px;display:flex;gap:8px;align-items:flex-end;}
@media (min-width:993px){.tk-reply{bottom:12px;}}
.tk-reply textarea{border:0;resize:none;flex:1;outline:none;padding:8px;font-size:16px;max-height:160px;}
.tk-cat{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:8px;}
.tk-cat label{display:flex;gap:8px;align-items:center;border:1.5px solid #e5e7eb;border-radius:12px;padding:10px;cursor:pointer;font-size:.8rem;font-weight:600;}
.tk-cat input{display:none;}
.tk-cat label:has(input:checked){border-color:#16a34a;background:#f0fdf4;color:#15803d;}
</style>

<?php if ($errors): ?><div class="t-flash err"><?php echo implode('<br>', array_map('htmlspecialchars', $errors)); ?></div><?php endif; ?>

<?php if ($ticket):
    $msgs = SupportTickets::messages((int) $ticket['id']);
    [$catLabel, $catIcon] = SupportTickets::CATEGORIES[$ticket['category']] ?? SupportTickets::CATEGORIES['general']; ?>
  <a href="<?php echo $self; ?>" class="small text-decoration-none"><i class="fas fa-arrow-left me-1"></i>All tickets</a>
  <div class="card mt-2"><div class="card-body">
    <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
      <div>
        <div class="small text-muted"><?php echo SupportTickets::ref((int) $ticket['id']); ?> · <i class="fas <?php echo $catIcon; ?> me-1"></i><?php echo htmlspecialchars($catLabel); ?></div>
        <h2 class="h5 fw-bold mb-0 mt-1"><?php echo htmlspecialchars($ticket['subject']); ?></h2>
      </div>
      <?php echo SupportTickets::statusBadge($ticket['status']); ?>
    </div>
  </div></div>
  <div class="tk-thread">
    <?php foreach ($msgs as $m): ?>
      <div class="tk-msg <?php echo $m['author']; ?>">
        <?php if ($m['author'] === 'support'): ?><b><i class="fas fa-headset me-1"></i>Support</b><br><?php endif; ?><?php echo htmlspecialchars($m['body']); ?>
        <small><?php echo htmlspecialchars($m['author'] === 'user' ? ($m['author_name'] ?? '') . ' · ' : ''); ?><?php echo date('d M Y, H:i', strtotime($m['created_at'])); ?></small>
      </div>
    <?php endforeach; ?>
  </div>
  <?php if ($ticket['status'] === 'closed'): ?>
    <div class="text-center text-muted small my-3">This ticket is closed. <a href="<?php echo $self; ?>?new=1">Raise a new one</a> if you still need help.</div>
  <?php else: ?>
    <form method="post" class="tk-reply">
      <?php echo csrf_field(); ?><input type="hidden" name="action" value="reply"><input type="hidden" name="id" value="<?php echo (int) $ticket['id']; ?>">
      <textarea name="body" rows="1" placeholder="Write a reply…" required oninput="this.style.height='auto';this.style.height=this.scrollHeight+'px'"></textarea>
      <button class="btn btn-primary" style="border-radius:14px;" aria-label="Send"><i class="fas fa-paper-plane"></i></button>
    </form>
  <?php endif; ?>

<?php elseif ($showForm): ?>
  <a href="<?php echo $self; ?>" class="small text-decoration-none"><i class="fas fa-arrow-left me-1"></i>All tickets</a>
  <div class="card mt-2"><div class="card-body p-4">
    <h2 class="h5 fw-bold mb-1">Raise a ticket</h2>
    <p class="text-muted small">Tell support what's wrong or what you need. You'll get the reply right here.</p>
    <form method="post">
      <?php echo csrf_field(); ?><input type="hidden" name="action" value="create">
      <input type="hidden" name="page_url" value="<?php echo htmlspecialchars($_SERVER['HTTP_REFERER'] ?? ''); ?>">
      <label class="form-label small fw-semibold">What is it about?</label>
      <div class="tk-cat mb-3">
        <?php foreach (SupportTickets::CATEGORIES as $k => [$label, $icon]): ?>
          <label><input type="radio" name="category" value="<?php echo $k; ?>" <?php echo $in['category'] === $k ? 'checked' : ''; ?>><i class="fas <?php echo $icon; ?>"></i><?php echo $label; ?></label>
        <?php endforeach; ?>
      </div>
      <div class="row g-3">
        <div class="col-md-8"><label class="form-label small fw-semibold">Title</label><input class="form-control" name="subject" maxlength="200" value="<?php echo htmlspecialchars($in['subject']); ?>" placeholder="e.g. Receipt doesn't print after checkout" required></div>
        <div class="col-md-4"><label class="form-label small fw-semibold">How urgent?</label>
          <select class="form-select" name="priority"><?php foreach (SupportTickets::PRIORITIES as $k => $l): ?><option value="<?php echo $k; ?>" <?php echo $in['priority'] === $k ? 'selected' : ''; ?>><?php echo $l; ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><label class="form-label small fw-semibold">Details</label>
          <textarea class="form-control" name="body" rows="5" placeholder="What were you doing, what happened, and what did you expect?" required><?php echo htmlspecialchars($in['body']); ?></textarea></div>
      </div>
      <button class="btn btn-primary mt-3 px-4"><i class="fas fa-paper-plane me-1"></i>Send to support</button>
    </form>
  </div></div>

<?php else: ?>
  <div class="tk-hero">
    <div><h2><i class="fas fa-headset me-2"></i>Need help?</h2><p>Raise a ticket and our support team will get back to you here.</p></div>
    <a class="btn" href="<?php echo $self; ?>?new=1"><i class="fas fa-plus me-1"></i>New ticket</a>
  </div>
  <?php if (!$tickets): ?>
    <div class="text-center text-muted py-5"><i class="fas fa-ticket fa-2x mb-2 d-block"></i>No tickets yet. Check the <a href="<?php echo public_url($isStaffViewer ? 'staff/guide/' : 'super/guide/'); ?>">Help centre</a> first. Most answers are there.</div>
  <?php endif; ?>
  <div class="tk-list">
    <?php foreach ($tickets as $t): [, $icon] = SupportTickets::CATEGORIES[$t['category']] ?? SupportTickets::CATEGORIES['general']; ?>
      <a href="<?php echo $self; ?>?id=<?php echo (int) $t['id']; ?>">
        <span class="tk-ic"><i class="fas <?php echo $icon; ?>"></i></span>
        <span class="flex-grow-1" style="min-width:0;">
          <strong class="d-block text-truncate"><?php echo htmlspecialchars($t['subject']); ?></strong>
          <span class="meta"><?php echo SupportTickets::ref((int) $t['id']); ?> · <?php echo (int) $t['msgs']; ?> message<?php echo $t['msgs'] == 1 ? '' : 's'; ?> · <?php echo date('d M, H:i', strtotime($t['updated_at'])); ?></span>
        </span>
        <?php if ($t['unread_by_user']): ?><span class="tk-new" title="New reply"></span><?php endif; ?>
        <?php echo SupportTickets::statusBadge($t['status']); ?>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../templates/' . ($isStaffViewer ? 'staff' : 'tenants') . '/layout.php';
