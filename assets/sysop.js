/*
 * WebCarrier BBS: live parts of the sysop backend. Every page asks for calls, reports and waiting
 * users (every 15 seconds, every 30 seconds while the tab is in the background), shows the bar with
 * calls, gives a hint in the tab title and runs the chat page. Also switches dependent form fields,
 * starts the daily backup from the overview, computes checksums in steps, previews the BBS ad and
 * copies direct dial links.
 *
 * Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
(function () {
  'use strict';

  // fields with data-needs="id" are only active while the field with that id has a value
  Array.prototype.forEach.call(document.querySelectorAll('[data-needs]'), function (el) {
    var src = document.getElementById(el.getAttribute('data-needs'));
    if (!src) { return; }
    var sync = function () { el.disabled = src.value === ''; };
    src.addEventListener('change', sync);
    sync();
  });

  // buttons with data-copy put a link on the clipboard, without permission it is shown to copy by hand
  Array.prototype.forEach.call(document.querySelectorAll('button[data-copy]'), function (b) {
    b.addEventListener('click', function () {
      var url = b.getAttribute('data-copy');
      var done = function () { b.textContent = b.getAttribute('data-done') || url; };
      var manual = function () { window.prompt(b.getAttribute('data-manual') || '', url); };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(done, manual);
      } else {
        manual();
      }
    });
  });

  /* ---------------------------------------------------------------- preview of the BBS ad */

  var adText = document.getElementById('ad-text');
  var adPrev = document.getElementById('ad-preview');
  if (adText && adPrev) {
    var macros = JSON.parse(adPrev.getAttribute('data-macros') || '{}');
    var fill = function (v, w, al) {
      v = String(v);
      if (!w) { return v; }
      if (v.length >= w) { return v.substr(0, w); }
      var gap = w - v.length;
      if (al === 'R') { return ' '.repeat(gap) + v; }
      if (al === 'C') { return ' '.repeat(Math.floor(gap / 2)) + v + ' '.repeat(gap - Math.floor(gap / 2)); }
      return v + ' '.repeat(gap);
    };
    adText.addEventListener('input', function () {
      adPrev.textContent = adText.value.replace(/@([A-Z]{2,12})(?::(\d{1,2})([LRC]?))?@/g, function (m, n, w, al) {
        return Object.prototype.hasOwnProperty.call(macros, n) ? fill(macros[n], parseInt(w, 10) || 0, al || 'L') : m;
      });
    });
  }

  var live = document.getElementById('live');
  if (!live || !window.fetch || !window.FormData) { return; }
  var cfg = JSON.parse(live.getAttribute('data-cfg') || '{}');
  var L = cfg.L || {};
  var csrf = live.getAttribute('data-csrf') || '';
  var bar = document.getElementById('callbar');
  var baseTitle = document.title;
  var blink = null;
  var blinkText = '';
  var iconLink = null;
  var knownCalls = {};
  var reports = null;
  var timer = null;

  function fmt(s, a) {
    for (var i = 0; i < a.length; i++) { s = s.split('{' + (i + 1) + '}').join(a[i]); }
    return s;
  }

  function post(url, data) {
    var fd = new FormData();
    fd.append('csrf', csrf);
    Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
    return fetch(url, { method: 'POST', credentials: 'same-origin', body: fd }).then(function (r) {
      if (!r.ok) { throw new Error('HTTP ' + r.status); }
      return r.json();
    });
  }

  /* ---------------------------------------------------------------- hint in the tab */

  function setIcon(dot) {
    if (!document.head) { return; }
    var c = document.createElement('canvas');
    c.width = 32; c.height = 32;
    var x = c.getContext('2d');
    x.fillStyle = '#0000aa';
    x.fillRect(0, 0, 32, 32);
    x.fillStyle = '#ffffff';
    x.fillRect(6, 9, 20, 3);
    x.fillRect(6, 15, 14, 3);
    x.fillRect(6, 21, 17, 3);
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

  function hint(text) {
    if (!cfg.notify || !document.hidden) { return; }
    blinkText = '(!) ' + text;
    if (blink) { return; }
    setIcon(true);
    document.title = blinkText;
    blink = setInterval(function () {
      document.title = document.title === blinkText ? baseTitle : blinkText;
    }, 1000);
  }

  function clearHint() {
    if (!blink) { return; }
    clearInterval(blink);
    blink = null;
    document.title = baseTitle;
    setIcon(false);
  }

  /* ---------------------------------------------------------------- status of the board */

  function schedule() {
    clearTimeout(timer);
    timer = setTimeout(status, document.hidden ? 30000 : 15000);
  }

  function status() {
    post(cfg.url, {}).then(function (r) {
      if (!r || r.err) { return; }
      if (bar) {
        bar.innerHTML = r.bar || '';
        bar.hidden = !(r.calls && r.calls.length);
      }
      (r.calls || []).forEach(function (c) {
        if (!knownCalls[c.id]) {
          knownCalls[c.id] = true;
          hint(fmt(L.n_call || '', [c.handle]));
        }
      });
      if (reports !== null && r.reports > reports) { hint(L.n_report || ''); }
      reports = r.reports;
    }).catch(function () { /* next try with the next round */ }).then(schedule);
  }

  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) { clearHint(); }
    schedule();
  });
  if (cfg.notify) { setIcon(false); }

  /* ---------------------------------------------------------------- daily backup, checksums */

  var ab = document.getElementById('autobackup');
  if (ab) {
    var abText = function (cls, text) {
      ab.className = cls;
      ab.firstChild.textContent = text;
    };
    post(ab.getAttribute('data-url'), {}).then(function (r) {
      if (r.state === 'error') {
        abText('flash warn', r.text || ab.getAttribute('data-fail'));
        return;
      }
      abText('flash', r.text || '');
      var last = document.getElementById('auto-last');
      if (last && r.when) { last.textContent = r.when; }
      var old = document.getElementById('autobackup-err');
      if (old) { old.parentNode.removeChild(old); }
    }).catch(function () { abText('flash warn', ab.getAttribute('data-fail')); });
  }

  var hashBtn = document.getElementById('hash-run');
  var hashOut = document.getElementById('hash-progress');
  if (hashBtn && hashOut) {
    hashBtn.addEventListener('click', function (e) {
      e.preventDefault();
      hashBtn.disabled = true;
      hashBtn.textContent = hashBtn.getAttribute('data-busy');
      var step = function () {
        post(hashBtn.getAttribute('data-url'), {}).then(function (r) {
          hashOut.textContent = r.text || '';
          if (!r.err && r.left > 0 && r.done > 0) { step(); return; }
          hashBtn.hidden = true;
        }).catch(function () { hashBtn.disabled = false; });
      };
      step();
    });
  }

  // calls already on the page count as known
  if (bar) {
    Array.prototype.forEach.call(bar.querySelectorAll('button[name=take]'), function (b) { knownCalls[b.value] = true; });
  }
  status();

  /* ---------------------------------------------------------------- chat */

  var chat = document.getElementById('chat');
  if (!chat) { return; }
  var list = document.getElementById('chat-lines');
  var form = document.getElementById('chat-form');
  var input = document.getElementById('chat-in');
  var n = parseInt(chat.getAttribute('data-lines'), 10) || 0;
  var open = chat.getAttribute('data-open') === '1';
  var chatTimer = null;
  // one request after the other, so no line comes twice
  var queue = Promise.resolve();

  function show(r) {
    if (!r || r.err) { return; }
    if (r.html) {
      list.insertAdjacentHTML('beforeend', r.html);
      list.scrollTop = list.scrollHeight;
      if (r.peer) { hint(L.n_chat || ''); }
    }
    n = r.n;
    if (open && !r.open) {
      open = false;
      // the chat ended: show the page again with the note instead of the form
      window.location.reload();
    }
  }

  function chatPoll(data) {
    queue = queue.then(function () {
      var d = data || {};
      d.since = n;
      return post(chat.getAttribute('data-poll'), d).then(show).catch(function () { /* the next round tries again */ });
    });
    return queue;
  }

  function chatSchedule() {
    clearTimeout(chatTimer);
    if (!open) { return; }
    chatTimer = setTimeout(function () { chatPoll().then(chatSchedule); }, document.hidden ? 30000 : 1500);
  }

  if (form && input) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var v = input.value.trim();
      if (!v) { return; }
      input.value = '';
      chatPoll({ line: v }).then(chatSchedule);
    });
  }
  document.addEventListener('visibilitychange', chatSchedule);
  list.scrollTop = list.scrollHeight;
  chatSchedule();
}());
