// public/assets/js/boot-chime.js — the built-in start-up chime (original; generated live with the Web Audio API).
// window.dzChime(audioContext) plays it: a soft rising arpeggio that settles on a warm chord, about 2.5 s.
(function () {
  window.dzChime = function (ctx) {

    var t = ctx.currentTime + 0.05, out = ctx.createGain(), lp = ctx.createBiquadFilter();
    lp.type = 'lowpass'; lp.frequency.value = 4200;
    var delay = ctx.createDelay(), fb = ctx.createGain(), wet = ctx.createGain();
    delay.delayTime.value = 0.19; fb.gain.value = 0.28; wet.gain.value = 0.22;
    out.gain.value = 0.42;
    out.connect(lp); lp.connect(ctx.destination); lp.connect(delay); delay.connect(fb); fb.connect(delay); delay.connect(wet); wet.connect(ctx.destination);
    function note(f, start, len, vol, type) {
      var o = ctx.createOscillator(), g = ctx.createGain();
      o.type = type || 'sine'; o.frequency.value = f;
      g.gain.setValueAtTime(0.0001, start);
      g.gain.exponentialRampToValueAtTime(vol, start + 0.04);
      g.gain.exponentialRampToValueAtTime(0.0001, start + len);
      o.connect(g); g.connect(out); o.start(start); o.stop(start + len + 0.05);
    }
    [523.25, 659.25, 783.99, 1046.5].forEach(function (f, i) { note(f, t + i * 0.09, 0.9, 0.22, 'triangle'); });
    [261.63, 392.0, 493.88, 587.33, 659.25].forEach(function (f) { note(f, t + 0.36, 2.3, 0.12, 'sine'); });
    note(130.81, t + 0.36, 2.0, 0.10, 'sine');
    };
})();
