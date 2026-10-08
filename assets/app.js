/*
 * WebCarrier BBS: terminal controller.
 * Dials the box, sends keys and lines to api.php, runs the line editor and file transfers.
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

  var phase = 'idle';      // idle, dialing, online, offline
  var ask = null;          // what the BBS expects next
  var pending = false;     // request running
  var polling = false;     // poll request running
  var lastPoll = 0;
  var prompt = '';         // current prompt line incl. colours, redrawn after poll output
  var uploading = false;
  var csrf = '';
  var idleSec = 300;
  var lastKey = Date.now();
  var keybuf = [];
  var line = '';
  var ed = null;
  var audio = null;
  var COLS = 80;

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
    cv.style.width = Math.floor(w) + 'px';
    cv.style.height = Math.floor(w * 3 / 4) + 'px';
  }
  window.addEventListener('resize', fit);
  fit();

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
      send('start', '');
    }, wait);
  }

  function send(a, v) {
    pending = true;
    ask = null;
    term.showCursor(false);
    fetch(CFG.base + 'api.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ a: a, v: v, csrf: csrf })
    }).then(function (r) {
      if (!r.ok) { throw new Error('HTTP ' + r.status); }
      return r.json();
    }).then(handle).catch(function () {
      pending = false;
      term.flush();
      term.print('\r\n' + col(12) + (L.netfail || 'Line error.') + col(7));
      hangup();
    });
  }

  function handle(r) {
    pending = false;
    if (r.csrf) { csrf = r.csrf; }
    if (phase === 'dialing') { phase = 'online'; }
    term.setBaud(typeof r.baud === 'number' ? r.baud : CFG.baud);
    if (r.idle) { idleSec = r.idle; }
    if (r.dl) { document.getElementById('dlframe').src = r.dl; }
    term.enqueue(r.o || '');
    var after = function () {
      if (r.hang) { hangup(); } else { setAsk(r.ask); }
    };
    if (term.busy()) {
      term.onDrain = function () { term.onDrain = null; after(); };
    } else {
      after();
    }
  }

  function hangup() {
    phase = 'offline';
    ask = null; ed = null; keybuf = [];
    term.showCursor(false);
    term.print('\x1b[0m\r\n' + col(7) + (L.nocarrier || 'NO CARRIER') + '\r\n\r\n' + col(8) + (L.redial || '') + col(7));
  }

  /* fromPoll: the idle timer is only reset by keys, not by polls */
  function setAsk(a, fromPoll) {
    ask = a || null;
    line = '';
    if (!fromPoll) { lastKey = Date.now(); }
    if (!ask) { return; }
    prompt = term.rowPrefix();
    if (ask.t === 'edit') { startEditor(ask); }
    term.showCursor(true);
    drainKeys();
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
    term.showCursor(true);
  }

  function onPoll(r) {
    polling = false;
    if (r.csrf) { csrf = r.csrf; }
    if (phase !== 'online') { return; }
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
    if (Date.now() - lastPoll < (ask.t === 'chat' ? 1500 : 10000)) { return; }
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
    if (phase === 'idle') { ac(); dial(); return; }
    if (phase === 'offline') { dial(); return; }
    if (phase === 'dialing' || uploading) { return; }
    if (term.busy()) {
      if (k === '\x1b') { term.flush(); } else if (keybuf.length < 64) { keybuf.push(k); }
      return;
    }
    if (pending || polling || !ask) {
      if (keybuf.length < 64) { keybuf.push(k); }
      return;
    }
    handleKey(k);
  }

  document.addEventListener('keydown', function (e) {
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
    if (!ask) { return; }
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
      if (k === '\x1b') { return; }
      if (CP.canType(k) && line.length < (ask.m || 80)) {
        line += k;
        term.print(ask.p ? '*' : k);
      }
      return;
    }
    if (ask.t === 'edit') { edKey(k); }
  }

  /* ---------------------------------------------------------------- line editor */

  function startEditor(a) {
    ed = { lines: (a.l || []).slice(0, a.m || 200), cur: '', w: Math.min(a.w || 74, 74), m: a.m || 200 };
    if (ed.lines.length) { edList(); }
    edPrompt();
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
          ask = null;
          term.showCursor(false);
          term.print(col(10) + (L.ed_saved || 'Saving...') + col(7) + '\r\n');
          send('in', text === '' ? '\x00' : text);
          return;
        }
        if (u === '/A') {
          ed = null;
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
          if (n >= 1 && n <= ed.lines.length) { ed.lines.splice(n - 1, 1); }
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
      if (!r || !r.ok) { term.print(col(12) + ((r && r.msg) || 'Error') + col(7) + '\r\n'); }
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
    if (phase === 'online' && ask && !pending && !uploading && Date.now() - lastKey > idleSec * 1000) {
      ask = null;
      ed = null;
      send('idle', '');
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

  window.addEventListener('pagehide', function () {
    if (phase === 'online' && navigator.sendBeacon) {
      var body = new Blob([JSON.stringify({ a: 'bye', v: '', csrf: csrf })], { type: 'application/json' });
      navigator.sendBeacon(CFG.base + 'api.php', body);
    }
  });

  intro();
  cv.focus();
}());
