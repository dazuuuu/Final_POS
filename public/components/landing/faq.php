<?php
// Landing: frequently asked questions. The same list is given to Google as FAQ data (Seo::landing),
// so the page text and the search listing always agree. Edit the wording in Seo::faq().
?>
<section class="sec" id="faq" aria-labelledby="faq-h">
  <div class="wrap" style="max-width:820px">
    <div class="sec-head">
      <span class="eyebrow">Questions</span>
      <h2 id="faq-h">Frequently asked questions</h2>
    </div>
    <?php foreach ($faq as [$q, $a]): ?>
    <details class="faq-i"><summary><?php echo $h($q); ?></summary><p><?php echo $h($a); ?></p></details>
    <?php endforeach; ?>
  </div>
</section>
<style>
.faq-i{border:1px solid #e3e8e5;border-radius:14px;background:#fff;margin:0 0 10px;padding:0 18px}
.faq-i summary{cursor:pointer;font-weight:700;padding:16px 0;list-style:none;display:flex;justify-content:space-between;gap:12px}
.faq-i summary::-webkit-details-marker{display:none}
.faq-i summary::after{content:"+";font-weight:700;color:#1f9a5f}
.faq-i[open] summary::after{content:"–"}
.faq-i p{margin:0 0 16px;color:#4b5563;line-height:1.65}
</style>
