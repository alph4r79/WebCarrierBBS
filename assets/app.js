/*
 * WebCarrier BBS: terminal controller.
 * Dials the box, sends keys and lines to api.php, runs the line editor and file transfers,
 * shows a hint in the tab title while the tab is in the background. After a reload of the tab
 * the line is resumed with the token kept in sessionStorage. A direct dial link (#msg/123,
 * #area/5) goes to the box with the next call.
 *
 * Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
(function () {
  'use strict';

  var CFG = window.CBCFG || {};
  var L = CFG.L || {};
  var CP = window.CarrierCP;
  var cv = document.getElementById('term');
  var term = new window.CarrierTerm(cv);
  var upInput = document.getElementById('upfile');
  var tube = document.getElementById('tube');
  var crt = tube && window.CarrierCRT ? new window.CarrierCRT(tube, term) : null;
  var bar = document.getElementById('openbar');
  var barLink = document.getElementById('openlink');

  var phase = 'idle';      // idle, dialing, online, offline
  var ask = null;          // what the BBS expects next
  var pending = false;     // request running
  var polling = false;     // poll request running
  var autoSent = false;    // running request is the automatic input after wait()
  var resuming = false;    // running request is the resume after a reload
  var lastPoll = 0;
  var prompt = '';         // current prompt line incl. colours, redrawn after poll output
  var uploading = false;
  var csrf = '';
  var idleSec = 300;
  var lastKey = Date.now();
  var idleWarned = false;
  var keybuf = [];
  var line = '';
  var ed = null;
  var audio = null;
  var COLS = 80;
  var RT_KEY = 'webcarrierbbs.rt';
  var ED_KEY = 'webcarrierbbs.ed';

  function col(n) {
    var map = [0, 4, 2, 6, 1, 5, 3, 7];
    return '\x1b[0;' + (n > 7 ? '1;' : '') + '3' + map[n & 7] + 'm';
  }
  function pad(n, w) {
    var s = String(n);
    while (s.length < w) { s = ' ' + s; }
    return s;
  }

  /* ---------------------------------------------------------------- layout */

  function fit() {
    var foot = document.getElementById('legal') ? 22 : 0;
    var W = window.innerWidth, H = window.innerHeight - foot;
    var w = Math.min(W, H * 4 / 3);
    var box = tube || cv;
    box.style.width = Math.floor(w) + 'px';
    box.style.height = Math.floor(w * 3 / 4) + 'px';
  }
  window.addEventListener('resize', fit);
  fit();
  if (crt) { crt.set(CFG.crt); }

  /* ---------------------------------------------------------------- hint in the tab */

  var baseTitle = document.title;
  var blinkTimer = null;
  var blinkText = '';
  var iconLink = null;

  /* Small icon drawn on a canvas, with a red dot while a hint is active. */
  function setIcon(dot) {
    if (!document.head) { return; }
    var c = document.createElement('canvas');
    c.width = 32; c.height = 32;
    var x = c.getContext('2d');
    x.fillStyle = '#000';
    x.fillRect(0, 0, 32, 32);
    x.fillStyle = '#aaa';
    for (var i = 0; i < 5; i++) {
      x.fillRect(5 + i * 2, 8 + i * 2, 4, 2);
      x.fillRect(5 + i * 2, 24 - i * 2, 4, 2);
    }
    x.fillRect(17, 24, 10, 3);
    if (dot) {
      x.fillStyle = '#ff5555';
      x.beginPath();
      x.arc(24, 8, 7, 0, Math.PI * 2);
      x.fill();
    }
    if (!iconLink) {
      iconLink = document.createElement('link');
      iconLink.rel = 'icon';
      document.head.appendChild(iconLink);
    }
    iconLink.href = c.toDataURL('image/png');
  }

  /* Title changes every second between the hint and the name of the box until the tab is visible again. */
  function notify(kind) {
    if (!CFG.notify || !document.hidden) { return; }
    blinkText = '(!) ' + (L['n_' + kind] || L.n_msg || '');
    if (blinkTimer) { return; }
    setIcon(true);
    document.title = blinkText;
    blinkTimer = setInterval(function () {
      document.title = document.title === blinkText ? baseTitle : blinkText;
    }, 1000);
  }
  function clearNotify() {
    if (!blinkTimer) { return; }
    clearInterval(blinkTimer);
    blinkTimer = null;
    document.title = baseTitle;
    setIcon(false);
  }
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) { clearNotify(); }
  });
  if (CFG.notify) { setIcon(false); }

  /* ---------------------------------------------------------------- open in WAnsiDraw */

  /* New tab without a way back to this page. If the browser blocks it, a bar with a real link. */
  function openExternal(url) {
    var w = null;
    try { w = window.open('', '_blank'); } catch (e) { w = null; }
    if (w) {
      try {
        w.opener = null;
        w.location.replace(url);
        return;
      } catch (e2) {
        try { w.close(); } catch (e3) { /* gone already */ }
      }
    }
    if (!bar) { return; }
    barLink.href = url;
    barLink.textContent = L.open_wad || 'WAnsiDraw';
    document.getElementById('openesc').textContent = L.open_esc || 'Esc';
    bar.hidden = false;
    barLink.focus();
  }
  function closeBar() {
    bar.hidden = true;
    barLink.removeAttribute('href');
    cv.focus();
  }
  if (barLink) {
    barLink.addEventListener('click', function () { setTimeout(closeBar, 0); });
  }

  /* ---------------------------------------------------------------- sound */

  function ac() {
    if (!CFG.sound) { return null; }
    if (!audio) {
      try { audio = new (window.AudioContext || window.webkitAudioContext)(); } catch (e) { audio = null; }
    }
    return audio;
  }
  function tone(freqs, start, dur, vol) {
    var a = ac();
    if (!a) { return; }
    var g = a.createGain();
    g.gain.value = vol;
    g.connect(a.destination);
    freqs.forEach(function (f) {
      var o = a.createOscillator();
      o.type = 'sine';
      o.frequency.value = f;
      o.connect(g);
      o.start(a.currentTime + start);
      o.stop(a.currentTime + start + dur);
    });
  }
  function noise(start, dur, vol) {
    var a = ac();
    if (!a) { return; }
    var len = Math.floor(a.sampleRate * dur);
    var buf = a.createBuffer(1, len, a.sampleRate);
    var d = buf.getChannelData(0);
    for (var i = 0; i < len; i++) { d[i] = Math.random() * 2 - 1; }
    var src = a.createBufferSource();
    src.buffer = buf;
    var bp = a.createBiquadFilter();
    bp.type = 'bandpass';
    bp.frequency.value = 1800;
    bp.Q.value = 0.8;
    var g = a.createGain();
    g.gain.value = vol;
    src.connect(bp); bp.connect(g); g.connect(a.destination);
    src.start(a.currentTime + start);
  }
  var DTMF = { '1': [697, 1209], '2': [697, 1336], '3': [697, 1477], '4': [770, 1209], '5': [770, 1336],
    '6': [770, 1477], '7': [852, 1209], '8': [852, 1336], '9': [852, 1477], '0': [941, 1336] };

  /* Dial tones, answer tone and a short handshake. Returns the duration in ms. */
  function modemSound(num) {
    if (!ac()) { return 1500; }
    var t = 0.2;
    num.replace(/\D/g, '').split('').forEach(function (d) {
      tone(DTMF[d], t, 0.09, 0.06);
      t += 0.14;
    });
    t += 0.5;
    tone([425], t, 0.9, 0.04);
    t += 1.3;
    tone([2100], t, 1.0, 0.04);
    t += 1.05;
    for (var k = 0; k < 6; k++) {
      tone([k % 2 ? 1200 : 2400], t, 0.14, 0.03);
      noise(t, 0.14, 0.025);
      t += 0.16;
    }
    noise(t, 0.7, 0.035);
    t += 0.8;
    return Math.round(t * 1000);
  }
  term.onBell = function () { tone([800], 0, 0.12, 0.05); };

  /* ---------------------------------------------------------------- direct dial link */

  /* #msg/123 or #area/5 in the address: the box goes there after the greeting of the next call. The
     hash leaves the address bar at once, so a reload later on does not jump again. */
  var target = '';
  function takeHash() {
    var h = window.location.hash || '';
    if (h.length < 2) { return; }
    var m = /^#((?:msg|area)\/[0-9]{1,9})$/.exec(h);
    if (m && phase !== 'online' && phase !== 'dialing') { target = m[1]; }
    try {
      window.history.replaceState(null, '', window.location.pathname + window.location.search);
    } catch (e) { /* the hash just stays */ }
  }
  takeHash();
  window.addEventListener('hashchange', takeHash);

  /* Key L: the box shows the address and the terminal copies it. Without permission of the browser
     the address is only on the screen. */
  function copyLink(url) {
    var cb = navigator.clipboard;
    if (!cb || !cb.writeText || window.isSecureContext === false) { return; }
    var a = ask;
    cb.writeText(url).then(function () {
      if (phase !== 'online' || !a || ask !== a || pending || polling || term.busy()) { return; }
      term.showCursor(false);
      term.write('\r\x1b[K');
      term.print(col(10) + (L.link_copied || 'Link copied.') + col(7) + '\r\n');
      redraw();
    }, function () { /* not allowed */ });
  }

  /* ---------------------------------------------------------------- resume token */

  /* sessionStorage: survives a reload of this tab, a new tab or window does not get it */
  function rtGet() {
    try { return window.sessionStorage.getItem(RT_KEY) || ''; } catch (e) { return ''; }
  }
  function rtSet(v) {
    try {
      if (v) { window.sessionStorage.setItem(RT_KEY, v); } else { window.sessionStorage.removeItem(RT_KEY); edDraft(null); }
    } catch (e) { /* storage blocked: no resume then */ }
  }

  /* Finished lines of the editor live only here until /S, so they are kept for a reload as well.
     sig ties them to the editor the box asked for. */
  function edSig(a) {
    return JSON.stringify([a.l || [], a.w || 0, a.m || 0]);
  }
  function edDraft(d) {
    try {
      if (d) { window.sessionStorage.setItem(ED_KEY, JSON.stringify(d)); } else { window.sessionStorage.removeItem(ED_KEY); }
    } catch (e) { /* storage blocked */ }
  }
  function edRestore(a) {
    var d = null;
    try { d = JSON.parse(window.sessionStorage.getItem(ED_KEY) || 'null'); } catch (e) { d = null; }
    return d && d.sig === edSig(a) && Array.isArray(d.lines) ? d.lines : null;
  }

  /* ---------------------------------------------------------------- connection */

  function phoneNumber() {
    var h = 7;
    var host = CFG.host || 'bbs';
    for (var i = 0; i < host.length; i++) { h = (h * 31 + host.charCodeAt(i)) >>> 0; }
    var s = String(h % 9000000 + 1000000);
    return s.substr(0, 3) + '-' + s.substr(3);
  }

  function intro() {
    phase = 'idle';
    ask = null; ed = null; keybuf = [];
    term.setBaud(0);
    term.reset();
    term.print(col(15) + (L.title || 'WebCarrier Terminal') + col(8) + '  v' + (CFG.version || '') + '\r\n');
    term.print(col(8) + '(C) 2026 ' + (CFG.author || '') + ', AGPL-3.0\r\n');
    term.print(col(8) + '\u2500'.repeat(40) + '\r\n\r\n');
    term.print(col(7) + 'ATZ\r\n' + col(10) + 'OK\r\n\r\n');
    term.print(col(7) + (L.press || 'Press any key to dial') + ' ' + col(11) + (CFG.name || '') + col(7) + ' ...');
    term.showCursor(true);
  }

  function dial() {
    phase = 'dialing';
    term.showCursor(false);
    var num = phoneNumber();
    term.print('\r\n\r\n' + col(7) + 'ATDT ' + num + '\r\n');
    var wait = modemSound(num);
    setTimeout(function () {
      var b = CFG.baud > 0 ? CFG.baud : 115200;
      term.print(col(15) + 'CONNECT ' + b + '/ARQ/V42BIS' + col(7) + '\r\n\r\n');
      csrf = '';
      rtSet('');
      send('start', '', '', takeTarget());
    }, wait);
  }

  /* Reload of the tab during a call: the box shows the last screen and the open prompt again. */
  function resume(rt) {
    phase = 'dialing';
    resuming = true;
    term.showCursor(false);
    term.setBaud(0);
    send('resume', '', rt, takeTarget());
  }

  /* The target goes with one call only. */
  function takeTarget() {
    var t = target;
    target = '';
    return t;
  }

  function send(a, v, rt, dw) {
    pending = true;
    autoSent = a === 'wait';
    ask = null;
    term.showCursor(false);
    var body = { a: a, v: v, csrf: csrf };
    if (rt) { body.rt = rt; }
    if (dw) { body.dw = dw; }
    fetch(CFG.base + 'api.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    }).then(function (r) {
      if (!r.ok) { throw new Error('HTTP ' + r.status); }
      return r.json();
    }).then(handle).catch(function () {
      pending = false;
      term.flush();
      term.print('\r\n' + col(12) + (L.netfail || 'Line error.') + col(7));
      // the call may still be alive on the box: the token stays, so F5 can resume it
      hangup(true);
    });
  }

  function handle(r) {
    pending = false;
    var resumed = resuming && r.resumed === true;
    resuming = false;
    if (r.csrf) { csrf = r.csrf; }
    if (phase === 'dialing') { phase = 'online'; }
    if (r.rt) { rtSet(r.rt); } else if (r.hang || r.resumed === false) { rtSet(''); }
    var baud = typeof r.baud === 'number' ? r.baud : CFG.baud;
    // the restored screen comes at once, the modem speed counts from the next output on
    term.setBaud(resumed ? 0 : baud);
    if (r.idle) { idleSec = r.idle; }
    // idle time goes on over a reload
    if (resumed && typeof r.il === 'number') { lastKey = Date.now() - r.il * 1000; }
    if (r.dl) { document.getElementById('dlframe').src = r.dl; }
    if (r.crt && crt) { crt.set(r.crt); }
    if (r.open) { openExternal(r.open); }
    if (r.notify) { notify(r.notify); }
    term.enqueue(r.o || '');
    var after = function () {
      if (resumed) { term.setBaud(baud); }
      // the answer to an automatic input after wait() does not reset the idle timer, nor does a resumed line
      if (r.hang) {
        hangup();
      } else {
        setAsk(r.ask, autoSent || resumed, resumed);
        if (r.copy) { copyLink(r.copy); }
      }
    };
    if (term.busy()) {
      term.onDrain = function () { term.onDrain = null; after(); };
    } else {
      after();
    }
  }

  function hangup(keepToken) {
    phase = 'offline';
    if (!keepToken) { rtSet(''); }
    ask = null; ed = null; keybuf = [];
    term.showCursor(false);
    term.print('\x1b[0m\r\n' + col(7) + (L.nocarrier || 'NO CARRIER') + '\r\n\r\n' + col(8) + (L.redial || '') + col(7));
  }

  /* fromPoll: the idle timer is only reset by keys, not by polls. resumed: the prompt after a reload. */
  function setAsk(a, fromPoll, resumed) {
    ask = a || null;
    line = '';
    if (!fromPoll) { lastKey = Date.now(); }
    if (!ask) { return; }
    prompt = term.rowPrefix();
    if (ask.t === 'edit') { startEditor(ask, resumed ? edRestore(ask) : null); }
    if (ask.t === 'wait') {
      keybuf = [];
      startWait(ask);
      return;
    }
    term.showCursor(true);
    drainKeys();
  }

  /* wait(): no keys, after ms the terminal sends an empty input by itself */
  function startWait(a) {
    var fire = function () {
      if (phase !== 'online' || ask !== a) { return; }
      if (pending || polling || uploading || term.busy()) { setTimeout(fire, 200); return; }
      ask = null;
      send('wait', '');
    };
    setTimeout(fire, a.ms || 0);
  }

  function drainKeys() {
    while (ask && !pending && !polling && !uploading && !term.busy() && keybuf.length) {
      handleKey(keybuf.shift());
    }
  }

  /* ---------------------------------------------------------------- poll (messages, chat) */

  /* Prompt and typed text again after output that interrupted an input. */
  function redraw() {
    if (!ask) { return; }
    if (ask.t === 'edit' && ed) {
      edPrompt();
      term.print(ed.cur);
    } else {
      term.write(prompt);
      if (ask.t === 'line' || ask.t === 'chat') { term.print(ask.p ? '*'.repeat(line.length) : line); }
    }
    if (ask.t === 'wait') { return; }
    term.showCursor(true);
  }

  function onPoll(r) {
    polling = false;
    if (r.csrf) { csrf = r.csrf; }
    if (phase !== 'online') { return; }
    if (r.notify) { notify(r.notify); }
    var out = r.o || '';
    if (!out && !r.ask && !r.hang) { drainKeys(); return; }
    term.showCursor(false);
    term.write('\r\x1b[K');
    term.enqueue(out);
    var after = function () {
      if (r.hang) { hangup(); return; }
      if (r.ask) { setAsk(r.ask, true); } else { redraw(); }
      drainKeys();
    };
    if (term.busy()) {
      term.onDrain = function () { term.onDrain = null; after(); };
    } else {
      after();
    }
  }

  setInterval(function () {
    if (phase !== 'online' || !ask || pending || polling || uploading || term.busy()) { return; }
    // fp: a prompt that waits for something from the other side (call to the sysop)
    if (Date.now() - lastPoll < (ask.t === 'chat' || ask.fp ? 1500 : 10000)) { return; }
    lastPoll = Date.now();
    polling = true;
    fetch(CFG.base + 'api.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ a: 'poll', v: '', csrf: csrf })
    }).then(function (r) {
      if (!r.ok) { throw new Error('HTTP ' + r.status); }
      return r.json();
    }).then(onPoll).catch(function () { polling = false; });
  }, 500);

  /* ---------------------------------------------------------------- keyboard */

  function keyOf(e) {
    if (e.metaKey) { return null; }
    if (e.key === 'Enter') { return '\r'; }
    if (e.key === 'Backspace') { return '\b'; }
    if (e.key === 'Escape') { return '\x1b'; }
    if (e.key && e.key.length === 1) {
      if (e.ctrlKey && !e.altKey) { return null; }
      return e.key;
    }
    return null;
  }

  function feed(k) {
    lastKey = Date.now();
    idleWarned = false;
    if (phase === 'idle') { ac(); dial(); return; }
    if (phase === 'offline') { dial(); return; }
    if (phase === 'dialing' || uploading) { return; }
    if (term.busy()) {
      if (k === '\x1b') { term.flush(); } else if (keybuf.length < 64) { keybuf.push(k); }
      return;
    }
    if (ask && ask.t === 'wait') { return; }
    if (pending || polling || !ask) {
      if (keybuf.length < 64) { keybuf.push(k); }
      return;
    }
    handleKey(k);
  }

  document.addEventListener('keydown', function (e) {
    // bar with the link: Enter opens it, Esc closes the bar, nothing reaches the terminal
    if (bar && !bar.hidden) {
      if (e.key === 'Escape') {
        e.preventDefault();
        closeBar();
      } else if (e.key === 'Enter') {
        barLink.focus();
      } else if (e.key && e.key.length === 1) {
        e.preventDefault();
      }
      return;
    }
    var k = keyOf(e);
    if (k === null) { return; }
    e.preventDefault();
    feed(k);
  });

  document.addEventListener('paste', function (e) {
    if (phase !== 'online' || !ask || (ask.t !== 'line' && ask.t !== 'edit' && ask.t !== 'chat')) { return; }
    var text = (e.clipboardData && e.clipboardData.getData('text')) || '';
    e.preventDefault();
    text = text.replace(/\r\n?/g, '\n').substr(0, 8000);
    for (var i = 0; i < text.length; i++) {
      var ch = text.charAt(i);
      if (ch === '\n') {
        if (ask && ask.t === 'edit') { handleKey('\r'); } else { break; }
      } else if (ch === '\t') {
        handleKey(' ');
      } else {
        handleKey(ch);
      }
      if (!ask) { break; }
    }
  });

  /* Backspace that also works when the cursor sits at the start of a wrapped line. */
  function bs() {
    if (term.x > 0) {
      term.write('\b \b');
    } else if (term.y > 0) {
      term.y--;
      term.x = COLS - 1;
      term.set(term.y * COLS + term.x, 32, term.attr());
    }
  }

  function handleKey(k) {
    if (!ask || ask.t === 'wait') { return; }
    if (ask.t === 'hot' || ask.t === 'upload') {
      var up = k.length === 1 ? k.toUpperCase() : k;
      if (up === '\b' || up === '\x1b') { return; }
      var keys = ask.k || '';
      if (keys !== '' && keys.indexOf(up) < 0) { return; }
      if (ask.t === 'upload' && up === keys.charAt(0)) {
        upInput.click();
        return;
      }
      var a = ask;
      ask = null;
      term.showCursor(false);
      if (a.c) {
        term.write('\r\x1b[K');
      } else {
        term.print((up === '\r' || up === ' ' ? '' : up) + '\r\n');
      }
      send('in', keys === '' ? '\r' : k);
      return;
    }
    if (ask.t === 'line' || ask.t === 'chat') {
      if (k === '\r') {
        var v = line;
        var chat = ask.t === 'chat';
        line = '';
        ask = null;
        term.showCursor(false);
        if (chat) {
          // own chat line in white
          term.write('\r\x1b[K');
          term.print(col(15) + '> ' + v + col(7));
        }
        term.write('\r\n');
        send('in', v);
        return;
      }
      if (k === '\b') {
        if (line.length) { line = line.slice(0, -1); bs(); }
        return;
      }
      if (k === '\x1b') {
        // only lines that allow it (x): drop the typed text and report an abort
        if (ask.t === 'line' && ask.x) {
          while (line.length) { line = line.slice(0, -1); bs(); }
          ask = null;
          term.showCursor(false);
          term.write('\r\n');
          send('in', '\x1b');
        }
        return;
      }
      if (CP.canType(k) && line.length < (ask.m || 80)) {
        line += k;
        term.print(ask.p ? '*' : k);
      }
      return;
    }
    if (ask.t === 'edit') { edKey(k); }
  }

  /* ---------------------------------------------------------------- line editor */

  function startEditor(a, lines) {
    ed = { lines: (lines || a.l || []).slice(0, a.m || 200), cur: '', w: Math.min(a.w || 74, 74), m: a.m || 200, sig: edSig(a) };
    edSave();
    if (ed.lines.length) { edList(); }
    edPrompt();
  }
  function edSave() {
    if (ed) { edDraft({ sig: ed.sig, lines: ed.lines }); }
  }
  function edPrompt() {
    term.write(col(8) + pad(ed.lines.length + 1, 3) + ': ' + col(7));
  }
  function edList() {
    for (var i = 0; i < ed.lines.length; i++) {
      term.write(col(8) + pad(i + 1, 3) + ': ' + col(7));
      term.print(ed.lines[i]);
      term.write('\r\n');
    }
  }
  function edCommit() {
    if (ed.lines.length >= ed.m) {
      term.print(col(12) + (L.ed_full || 'Full.') + col(7) + '\r\n');
      ed.cur = '';
      edPrompt();
      return false;
    }
    ed.lines.push(ed.cur);
    ed.cur = '';
    edSave();
    return true;
  }
  function edKey(k) {
    if (k === '\x1b') { return; }
    if (k === '\b') {
      if (ed.cur.length) { ed.cur = ed.cur.slice(0, -1); bs(); }
      return;
    }
    if (k === '\r') {
      var u = ed.cur.trim().toUpperCase();
      if (/^\/(S|A|L|C|\?|D\s*\d+)$/.test(u)) {
        ed.cur = '';
        term.write('\r\n');
        if (u === '/S') {
          while (ed.lines.length && ed.lines[ed.lines.length - 1].trim() === '') { ed.lines.pop(); }
          var text = ed.lines.join('\n');
          ed = null;
          edDraft(null);
          ask = null;
          term.showCursor(false);
          term.print(col(10) + (L.ed_saved || 'Saving...') + col(7) + '\r\n');
          send('in', text === '' ? '\x00' : text);
          return;
        }
        if (u === '/A') {
          ed = null;
          edDraft(null);
          ask = null;
          term.showCursor(false);
          term.print(col(12) + (L.ed_aborted || 'Aborted.') + col(7) + '\r\n');
          send('in', '\x00');
          return;
        }
        if (u === '/L') {
          edList();
        } else if (u === '/?') {
          term.print(col(11) + (L.ed_help || '') + col(7) + '\r\n');
        } else if (u.charAt(1) === 'D') {
          var n = parseInt(u.substr(2), 10);
          if (n >= 1 && n <= ed.lines.length) { ed.lines.splice(n - 1, 1); edSave(); }
          edList();
        }
        edPrompt();
        return;
      }
      term.write('\r\n');
      if (edCommit()) { edPrompt(); }
      return;
    }
    if (!CP.canType(k)) { return; }
    if (ed.cur.length >= ed.w) {
      if (ed.lines.length >= ed.m) { return; }
      if (k === ' ') {
        term.write('\r\n');
        if (edCommit()) { edPrompt(); }
        return;
      }
      var sp = ed.cur.lastIndexOf(' ');
      var carry = '';
      if (sp > 0) {
        carry = ed.cur.slice(sp + 1);
        ed.cur = ed.cur.slice(0, sp);
        for (var i = 0; i < carry.length; i++) { bs(); }
      }
      term.write('\r\n');
      if (!edCommit()) { return; }
      edPrompt();
      ed.cur = carry;
      term.print(carry);
    }
    ed.cur += k;
    term.print(k);
  }

  /* ---------------------------------------------------------------- upload */

  upInput.addEventListener('change', function () {
    var f = upInput.files && upInput.files[0];
    if (!f || !ask || ask.t !== 'upload') { upInput.value = ''; return; }
    uploading = true;
    ask = null;
    term.showCursor(false);
    var label = col(7) + (L.uploading || 'Sending') + ' ' + col(15) + f.name + col(7) + ' ';
    term.print('\r\n' + label + '0%');
    var fd = new FormData();
    fd.append('file', f);
    fd.append('csrf', csrf);
    upInput.value = '';
    var x = new XMLHttpRequest();
    x.open('POST', CFG.base + 'upload.php');
    x.upload.onprogress = function (ev) {
      if (ev.lengthComputable) { term.print('\r' + label + Math.floor(ev.loaded * 100 / ev.total) + '%\x1b[K'); }
    };
    x.onload = function () {
      uploading = false;
      var r = null;
      try { r = JSON.parse(x.responseText); } catch (e) { r = null; }
      term.print('\r\n');
      // rej: refused by the check of the box, the reason comes with the answer to \x01
      if (!r || (!r.ok && !r.rej)) { term.print(col(12) + ((r && r.msg) || 'Error') + col(7) + '\r\n'); }
      send('in', '\x01');
    };
    x.onerror = function () {
      uploading = false;
      term.print('\r\n');
      send('in', '\x01');
    };
    x.send(fd);
  });

  /* ---------------------------------------------------------------- idle and leaving */

  setInterval(function () {
    if (phase !== 'online' || !ask || pending || uploading) { return; }
    var left = idleSec * 1000 - (Date.now() - lastKey);
    if (left <= 0) {
      ask = null;
      ed = null;
      send('idle', '');
    } else if (left <= 60000 && idleSec > 90 && !idleWarned && !polling && !term.busy()) {
      // one minute before the line is cut
      idleWarned = true;
      term.showCursor(false);
      term.write('\r\x1b[K');
      term.print(col(12) + (L.idle_warn || '') + col(7) + '\r\n');
      if (term.onBell) { term.onBell(); }
      redraw();
      notify('bye');
    }
  }, 5000);

  /* Keepalive. Editor and uploads don't talk to the server for a while and the node would time out. */
  setInterval(function () {
    var busy = uploading || (ask && Date.now() - lastKey < idleSec * 1000);
    if (phase !== 'online' || pending || !busy) { return; }
    fetch(CFG.base + 'api.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ a: 'ping', v: '', csrf: csrf })
    }).then(function (r) { return r.json(); }).then(function (r) {
      if (r && r.csrf) { csrf = r.csrf; }
    }).catch(function () {});
  }, 60000);

  /* Leaving the page: before the login that is the end of the call. After it the box cannot tell a reload
     from a closed tab, so the node waits a short time for this tab to come back with its token. */
  window.addEventListener('pagehide', function () {
    if (phase === 'online' && navigator.sendBeacon) {
      var body = new Blob([JSON.stringify({ a: rtGet() ? 'away' : 'bye', v: '', csrf: csrf })], { type: 'application/json' });
      navigator.sendBeacon(CFG.base + 'api.php', body);
    }
  });

  var token = rtGet();
  if (token) {
    term.reset();
    resume(token);
  } else {
    intro();
  }
  cv.focus();
}());
