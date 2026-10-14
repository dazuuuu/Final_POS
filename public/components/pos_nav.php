<?php
// public/components/pos_nav.php — the sidebar menu (owner + staff), rendered
// from PosMenu::groups(). Each group is a collapsible section; the group with
// the current page starts open. Open/closed state is remembered per browser.
$__groups = PosMenu::groups();
$__activePath = PosMenu::activePath($__groups);
$__activeGroup = PosMenu::activeGroup($__groups, $__activePath);
?>
<nav class="t-nav pn">
    <?php foreach ($__groups as $__g):
        $__single = count($__g['links']) === 1;
        $__open = $__g['key'] === $__activeGroup || $__g['key'] === 'home'; ?>
        <?php if ($__single): $__l = $__g['links'][0]; ?>
            <a class="t-link pn-solo <?php echo $__l[2] === $__activePath ? 'active' : ''; ?>" href="<?php echo public_url($__l[2]); ?>">
                <i class="fas <?php echo $__g['icon']; ?>"></i><span><?php echo $__g['key'] === 'home' ? 'Home' : htmlspecialchars($__l[0]); ?></span>
            </a>
        <?php else: ?>
        <div class="pn-group <?php echo $__open ? 'open' : ''; ?>" data-group="<?php echo $__g['key']; ?>">
            <button type="button" class="pn-head" aria-expanded="<?php echo $__open ? 'true' : 'false'; ?>">
                <i class="fas <?php echo $__g['icon']; ?>"></i><span><?php echo htmlspecialchars($__g['label']); ?></span>
                <?php if (array_filter(array_column($__g['links'], 3))): ?><b class="pn-dot"></b><?php endif; ?>
                <i class="fas fa-chevron-down pn-chev"></i>
            </button>
            <div class="pn-links">
                <?php foreach ($__g['links'] as $__l): ?>
                <a class="t-link <?php echo $__l[2] === $__activePath ? 'active' : ''; ?>" href="<?php echo public_url($__l[2]); ?>">
                    <i class="fas <?php echo $__l[1]; ?>"></i><span><?php echo htmlspecialchars($__l[0]); ?></span>
                    <?php if ($__l[3]): ?><span class="t-soon pn-badge"><?php echo htmlspecialchars($__l[3]); ?></span><?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    <?php endforeach; ?>
    <hr>
    <a class="t-link t-danger" href="<?php echo public_url('auth/logout.php'); ?>">
        <i class="fas fa-arrow-right-from-bracket"></i><span>Logout</span>
    </a>
</nav>
<style>
.pn-group{margin:2px 0;}
.pn-head{width:100%;display:flex;align-items:center;gap:12px;border:0;background:none;padding:10px 14px;border-radius:10px;
  font-size:.74rem;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#8a8f9c;text-align:left;cursor:pointer;}
.pn-head:hover{background:#f4f6f8;color:#334155;}
.pn-head>i:first-child{width:18px;text-align:center;font-size:.85rem;}
.pn-head span{flex:1;}
.pn-chev{font-size:.7rem;transition:transform .2s;}
.pn-group.open .pn-chev{transform:rotate(180deg);}
.pn-group.open .pn-head{color:var(--tk-green-dark,#15803d);}
.pn-links{display:block;max-height:0;overflow:hidden;transition:max-height .25s ease;}
.pn-group.open .pn-links{max-height:900px;}
.pn-links .t-link{padding-left:22px;}
.pn-dot{width:8px;height:8px;border-radius:50%;background:#ef4444;}
.pn-badge{background:#ef4444 !important;color:#fff !important;}
</style>
<script>
(function () {
  var KEY = 'posNavOpen';
  var saved = {};
  try { saved = JSON.parse(localStorage.getItem(KEY) || '{}'); } catch (e) {}
  document.querySelectorAll('.pn-group').forEach(function (g) {
    var k = g.getAttribute('data-group');
    if (saved[k] === true && !g.classList.contains('open')) g.classList.add('open');
    g.querySelector('.pn-head').addEventListener('click', function () {
      var on = g.classList.toggle('open');
      this.setAttribute('aria-expanded', on ? 'true' : 'false');
      saved[k] = on;
      try { localStorage.setItem(KEY, JSON.stringify(saved)); } catch (e) {}
    });
  });
})();
</script>
