<?php
// public/components/printer_script.php — include in a receipt page's <head>.
// Loads the device printer driver; set $autoPrint = true before including to
// print as soon as the page opens (right after a sale is completed).
require_once ROOT_PATH . '/app/helpers/uploads.php';
?>
<meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES); ?>">
<meta name="taso-print-endpoint" content="<?php echo htmlspecialchars(public_url('api/print/network.php')); ?>">
<?php if (Features::isBasic()): ?><meta name="taso-print-basic" content="1"><?php endif; ?>
<script src="<?php echo htmlspecialchars(public_url('assets/js/printer.js')); ?>"></script>
<?php if (!empty($autoPrint)): ?>
<script>
  // Drop the flag so a refresh doesn't print the receipt again.
  history.replaceState(null, '', location.href.replace(/([?&])new=1(&|$)/, '$1').replace(/[?&]$/, ''));
  window.addEventListener('load', function () { TasoPrinter.autoPrint(); });
</script>
<?php endif; ?>
