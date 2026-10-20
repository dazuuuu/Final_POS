<?php
// public/support/alerts/index.php — News & alerts to shop owners (Alerts).
// Write one, choose notice / vote / feedback (and whether an answer is required
// to close it), add an optional poster image, send it to every account or one.
// ?id=N shows who saw it, the votes and every answer.
require_once __DIR__ . '/../_boot.php';

$pdo = Database::pdo();
$self = public_url('support/alerts/');

if (support_posted()) {
    $act = $_POST['action'] ?? '';
    if ($act === 'create') {
        $err = '';
        if (trim((string) ($_POST['title'] ?? '')) === '' || trim((string) ($_POST['body'] ?? '')) === '') $err = 'Write a title and a message.';
        if (($_POST['kind'] ?? '') === 'poll' && count(array_filter(array_map('trim', preg_split('/\R/', (string) ($_POST['options'] ?? ''))))) < 2) $err = 'A vote needs at least two options (one per line).';
        $image = null;
        $up = $_FILES['image'] ?? null;
        if (!$err && $up && ($up['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $types = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
            $mime = $up['error'] === UPLOAD_ERR_OK ? (mime_content_type($up['tmp_name']) ?: '') : '';
            if (!isset($types[$mime]) || $up['size'] > 4 * 1024 * 1024) {
                $err = 'The poster must be a PNG, JPG or WEBP under 4 MB.';
            } else {
                $dir = ROOT_PATH . '/public/assets/uploads/platform';
                if (!is_dir($dir)) @mkdir($dir, 0775, true);
                $name = 'alert_' . bin2hex(random_bytes(5)) . '.' . $types[$mime];
                if (move_uploaded_file($up['tmp_name'], "$dir/$name")) $image = 'assets/uploads/platform/' . $name;
                else $err = 'Could not save the poster — check folder permissions.';
            }
        }
        if ($err) {
            support_flash($err, 'danger');
            $_SESSION['alert_old'] = $_POST;
        } else {
            $id = Alerts::create($_POST, $image, SupportAccount::currentEmail());
            support_flash((int) ($_POST['target'] ?? 0) ? 'Alert sent to the account — it pops up on their dashboard.' : 'Alert sent to every account — it pops up on their dashboards.');
            header('Location: ' . $self . '?id=' . $id);
            exit;
        }
    } elseif ($act === 'toggle') {
        Alerts::setActive((int) $_POST['id'], !empty($_POST['on']));
        support_flash(!empty($_POST['on']) ? 'Alert is live again.' : 'Alert paused — owners no longer see it.');
    } elseif ($act === 'delete') {
        Alerts::delete((int) $_POST['id']);
        support_flash('Alert deleted.');
        header('Location: ' . $self);
        exit;
    }
    header('Location: ' . $self . (isset($_POST['id']) ? '?id=' . (int) $_POST['id'] : ''));
    exit;
}

$accounts = support_accounts($pdo);
$old = $_SESSION['alert_old'] ?? [];
unset($_SESSION['alert_old']);
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$view = isset($_GET['id']) ? Alerts::find((int) $_GET['id']) : null;
$page_title = $view ? 'Alert results' : 'News & alerts';
$nav = 'alerts';
ob_start();
?>
<style>
.al-k{display:inline-block;border:1px solid #e4e4e7;border-radius:6px;font-size:.7rem;font-weight:500;padding:1px 7px;color:#3f3f46;}
.al-bar{height:8px;background:#f4f4f5;border-radius:5px;overflow:hidden;margin-top:4px;}
.al-bar i{display:block;height:100%;background:#18181b;border-radius:5px;}
.al-prev{border:1px solid #e4e4e7;display:grid;background:#fff;}
.al-prev .im{background:#f4f4f5 center/cover no-repeat;min-height:220px;}
.al-prev .c{padding:30px 28px;}
.al-prev h3{font-family:'Jost',system-ui,sans-serif;font-weight:700;font-size:1.7rem;line-height:1.05;margin:0 0 12px;}
.al-prev p{font-family:'IBM Plex Mono',ui-monospace,monospace;font-size:.8rem;line-height:1.7;white-space:pre-line;margin:0;}
.al-prev .b{margin-top:22px;background:#000;color:#fff;text-align:center;padding:12px;font-family:'Jost',sans-serif;font-weight:700;letter-spacing:.06em;text-transform:uppercase;font-size:.8rem;}
</style>
<link href="https://fonts.googleapis.com/css2?family=Jost:wght@700&family=IBM+Plex+Mono:wght@400&display=swap" rel="stylesheet">

<?php if ($view): $res = Alerts::results((int) $view['id']); $seen = count(array_filter($res['rows'], fn($r) => $r['seen_at'])); ?>
  <a class="small text-decoration-none" href="<?php echo $self; ?>"><i class="fas fa-arrow-left me-1"></i>All alerts</a>
  <div class="row g-3 mt-1">
    <div class="col-lg-5">
      <div class="s-card">
        <div class="d-flex justify-content-between align-items-start gap-2">
          <div><span class="al-k"><?php echo $h(Alerts::KINDS[$view['kind']]); ?></span> <span class="al-k"><?php echo $view['target_account'] ? 'One account' : 'All accounts'; ?></span>
            <?php if ((int) $view['required']): ?><span class="al-k">Answer required</span><?php endif; ?>
            <?php if (!(int) $view['active']): ?><span class="al-k">Paused</span><?php endif; ?></div>
          <span class="small text-muted"><?php echo date('j M Y H:i', strtotime($view['created_at'])); ?></span>
        </div>
        <h2 class="h5 mt-3 mb-2"><?php echo $h($view['title']); ?></h2>
        <p class="small" style="white-space:pre-line"><?php echo $h($view['body']); ?></p>
        <?php if ($view['image_path']): ?><img src="<?php echo $h(Alerts::image($view['image_path'])); ?>" alt="" style="width:100%;border-radius:8px;border:1px solid #e4e4e7"><?php endif; ?>
        <div class="d-flex gap-2 mt-3">
          <form method="post"><?php echo support_csrf_field(); ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?php echo (int) $view['id']; ?>"><input type="hidden" name="on" value="<?php echo (int) $view['active'] ? '' : '1'; ?>">
            <button class="btn btn-sm btn-outline-secondary"><?php echo (int) $view['active'] ? 'Pause' : 'Make live again'; ?></button></form>
          <form method="post" onsubmit="return confirm('Delete this alert and its answers?')"><?php echo support_csrf_field(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int) $view['id']; ?>">
            <button class="btn btn-sm btn-outline-danger">Delete</button></form>
        </div>
      </div>
    </div>
    <div class="col-lg-7">
      <div class="s-card mb-3">
        <h2 class="h6 mb-1">Reach</h2>
        <p class="small text-muted mb-0"><?php echo $seen; ?> owner<?php echo $seen === 1 ? '' : 's'; ?> saw it · <?php echo count(array_filter($res['rows'], fn($r) => $r['closed_at'])); ?> closed it ·
          <?php echo count(array_filter($res['rows'], fn($r) => $r['choice'] !== null || $r['feedback'] !== null)); ?> answered</p>
      </div>
      <?php if ($view['kind'] === 'poll'): $total = max(1, array_sum($res['votes'])); ?>
        <div class="s-card mb-3">
          <h2 class="h6 mb-3">Votes</h2>
          <?php foreach ($view['option_list'] as $o): $n = (int) ($res['votes'][$o] ?? 0); ?>
            <div class="mb-3"><div class="d-flex justify-content-between small"><span><?php echo $h($o); ?></span><span><?php echo $n; ?> · <?php echo round($n / $total * 100); ?>%</span></div>
              <div class="al-bar"><i style="width:<?php echo $n / $total * 100; ?>%"></i></div></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <div class="s-card">
        <h2 class="h6 mb-2"><?php echo $view['kind'] === 'feedback' ? 'Feedback' : 'Who responded'; ?></h2>
        <?php if (!$res['rows']): ?><p class="small text-muted mb-0">Nobody has seen it yet — it shows the next time owners open their POS.</p><?php endif; ?>
        <?php foreach ($res['rows'] as $r): ?>
          <div class="border-top py-2 small">
            <b><?php echo $h($r['account'] ?? ('Account #' . $r['account_id'])); ?></b> <span class="text-muted">· <?php echo $h($r['username'] ?? ''); ?> · <?php echo $r['closed_at'] ? date('j M H:i', strtotime($r['closed_at'])) : 'seen, not closed'; ?></span>
            <?php if ($r['choice'] !== null): ?><div>Voted: <b><?php echo $h($r['choice']); ?></b></div><?php endif; ?>
            <?php if ($r['feedback'] !== null): ?><div style="white-space:pre-line">“<?php echo $h($r['feedback']); ?>”</div><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

<?php else: $all = Alerts::all(); ?>
  <div class="row g-3">
    <div class="col-xl-6">
      <div class="s-card">
        <h2 class="h6 mb-1">New alert</h2>
        <p class="small text-muted">Pops up on the owners' dashboards the next time they open the POS, and stays in their News &amp; alerts.</p>
        <form method="post" enctype="multipart/form-data" id="alForm"><?php echo support_csrf_field(); ?><input type="hidden" name="action" value="create">
          <label class="form-label small mb-1">Send to</label>
          <select class="form-select form-select-sm mb-2" name="target">
            <option value="0">All accounts (<?php echo count($accounts); ?>)</option>
            <?php foreach ($accounts as $a): ?><option value="<?php echo (int) $a['id']; ?>" <?php echo (int) ($old['target'] ?? 0) === (int) $a['id'] ? 'selected' : ''; ?>>Only <?php echo $h($a['name']); ?></option><?php endforeach; ?>
          </select>
          <label class="form-label small mb-1">Title</label>
          <input class="form-control mb-2" name="title" id="alT" maxlength="160" required value="<?php echo $h($old['title'] ?? ''); ?>" placeholder="e.g. New: print receipts over Wi-Fi">
          <label class="form-label small mb-1">Message</label>
          <textarea class="form-control mb-2" name="body" id="alB" rows="4" required placeholder="What you want them to know"><?php echo $h($old['body'] ?? ''); ?></textarea>
          <label class="form-label small mb-1">Type</label>
          <div class="d-flex gap-3 small mb-2">
            <?php foreach (Alerts::KINDS as $k => $l): ?><label><input type="radio" class="form-check-input me-1" name="kind" value="<?php echo $k; ?>" <?php echo ($old['kind'] ?? 'notice') === $k ? 'checked' : ''; ?>><?php echo $l; ?></label><?php endforeach; ?>
          </div>
          <div id="alOpts" hidden><label class="form-label small mb-1">Options — one per line</label>
            <textarea class="form-control form-control-sm mb-2" name="options" rows="3" placeholder="Yes&#10;No&#10;Not sure"><?php echo $h($old['options'] ?? ''); ?></textarea></div>
          <label class="form-check small mb-2" id="alReq" hidden><input class="form-check-input" type="checkbox" name="required" value="1" <?php echo !empty($old['required']) ? 'checked' : ''; ?>>
            <span class="form-check-label">They must answer before they can close it</span></label>
          <div class="row g-2 mb-2">
            <div class="col-6"><label class="form-label small mb-1">Button text <span class="text-muted">(optional)</span></label><input class="form-control form-control-sm" name="button_label" id="alBtn" maxlength="40" value="<?php echo $h($old['button_label'] ?? ''); ?>" placeholder="Got it / Vote / Submit"></div>
            <div class="col-6"><label class="form-label small mb-1">Show until <span class="text-muted">(optional)</span></label><input class="form-control form-control-sm" type="date" name="ends_at" value="<?php echo $h($old['ends_at'] ?? ''); ?>"></div>
          </div>
          <label class="form-label small mb-1">Poster image <span class="text-muted">(optional — fills the left half)</span></label>
          <input class="form-control form-control-sm mb-3" type="file" name="image" id="alImg" accept="image/png,image/jpeg,image/webp">
          <button class="btn btn-primary" onclick="return confirm(this.form.elements.target.value === '0' ? 'Send this alert to every account?' : 'Send this alert to this account?')"><i class="fas fa-paper-plane me-1"></i>Send alert</button>
        </form>
      </div>
    </div>
    <div class="col-xl-6">
      <div class="s-card mb-3">
        <h2 class="h6 mb-2">Preview</h2>
        <div class="al-prev" id="alPrev"><div class="im" id="alPrevImg" hidden></div>
          <div class="c"><h3 id="alPrevT">Your title</h3><p id="alPrevB">Your message shows here.</p><div id="alPrevX"></div><div class="b" id="alPrevBtn">Got it</div></div></div>
      </div>
      <div class="s-card">
        <h2 class="h6 mb-2">Sent</h2>
        <?php if (!$all): ?><p class="small text-muted mb-0">No alerts yet.</p><?php endif; ?>
        <?php foreach ($all as $a): ?>
          <a class="d-flex justify-content-between align-items-center border-top py-2 text-decoration-none" href="<?php echo $self . '?id=' . (int) $a['id']; ?>" style="color:inherit">
            <span><b style="font-weight:500"><?php echo $h($a['title']); ?></b><br>
              <span class="small text-muted"><?php echo date('j M', strtotime($a['created_at'])); ?> · <?php echo $h(Alerts::KINDS[$a['kind']]); ?> · <?php echo $a['target_account'] ? $h($a['target_name'] ?? 'one account') : 'all accounts'; ?><?php echo (int) $a['active'] ? '' : ' · paused'; ?></span></span>
            <span class="small text-muted text-end text-nowrap"><?php echo (int) $a['seen']; ?> seen<br><?php echo (int) $a['answered']; ?> answered</span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <script>
  (function () {
    var f = document.getElementById('alForm'), $ = function (id) { return document.getElementById(id); };
    function upd() {
      var k = f.querySelector('input[name="kind"]:checked').value;
      $('alOpts').hidden = k !== 'poll'; $('alReq').hidden = k === 'notice';
      $('alPrevT').textContent = $('alT').value || 'Your title';
      $('alPrevB').textContent = $('alB').value || 'Your message shows here.';
      $('alPrevBtn').textContent = $('alBtn').value || (k === 'poll' ? 'Vote' : (k === 'feedback' ? 'Submit' : 'Got it'));
      var x = '';
      if (k === 'poll') x = (f.elements.options.value.split('\n').filter(function (s) { return s.trim(); }).map(function (o) { return '<div style="border:1.5px solid #000;padding:8px 12px;margin-top:6px;font:12px IBM Plex Mono,monospace">' + o.replace(/</g, '&lt;') + '</div>'; }).join(''));
      if (k === 'feedback') x = '<div style="border:1.5px solid #000;padding:10px 12px;margin-top:16px;font:12px IBM Plex Mono,monospace;color:#999">Your feedback</div>';
      $('alPrevX').innerHTML = x;
    }
    f.addEventListener('input', upd); f.addEventListener('change', upd); upd();
    $('alImg').addEventListener('change', function () {
      var file = this.files[0], im = $('alPrevImg');
      if (!file) { im.hidden = true; $('alPrev').style.gridTemplateColumns = '1fr'; return; }
      im.style.backgroundImage = 'url(' + URL.createObjectURL(file) + ')'; im.hidden = false; $('alPrev').style.gridTemplateColumns = '1fr 1fr';
    });
  })();
  </script>
<?php endif; ?>
<?php
$content = ob_get_clean();
include __DIR__ . '/../_layout.php';
