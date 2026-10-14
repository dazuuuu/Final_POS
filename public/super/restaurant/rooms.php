<?php
// public/super/restaurant/rooms.php — set up the rooms: name, floor, what's
// inside (beds, bathroom, toilet, sitting, electronics…), food and room
// service, house rules and prices (night / 3 days / week / rental). Also the
// late check-out fee per hour. Owner, or staff given "Rooms: set up rooms".
require_once __DIR__ . '/../../../app/app.php';
define('REST_CAP', Capabilities::ROOMS_MANAGE);
require_once __DIR__ . '/_hospitality.php';

$self = public_url('super/restaurant/rooms.php');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf_token'] ?? null)) {
    $action = $_POST['action'] ?? 'save';
    $id = (int) ($_POST['id'] ?? 0);
    if ($action === 'late_fee') {
        $fee = max(0, round((float) ($_POST['late_fee_per_hour'] ?? 0), 2));
        $pdo->prepare('INSERT INTO room_settings (tenant_id, late_fee_per_hour) VALUES (?, ?) ON DUPLICATE KEY UPDATE late_fee_per_hour = VALUES(late_fee_per_hour)')
            ->execute([$tid, $fee]);
        rest_flash($fee > 0 ? 'Late check-out fee set to ' . hosp_money($fee) . ' per hour.' : 'Late check-out fee turned off.');
    } elseif ($action === 'delete') {
        $pdo->prepare('UPDATE room_rooms SET is_active = 0 WHERE id = ? AND tenant_id = ?')->execute([$id, $tid]);
        rest_flash('Room removed. Past stays keep their records.');
    } else {
        $txt = fn($k, $len = 255) => ($v = trim((string) ($_POST[$k] ?? ''))) === '' ? null : mb_substr($v, 0, $len);
        $data = [
            'name' => $txt('name', 80), 'floor' => $txt('floor', 40), 'description' => $txt('description', 5000),
            'beds' => $txt('beds', 120), 'bathroom' => $txt('bathroom', 120), 'toilet' => $txt('toilet', 120), 'sitting' => $txt('sitting', 120),
            'amenities' => $txt('amenities', 5000), 'food_included' => $txt('food_included'),
            'room_service' => array_key_exists($_POST['room_service'] ?? '', ROOM_SERVICE) ? $_POST['room_service'] : 'none',
            'room_service_price' => rest_money($_POST['room_service_price'] ?? ''),
            'rules' => $txt('rules', 5000),
            'price_night' => rest_money($_POST['price_night'] ?? ''), 'price_3days' => rest_money($_POST['price_3days'] ?? ''),
            'price_week' => rest_money($_POST['price_week'] ?? ''), 'price_rental' => rest_money($_POST['price_rental'] ?? ''),
            'rental_terms' => $txt('rental_terms'),
            'status' => ($_POST['status'] ?? '') === 'maintenance' ? 'maintenance' : 'available',
        ];
        if ($data['room_service'] !== 'paid') { $data['room_service_price'] = null; }
        if ($data['name'] === null) {
            rest_flash('Give the room a name or number.', 'error');
        } elseif ($data['price_night'] === null && $data['price_3days'] === null && $data['price_week'] === null && $data['price_rental'] === null) {
            rest_flash('Enter at least one price (per night, 3 days, week or rental).', 'error');
        } else {
            $cols = array_keys($data);
            if ($id && room_find($pdo, $tid, $id)) {
                $pdo->prepare('UPDATE room_rooms SET ' . implode(', ', array_map(fn($c) => "`$c` = ?", $cols)) . ' WHERE id = ? AND tenant_id = ?')
                    ->execute(array_merge(array_values($data), [$id, $tid]));
                rest_flash('Room "' . $data['name'] . '" saved.');
            } else {
                $pdo->prepare('INSERT INTO room_rooms (tenant_id, `' . implode('`,`', $cols) . '`) VALUES (?' . str_repeat(',?', count($cols)) . ')')
                    ->execute(array_merge([$tid], array_values($data)));
                rest_flash('Room "' . $data['name'] . '" added.');
            }
            header('Location: ' . $self);
            exit;
        }
        header('Location: ' . $self . ($id ? '?edit=' . $id : ''));
        exit;
    }
    header('Location: ' . $self);
    exit;
}

$rooms = room_list($pdo, $tid);
$settings = room_settings($pdo, $tid);
$edit = isset($_GET['edit']) ? room_find($pdo, $tid, (int) $_GET['edit']) : null;
$r = $edit ?: [];
$v = fn($k) => hosp_h($r[$k] ?? '');
$showForm = $edit || isset($_GET['new']) || !$rooms;

