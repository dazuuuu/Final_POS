<?php
// public/super/services/receipt.php?id=N — printable invoice (unpaid) or
// receipt (paid) for a client visit.
require_once __DIR__ . '/_common.php';
svc_require($isDesk);

$t = $SD->find((int) ($_GET['id'] ?? 0));
if (!$t) {
    header('Location: ' . public_url('super/services/'));
    exit;
}
$shop = (new Models\TenantModel($pdo))->find((int) TenantContext::tenantId()) ?: [];
$logo = Branding::tenantLogo($shop);
$cur = $shop['currency'] ?? 'KES';
$m = fn($n) => htmlspecialchars($cur) . ' ' . number_format((float) $n, 2);
$paid = $t['status'] === 'paid';
$flash = $_SESSION['flash']['success'] ?? '';
unset($_SESSION['flash']['success']);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo $paid ? 'Receipt' : 'Invoice'; ?> <?php echo svc_h($t['number']); ?></title>
<style>
  body{font-family:-apple-system,'Segoe UI',Roboto,Arial,sans-serif;background:#f3f4f6;margin:0;padding:16px;color:#111827;}
  .slip{max-width:380px;margin:0 auto;background:#fff;padding:20px;border-radius:10px;box-shadow:0 4px 18px rgba(0,0,0,.06);}
  .c{text-align:center;} .muted{color:#6b7280;} h1{font-size:18px;margin:6px 0 2px;}
  .tag{display:inline-block;letter-spacing:.15em;font-weight:800;font-size:12px;padding:3px 10px;border-radius:999px;margin:8px 0;}
  .tag.paid{background:#dcfce7;color:#166534;} .tag.inv{background:#fef3c7;color:#92400e;}
  table{width:100%;border-collapse:collapse;font-size:13px;margin-top:10px;}
  td{padding:5px 0;vertical-align:top;} .r{text-align:right;white-space:nowrap;}
  .line{border-top:1px dashed #d1d5db;margin:10px 0;}
  .tot td{font-weight:800;font-size:15px;}
  .actions{max-width:380px;margin:12px auto 0;display:flex;gap:8px;}
  .actions a,.actions button{flex:1;padding:10px;border-radius:8px;border:1px solid #d1d5db;background:#fff;font-weight:600;text-align:center;text-decoration:none;color:#111827;cursor:pointer;font-size:14px;}
  .actions .p{background:#16a34a;color:#fff;border-color:#16a34a;}
  .ok{max-width:380px;margin:0 auto 12px;background:#dcfce7;color:#166534;padding:10px 14px;border-radius:8px;font-size:14px;}
  @media print{ body{background:#fff;padding:0;} .slip{box-shadow:none;} .actions,.ok{display:none;} }
</style>
<?php $autoPrint = !empty($_GET['new']) && $paid; include __DIR__ . '/../../components/printer_script.php'; ?>
</head>
<body>
<?php if ($flash): ?><div class="ok"><?php echo svc_h($flash); ?></div><?php endif; ?>
<div class="slip sheet">
  <div class="c">
    <?php if ($logo): ?><img src="<?php echo svc_h($logo); ?>" alt="" style="max-height:56px;max-width:140px;"><?php endif; ?>
    <h1><?php echo svc_h($shop['name'] ?? ''); ?></h1>
    <div class="muted" style="font-size:12px;"><?php echo svc_h(trim(($shop['address'] ?? '') . ' ' . (($shop['phone'] ?? '') ? '· ' . $shop['phone'] : ''))); ?></div>
    <div class="tag <?php echo $paid ? 'paid' : 'inv'; ?>"><?php echo $paid ? 'RECEIPT' : 'INVOICE'; ?></div>
  </div>
  <div style="font-size:12px;">
    <div><strong><?php echo svc_h($t['number']); ?></strong> · <?php echo date('j M Y, g:i a', strtotime($paid ? $t['paid_at'] : $t['created_at'])); ?></div>
    <div>Client: <strong><?php echo svc_h($t['customer_name']); ?></strong><?php echo $t['customer_phone'] ? ' · ' . svc_h($t['customer_phone']) : ''; ?></div>
    <?php if ($t['scheduled_at']): ?><div>Appointment: <?php echo date('D j M Y, g:i a', strtotime($t['scheduled_at'])); ?></div><?php endif; ?>
  </div>
  <table>
    <?php foreach ($t['items'] as $it): ?>
      <?php if (($it['kind'] ?? 'service') === 'product'): $q = rtrim(rtrim(number_format((float) $it['quantity'], 2), '0'), '.'); ?>
      <tr><td><?php echo $q; ?> × <?php echo svc_h($it['service_name']); ?><?php if ((float) $it['quantity'] != 1): ?><div class="muted" style="font-size:11px;">@ <?php echo $m($it['price']); ?></div><?php endif; ?></td><td class="r"><?php echo $m((float) $it['price'] * (float) $it['quantity']); ?></td></tr>
      <?php else: ?>
      <tr><td><?php echo svc_h($it['service_name']); ?><?php if ($it['staff_name']): ?><div class="muted" style="font-size:11px;">by <?php echo svc_h($it['staff_name']); ?></div><?php endif; ?></td><td class="r"><?php echo $m($it['price']); ?></td></tr>
      <?php endif; ?>
    <?php endforeach; ?>
  </table>
  <div class="line"></div>
  <table>
    <?php if ((float) $t['discount_amount'] > 0): ?>
      <tr><td>Subtotal</td><td class="r"><?php echo $m($t['subtotal']); ?></td></tr>
      <tr><td>Discount</td><td class="r">− <?php echo $m($t['discount_amount']); ?></td></tr>
    <?php endif; ?>
    <tr class="tot"><td><?php echo $paid ? 'Total paid' : 'Amount due'; ?></td><td class="r"><?php echo $m($paid ? $t['total'] : $t['subtotal']); ?></td></tr>
    <?php if ($paid): ?>
      <tr><td class="muted">Paid by</td><td class="r muted"><?php echo $t['payment_method'] === 'split' ? 'Cash ' . $m($t['cash_amount']) . ' + M-Pesa ' . $m($t['mpesa_amount']) : ($t['payment_method'] === 'mpesa' ? 'M-Pesa' : 'Cash'); ?></td></tr>
      <?php if ((float) $t['change_due'] > 0): ?><tr><td class="muted">Change</td><td class="r muted"><?php echo $m($t['change_due']); ?></td></tr><?php endif; ?>
    <?php endif; ?>
  </table>
  <div class="line"></div>
  <div class="c muted" style="font-size:12px;"><?php echo svc_h($shop['receipt_footer'] ?? '') ?: 'Thank you!'; ?></div>
</div>
<div class="actions">
  <a href="<?php echo public_url('super/services/view.php?id=' . (int) $t['id']); ?>">Back</a>
  <button class="p" onclick="TasoPrinter.printClick()">Print</button>
  <a href="<?php echo public_url('super/services/new.php'); ?>">Next client</a>
</div>
</body>
</html>
