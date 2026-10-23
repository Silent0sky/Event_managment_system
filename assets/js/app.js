/* =========================================================
   FRESHERS PARTY 2026 — shared client utilities (app.js)
   ========================================================= */
(function () {
  'use strict';

  /* ---------- Auto-dismiss alerts ---------- */
  document.querySelectorAll('.alert-dismissible').forEach(function (a) {
    setTimeout(function () {
      try { bootstrap.Alert.getOrCreateInstance(a).close(); } catch (e) { /* noop */ }
    }, 6500);
  });

  /* ---------- Copy-to-clipboard buttons [data-copy] ---------- */
  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-copy]');
    if (!btn) return;
    ev.preventDefault();
    var text = btn.getAttribute('data-copy') || '';
    var label = btn.querySelector('[data-copy-label]');
    function ok() {
      if (label) { var old = label.textContent; label.textContent = 'Copied!'; setTimeout(function () { label.textContent = old; }, 1800); }
      toastFeedback(btn, 'Copied to clipboard');
    }
    function fail() { toastFeedback(btn, 'Press and hold to copy', true); }
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(ok, function () { legacyCopy(text) ? ok() : fail(); });
    } else {
      legacyCopy(text) ? ok() : fail();
    }
  });
  function legacyCopy(text) {
    try {
      var ta = document.createElement('textarea');
      ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.focus(); ta.select();
      var ok = document.execCommand('copy');
      document.body.removeChild(ta);
      return ok;
    } catch (e) { return false; }
  }
  function toastFeedback(btn, msg, warn) {
    if (btn.dataset.toast === '0') return;
    var t = document.createElement('div');
    t.className = 'copy-toast' + (warn ? ' copy-toast-warn' : '');
    t.textContent = msg;
    document.body.appendChild(t);
    requestAnimationFrame(function () { t.classList.add('show'); });
    setTimeout(function () { t.classList.remove('show'); setTimeout(function () { t.remove(); }, 300); }, 1700);
  }
  if (!document.getElementById('copyToastStyle')) {
    var st = document.createElement('style');
    st.id = 'copyToastStyle';
    st.textContent = '.copy-toast{position:fixed;left:50%;bottom:5.5rem;transform:translateX(-50%) translateY(8px);background:#134e4a;color:#fff;padding:.55rem 1rem;border-radius:999px;font-weight:600;font-size:.85rem;opacity:0;transition:all .25s ease;z-index:3000;box-shadow:0 10px 25px rgba(0,0,0,.25);pointer-events:none;white-space:nowrap}.copy-toast.show{opacity:1;transform:translateX(-50%)}.copy-toast-warn{background:#b45309}';
    document.head.appendChild(st);
  }

  /* ---------- Photo input preview [data-photo-preview] ---------- */
  document.querySelectorAll('input[type="file"][data-photo-preview]').forEach(function (input) {
    input.addEventListener('change', function () {
      var sel = input.getAttribute('data-photo-preview');
      var img = document.querySelector(sel);
      if (!img || !input.files || !input.files[0]) return;
      if (!/^image\/(jpeg|png|webp)$/.test(input.files[0].type)) { img.classList.remove('show'); return; }
      img.src = URL.createObjectURL(input.files[0]);
      img.classList.add('show');
    });
  });

  /* ---------- Admin quick-action forms ----------
     <form data-confirm="message" data-reason-prompt="Question?" data-reason-required="1">
     Confirms with the user; when data-reason-prompt is set, asks for a reason
     (required when data-reason-required="1") and fills [name=reason]. */
  document.querySelectorAll('form[data-confirm], form[data-reason-prompt]').forEach(function (form) {
    form.addEventListener('submit', function (ev) {
      var msg = form.getAttribute('data-confirm') || 'Are you sure?';
      if (!window.confirm(msg)) { ev.preventDefault(); return; }
      var promptQ = form.getAttribute('data-reason-prompt');
      if (promptQ) {
        var required = form.getAttribute('data-reason-required') === '1';
        var reason = window.prompt(promptQ, '');
        if (reason === null) { ev.preventDefault(); return; }
        reason = reason.trim();
        if (required && reason === '') {
          ev.preventDefault();
          window.alert('A reason is required for this action.');
          return;
        }
        var field = form.querySelector('[name="reason"]');
        if (field) field.value = reason;
      }
      var btn = form.querySelector('button[type="submit"]');
      if (btn) { btn.disabled = true; setTimeout(function () { btn.disabled = false; }, 4000); }
    });
  });

  /* ---------- Pass page: QR render + download + print ---------- */
  var qrBox = document.getElementById('qrBox');
  if (qrBox && window.QRCode) {
    var token = qrBox.getAttribute('data-token') || '';
    var size = parseInt(qrBox.getAttribute('data-size') || '240', 10);
    new QRCode(qrBox, {
      text: token,
      width: size,
      height: size,
      colorDark: '#134e4a',
      colorLight: '#ffffff',
      correctLevel: QRCode.CorrectLevel.M
    });
  }

  var btnDownload = document.getElementById('btnDownloadPass');
  if (btnDownload) {
    btnDownload.addEventListener('click', function () {
      var card = document.getElementById('passCard');
      if (!card) return;
      var old = btnDownload.innerHTML;
      btnDownload.disabled = true;
      btnDownload.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Preparing…';
      function restore() { btnDownload.disabled = false; btnDownload.innerHTML = old; }
      if (!window.html2canvas) { window.print(); restore(); return; }
      window.html2canvas(card, { scale: 2, backgroundColor: '#f6f8f9', useCORS: true, logging: false })
        .then(function (canvas) {
          var link = document.createElement('a');
          link.download = (btnDownload.getAttribute('data-filename') || 'fresher-pass') + '.png';
          link.href = canvas.toDataURL('image/png');
          document.body.appendChild(link);
          link.click();
          link.remove();
          restore();
        })
        .catch(function () { window.print(); restore(); });
    });
  }

  var btnPrint = document.getElementById('btnPrintPass');
  if (btnPrint) btnPrint.addEventListener('click', function () { window.print(); });

  /* ---------- UPI app button fallback note ---------- */
  document.querySelectorAll('[data-upi-note]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      setTimeout(function () {
        toastFeedback({ dataset: {} }, 'If no UPI app opened, copy the UPI ID and pay manually.', true);
      }, 1200);
    });
  });

  /* ---------- Relative "auto refresh" timestamp ticker [data-autorefresh-label] ---------- */
  if (document.getElementById('autoRefreshLabel')) {
    setInterval(function () {
      var el = document.getElementById('autoRefreshLabel');
      var t = parseInt(el.getAttribute('data-ts') || '0', 10);
      if (!t) return;
      var s = Math.max(0, Math.floor(Date.now() / 1000) - t);
      el.textContent = s < 5 ? 'just now' : (s < 60 ? s + 's ago' : Math.floor(s / 60) + 'm ago');
    }, 5000);
  }
})();
