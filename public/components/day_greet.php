<?php
// public/components/day_greet.php — once a day, right after someone opens the
// system: their opening sales and what they've sold so far (closing sales).
if (empty($_SESSION['day_greet']) || !TenantContext::userId() || !TenantContext::tenantId()) {
    return;
}
unset($_SESSION['day_greet']);
try {
    $__dgDb = Database::pdo();
    $__dgS = DaySales::open($__dgDb, (int) TenantContext::tenantId(), (int) TenantContext::userId());
    $__dgNow = DaySales::totalFor($__dgDb, (int) TenantContext::tenantId(), date('Y-m-d'), date('Y-m-d'), (int) TenantContext::userId());
    $__dgT = (new Models\TenantModel($__dgDb))->find(TenantContext::tenantId());
} catch (\Throwable $e) {
    return;
}
$__dgCur = htmlspecialchars($__dgT['currency'] ?? 'KES');
$__dgH = (int) date('G');
$__dgHello = $__dgH < 12 ? 'Good morning' : ($__dgH < 17 ? 'Good afternoon' : 'Good evening');
?>
<div id="dayGreet" class="dg-back" role="dialog" aria-modal="true" aria-labelledby="dgTitle">
  <div class="dg-card">
    <div class="dg-sun"><i class="fas fa-sun"></i></div>
    <h2 id="dgTitle" class="h5 fw-bold mb-1"><?php echo $__dgHello; ?>, <?php echo htmlspecialchars($_SESSION['username'] ?? ''); ?></h2>
    <div class="text-muted small mb-3"><?php echo date('l, j F Y'); ?> · day opened at <?php echo date('g:i a', strtotime($__dgS['opened_at'])); ?></div>
    <div class="dg-tiles">
      <div><span>Opening sales</span><strong><?php echo $__dgCur . ' ' . number_format((float) $__dgS['opening_sales'], 0); ?></strong></div>
      <div><span>Closing sales (so far)</span><strong class="text-success"><?php echo $__dgCur . ' ' . number_format($__dgNow['total'], 0); ?></strong>
        <small><?php echo (int) $__dgNow['count']; ?> sale<?php echo $__dgNow['count'] === 1 ? '' : 's'; ?> today</small></div>
    </div>
    <div class="d-flex gap-2 mt-3">
      <a class="btn btn-outline-secondary flex-fill" href="<?php echo public_url('super/day/'); ?>"><i class="fas fa-file-arrow-down me-1"></i>Day report</a>
      <button type="button" class="btn btn-primary flex-fill" onclick="document.getElementById('dayGreet').remove()">Start selling</button>
    </div>
  </div>
</div>
<style>
.dg-back{position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:3000;display:flex;align-items:center;justify-content:center;padding:16px;animation:dgIn .2s ease;}
.dg-card{background:#fff;border-radius:18px;max-width:420px;width:100%;padding:24px;box-shadow:0 20px 60px rgba(0,0,0,.3);text-align:center;}
.dg-sun{width:52px;height:52px;border-radius:50%;margin:0 auto 10px;background:#fff7e6;color:#f59e0b;display:flex;align-items:center;justify-content:center;font-size:1.4rem;}
.dg-tiles{display:grid;grid-template-columns:1fr 1fr;gap:10px;text-align:left;}
.dg-tiles>div{background:#f7f9f8;border-radius:12px;padding:12px;}
.dg-tiles span{display:block;font-size:.68rem;color:#8a8f96;text-transform:uppercase;letter-spacing:.04em;}
.dg-tiles strong{display:block;font-size:1.15rem;}
.dg-tiles small{color:#8a8f96;font-size:.72rem;}
@keyframes dgIn{from{opacity:0}to{opacity:1}}
</style>
<script>document.getElementById('dayGreet').addEventListener('click',function(e){if(e.target===this)this.remove();});</script>
