/* Kickerbay Dashboard-Terminfix R13
 * - deutsche Wochentage
 * - Endzeit/Uhr-Zusatz
 * - verständliche Rückmeldung bei gesperrter Anwesenheit
 */
(function () {
  'use strict';

  var path = String(window.location.pathname || '').toLowerCase();
  var isDashboard = path === '/' || path.endsWith('/dashboard.php') || path.endsWith('/dashboard');
  if (!isDashboard) return;

  var days = {
    'Sun': 'So.', 'Mon': 'Mo.', 'Tue': 'Di.', 'Wed': 'Mi.',
    'Thu': 'Do.', 'Fri': 'Fr.', 'Sat': 'Sa.',
    'So': 'So.', 'Mo': 'Mo.', 'Di': 'Di.', 'Mi': 'Mi.',
    'Do': 'Do.', 'Fr': 'Fr.', 'Sa': 'Sa.'
  };

  function germanizeChip(chip) {
    if (!chip) return;
    var text = String(chip.textContent || '').trim();
    if (!text) return;

    text = text.replace(/^(Sun|Mon|Tue|Wed|Thu|Fri|Sat|So|Mo|Di|Mi|Do|Fr|Sa)\.?\s*,?\s*/,
      function (_, day) { return (days[day] || day) + ', '; });

    if (/^Treffen:\s*\d{1,2}:\d{2}$/i.test(text)) {
      text += ' Uhr';
    } else if (/\d{1,2}:\d{2}(?:\s*[–-]\s*\d{1,2}:\d{2})?$/.test(text) && !/\bUhr$/i.test(text)) {
      text += ' Uhr';
    }

    chip.textContent = text;
  }

  function updateDateChips(root) {
    (root || document).querySelectorAll('.kb-next-term__chip').forEach(germanizeChip);
  }

  function attendanceBox(termId) {
    return document.querySelector('.kb-attend[data-term-id="' + String(termId || '') + '"]');
  }

  function messageFor(error) {
    switch (error) {
      case 'rsvp_locked': return 'Zusageschluss abgelaufen';
      case 'access_denied': return 'Keine Berechtigung für diese Mannschaft';
      case 'csrf': return 'Sitzung abgelaufen – Seite neu laden';
      case 'auth_required': return 'Bitte erneut anmelden';
      default: return 'Speichern nicht möglich';
    }
  }

  function showAttendanceMessage(termId, text, isError) {
    var box = attendanceBox(termId);
    if (!box) return;
    var out = box.querySelector('.kb-att-saved');
    if (!out) return;
    out.textContent = text;
    out.style.color = isError ? '#ffb4b4' : '#d9ffe2';
  }

  function lockAttendance(termId) {
    var box = attendanceBox(termId);
    if (!box) return;
    box.querySelectorAll('.kb-att-btn-icon,[data-action="reset-my"]').forEach(function (btn) {
      btn.disabled = true;
      btn.setAttribute('aria-disabled', 'true');
      btn.style.opacity = '.45';
      btn.style.cursor = 'not-allowed';
    });
  }

  var originalFetch = window.fetch ? window.fetch.bind(window) : null;
  if (originalFetch) {
    window.fetch = async function (input, init) {
      var response = await originalFetch(input, init);
      try {
        var url = typeof input === 'string' ? input : String(input && input.url || '');
        if (url.indexOf('/api/term_poll.php') !== -1) {
          var copy = response.clone();
          var data = await copy.json().catch(function () { return null; });
          var body = String(init && init.body || '');
          var match = body.match(/(?:^|&)termin_id=([^&]+)/);
          var termId = match ? decodeURIComponent(match[1]) : '';

          if (data && data.ok) {
            showAttendanceMessage(termId, '✓ gespeichert', false);
          } else if (data && data.error) {
            showAttendanceMessage(termId, messageFor(data.error), true);
            if (data.error === 'rsvp_locked') lockAttendance(termId);
            window.setTimeout(function () { window.location.reload(); }, 900);
          }
        }
      } catch (ignore) {}
      return response;
    };
  }

  function start() {
    updateDateChips(document);
    var observer = new MutationObserver(function (mutations) {
      mutations.forEach(function (m) {
        m.addedNodes.forEach(function (node) {
          if (node && node.nodeType === 1) updateDateChips(node);
        });
      });
    });
    observer.observe(document.body, { childList: true, subtree: true });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start, { once: true });
  } else {
    start();
  }
})();