ob_start();
?>
<div class="row g-4">
  <div class="col-12 col-xl-<?php echo $showForm ? '7' : '8'; ?>">
    <?php if ($showForm): ?>
    <div class="card border-0 shadow-sm" style="border-radius:12px;"><div class="card-body p-4">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h5 mb-0"><?php echo $edit ? 'Edit ' . $v('name') : 'Add a room'; ?></h2>
        <?php if ($rooms): ?><a href="<?php echo $self; ?>" class="btn btn-sm btn-link">Cancel</a><?php endif; ?>
      </div>
      <form method="post">
        <?php echo csrf_field(); ?><input type="hidden" name="id" value="<?php echo (int) ($r['id'] ?? 0); ?>">
        <div class="row g-2 mb-2">
          <div class="col-7"><label class="form-label small fw-semibold mb-1">Room name / number</label><input name="name" class="form-control" value="<?php echo $v('name'); ?>" placeholder="e.g. Room 4 · Deluxe" required></div>
          <div class="col-5"><label class="form-label small fw-semibold mb-1">Floor</label><input name="floor" class="form-control" value="<?php echo $v('floor'); ?>" placeholder="e.g. Ground, 1st"></div>
        </div>
        <label class="form-label small fw-semibold mb-1">Description</label>
        <textarea name="description" class="form-control mb-2" rows="2" placeholder="A quiet room facing the garden…"><?php echo $v('description'); ?></textarea>
        <div class="row g-2 mb-2">
          <div class="col-6"><label class="form-label small fw-semibold mb-1">Beds</label><input name="beds" class="form-control" value="<?php echo $v('beds'); ?>" placeholder="e.g. 1 double + 1 single"></div>
          <div class="col-6"><label class="form-label small fw-semibold mb-1">Bathroom</label><input name="bathroom" class="form-control" value="<?php echo $v('bathroom'); ?>" placeholder="e.g. Ensuite, hot shower"></div>
          <div class="col-6"><label class="form-label small fw-semibold mb-1">Toilet</label><input name="toilet" class="form-control" value="<?php echo $v('toilet'); ?>" placeholder="e.g. Inside the room"></div>
          <div class="col-6"><label class="form-label small fw-semibold mb-1">Sitting area</label><input name="sitting" class="form-control" value="<?php echo $v('sitting'); ?>" placeholder="e.g. 2-seater sofa"></div>
        </div>
        <label class="form-label small fw-semibold mb-1">Comes with</label>
        <textarea name="amenities" class="form-control mb-2" rows="2" placeholder="e.g. TV, Wi-Fi, fridge, kettle, wardrobe, towels"><?php echo $v('amenities'); ?></textarea>
        <label class="form-label small fw-semibold mb-1">Food included</label>
        <input name="food_included" class="form-control mb-2" value="<?php echo $v('food_included'); ?>" placeholder="e.g. Breakfast for 2 (leave empty if none)">
        <div class="row g-2 mb-2 align-items-end">
          <div class="col-7"><label class="form-label small fw-semibold mb-1">Room service</label>
            <select name="room_service" id="rsKind" class="form-select"><?php foreach (ROOM_SERVICE as $k => $l): ?><option value="<?php echo $k; ?>" <?php echo ($r['room_service'] ?? 'none') === $k ? 'selected' : ''; ?>><?php echo $l; ?></option><?php endforeach; ?></select></div>
          <div class="col-5" id="rsPriceWrap"><label class="form-label small fw-semibold mb-1">Per room service</label><input name="room_service_price" type="number" step="0.01" min="0" class="form-control" value="<?php echo $v('room_service_price'); ?>"></div>
        </div>
        <label class="form-label small fw-semibold mb-1">House rules</label>
        <textarea name="rules" class="form-control mb-3" rows="2" placeholder="e.g. No smoking. Visitors leave by 10pm. Check-out 10am."><?php echo $v('rules'); ?></textarea>

        <h3 class="h6 fw-bold mb-2">Prices <span class="text-muted fw-normal small">(fill the ones you offer — reception can still adjust the agreed price)</span></h3>
        <div class="row g-2 mb-2">
          <?php foreach (['price_night' => 'Per night', 'price_3days' => '3 days', 'price_week' => 'Per week', 'price_rental' => 'Rental / month'] as $k => $l): ?>
          <div class="col-6 col-md-3"><label class="form-label small mb-1"><?php echo $l; ?></label><input name="<?php echo $k; ?>" type="number" step="0.01" min="0" class="form-control" value="<?php echo $v($k); ?>"></div>
          <?php endforeach; ?>
        </div>
        <input name="rental_terms" class="form-control mb-3" value="<?php echo $v('rental_terms'); ?>" placeholder="Rental terms, e.g. negotiable, 1 month deposit">
        <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="status" value="maintenance" id="maint" <?php echo ($r['status'] ?? '') === 'maintenance' ? 'checked' : ''; ?>><label class="form-check-label small" for="maint">Closed for maintenance (can't be booked)</label></div>
        <button class="btn btn-primary w-100"><?php echo $edit ? 'Save room' : 'Add room'; ?></button>
      </form>
    </div></div>
    <?php else: ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h2 class="h5 mb-0">Rooms <span class="text-muted small fw-normal"><?php echo count($rooms); ?></span></h2>
      <a href="<?php echo $self; ?>?new=1" class="btn btn-primary btn-sm"><i class="fas fa-plus me-1"></i>Add room</a>
    </div>
    <div class="row g-3">
      <?php foreach ($rooms as $rm): ?>
      <div class="col-12 col-md-6"><div class="card border-0 shadow-sm h-100" style="border-radius:12px;"><div class="card-body p-3">
        <div class="d-flex justify-content-between">
          <div><strong><?php echo hosp_h($rm['name']); ?></strong> <?php echo $rm['floor'] ? '<span class="small text-muted">· ' . hosp_h($rm['floor']) . ' floor</span>' : ''; ?></div>
          <?php echo $rm['status'] === 'maintenance' ? '<span class="badge bg-secondary">Maintenance</span>' : ''; ?>
        </div>
        <?php if ($rm['description']): ?><div class="small text-muted mt-1"><?php echo hosp_h($rm['description']); ?></div><?php endif; ?>
        <div class="small mt-2">
          <?php foreach (['beds' => 'fa-bed', 'bathroom' => 'fa-shower', 'toilet' => 'fa-toilet', 'sitting' => 'fa-couch'] as $k => $ic): if ($rm[$k]): ?>
            <div><i class="fas <?php echo $ic; ?> fa-fw text-muted me-1"></i><?php echo hosp_h($rm[$k]); ?></div>
          <?php endif; endforeach; ?>
          <?php if ($rm['amenities']): ?><div><i class="fas fa-tv fa-fw text-muted me-1"></i><?php echo hosp_h($rm['amenities']); ?></div><?php endif; ?>
          <?php if ($rm['food_included']): ?><div><i class="fas fa-utensils fa-fw text-muted me-1"></i><?php echo hosp_h($rm['food_included']); ?></div><?php endif; ?>
          <div><i class="fas fa-bell-concierge fa-fw text-muted me-1"></i><?php echo $rm['room_service'] === 'paid' ? 'Room service ' . hosp_money($rm['room_service_price']) . ' each' : ROOM_SERVICE[$rm['room_service']]; ?></div>
        </div>
        <div class="d-flex flex-wrap gap-1 mt-2">
          <?php foreach (['price_night' => '/night', 'price_3days' => '/3 days', 'price_week' => '/week', 'price_rental' => '/month rental'] as $k => $l): if ($rm[$k] !== null): ?>
            <span class="badge bg-light text-dark border"><?php echo hosp_money($rm[$k]) . ' ' . $l; ?></span>
          <?php endif; endforeach; ?>
        </div>
        <div class="d-flex gap-2 mt-3">
          <a href="<?php echo $self; ?>?edit=<?php echo (int) $rm['id']; ?>" class="btn btn-sm btn-outline-primary flex-fill">Edit</a>
          <form method="post" onsubmit="return confirm('Remove this room?');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int) $rm['id']; ?>"><button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button></form>
        </div>
      </div></div></div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
  <div class="col-12 col-xl-<?php echo $showForm ? '5' : '4'; ?>">
    <div class="card border-0 shadow-sm" style="border-radius:12px;"><div class="card-body p-4">
      <h2 class="h6 fw-bold mb-1">Late check-out fee</h2>
      <p class="small text-muted mb-3">Each night is 24 hours from check-in. If a guest leaves later, every extra hour is charged at this rate. Set 0 for no penalty.</p>
      <form method="post" class="d-flex gap-2">
        <?php echo csrf_field(); ?><input type="hidden" name="action" value="late_fee">
        <div class="input-group"><span class="input-group-text"><?php echo hosp_h($currency); ?></span><input type="number" name="late_fee_per_hour" step="0.01" min="0" class="form-control" value="<?php echo (float) $settings['late_fee_per_hour']; ?>"><span class="input-group-text">/ hour</span></div>
        <button class="btn btn-primary">Save</button>
      </form>
    </div></div>
  </div>
</div>
<script>
(function () {
  var k = document.getElementById('rsKind'), w = document.getElementById('rsPriceWrap');
  if (!k) return;
  var sync = function () { w.style.visibility = k.value === 'paid' ? 'visible' : 'hidden'; };
  k.addEventListener('change', sync); sync();
})();
</script>
<?php
rest_render('Rooms', ob_get_clean());
