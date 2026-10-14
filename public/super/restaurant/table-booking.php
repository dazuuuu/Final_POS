<?php
// public/super/restaurant/table-booking.php?id=N — one table booking: its
// invoice (a receipt once paid) listing the meals, platters (with what's
// inside), free items and drinks. Mark the group arrived, take payment
// (any deposit counts) and print the receipt.
require_once __DIR__ . '/../../../app/app.php';
define('REST_CAP', Capabilities::TABLE_BOOKINGS);
require_once __DIR__ . '/_hospitality.php';

$id = (int) ($_GET['id'] ?? 0);
$b = tb_find($pdo, $tid, $id);
if (!$b) {
    rest_flash('Booking not found.', 'error');
    header('Location: ' . public_url('super/restaurant/table-bookings.php'));
    exit;
}
$self = public_url('super/restaurant/table-booking.php?id=' . $id);
$open = in_array($b['status'], ['booked', 'arrived'], true);
$balance = round((float) $b['total'] - (float) $b['paid_amount'], 2);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf_token'] ?? null) && $open) {
    $action = $_POST['action'] ?? '';
    if ($action === 'arrived') {
        $pdo->prepare("UPDATE rest_table_bookings SET status = 'arrived' WHERE id = ?")->execute([$id]);
        rest_flash($b['customer_name'] . ' has arrived.');
    } elseif ($action === 'cancel') {
        $pdo->prepare("UPDATE rest_table_bookings SET status = 'cancelled' WHERE id = ?")->execute([$id]);
        rest_flash('Booking cancelled.' . ((float) $b['paid_amount'] > 0 ? ' Remember to refund the deposit of ' . hosp_money($b['paid_amount']) . ' if due.' : ''));
    } elseif ($action === 'pay') {
        $method = array_key_exists($_POST['method'] ?? '', REST_PAY_METHODS) ? $_POST['method'] : 'cash';
        $given = round((float) ($_POST['amount_given'] ?? 0), 2);
        if ($balance > 0 && $given + 0.0001 < $balance) {
            rest_flash('The amount paid is less than the balance of ' . hosp_money($balance) . '.', 'error');
            header('Location: ' . $self);
            exit;
        }
        $pdo->beginTransaction();
        if ($balance > 0) {
            $pdo->prepare('INSERT INTO rest_table_booking_payments (tenant_id, booking_id, amount, method, reference, user_id) VALUES (?,?,?,?,?,?)')
                ->execute([$tid, $id, $balance, $method, trim((string) ($_POST['reference'] ?? '')) ?: null, TenantContext::userId()]);
        }
        $pdo->prepare("UPDATE rest_table_bookings SET status = 'completed', paid_amount = total, paid_at = NOW() WHERE id = ?")->execute([$id]);
        $pdo->commit();
        $change = $balance > 0 && $method === 'cash' ? round($given - $balance, 2) : 0;
        rest_flash('Paid. ' . ($change > 0 ? 'Give change: ' . hosp_money($change) . '. ' : '') . 'Receipt printed.');
        header('Location: ' . $self . '&new=1');
        exit;
    }
    header('Location: ' . $self);
    exit;
}

$paid = $b['status'] === 'completed';
$food = array_filter($b['items'], fn($i) => !$i['is_drink']);
$drinks = array_filter($b['items'], fn($i) => $i['is_drink']);
$row = fn($l, $r, $bold = false) => '<tr' . ($bold ? ' style="font-weight:700;"' : '') . '><td style="padding:2px 0;">' . $l . '</td><td style="text-align:right;padding:2px 0;white-space:nowrap;">' . $r . '</td></tr>';
$itemRows = function (array $items) {
    $out = '';
    foreach ($items as $i) {
        $out .= '<tr><td style="padding:3px 4px 3px 0;vertical-align:top;">' . hosp_h($i['name'])
            . ($i['contents'] ? '<div style="font-size:11px;color:#475569;">' . hosp_h($i['contents']) . '</div>' : '') . '</td>'
            . '<td style="padding:3px 4px;text-align:center;vertical-align:top;">' . rest_qty((float) $i['quantity']) . '</td>'
            . '<td style="padding:3px 0;text-align:right;vertical-align:top;white-space:nowrap;">' . ($i['is_free'] ? 'FREE' : number_format((float) $i['line_total'], 2)) . '</td></tr>';
    }
    return $out;
};

