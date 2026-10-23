/* =========================================================
   FRESHERS PARTY 2026 — Gate Scanner client (scanner/scan.php)
   ---------------------------------------------------------
   - Camera QR scanning via html5-qrcode 2.3.8 (loaded before this file)
   - Verification via POST /api/verify-pass.php (window.FP_SCAN config):
     camera scans send {token}, the manual box sends the student's
     college PNR number {pnr} (e.g. 2425000000) — universal student ID.
   - WebAudio beeps (no audio files), full-screen result overlay,
     manual PNR fallback, 8s per-code cooldown.
   ========================================================= */
(function () {
  'use strict';

  var CFG = window.FP_SCAN || {};
  var TOKEN_RE = /^[a-f0-9]{32}$/i;
  var PNR_RE = /^[0-9]{10,15}$/;
  var COOLDOWN_MS = 15000;   /* ignore the same code within 15 seconds */
  var AUTO_DISMISS_MS = 4000;

  /* ---------- state ---------- */
  var scanner = null;
  var running = false;
  var paused = false;
  var lastToken = '';
  var lastTokenAt = 0;
  var cooldown = {};
  var overlayOpen = false;
  var overlayTimer = null;
  var countdownTimer = null;
  var lastResult = null;

  /* ---------- elements (all guarded) ---------- */
  function byId(id) { return document.getElementById(id); }
  var elPlaceholder = byId('camPlaceholder');
  var elCamError = byId('camError');
  var elSight = byId('qrSight');
  var btnStart = byId('btnStart');
  var btnStartCam = byId('btnStartCam');
  var btnStop = byId('btnStop');
  var manualForm = byId('manualForm');
  var manualInput = byId('manualPnr');
  var btnManual = byId('btnManual');
  var elOverlay = byId('scanOverlay');
  var elCard = byId('scanResultCard');
  var elEmoji = byId('srEmoji');
  var elTitle = byId('srTitle');
  var elSub = byId('srSub');
  var elDetails = byId('srDetails');
  var elCount = byId('srCount');
  var elLastName = byId('lastScanName');
  var elLastResult = byId('lastScanResult');
  var elLastTime = byId('lastScanTime');
  var elToday = byId('todayCount');

  /* =========================================================
     WebAudio beeps — created lazily, resumed on first gesture
     ========================================================= */
  var audioCtx = null;

  function unlockAudio() {
    try {
      if (!audioCtx) {
        var AC = window.AudioContext || window.webkitAudioContext;
        if (!AC) { return; }
        audioCtx = new AC();
      }
      if (audioCtx.state === 'suspended') { audioCtx.resume(); }
    } catch (e) {
      audioCtx = null;
    }
  }

  function tone(freq, ms, type, delaySec) {
    if (!audioCtx) { return; }
    try {
      var t0 = audioCtx.currentTime + (delaySec || 0);
      var osc = audioCtx.createOscillator();
      var amp = audioCtx.createGain();
      osc.type = type || 'sine';
      osc.frequency.setValueAtTime(freq, t0);
      amp.gain.setValueAtTime(0.0001, t0);
      amp.gain.exponentialRampToValueAtTime(0.22, t0 + 0.012);
      amp.gain.exponentialRampToValueAtTime(0.0001, t0 + (ms / 1000));
      osc.connect(amp);
      amp.connect(audioCtx.destination);
      osc.start(t0);
      osc.stop(t0 + (ms / 1000) + 0.06);
    } catch (e) { /* audio is best-effort */ }
  }

  /* success: single 880Hz sine · warning: two 660Hz beeps · error: 220Hz triangle */
  function beep(kind) {
    unlockAudio();
    if (!audioCtx) { return; }
    if (kind === 'success') {
      tone(880, 120, 'sine', 0);
    } else if (kind === 'warning') {
      tone(660, 120, 'sine', 0);
      tone(660, 120, 'sine', 0.22);
    } else {
      tone(220, 350, 'triangle', 0);
    }
  }

  /* resume AudioContext on the very first user gesture (mobile policy) */
  document.addEventListener('pointerdown', unlockAudio, { once: true });
  document.addEventListener('touchstart', unlockAudio, { once: true });

  /* =========================================================
     Time formatting ("Y-m-d H:i:s" DB strings → "10:42 AM")
     ========================================================= */
  function parseDbTime(s) {
    if (!s) { return null; }
    var d = new Date(String(s).replace(' ', 'T'));
    return isNaN(d.getTime()) ? null : d;
  }
  function fmtClock(s) {
    var d = parseDbTime(s);
    if (!d) { return (s || '—'); }
    return d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
  }
  function fmtClockDate(s) {
    var d = parseDbTime(s);
    if (!d) { return (s || '—'); }
    return d.toLocaleDateString('en-US', { day: 'numeric', month: 'short' }) + ', ' +
      d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
  }
  function fmtNow() {
    return new Date().toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
  }

  /* =========================================================
     Camera start / stop
     ========================================================= */
  function setRunning(on) {
    running = on;
    if (btnStart) { btnStart.hidden = on; }
    if (btnStartCam) { btnStartCam.hidden = on; }
    if (btnStop) { btnStop.hidden = !on; }
    if (elPlaceholder) { elPlaceholder.style.display = on ? 'none' : 'flex'; }
    if (elSight) { elSight.style.display = on ? 'flex' : 'none'; }
    if (!on) { hideCamError(); }
  }

  function showCamError(msg) {
    if (elCamError) {
      elCamError.textContent = msg;
      elCamError.hidden = false;
    }
    if (elPlaceholder) { elPlaceholder.style.display = 'flex'; }
  }

  function hideCamError() {
    if (elCamError) {
      elCamError.hidden = true;
      elCamError.textContent = '';
    }
  }

  function camErrorMessage(err) {
    var s = '';
    try { s = String((err && (err.message || err.name || err)) || '').toLowerCase(); } catch (e) { s = ''; }
    if (s.indexOf('permission') !== -1 || s.indexOf('denied') !== -1 || s.indexOf('notallowed') !== -1) {
      return 'Camera permission denied. Allow camera access for this site in your browser settings, then tap START SCANNER again. You can still use manual token entry below.';
    }
    if (s.indexOf('https') !== -1 || s.indexOf('secure') !== -1 || s.indexOf('insecure') !== -1) {
      return 'Camera requires a secure HTTPS connection. Open this page over HTTPS. You can still use manual token entry below.';
    }
    if (s.indexOf('notfound') !== -1 || s.indexOf('no camera') !== -1 || s.indexOf('devices') !== -1) {
      return 'No camera was found on this device. You can still use manual token entry below.';
    }
    return 'Camera unavailable. Allow camera permission and make sure the site uses HTTPS. You can still use manual token entry below.';
  }

  function startScanner() {
    if (running) { return; }
    unlockAudio();
    if (!window.Html5Qrcode) {
      showCamError('Scanner library failed to load. Check your internet connection and reload the page.');
      return;
    }
    hideCamError();
    if (!scanner) {
      try {
        scanner = new window.Html5Qrcode('reader');
      } catch (e) {
        scanner = null;
        showCamError(camErrorMessage(e));
        return;
      }
    }
    var instance = scanner;
    instance
      .start(
        { facingMode: 'environment' },
        { fps: 10, qrbox: { width: 250, height: 250 } },
        onScanSuccess,
        onScanFailure
      )
      .then(function () {
        if (scanner === instance) { setRunning(true); }
      })
      .catch(function (err) {
        if (scanner === instance) { scanner = null; }
        setRunning(false);
        showCamError(camErrorMessage(err));
      });
  }

  function stopScanner() {
    var instance = scanner;
    scanner = null;
    setRunning(false);
    if (!instance) { return; }
    try {
      instance.stop()
        .then(function () { instance.clear(); })
        .catch(function () { /* was not running — nothing to clear */ });
    } catch (e) { /* ignore */ }
  }

  /* per-frame decode errors are noise — silently ignored */
  function onScanFailure() { /* noop */ }

  /* =========================================================
     Code submission (camera token + manual PNR both land here)
     ========================================================= */
  function onScanSuccess(decodedText) {
    var token = String(decodedText || '').trim().toLowerCase();
    if (paused || token === '') { return; }

    var now = Date.now();
    /* same token within 8s → ignore (camera re-reads the same code) */
    if (token === lastToken && (now - lastTokenAt) < COOLDOWN_MS) { return; }
    if (cooldown[token] && (now - cooldown[token]) < COOLDOWN_MS) { return; }

    cooldown[token] = now;
    lastToken = token;
    lastTokenAt = now;
    pruneCooldown(now);

    submitCode('token=' + encodeURIComponent(token));
  }

  function pruneCooldown(now) {
    var keys = Object.keys(cooldown);
    if (keys.length < 64) { return; }
    for (var i = 0; i < keys.length; i++) {
      if (now - cooldown[keys[i]] >= COOLDOWN_MS) { delete cooldown[keys[i]]; }
    }
  }

  function setBusy(on) {
    if (btnManual) { btnManual.disabled = on; }
    if (manualInput) { manualInput.disabled = on; }
  }

  function submitCode(body) {
    paused = true;
    setBusy(true);
    var status = 0;
    fetch(CFG.api, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-CSRF-Token': String(CFG.csrf || '')
      },
      credentials: 'same-origin',
      body: body
    })
      .then(function (res) {
        status = res.status;
        return res.json();
      })
      .then(function (json) {
        if (json && json.csrf) {
          CFG.csrf = json.csrf;
        }
        /* volunteer session expired mid-shift → show overlay then redirect */
        if (status === 401) {
          renderResult({
            ok: false,
            result: 'SESSION_EXPIRED',
            message: 'Volunteer session expired. Redirecting to login...',
            student: null,
            entry: null,
            stats: null
          });
          setTimeout(function () { window.location.assign('login.php'); }, 3000);
          return;
        }
        renderResult(json);
      })
      .catch(function () {
        renderResult({
          ok: false,
          result: 'ERROR',
          message: 'Could not reach the server. Check your internet connection and try again.',
          student: null,
          entry: null,
          stats: null
        });
      })
      .then(function () {
        setBusy(false);
      });
  }

  /* =========================================================
     Result rendering (full-screen overlay)
     ========================================================= */
  function detailRow(label, value) {
    var row = document.createElement('div');
    row.className = 'row';
    var l = document.createElement('div');
    l.className = 'col-5 d-label';
    l.textContent = String(label);
    var v = document.createElement('div');
    v.className = 'col-7 d-value text-end';
    v.textContent = String(value);
    row.appendChild(l);
    row.appendChild(v);
    return row;
  }

  function renderResult(json) {
    var data = (json && typeof json === 'object') ? json : {};
    var result = String(data.result || 'ERROR');
    var student = data.student || null;
    var entry = data.entry || null;
    var stats = data.stats || null;

    /* class: SUCCESS/MANUAL_APPROVED → sr-success; PAYMENT_PENDING/ENTRY_CLOSED
       → sr-warning; everything else (incl. ERROR) → sr-danger */
    var kind = 'danger';
    if (result === 'SUCCESS' || result === 'MANUAL_APPROVED') { kind = 'success'; }
    else if (result === 'PAYMENT_PENDING' || result === 'ENTRY_CLOSED') { kind = 'warning'; }

    var emoji = (kind === 'success') ? '✓' : ((kind === 'warning') ? '⚠' : '✕');
    var title = '';
    var sub = '';
    var details = [];

    if (kind === 'success') {
      title = 'ENTRY APPROVED';
      sub = ((student && student.name) ? student.name : 'Student') +
        ((student && student.pass_type) ? ' · ' + student.pass_type : '');
      if (student && student.pnr) { details.push(['PNR', student.pnr]); }
      details.push(['Student Name', (student && student.name) ? student.name : '—']);
      if (student && student.pass_id) { details.push(['Pass ID', student.pass_id]); }
      if (student) {
        var course = String(student.course || '');
        if (student.year) { course = (course ? course + ' · ' : '') + String(student.year); }
        if (course) { details.push(['Course', course]); }
      }
      if (entry && entry.gate) { details.push(['Gate', entry.gate]); }
      if (entry && entry.time) { details.push(['Time', fmtClock(entry.time)]); }
      var method = (entry && entry.method) ? String(entry.method) : 'QR';
      details.push(['Method', method.toUpperCase() + ' SCAN']);
    } else if (result === 'ALREADY_USED') {
      title = 'ENTRY DENIED';
      sub = 'PASS ALREADY USED';
      if (student && student.pnr) { details.push(['PNR', student.pnr]); }
      if (student && student.name) { details.push(['Student', student.name]); }
      if (entry && entry.first_time) { details.push(['First Entry', fmtClockDate(entry.first_time)]); }
      if (entry && entry.first_gate) { details.push(['First Gate', entry.first_gate]); }
      if (entry && entry.first_by) { details.push(['Scanned By', entry.first_by]); }
    } else if (result === 'INVALID') {
      title = 'INVALID PASS';
      sub = String(data.message || 'This QR code / PNR number is not registered.');
    } else if (result === 'PAYMENT_PENDING') {
      title = 'PAYMENT NOT VERIFIED';
      sub = 'Payment verification is still pending. ENTRY DENIED.';
    } else if (result === 'PAYMENT_REJECTED') {
      title = 'ENTRY DENIED';
      sub = 'Payment was rejected.';
    } else if (result === 'PASS_DISABLED') {
      title = 'ENTRY DENIED';
      sub = 'Pass disabled by organizers.';
    } else if (result === 'PASS_CANCELLED') {
      title = 'ENTRY DENIED';
      sub = 'Pass cancelled by organizers.';
    } else if (result === 'ENTRY_CLOSED') {
      title = 'ENTRY CLOSED';
      sub = 'Entry is currently closed by the event admin.';
    } else if (result === 'SESSION_EXPIRED') {
      title = 'SESSION EXPIRED';
      sub = 'Volunteer session expired. Redirecting to login in 3s...';
    } else {
      title = 'SCAN ERROR';
      sub = String(data.message || 'Something went wrong while verifying. Please try again.');
    }

    /* denied for payment/pass reasons → still show who was denied */
    if ((result === 'PAYMENT_PENDING' || result === 'PAYMENT_REJECTED' ||
         result === 'PASS_DISABLED' || result === 'PASS_CANCELLED') && student) {
      if (student.pnr) { details.push(['PNR', student.pnr]); }
      details.push(['Student', student.name || '—']);
      if (student.pass_id) { details.push(['Pass ID', student.pass_id]); }
    }

    lastResult = {
      result: result,
      kind: kind,
      student: student,
      stats: stats,
      message: String(data.message || '')
    };

    if (elEmoji) { elEmoji.textContent = emoji; }
    if (elTitle) { elTitle.textContent = title; }
    if (elSub) { elSub.textContent = sub; }
    if (elDetails) {
      elDetails.textContent = '';
      for (var i = 0; i < details.length; i++) {
        elDetails.appendChild(detailRow(details[i][0], details[i][1]));
      }
      elDetails.style.display = details.length ? '' : 'none';
    }
    if (elCard) {
      elCard.classList.remove('sr-success', 'sr-danger', 'sr-warning');
      elCard.classList.add(kind === 'success' ? 'sr-success' : (kind === 'warning' ? 'sr-warning' : 'sr-danger'));
    }

    beep(kind);
    if (lastToken) {
      if (kind === 'success') {
        cooldown[lastToken] = Date.now() + 45000; /* don't re-scan approved pass for 45s while student is entering */
      } else if (result === 'ALREADY_USED') {
        cooldown[lastToken] = Date.now() + 25000; /* don't spam ALREADY_USED repeatedly */
      }
    }
    openOverlay();
  }

  /* =========================================================
     Overlay show / dismiss (tap anywhere OR auto after 4s)
     ========================================================= */
  function openOverlay() {
    if (!elOverlay) {
      paused = false;
      updateLastScan(lastResult);
      return;
    }
    overlayOpen = true;
    elOverlay.classList.add('show');
    resetOverlayTimers();
  }

  function resetOverlayTimers() {
    if (overlayTimer) { clearTimeout(overlayTimer); overlayTimer = null; }
    if (countdownTimer) { clearInterval(countdownTimer); countdownTimer = null; }
    var left = Math.max(1, Math.round(AUTO_DISMISS_MS / 1000));
    if (elCount) { elCount.textContent = String(left); }
    countdownTimer = setInterval(function () {
      left -= 1;
      if (elCount) { elCount.textContent = String(Math.max(0, left)); }
    }, 1000);
    overlayTimer = setTimeout(dismissOverlay, AUTO_DISMISS_MS);
  }

  function dismissOverlay() {
    if (!overlayOpen) { return; }
    overlayOpen = false;
    if (overlayTimer) { clearTimeout(overlayTimer); overlayTimer = null; }
    if (countdownTimer) { clearInterval(countdownTimer); countdownTimer = null; }
    if (elOverlay) { elOverlay.classList.remove('show'); }
    paused = false;                       /* camera may scan again */
    updateLastScan(lastResult);
  }

  if (elOverlay) {
    elOverlay.addEventListener('click', dismissOverlay);
  }

  /* =========================================================
     Last scan card + today counter
     ========================================================= */
  function updateLastScan(info) {
    if (!info) { return; }
    if (elLastName) {
      var name = (info.student && info.student.name)
        ? info.student.name
        : (info.result === 'INVALID' ? 'Unknown QR code' : '—');
      elLastName.textContent = name;
    }
    if (elLastResult) {
      elLastResult.textContent = '';
      var cls = 'text-bg-secondary';
      if (info.kind === 'success') { cls = 'text-bg-success'; }
      else if (info.kind === 'warning') { cls = 'text-bg-warning'; }
      else { cls = 'text-bg-danger'; }
      var b = document.createElement('span');
      b.className = 'badge rounded-pill ' + cls + ' fw-semibold';
      b.textContent = String(info.result || '—').replace(/_/g, ' ');
      elLastResult.appendChild(b);
    }
    if (elLastTime) { elLastTime.textContent = fmtNow(); }
    if (elToday && info.stats && typeof info.stats.entered_today === 'number') {
      elToday.textContent = String(info.stats.entered_today);
    }
  }

  /* =========================================================
     Manual PNR fallback (college PNR = universal student ID)
     ========================================================= */
  function submitManual() {
    if (paused) { return; }
    unlockAudio();
    if (!manualInput) { return; }
    var pnr = String(manualInput.value || '').replace(/[\s\-]/g, '');
    if (!PNR_RE.test(pnr)) {
      window.alert('Enter a valid college PNR number (10–15 digits, e.g. 2425000000)');
      manualInput.focus();
      return;
    }
    var now = Date.now();
    if (cooldown['pnr:' + pnr] && (now - cooldown['pnr:' + pnr]) < COOLDOWN_MS) {
      window.alert('This PNR was just checked — wait a few seconds and try again.');
      return;
    }
    cooldown['pnr:' + pnr] = now;
    manualInput.value = '';
    submitCode('pnr=' + encodeURIComponent(pnr));
  }

  /* the button lives inside the form → the submit event covers it.
     (a separate click handler would double-fire on invalid input) */
  if (btnManual && !manualForm) { btnManual.addEventListener('click', submitManual); }
  if (manualForm) {
    manualForm.addEventListener('submit', function (ev) {
      ev.preventDefault();
      submitManual();
    });
  }

  /* =========================================================
     Controls wiring
     ========================================================= */
  if (btnStart) { btnStart.addEventListener('click', startScanner); }
  if (btnStartCam) { btnStartCam.addEventListener('click', startScanner); }
  if (btnStop) { btnStop.addEventListener('click', stopScanner); }

  /* restored from back/forward cache → camera is dead, reset cleanly */
  window.addEventListener('pageshow', function (ev) {
    if (ev.persisted && running) { stopScanner(); }
  });

  /* config sanity (script loaded without scan.php injection) */
  if (!CFG.api || !CFG.csrf) {
    if (elCamError) {
      elCamError.textContent = 'Scanner configuration failed to load. Please reload the page.';
      elCamError.hidden = false;
    }
  }
})();
