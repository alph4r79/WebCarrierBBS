/*
 * WebCarrier BBS: ANSI terminal emulator.
 * 80x25 VGA text mode on a canvas, 9x16 IBM font, CP437, ANSI.SYS escape codes,
 * blinking attributes, emulated modem speed.
 *
 * Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
(function () {
  'use strict';

  var PAL = ['#000000', '#0000AA', '#00AA00', '#00AAAA', '#AA0000', '#AA00AA', '#AA5500', '#AAAAAA',
    '#555555', '#5555FF', '#55FF55', '#55FFFF', '#FF5555', '#FF55FF', '#FFFF55', '#FFFFFF'];
  var ANSI2VGA = [0, 4, 2, 6, 1, 5, 3, 7];
  var COLS = 80, ROWS = 25, CW = 9, CH = 16;

  /* CP437 helpers: unicode text to byte string and back. */
  var UNI = window.CB_CP437 || '';
  var REV = {};
  for (var i = 128; i < UNI.length; i++) { REV[UNI.charAt(i)] = i; }
  for (var j = 1; j < 32; j++) { REV[UNI.charAt(j)] = j; }
  REV['\u2302'] = 127;

  function toCP(s) {
    var out = '';
    for (var k = 0; k < s.length; k++) {
      var c = s.charCodeAt(k);
      if (c < 128) { out += s.charAt(k); }
      else if (REV[s.charAt(k)] !== undefined) { out += String.fromCharCode(REV[s.charAt(k)]); }
      else { out += '?'; }
    }
    return out;
  }
  function canType(ch) {
    var c = ch.charCodeAt(0);
    return ch.length === 1 && ((c >= 32 && c < 127) || (c >= 128 && REV[ch] !== undefined && REV[ch] >= 128));
  }

  function Term(canvas) {
    this.cv = canvas;
    canvas.width = COLS * CW;
    canvas.height = ROWS * CH;
    this.ctx = canvas.getContext('2d', { alpha: false });
    this.ch = new Uint8Array(COLS * ROWS).fill(32);
    this.at = new Uint8Array(COLS * ROWS).fill(7);
    this.dirty = new Uint8Array(COLS * ROWS).fill(1);
    this.x = 0; this.y = 0;
    this.fg = 7; this.bg = 0; this.bold = false; this.blink = false; this.rev = false;
    this.sx = 0; this.sy = 0;
    this.st = 0; this.params = '';
    this.cursorOn = false;
    this.blinkPhase = true;
    this.lastCur = -1;
    this.onBell = null;
    this.onDrain = null;
    this.queue = '';
    this.qpos = 0;
    this.cps = 0;
    this.budget = 0;
    this.lastT = 0;
    this.buildAtlas();
    var self = this;
    setInterval(function () { self.blinkPhase = !self.blinkPhase; self.markBlink(); }, 267);
    var loop = function (t) { self.frame(t); requestAnimationFrame(loop); };
    requestAnimationFrame(loop);
  }

  Term.prototype.buildAtlas = function () {
    var hex = window.CB_FONT || '';
    var font = new Uint8Array(4096);
    for (var b = 0; b < 4096; b++) { font[b] = parseInt(hex.substr(b * 2, 2), 16) || 0; }
    this.atlas = [];
    for (var c = 0; c < 16; c++) {
      var cv = document.createElement('canvas');
      cv.width = 256 * CW; cv.height = CH;
      var cx = cv.getContext('2d');
      var img = cx.createImageData(cv.width, CH);
      var col = PAL[c];
      var r = parseInt(col.substr(1, 2), 16), g = parseInt(col.substr(3, 2), 16), bl = parseInt(col.substr(5, 2), 16);
      for (var gl = 0; gl < 256; gl++) {
        var line = gl >= 0xC0 && gl <= 0xDF;
        for (var row = 0; row < CH; row++) {
          var bits = font[gl * 16 + row];
          for (var px = 0; px < CW; px++) {
            var on = px < 8 ? (bits & (0x80 >> px)) : (line ? (bits & 1) : 0);
            if (!on) { continue; }
            var o = (row * cv.width + gl * CW + px) * 4;
            img.data[o] = r; img.data[o + 1] = g; img.data[o + 2] = bl; img.data[o + 3] = 255;
          }
        }
      }
      cx.putImageData(img, 0, 0);
      this.atlas.push(cv);
    }
  };

  /* ---------------------------------------------------------------- output queue */

  Term.prototype.setBaud = function (baud) {
    this.cps = baud > 0 ? baud / 10 : 0;
  };
  Term.prototype.enqueue = function (s) {
    if (!s) { return; }
    this.queue = this.queue.substr(this.qpos) + s;
    this.qpos = 0;
  };
  Term.prototype.busy = function () {
    return this.qpos < this.queue.length;
  };
  Term.prototype.flush = function () {
    if (this.busy()) { this.write(this.queue.substr(this.qpos)); }
    this.queue = ''; this.qpos = 0;
  };

  Term.prototype.frame = function (t) {
    var dt = this.lastT ? Math.min(100, t - this.lastT) : 16;
    this.lastT = t;
    if (this.busy()) {
      var n;
      if (this.cps <= 0) {
        n = this.queue.length - this.qpos;
      } else {
        this.budget += this.cps * dt / 1000;
        n = Math.floor(this.budget);
        this.budget -= n;
      }
      if (n > 0) {
        var part = this.queue.substr(this.qpos, n);
        this.qpos += part.length;
        this.write(part);
      }
      if (!this.busy()) {
        this.queue = ''; this.qpos = 0; this.budget = 0;
        if (this.onDrain) { var cb = this.onDrain; cb(); }
      }
    }
    this.render();
  };

  /* ---------------------------------------------------------------- screen ops */

  Term.prototype.attr = function () {
    var f = this.fg + (this.bold ? 8 : 0), b = this.bg;
    if (this.rev) { var t = f; f = b; b = t & 7; }
    return (this.blink ? 128 : 0) | (b << 4) | f;
  };
  Term.prototype.blankAttr = function () {
    return ((this.rev ? (this.fg & 7) : this.bg) << 4) | 7;
  };
  Term.prototype.set = function (i, c, a) {
    this.ch[i] = c; this.at[i] = a; this.dirty[i] = 1;
  };
  Term.prototype.fill = function (from, to) {
    var a = this.blankAttr();
    for (var i = from; i < to; i++) { this.set(i, 32, a); }
  };
  Term.prototype.put = function (c) {
    this.set(this.y * COLS + this.x, c, this.attr());
    this.x++;
    if (this.x >= COLS) { this.x = 0; this.lf(); }
  };
  Term.prototype.lf = function () {
    this.y++;
    if (this.y >= ROWS) {
      this.ch.copyWithin(0, COLS);
      this.at.copyWithin(0, COLS);
      this.dirty.fill(1);
      this.fill((ROWS - 1) * COLS, ROWS * COLS);
      this.y = ROWS - 1;
    }
  };
  Term.prototype.clear = function (mode) {
    var cur = this.y * COLS + this.x;
    if (mode === 2) { this.fill(0, COLS * ROWS); this.x = 0; this.y = 0; }
    else if (mode === 1) { this.fill(0, cur + 1); }
    else { this.fill(cur, COLS * ROWS); }
  };
  Term.prototype.eraseLine = function (mode) {
    var s = this.y * COLS;
    if (mode === 2) { this.fill(s, s + COLS); }
    else if (mode === 1) { this.fill(s, s + this.x + 1); }
    else { this.fill(s + this.x, s + COLS); }
  };
  Term.prototype.reset = function () {
    this.fg = 7; this.bg = 0; this.bold = false; this.blink = false; this.rev = false;
    this.clear(2);
  };

  Term.prototype.sgr = function (p) {
    var n = p === '' ? [0] : p.split(';').map(function (v) { return parseInt(v, 10) || 0; });
    for (var i = 0; i < n.length; i++) {
      var v = n[i];
      if (v === 0) { this.fg = 7; this.bg = 0; this.bold = false; this.blink = false; this.rev = false; }
      else if (v === 1) { this.bold = true; }
      else if (v === 2 || v === 22) { this.bold = false; }
      else if (v === 5 || v === 6) { this.blink = true; }
      else if (v === 25) { this.blink = false; }
      else if (v === 7) { this.rev = true; }
      else if (v === 27) { this.rev = false; }
      else if (v >= 30 && v <= 37) { this.fg = ANSI2VGA[v - 30]; }
      else if (v === 39) { this.fg = 7; }
      else if (v >= 40 && v <= 47) { this.bg = ANSI2VGA[v - 40]; }
      else if (v === 49) { this.bg = 0; }
      else if (v >= 90 && v <= 97) { this.fg = ANSI2VGA[v - 90]; this.bold = true; }
      else if (v >= 100 && v <= 107) { this.bg = ANSI2VGA[v - 100]; }
    }
  };

  Term.prototype.csi = function (f, p) {
    var raw = p.replace(/[?=>]/g, '');
    var n = raw.split(';').map(function (v) { return parseInt(v, 10); });
    var a = isNaN(n[0]) ? 0 : n[0];
    var one = a || 1;
    switch (f) {
      case 'm': this.sgr(raw); break;
      case 'H': case 'f':
        this.y = Math.min(ROWS - 1, Math.max(0, (a || 1) - 1));
        this.x = Math.min(COLS - 1, Math.max(0, ((isNaN(n[1]) ? 0 : n[1]) || 1) - 1));
        break;
      case 'A': this.y = Math.max(0, this.y - one); break;
      case 'B': this.y = Math.min(ROWS - 1, this.y + one); break;
      case 'C': this.x = Math.min(COLS - 1, this.x + one); break;
      case 'D': this.x = Math.max(0, this.x - one); break;
      case 'J': this.clear(a); break;
      case 'K': this.eraseLine(a); break;
      case 's': this.sx = this.x; this.sy = this.y; break;
      case 'u': this.x = this.sx; this.y = this.sy; break;
      default: break;
    }
  };

  /** Write a byte string (char codes 0..255 are CP437). */
  Term.prototype.write = function (s) {
    for (var i = 0; i < s.length; i++) {
      var c = s.charCodeAt(i) & 255;
      if (this.st === 0) {
        if (c === 27) { this.st = 1; }
        else if (c === 13) { this.x = 0; }
        else if (c === 10) { this.lf(); }
        else if (c === 8) { if (this.x > 0) { this.x--; } }
        else if (c === 9) { this.x = Math.min(COLS - 1, (this.x + 8) & ~7); }
        else if (c === 7) { if (this.onBell) { this.onBell(); } }
        else if (c === 12) { this.clear(2); }
        else if (c === 0) { /* ignore */ }
        else { this.put(c); }
      } else if (this.st === 1) {
        if (c === 91) { this.st = 2; this.params = ''; } else { this.st = 0; }
      } else {
        if (c >= 0x30 && c <= 0x3F) { this.params += String.fromCharCode(c); }
        else if (c >= 0x40 && c <= 0x7E) { this.csi(String.fromCharCode(c), this.params); this.st = 0; }
        else { this.st = 0; }
      }
    }
  };

  Term.prototype.showCursor = function (on) {
    this.cursorOn = on;
    this.dirty[this.y * COLS + this.x] = 1;
  };

  function sgrOf(a) {
    var f = a & 15, b = (a >> 4) & 7;
    return '\x1b[0;' + (f > 7 ? '1;' : '') + (a & 128 ? '5;' : '') + '3' + ANSI2VGA[f & 7] + ';4' + ANSI2VGA[b] + 'm';
  }

  /** Current row up to the cursor as a byte string with colour codes, ending in the current colour. */
  Term.prototype.rowPrefix = function () {
    var out = '', last = -1, s = this.y * COLS;
    for (var i = 0; i < this.x; i++) {
      var a = this.at[s + i];
      if (a !== last) { out += sgrOf(a); last = a; }
      out += String.fromCharCode(this.ch[s + i]);
    }
    return out + sgrOf(this.attr());
  };

  /** Write unicode text (converted to CP437). */
  Term.prototype.print = function (s) { this.write(toCP(s)); };

  /* ---------------------------------------------------------------- rendering */

  Term.prototype.markBlink = function () {
    for (var i = 0; i < this.at.length; i++) { if (this.at[i] & 128) { this.dirty[i] = 1; } }
    if (this.lastCur >= 0) { this.dirty[this.lastCur] = 1; }
  };

  Term.prototype.render = function () {
    var cur = this.y * COLS + this.x;
    if (cur !== this.lastCur) {
      if (this.lastCur >= 0) { this.dirty[this.lastCur] = 1; }
      this.dirty[cur] = 1;
      this.lastCur = cur;
    }
    var ctx = this.ctx;
    for (var i = 0; i < this.dirty.length; i++) {
      if (!this.dirty[i]) { continue; }
      this.dirty[i] = 0;
      var px = (i % COLS) * CW, py = Math.floor(i / COLS) * CH;
      var a = this.at[i], f = a & 15;
      ctx.fillStyle = PAL[(a >> 4) & 7];
      ctx.fillRect(px, py, CW, CH);
      var c = this.ch[i];
      if (c !== 32 && c !== 0 && !((a & 128) && !this.blinkPhase)) {
        ctx.drawImage(this.atlas[f], c * CW, 0, CW, CH, px, py, CW, CH);
      }
      if (i === cur && this.cursorOn && this.blinkPhase) {
        ctx.fillStyle = PAL[f === ((a >> 4) & 7) ? 7 : f];
        ctx.fillRect(px, py + 13, CW, 2);
      }
    }
  };

  window.CarrierTerm = Term;
  window.CarrierCP = { toCP: toCP, canType: canType };
}());