ob_start();
hosp_print_head(!empty($_GET['new']) && $paid);
?>
<div class="row g-4">
  <div class="col-12 col-lg-6">
    <div class="sheet">
      <?php echo hosp_sheet_header($paid ? 'RECEIPT' : 'BOOKING INVOICE', $b['booking_no'], date('j M Y, g:i a', strtotime($paid ? $b['paid_at'] : $b['created_at']))); ?>
      <div style="font-size:12px;margin-bottom:8px;">
        <div><strong>Booking:</strong> <?php echo hosp_h($b['customer_name']); ?><?php echo $b['customer_phone'] ? ' · ' . hosp_h($b['customer_phone']) : ''; ?></div>
        <div>For: <?php echo date('D j M Y, g:i a', strtotime($b['booking_at'])); ?> · <?php echo (int) $b['party_size']; ?> people</div>
        <?php if ($b['tables_label']): ?><div>Table(s): <?php echo hosp_h($b['tables_label']); ?></div><?php endif; ?>
        <?php if ($b['notes']): ?><div>Note: <?php echo hosp_h($b['notes']); ?></div><?php endif; ?>
      </div>
      <table style="width:100%;border-collapse:collapse;font-size:13px;">
        <tr style="border-bottom:1px solid #cbd5e1;"><th style="text-align:left;font-size:12px;">ITEM</th><th style="text-align:center;font-size:12px;">QTY</th><th style="text-align:right;font-size:12px;">AMT</th></tr>
        <?php echo $itemRows($food); ?>
        <?php if ($drinks): ?><tr><td colspan="3" style="padding-top:6px;font-size:12px;font-weight:700;">DRINKS</td></tr><?php echo $itemRows($drinks); ?><?php endif; ?>
      </table>
      <table style="width:100%;border-collapse:collapse;font-size:14px;border-top:2px dashed #cbd5e1;margin-top:6px;">
        <?php if ((float) $b['discount'] > 0) { echo $row('Subtotal', hosp_money($b['subtotal'])) . $row('Discount', '-' . hosp_money($b['discount'])); } ?>
        <?php echo $row('TOTAL', hosp_money($b['total']), true); ?>
        <?php foreach ($b['payments'] as $p) { echo $row('Paid · ' . (REST_PAY_METHODS[$p['method']] ?? $p['method']) . ($p['reference'] ? ' ' . hosp_h($p['reference']) : ''), hosp_money($p['amount'])); } ?>
        <?php echo $row($balance > 0 && !$paid ? 'BALANCE DUE' : 'Balance', hosp_money($paid ? 0 : max(0, $balance)), true); ?>
      </table>
      <?php $freeCount = count(array_filter($b['items'], fn($i) => $i['is_free'])); if ($freeCount): ?>
        <div style="font-size:12px;margin-top:6px;"><?php echo $freeCount; ?> item(s) on the house.</div>
      <?php endif; ?>
      <p style="text-align:center;font-size:12px;margin-top:12px;"><?php echo hosp_h(($__tenant['receipt_footer'] ?? '') ?: 'Thank you — enjoy your meal!'); ?></p>
    </div>
    <div class="d-flex gap-2 justify-content-center mt-3" style="max-width:400px;margin:0 auto;">
      <button type="button" class="btn btn-primary flex-fill" onclick="TasoPrinter.printClick()"><i class="fas fa-print me-1"></i>Print <?php echo $paid ? 'receipt' : 'invoice'; ?></button>
      <a href="<?php echo public_url('super/restaurant/table-bookings.php'); ?>" class="btn btn-outline-secondary flex-fill">All bookings</a>
    </div>
  </div>
  <div class="col-12 col-lg-6">
    <div class="d-flex align-items-center gap-2 mb-3"><h2 class="h5 mb-0"><?php echo hosp_h($b['customer_name']); ?></h2><?php echo hosp_badge(TB_STATUS, $b['status']); ?></div>
    <?php if ($paid): ?>
      <a href="<?php echo public_url('super/restaurant/table-bookings.php'); ?>" class="btn btn-success w-100 py-2"><i class="fas fa-plus me-1"></i>Next booking</a>
    <?php elseif ($open): ?>
      <?php if ($b['status'] === 'booked'): ?>
        <form method="post" class="mb-3"><?php echo csrf_field(); ?><input type="hidden" name="action" value="arrived"><button class="btn btn-warning w-100"><i class="fas fa-people-group me-1"></i>Group has arrived</button></form>
      <?php endif; ?>
      <div class="card border-0 shadow-sm mb-3" style="border-radius:12px;"><div class="card-body p-3">
        <h3 class="h6 fw-bold mb-2">Take payment</h3>
        <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="pay">
          <div class="row g-2 mb-2">
            <div class="col-6"><label class="form-label small mb-1">Paid by</label><select name="method" class="form-select"><?php foreach (REST_PAY_METHODS as $k => $l): ?><option value="<?php echo $k; ?>"><?php echo $l; ?></option><?php endforeach; ?></select></div>
            <div class="col-6"><label class="form-label small mb-1">Amount given</label><input type="number" step="0.01" min="0" name="amount_given" class="form-control" value="<?php echo max(0, $balance); ?>"></div>
            <div class="col-12"><input name="reference" class="form-control" placeholder="M-Pesa code (optional)"></div>
          </div>
          <div class="d-flex justify-content-between fw-bold mb-2"><span>Balance</span><span><?php echo hosp_money(max(0, $balance)); ?></span></div>
          <button class="btn btn-success w-100 py-2"><i class="fas fa-receipt me-1"></i>Pay &amp; print receipt</button>
        </form>
      </div></div>
      <form method="post" onsubmit="return confirm('Cancel this booking?');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="cancel"><button class="btn btn-sm btn-outline-danger">Cancel booking</button></form>
    <?php endif; ?>
    <div class="small text-muted mt-3">Booked by <?php echo hosp_h($b['created_by_name'] ?? '—'); ?> on <?php echo date('j M Y, g:i a', strtotime($b['created_at'])); ?><?php echo $b['customer_email'] ? ' · ' . hosp_h($b['customer_email']) : ''; ?></div>
  </div>
</div>
<?php
rest_render('Booking ' . $b['booking_no'], ob_get_clean());
