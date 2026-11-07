<?php
// public/components/login_hero.php — the sign-in screen on phones: a full
// background picture (Support → Settings → Mobile login background; a dark
// gradient until one is uploaded), a big "USER LOGIN" title, pill fields with
// round white icon badges and a white pill button. Computers keep their usual
// layout. Included in <head> by the auth layouts when a page sets $__authHero
// (the layout then adds class "lh" to <body>).
// Fields opt in with markup: <div class="lh-field [lh-right]"><span class="lh-ic"><i …></i></span><input …></div>
$__lhBg = Branding::platform()['login_bg'];
?>
<style>
.lh-ic{display:none;}
@media (max-width:600px){
  html,body{min-height:100%;}
  body.lh{
    background:linear-gradient(135deg,rgba(0,38,40,.82) 0%,rgba(0,38,40,.82) 50%,rgba(0,26,38,.9) 50%,rgba(0,26,38,.9) 100%)<?php echo $__lhBg !== '' ? ', url(' . json_encode($__lhBg) . ') center/cover no-repeat' : ''; ?>,#00262c;
    background-attachment:fixed;color:#fff;
  }
  body.lh .auth-split,body.lh .auth-side,body.lh .mono-wrap{background:transparent!important;min-height:100svh;}
  body.lh .auth-side{justify-content:center;padding:32px 22px 40px;}
  body.lh .auth-brand{color:#fff;margin-bottom:14px;}
  body.lh .auth-brand img{mix-blend-mode:normal;background:#fff;border-radius:50%;padding:4px;height:44px;}
  body.lh .auth-card{background:transparent!important;border:0!important;box-shadow:none!important;padding:0!important;width:100%;max-width:420px;}
  body.lh .auth-title{color:#fff;font-weight:800;font-size:2.1rem;letter-spacing:.02em;text-transform:uppercase;margin:6px 0 10px;}
  body.lh .auth-sub,body.lh .auth-foot{color:rgba(255,255,255,.75);}
  body.lh .auth-foot a,body.lh .auth-side a.link{color:#fff;text-decoration:underline;}
  body.lh .form-label{display:none;}
  body.lh .lh-field{position:relative;display:flex;align-items:center;margin-bottom:24px;}
  body.lh .lh-field > .pw-wrap{flex:1;width:100%;}
  body.lh .lh-field input.form-control{
    width:100%;height:58px;min-height:58px;border-radius:999px!important;border:0!important;background:rgba(255,255,255,.28)!important;
    color:#fff!important;font-size:1.05rem!important;padding:0 22px 0 80px!important;box-shadow:none!important;
  }
  body.lh .lh-field input.form-control::placeholder{color:rgba(255,255,255,.78);}
  body.lh .lh-field input.form-control:focus{background:rgba(255,255,255,.36)!important;outline:2px solid rgba(255,255,255,.65);}
  body.lh .lh-ic{
    display:flex;align-items:center;justify-content:center;position:absolute;left:-6px;z-index:2;
    width:70px;height:70px;border-radius:50%;background:#fff;color:#00262c;font-size:1.45rem;
    box-shadow:0 6px 18px rgba(0,0,0,.25);pointer-events:none;
  }
  body.lh .lh-field.lh-right input.form-control{padding:0 118px 0 26px!important;}
  body.lh .lh-field.lh-right .lh-ic{left:auto;right:-6px;}
  body.lh .lh-field .pw-eye{right:76px!important;color:rgba(255,255,255,.85)!important;z-index:3;}
  body.lh .btn-auth{
    height:58px;border-radius:999px!important;background:#fff!important;color:#00262c!important;
    font-weight:800;font-size:1.1rem;letter-spacing:.05em;text-transform:uppercase;margin-top:34px;
  }
  body.lh .btn-line{border-radius:999px;background:transparent;color:#fff;border-color:#fff;}
  body.lh .auth-alert{border-radius:14px;}
  body.lh .who{border-color:rgba(255,255,255,.7);border-radius:999px;overflow:hidden;}
  body.lh .who button{background:transparent;color:#fff;}
  body.lh .who button.on{background:#fff;color:#00262c;}
  body.lh #paneStaff{color:#fff;}
  /* The support sign-in card (layout_mono) */
  body.lh.mono{padding:28px 22px;}
  body.lh .auth-head{padding:0 0 6px;background:transparent;}
  body.lh .logo-icon{background:#fff;border-radius:50%;padding:6px;}
  body.lh .auth-card{overflow:visible!important;}
  body.lh .logo-name{color:#fff!important;}
  body.lh .logo-role{color:#fff!important;background:transparent!important;border:1px solid rgba(255,255,255,.6)!important;}
  body.lh .auth-body{padding:0!important;background:transparent;}
  body.lh .auth-body .mb-3,body.lh .auth-body .mb-4{margin-bottom:0!important;}
}
</style>
