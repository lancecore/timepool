/* TimePool — progressive enhancement: theming, timezone conversion, slot builder, copy, confirm. */
(function () {
  'use strict';

  /* ---- Theme toggle ---- */
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-theme-toggle]');
    if (!t) return;
    var cur = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
    document.documentElement.dataset.theme = cur;
    try { localStorage.setItem('tp-theme', cur); } catch (_) {}
  });

  /* ---- Timezone handling ---- */
  function detectTz() {
    try { return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'; } catch (_) { return 'UTC'; }
  }
  function storedTz() {
    try { return localStorage.getItem('tp-tz'); } catch (_) { return null; }
  }
  var viewerTz = storedTz() || detectTz();

  function tzList() {
    var base = ['UTC','Pacific/Honolulu','America/Anchorage','America/Los_Angeles','America/Denver',
      'America/Chicago','America/New_York','America/Sao_Paulo','Europe/London','Europe/Paris',
      'Europe/Berlin','Europe/Athens','Africa/Lagos','Africa/Johannesburg','Asia/Dubai','Asia/Kolkata',
      'Asia/Bangkok','Asia/Shanghai','Asia/Tokyo','Australia/Sydney','Pacific/Auckland'];
    var all = base;
    try { if (typeof Intl.supportedValuesOf === 'function') all = Intl.supportedValuesOf('timeZone'); } catch (_) {}
    var set = {}; var out = [];
    [viewerTz, detectTz()].concat(all).forEach(function (z) { if (z && !set[z]) { set[z] = 1; out.push(z); } });
    return out;
  }

  function fmtInstant(ms, dur) {
    var d = new Date(ms);
    try {
      var s = new Intl.DateTimeFormat(undefined, {
        weekday: 'short', month: 'short', day: 'numeric',
        hour: 'numeric', minute: '2-digit', timeZone: viewerTz
      }).format(d);
      return s.replace(/,([^,]*)$/, ' ·$1');
    } catch (_) { return d.toUTCString(); }
  }

  function renderTimes() {
    document.querySelectorAll('time.js-time[data-utc]').forEach(function (el) {
      var ms = parseInt(el.getAttribute('data-utc'), 10);
      if (!isNaN(ms)) el.textContent = fmtInstant(ms, el.getAttribute('data-dur'));
    });
  }

  function buildPickers() {
    var pickers = document.querySelectorAll('[data-tz-picker]');
    if (!pickers.length) return;
    var opts = tzList().map(function (z) {
      return '<option value="' + z + '"' + (z === viewerTz ? ' selected' : '') + '>' + z.replace(/_/g, ' ') + '</option>';
    }).join('');
    pickers.forEach(function (sel) {
      var wrap = sel.closest('.tz-pick');
      if (wrap) wrap.hidden = false; // useless without JS, so served hidden
      sel.innerHTML = opts;
      sel.value = viewerTz;
      sel.addEventListener('change', function () {
        viewerTz = sel.value;
        try { localStorage.setItem('tp-tz', viewerTz); } catch (_) {}
        document.querySelectorAll('[data-tz-picker]').forEach(function (s) { s.value = viewerTz; });
        renderTimes();
      });
    });
  }

  /* Auto-fill organizer timezone on the new-poll form. */
  function autoTz() {
    document.querySelectorAll('select[data-tz-select][data-autotz]').forEach(function (sel) {
      var z = detectTz();
      if (!Array.prototype.some.call(sel.options, function (o) { return o.value === z; })) {
        sel.add(new Option(z, z));
      }
      sel.value = z;
    });
  }

  /* ---- Slot builder ---- */
  function syncSlotRow(row) {
    var kind = row.querySelector('[data-slot-kind]');
    if (!kind) return;
    var allday = kind.value === 'date';
    var time = row.querySelector('input[type=time]');
    var dur = row.querySelector('[name="slot_dur[]"]');
    if (time) time.hidden = allday;
    if (dur) dur.hidden = allday;
  }
  function initSlots() {
    var form = document.querySelector('[data-poll-form]');
    if (!form) return;
    var list = form.querySelector('[data-slot-list]');
    var tpl = document.querySelector('[data-slot-template]');
    form.querySelectorAll('[data-slot-row]').forEach(syncSlotRow);

    var addBtn = form.querySelector('[data-slot-add]');
    if (addBtn && tpl) addBtn.addEventListener('click', function () {
      var node = tpl.content.firstElementChild.cloneNode(true);
      list.appendChild(node);
      syncSlotRow(node);
      var di = node.querySelector('input[type=date]'); if (di) di.focus();
    });

    list.addEventListener('click', function (e) {
      var rm = e.target.closest('[data-slot-remove]');
      if (!rm) return;
      var rows = list.querySelectorAll('[data-slot-row]');
      if (rows.length > 1) rm.closest('[data-slot-row]').remove();
      else { rows[0].querySelectorAll('input').forEach(function (i) { i.value = ''; }); }
    });
    list.addEventListener('change', function (e) {
      if (e.target.closest('[data-slot-kind]')) syncSlotRow(e.target.closest('[data-slot-row]'));
    });
  }

  /* ---- Copy to clipboard ---- */
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-copy]');
    if (!b) return;
    var src = document.querySelector('[data-copy-src]');
    if (!src) return;
    var done = function () { var o = b.textContent; b.textContent = 'Copied!'; setTimeout(function () { b.textContent = o; }, 1500); };
    if (navigator.clipboard) navigator.clipboard.writeText(src.value).then(done, function () { src.select(); document.execCommand('copy'); done(); });
    else { src.select(); document.execCommand('copy'); done(); }
  });

  /* ---- Confirm dangerous actions ---- */
  document.addEventListener('submit', function (e) {
    var msg = e.target.getAttribute && e.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) e.preventDefault();
  });

  /* ---- Submit feedback: block double POSTs on slow hosts, show a busy state ---- */
  document.addEventListener('submit', function (e) {
    if (e.defaultPrevented) return;
    var form = e.target;
    if (form.dataset.submitted) { e.preventDefault(); return; }
    form.dataset.submitted = '1';
    form.querySelectorAll('button[type=submit], button:not([type])').forEach(function (b) {
      b.disabled = true;
      b.setAttribute('aria-busy', 'true');
    });
  });
  // Restore forms when a page comes back from the back/forward cache.
  window.addEventListener('pageshow', function (e) {
    if (!e.persisted) return;
    document.querySelectorAll('form[data-submitted]').forEach(function (form) {
      delete form.dataset.submitted;
      form.querySelectorAll('button[aria-busy]').forEach(function (b) {
        b.disabled = false;
        b.removeAttribute('aria-busy');
      });
    });
  });

  /* ---- Init ---- */
  renderTimes();
  buildPickers();
  autoTz();
  initSlots();
})();
