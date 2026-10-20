/* public/assets/js/printer.js — receipt printer driver.
 *
 * The printer belongs to the device (the till's phone/tablet/PC), not the
 * account, so its settings live in this browser's localStorage. Modes:
 *   off        — the Print button opens the normal print dialog
 *   system     — print dialog / any printer installed on the device (Wi-Fi,
 *                USB, AirPrint…). Silent with Chrome's --kiosk-printing.
 *   bluetooth  — BLE thermal printer straight from the browser (Chrome /
 *                Edge on Android, Windows, macOS; not iPhone Safari)
 *   rawbt      — classic Bluetooth printers on Android via the RawBT app
 *   network    — Wi-Fi/LAN ESC/POS printer on port 9100, sent by the server
 *                (only when the server is on the same network as the printer)
 *
 * For the thermal modes the rendered receipt (.sheet) is turned into ESC/POS
 * text, so every receipt page prints without its own template.
 */
(function () {
  var KEY = 'taso.printer';
  var DEFAULTS = { mode: 'off', autoPrint: true, width: 58, copies: 1, cut: true, drawer: false, ip: '', port: 9100, btName: '' };

  // Write characteristics used by common BLE receipt printers.
  var BT_SERVICES = [
    '000018f0-0000-1000-8000-00805f9b34fb',
    'e7810a71-73ae-499d-8c15-faa9aef0c3f2',
    '49535343-fe7d-4ae5-8fa9-9fafd205e455',
    '0000ff00-0000-1000-8000-00805f9b34fb',
    '0000ffe0-0000-1000-8000-00805f9b34fb',
    '0000fee7-0000-1000-8000-00805f9b34fb'
  ];

  // Basic (20k) package: Bluetooth and USB / device printing only — Wi-Fi falls back to the device printer.
  var BASIC = !!document.querySelector('meta[name="taso-print-basic"]');
  function load() {
    var cfg;
    try { cfg = Object.assign({}, DEFAULTS, JSON.parse(localStorage.getItem(KEY) || '{}')); }
    catch (e) { cfg = Object.assign({}, DEFAULTS); }
    if (BASIC && cfg.mode === 'network') cfg.mode = 'system';
    return cfg;
  }
  function save(cfg) {
    try { localStorage.setItem(KEY, JSON.stringify(cfg)); } catch (e) {}
  }

  /* ---------- receipt -> ESC/POS ---------- */

  function ascii(s) {
    return String(s)
      .replace(/[−–—]/g, '-').replace(/[·•]/g, '|')
      .replace(/[‘’]/g, "'").replace(/[“”]/g, '"').replace(/ /g, ' ')
      .normalize('NFKD').replace(/[^\x20-\x7e\n]/g, '')
      .replace(/[ \t]+/g, ' ').trim();
  }
  function pad(s, n, right) {
    s = s.slice(0, n);
    var sp = new Array(n - s.length + 1).join(' ');
    return right ? sp + s : s + sp;
  }
  function wrap(text, cols) {
    var out = [];
    text.split('\n').forEach(function (line) {
      line = line.trim();
      while (line.length > cols) {
        var cut = line.lastIndexOf(' ', cols);
        if (cut < cols / 2) cut = cols;
        out.push(line.slice(0, cut).trim());
        line = line.slice(cut).trim();
      }
      if (line) out.push(line);
    });
    return out;
  }

  /** Walk the rendered receipt and produce [{text, align, bold, big}] lines. */
  function receiptLines(root, cols) {
    var lines = [];
    var sep = function () { lines.push({ text: new Array(cols + 1).join('-') }); };
    var dashed = function (el, side) { return getComputedStyle(el)['border' + side + 'Style'] === 'dashed'; };
    var hidden = function (el) { var cs = getComputedStyle(el); return cs.display === 'none' || cs.visibility === 'hidden'; };
    var styleOf = function (el) {
      var cs = getComputedStyle(el);
      return { align: cs.textAlign === 'center' ? 1 : (cs.textAlign === 'right' || cs.textAlign === 'end' ? 2 : 0),
               bold: parseInt(cs.fontWeight, 10) >= 600, big: parseFloat(cs.fontSize) >= 16 };
    };

    function row(tr) {
      var cells = Array.prototype.filter.call(tr.children, function (c) { return !hidden(c); });
      var txt = cells.map(function (c) { return ascii(c.innerText); });
      var st = styleOf(cells[0] || tr);
      if (dashed(tr, 'Top')) sep();
      if (txt.length === 1) { wrap(txt[0], cols).forEach(function (t) { lines.push({ text: t, bold: st.bold }); }); return; }
      if (txt.length === 2) {
        var l = txt[0], r = txt[1];
        if (l.length + r.length + 1 > cols) { wrap(l, cols).forEach(function (t) { lines.push({ text: t, bold: st.bold }); }); lines.push({ text: pad(r, cols, true), bold: st.bold }); }
        else lines.push({ text: l + pad(r, cols - l.length, true), bold: st.bold });
        return;
      }
      // ITEM | QTY | (PRICE) | AMT — numbers in fixed right-aligned columns.
      var rest = txt.slice(1), w = rest.length === 2 ? [5, 10] : [4, 9, 10];
      if (cols >= 48) w = w.map(function (n) { return n + 2; });
      var restStr = rest.map(function (s, i) { return pad(s, w[i] || 10, true); }).join('');
      var nameW = cols - restStr.length;
      if (txt[0].length <= nameW) lines.push({ text: pad(txt[0], nameW) + restStr, bold: st.bold });
      else { wrap(txt[0], cols).forEach(function (t) { lines.push({ text: t, bold: st.bold }); }); lines.push({ text: pad('', nameW) + restStr, bold: st.bold }); }
    }

    function walk(el) {
      if (el.nodeType !== 1 || hidden(el) || el.tagName === 'IMG' || el.tagName === 'SCRIPT') return;
      if (el.tagName === 'TABLE') {
        if (dashed(el, 'Top')) sep();
        el.querySelectorAll('tr').forEach(row);
        if (dashed(el, 'Bottom')) sep();
        return;
      }
      var blocky = Array.prototype.some.call(el.children, function (c) {
        return /^(DIV|P|TABLE|SECTION|H[1-6]|UL|OL)$/.test(c.tagName);
      });
      if (blocky) {
        if (dashed(el, 'Top')) sep();
        Array.prototype.forEach.call(el.children, walk);
        if (dashed(el, 'Bottom')) sep();
        return;
      }
      var text = ascii(el.innerText || '');
      if (!text) return;
      var st = styleOf(el);
      if (el.tagName === 'P' && lines.length) lines.push({ text: '' });
      wrap(text, cols).forEach(function (t) { lines.push({ text: t, align: st.align, bold: st.bold, big: st.big }); });
    }

    walk(root);
    return lines;
  }

  function escpos(root, cfg) {
    var cols = Number(cfg.width) === 80 ? 48 : 32;
    var out = [];
    var push = function () { for (var i = 0; i < arguments.length; i++) out.push(arguments[i]); };
    var text = function (s) { for (var i = 0; i < s.length; i++) out.push(s.charCodeAt(i)); };
    var lines = receiptLines(root, cols);
    for (var c = 0; c < Math.max(1, Number(cfg.copies) || 1); c++) {
      push(0x1b, 0x40);                                  // init
      lines.forEach(function (l) {
        push(0x1b, 0x61, l.align || 0);                 // align
        push(0x1b, 0x45, l.bold ? 1 : 0);               // bold
        push(0x1d, 0x21, l.big ? 0x01 : 0x00);          // double height
        text(l.text); push(0x0a);
      });
      push(0x1b, 0x45, 0, 0x1d, 0x21, 0, 0x1b, 0x61, 0);
      push(0x1b, 0x64, 4);                               // feed 4 lines
      if (cfg.cut) push(0x1d, 0x56, 0x42, 0x00);         // partial cut
    }
    if (cfg.drawer) push(0x1b, 0x70, 0x00, 0x19, 0xfa); // kick cash drawer
    return new Uint8Array(out);
  }

  function b64(bytes) {
    var s = '';
    for (var i = 0; i < bytes.length; i++) s += String.fromCharCode(bytes[i]);
    return btoa(s);
  }

  /* ---------- transports ---------- */

  var bt = { device: null, ch: null };

  async function btCharacteristic(device) {
    var server = device.gatt.connected ? device.gatt : await device.gatt.connect();
    var services = await server.getPrimaryServices();
    for (var i = 0; i < services.length; i++) {
      var chars = await services[i].getCharacteristics();
      for (var j = 0; j < chars.length; j++) {
        var p = chars[j].properties;
        if (p.write || p.writeWithoutResponse) return chars[j];
      }
    }
    throw new Error('This Bluetooth device has no printable channel.');
  }

  /** Pick a printer (needs a tap). Remembers its name for next time. */
  async function pairBluetooth() {
    if (!navigator.bluetooth) throw new Error('This browser cannot use Bluetooth. Use Chrome on Android or a computer, or the RawBT option.');
    var device = await navigator.bluetooth.requestDevice({ acceptAllDevices: true, optionalServices: BT_SERVICES });
    bt.device = device; bt.ch = await btCharacteristic(device);
    var cfg = load(); cfg.btName = device.name || 'Bluetooth printer'; save(cfg);
    return cfg.btName;
  }

  /** Reconnect to the remembered printer without a tap, where the browser allows it. */
  async function btReconnect() {
    if (bt.ch && bt.device && bt.device.gatt.connected) return bt.ch;
    if (!bt.device && navigator.bluetooth && navigator.bluetooth.getDevices) {
      var cfg = load();
      var list = await navigator.bluetooth.getDevices();
      bt.device = list.find(function (d) { return d.name === cfg.btName; }) || list[0] || null;
    }
    if (!bt.device) return null;
    bt.ch = await btCharacteristic(bt.device);
    return bt.ch;
  }

  async function sendBluetooth(bytes, allowPrompt) {
    var ch = null;
    try { ch = await btReconnect(); } catch (e) { ch = null; }
    if (!ch) {
      if (!allowPrompt) { var err = new Error('needs-tap'); err.needsTap = true; throw err; }
      await pairBluetooth(); ch = bt.ch;
    }
    var noResp = ch.properties.writeWithoutResponse;
    for (var i = 0; i < bytes.length; i += 180) {
      var chunk = bytes.slice(i, i + 180);
      if (noResp && ch.writeValueWithoutResponse) { await ch.writeValueWithoutResponse(chunk); await new Promise(function (r) { setTimeout(r, 20); }); }
      else await ch.writeValue(chunk);
    }
  }

  function sendRawBT(bytes) {
    window.location.href = 'intent:base64,' + b64(bytes) + '#Intent;scheme=rawbt;package=ru.a402d.rawbtprinter;end;';
  }

  async function sendNetwork(bytes, cfg) {
    var meta = document.querySelector('meta[name="taso-print-endpoint"]');
    var url = meta ? meta.content : 'api/print/network.php';
    var res = await fetch(url, {
      method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ip: cfg.ip, port: Number(cfg.port) || 9100, data: b64(bytes),
                             csrf_token: (document.querySelector('meta[name="csrf-token"]') || {}).content || '' })
    });
    var j = {};
    try { j = await res.json(); } catch (e) {}
    if (!res.ok || !j.ok) throw new Error(j.error || 'The printer did not respond.');
  }

  /* ---------- public API ---------- */

  /** Print the receipt. `user` = called from a tap (may open pickers/dialogs). */
  async function print(opts) {
    opts = opts || {};
    var cfg = Object.assign(load(), opts.config || {});
    var sheet = opts.element || document.querySelector('.sheet');
    if (cfg.mode === 'off' || cfg.mode === 'system' || !sheet) { window.print(); return; }
    var bytes = escpos(sheet, cfg);
    if (cfg.mode === 'bluetooth') return sendBluetooth(bytes, !!opts.user);
    if (cfg.mode === 'rawbt') return sendRawBT(bytes);
    if (cfg.mode === 'network') return sendNetwork(bytes, cfg);
  }

  function toast(msg, ok) {
    var t = document.createElement('div');
    t.className = 'noprint';
    t.textContent = msg;
    t.style.cssText = 'position:fixed;left:50%;bottom:20px;transform:translateX(-50%);z-index:9999;padding:10px 16px;border-radius:10px;font-size:14px;color:#fff;max-width:90vw;box-shadow:0 4px 14px rgba(0,0,0,.2);background:' + (ok ? '#16a34a' : '#dc2626');
    document.body.appendChild(t);
    setTimeout(function () { t.remove(); }, 4000);
  }

  /** Print button handler. */
  async function printClick() {
    try { await print({ user: true }); var m = load().mode; if (m === 'bluetooth' || m === 'network') toast('Receipt sent to printer.', true); }
    catch (e) { if (e && e.name !== 'NotFoundError') toast(e.message || 'Printing failed.', false); }
  }

  /** Called on a receipt page right after a sale is completed. */
  async function autoPrint() {
    var cfg = load();
    if (!cfg.autoPrint) return; // switched off in Printer settings on this device
    try {
      // No printer set up on this device yet: open the device's print dialog,
      // so the receipt still prints right after checkout without a tap.
      if (cfg.mode === 'system' || cfg.mode === 'off') { setTimeout(function () { window.print(); }, 300); return; }
      await print();
      if (cfg.mode !== 'rawbt') toast('Receipt printed.', true);
    } catch (e) {
      // Browsers only open a Bluetooth connection after a tap — offer one.
      var b = document.createElement('button');
      b.className = 'btn btn-primary btn-lg noprint';
      b.innerHTML = '<i class="fas fa-print me-2"></i>' + (e.needsTap ? 'Tap to print receipt' : 'Retry printing');
      b.style.cssText = 'position:fixed;left:50%;bottom:20px;transform:translateX(-50%);z-index:9999;box-shadow:0 4px 14px rgba(0,0,0,.25);';
      b.onclick = function () { b.remove(); printClick(); };
      document.body.appendChild(b);
      if (!e.needsTap) toast(e.message || 'Printing failed.', false);
    }
  }

  window.TasoPrinter = { load: load, save: save, print: print, printClick: printClick, autoPrint: autoPrint,
                         pairBluetooth: pairBluetooth, escpos: escpos, toast: toast };
})();
