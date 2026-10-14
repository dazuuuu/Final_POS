<?php /* Show/hide eye button on every password field. Include inside <head>. Self-contained (no URLs), so it also works on setup pages. */ ?>
<style>
  .pw-wrap{position:relative;display:block;}
  .pw-wrap > input{padding-right:2.6rem !important;}
  .pw-eye{position:absolute;top:50%;right:.5rem;transform:translateY(-50%);border:0;background:transparent;padding:.25rem;line-height:1;color:#64748b;cursor:pointer;z-index:5;}
  .pw-eye:hover,.pw-eye:focus-visible{color:#0f172a;}
  .pw-eye svg{width:18px;height:18px;display:block;}
</style>
<script>
(function () {
  var EYE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
  var OFF = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19M14.12 14.12a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';

  function attach(input) {
    if (input.dataset.pwToggle) return;
    input.dataset.pwToggle = '1';
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'pw-eye';
    btn.innerHTML = EYE;
    btn.setAttribute('aria-label', 'Show password');
    btn.addEventListener('click', function () {
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.innerHTML = show ? OFF : EYE;
      btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
      input.focus();
    });
    // Inside a Bootstrap input-group the input must stay a direct child, so
    // anchor the button to the group instead of wrapping the input.
    var group = input.parentElement && input.parentElement.classList.contains('input-group') ? input.parentElement : null;
    if (group) {
      group.style.position = 'relative';
      input.style.paddingRight = '2.6rem';
      group.appendChild(btn);
      var place = function () { btn.style.right = (group.clientWidth - input.offsetLeft - input.offsetWidth + 8) + 'px'; };
      place();
      window.addEventListener('resize', place);
    } else {
      var wrap = document.createElement('span');
      wrap.className = 'pw-wrap';
      input.parentNode.insertBefore(wrap, input);
      wrap.appendChild(input);
      wrap.appendChild(btn);
    }
  }

  function scan(root) {
    (root.querySelectorAll ? root : document).querySelectorAll('input[type="password"]').forEach(attach);
  }

  document.addEventListener('DOMContentLoaded', function () {
    scan(document);
    new MutationObserver(function (muts) {
      muts.forEach(function (m) { m.addedNodes.forEach(function (n) { if (n.nodeType === 1) { if (n.matches('input[type="password"]')) attach(n); else scan(n); } }); });
    }).observe(document.body, { childList: true, subtree: true });
  });
})();
</script>
