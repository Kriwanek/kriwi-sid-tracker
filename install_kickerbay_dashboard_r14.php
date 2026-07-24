<?php
declare(strict_types=1);

@ini_set('display_errors', '1');
@ini_set('display_startup_errors', '1');
@error_reporting(E_ALL);
header('Content-Type: text/html; charset=utf-8');

function kb_r14_h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function kb_r14_backup(string $path): string {
    $stamp = date('Ymd_His');
    $backup = $path . '.backup_r14_' . $stamp;
    if (!@copy($path, $backup)) {
        throw new RuntimeException('Sicherung konnte nicht erstellt werden: ' . $path);
    }
    return $backup;
}

function kb_r14_write(string $path, string $content): void {
    $tmp = $path . '.r14.tmp';
    if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
        throw new RuntimeException('Temporäre Datei konnte nicht geschrieben werden: ' . $tmp);
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Datei konnte nicht ersetzt werden: ' . $path);
    }
}

$root = __DIR__;
$dashboardPath = $root . '/dashboard.php';
$pollPath = $root . '/api/term_poll.php';
$messages = [];
$backups = [];

try {
    if (!is_file($dashboardPath) || !is_readable($dashboardPath) || !is_writable($dashboardPath)) {
        throw new RuntimeException('dashboard.php wurde nicht gefunden oder ist nicht beschreibbar. Die Installationsdatei muss direkt in public_html liegen.');
    }

    $dashboard = (string)file_get_contents($dashboardPath);
    $originalDashboard = $dashboard;

    /* 1. Deutsche Wochentage sowie Start- und Endzeit direkt in PHP erzeugen. */
    $newMetaFunction = <<<'PHPFUNC'
function kb_term_slide_meta(array $ev): array {
  $img = !empty($ev['image_path']) ? (string)$ev['image_path'] : kb_term_default_img((string)($ev['typ'] ?? ''));
  $title = trim((string)($ev['title'] ?? '')) ?: (trim((string)($ev['typ'] ?? '')) ?: 'Termin');
  $loc = trim((string)($ev['location'] ?? ''));
  $date_chip = '';
  $meet_chip = '';
  $tz = new DateTimeZone('Europe/Berlin');
  $st_dt = null;
  $end_dt = null;
  try {
    if (!empty($ev['start_at'])) $st_dt = new DateTimeImmutable((string)$ev['start_at'], $tz);
  } catch (Throwable $e) { $st_dt = null; }
  try {
    if (!empty($ev['end_at']) && (string)$ev['end_at'] !== '0000-00-00 00:00:00') $end_dt = new DateTimeImmutable((string)$ev['end_at'], $tz);
  } catch (Throwable $e) { $end_dt = null; }
  if ($st_dt) {
    $weekdays = [1=>'Mo.',2=>'Di.',3=>'Mi.',4=>'Do.',5=>'Fr.',6=>'Sa.',7=>'So.'];
    $date_chip = ($weekdays[(int)$st_dt->format('N')] ?? '') . ', ' . $st_dt->format('d.m.Y') . ' · ' . $st_dt->format('H:i');
    if ($end_dt && $end_dt > $st_dt) $date_chip .= '–' . $end_dt->format('H:i');
    $date_chip .= ' Uhr';
  }
  if (!empty($ev['meet_time'])) $meet_chip = 'Treffen: ' . substr((string)$ev['meet_time'], 0, 5) . ' Uhr';
  return ['img'=>$img,'title'=>$title,'loc'=>$loc,'date_chip'=>$date_chip,'meet_chip'=>$meet_chip];
}
PHPFUNC;

    $metaPattern = '~function\s+kb_term_slide_meta\s*\(array\s+\$ev\)\s*:\s*array\s*\{.*?\n\}~s';
    if (preg_match($metaPattern, $dashboard)) {
        $dashboard = (string)preg_replace($metaPattern, $newMetaFunction, $dashboard, 1);
        $messages[] = 'Deutsche Wochentage und Endzeit-Anzeige wurden eingebaut.';
    } else {
        $messages[] = 'Hinweis: Datumsfunktion war bereits verändert und wurde nicht überschrieben.';
    }

    /* 2. Anwesenheitswerte für alle drei Termine laden, nicht nur für den ersten. */
    $newAttendanceBoot = <<<'PHPBOOT'
/* Attendance-Initialwerte für alle sichtbaren Slides */
$att_boot = [];
if (!empty($next_terms)) {
  foreach ($next_terms as $bootTerm) {
    $bootId = (int)($bootTerm['id'] ?? 0);
    if ($bootId <= 0) continue;
    try {
      $counts = kb_att_counts($pdo, $bootId) ?: ['yes'=>0,'later'=>0,'sick'=>0,'no'=>0];
      $counts += ['yes'=>0,'later'=>0,'sick'=>0,'no'=>0,'late'=>0];
      if (!empty($counts['late'])) $counts['later'] = (int)$counts['later'] + (int)$counts['late'];
      unset($counts['late']);
      $my = kb_att_user_state($pdo, $bootId, (int)($u['id'] ?? 0));
      $att_boot[$bootId] = ['counts'=>$counts, 'my'=>$my];
    } catch (Throwable $e) {
      $att_boot[$bootId] = ['counts'=>['yes'=>0,'later'=>0,'sick'=>0,'no'=>0], 'my'=>null];
    }
  }
}

PHPBOOT;

    $bootPattern = '~/\*\s*Attendance-Initialwerte.*?\*/\s*\$att_boot\s*=\s*\[\];.*?(?=/\*\s*Events\s*\()~s';
    if (preg_match($bootPattern, $dashboard)) {
        $dashboard = (string)preg_replace($bootPattern, $newAttendanceBoot, $dashboard, 1);
        $messages[] = 'Anwesenheitsstände werden nun für Termin 1 bis 3 geladen.';
    }

    /* 3. Defekten doppelten Script-Block vollständig durch einen sauberen Slider und Attendance-Handler ersetzen. */
    $newNextTermScript = <<<'JSSCRIPT'
<script>
/* Kickerbay Dashboard R14: Termin-Slider und eigene Anwesenheit */
(function(){
  function initNextTerms(){
    const viewport = document.getElementById('kbNextViewport');
    const track = document.getElementById('kbNextTrack');
    const dotsC = document.getElementById('kbNextDots');
    if (!viewport || !track) return;

    const slides = Array.from(track.children).filter(el => el.classList.contains('kb-next-slide'));
    if (!slides.length) return;

    let idx = 0;
    let timer = null;
    const AUTOPLAY_MS = 6500;
    const SWIPE_THRESHOLD = 38;

    function stop(){ if (timer) { clearInterval(timer); timer = null; } }
    function start(){ if (slides.length > 1 && !timer) timer = setInterval(() => go(idx + 1), AUTOPLAY_MS); }
    function mark(){
      if (!dotsC) return;
      Array.from(dotsC.children).forEach((dot, i) => dot.classList.toggle('active', i === idx));
    }
    function go(nextIndex, userAction){
      idx = (nextIndex + slides.length) % slides.length;
      track.style.transform = 'translateX(' + (-idx * 100) + '%)';
      mark();
      refreshSlide(slides[idx]);
      if (userAction) { stop(); start(); }
    }

    if (dotsC) {
      dotsC.innerHTML = '';
      if (slides.length > 1) {
        slides.forEach((_, i) => {
          const dot = document.createElement('button');
          dot.type = 'button';
          dot.className = 'kb-next-dot';
          dot.setAttribute('aria-label', 'Termin ' + (i + 1) + ' anzeigen');
          dot.addEventListener('click', () => go(i, true));
          dotsC.appendChild(dot);
        });
      }
    }

    let dragging = false;
    let startX = 0;
    let currentX = 0;
    function dragStart(x){ dragging = true; startX = x; currentX = x; stop(); }
    function dragMove(x){
      if (!dragging) return;
      currentX = x;
      const width = Math.max(1, viewport.clientWidth);
      const delta = ((currentX - startX) / width) * 100;
      track.style.transform = 'translateX(' + ((-idx * 100) + delta) + '%)';
    }
    function dragEnd(){
      if (!dragging) return;
      const delta = currentX - startX;
      dragging = false;
      if (Math.abs(delta) >= SWIPE_THRESHOLD) go(delta < 0 ? idx + 1 : idx - 1, true);
      else go(idx, true);
    }
    viewport.addEventListener('touchstart', e => dragStart(e.touches[0].clientX), {passive:true});
    viewport.addEventListener('touchmove', e => dragMove(e.touches[0].clientX), {passive:true});
    viewport.addEventListener('touchend', dragEnd);
    viewport.addEventListener('mousedown', e => dragStart(e.clientX));
    viewport.addEventListener('mousemove', e => dragMove(e.clientX));
    viewport.addEventListener('mouseup', dragEnd);
    viewport.addEventListener('mouseleave', () => { if (dragging) dragEnd(); else start(); });
    viewport.addEventListener('mouseenter', stop);
    document.addEventListener('visibilitychange', () => document.hidden ? stop() : start());

    function encodeForm(data){
      return Object.entries(data).map(([key, value]) => encodeURIComponent(key) + '=' + encodeURIComponent(value == null ? '' : value)).join('&');
    }
    function attendanceBox(slide){ return slide ? slide.querySelector('.kb-attend') : null; }
    function savedLabel(box){ return box ? box.querySelector('.kb-att-saved') : null; }
    function setMessage(box, text, error){
      const label = savedLabel(box);
      if (!label) return;
      label.textContent = text || '';
      label.style.color = error ? '#ff9b9b' : '#b9ffd6';
    }
    function setBusy(box, busy){
      if (!box) return;
      box.querySelectorAll('button').forEach(button => button.disabled = !!busy);
      box.dataset.busy = busy ? '1' : '0';
    }
    function setLocked(box, locked){
      if (!box) return;
      box.dataset.locked = locked ? '1' : '0';
      box.querySelectorAll('.kb-att-btn-icon,[data-action="reset-my"]').forEach(button => button.disabled = !!locked);
    }
    function renderCounts(box, counts){
      if (!box || !counts) return;
      ['yes','later','sick','no'].forEach(key => {
        const value = parseInt(counts[key] || 0, 10) || 0;
        box.dataset[key] = String(value);
        const target = box.querySelector('[data-k="' + key + '"]');
        if (target) target.textContent = String(value);
      });
    }
    function renderMy(box, status){
      if (!box) return;
      const normalized = status || '';
      box.dataset.my = normalized;
      box.querySelectorAll('.kb-att-btn-icon').forEach(button => {
        const active = button.dataset.status === normalized;
        button.dataset.active = active ? '1' : '0';
        button.setAttribute('aria-pressed', active ? 'true' : 'false');
      });
    }
    function errorText(code){
      const map = {
        access_denied: 'Keine Freigabe für diese Mannschaft.',
        rsvp_locked: 'Der Zusageschluss ist erreicht.',
        csrf: 'Sitzung abgelaufen – Seite bitte neu laden.',
        auth_required: 'Bitte erneut anmelden.',
        save_failed: 'Speichern fehlgeschlagen.',
        server_error: 'Serverfehler beim Speichern.'
      };
      return map[code] || 'Änderung konnte nicht gespeichert werden.';
    }

    async function refreshBox(box){
      if (!box) return;
      const terminId = parseInt(box.dataset.termId || '0', 10);
      if (!terminId) return;
      try {
        const response = await fetch('/api/term_poll.php?fn=counts&termin_id=' + terminId + '&_=' + Date.now(), {
          credentials:'same-origin', cache:'no-store', headers:{'Accept':'application/json'}
        });
        const data = await response.json().catch(() => null);
        if (!response.ok || !data || !data.ok) throw new Error((data && data.error) || 'counts_failed');
        renderCounts(box, data.counts || {});
        renderMy(box, data.my && typeof data.my === 'object' ? data.my.status : data.my);
        setMessage(box, '', false);
      } catch (error) {
        console.warn('Kickerbay attendance refresh:', error);
      }
    }
    function refreshSlide(slide){ refreshBox(attendanceBox(slide)); }

    async function mutate(box, fn, extra){
      if (!box || box.dataset.busy === '1' || box.dataset.locked === '1') return;
      const terminId = parseInt(box.dataset.termId || '0', 10);
      const csrf = box.dataset.csrf || '';
      if (!terminId || !csrf) { setMessage(box, 'Sitzung abgelaufen – Seite bitte neu laden.', true); return; }
      setBusy(box, true);
      setMessage(box, 'speichert …', false);
      try {
        const payload = Object.assign({termin_id:terminId, csrf:csrf}, extra || {});
        const response = await fetch('/api/term_poll.php?fn=' + encodeURIComponent(fn), {
          method:'POST', credentials:'same-origin', cache:'no-store',
          headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','Accept':'application/json'},
          body:encodeForm(payload)
        });
        const data = await response.json().catch(() => null);
        if (!response.ok || !data || !data.ok) {
          const code = data && data.error ? data.error : 'save_failed';
          if (code === 'rsvp_locked') setLocked(box, true);
          throw new Error(code);
        }
        renderCounts(box, data.counts || {});
        if (fn === 'set') renderMy(box, data.my && typeof data.my === 'object' ? data.my.status : payload.status);
        if (fn === 'reset_my') renderMy(box, '');
        if (fn === 'reset_all') renderMy(box, '');
        setMessage(box, '✓ gespeichert', false);
      } catch (error) {
        const code = error && error.message ? error.message : 'save_failed';
        setMessage(box, errorText(code), true);
        await refreshBox(box);
      } finally {
        setBusy(box, false);
        if (box.dataset.locked === '1') setLocked(box, true);
      }
    }

    viewport.addEventListener('click', event => {
      const button = event.target.closest('.kb-att-btn-icon,[data-action="reset-my"],[data-action="reset-all"]');
      if (!button) return;
      const box = button.closest('.kb-attend');
      if (!box) return;
      event.preventDefault();
      event.stopPropagation();
      if (button.classList.contains('kb-att-btn-icon')) mutate(box, 'set', {status:button.dataset.status || ''});
      else if (button.dataset.action === 'reset-my') mutate(box, 'reset_my', {});
      else if (button.dataset.action === 'reset-all') mutate(box, 'reset_all', {});
    });

    slides.forEach(slide => refreshBox(attendanceBox(slide)));
    go(0, false);
    start();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initNextTerms, {once:true});
  else initNextTerms();
})();
</script>

JSSCRIPT;

    $jsPattern = '~<script>\s*(?:<script>\s*)?/\*\s*NEXT TERM Slider.*?</script>\s*(?=<script>\s*\(function\(\)\{\s*const box\s*=\s*document\.querySelector\(\x27\[data-kb-lineup-release\]\x27\))~s';
    if (preg_match($jsPattern, $dashboard)) {
        $dashboard = (string)preg_replace($jsPattern, $newNextTermScript, $dashboard, 1);
        $messages[] = 'Der defekte doppelte Script-Block wurde entfernt.';
    } else {
        $nextMarker = strpos($dashboard, '/* NEXT TERM Slider');
        $lineupMarker = strpos($dashboard, "const box = document.querySelector('[data-kb-lineup-release]');", $nextMarker === false ? 0 : $nextMarker);
        if ($nextMarker !== false && $lineupMarker !== false) {
            $before = substr($dashboard, 0, $nextMarker);
            $scriptStart = strrpos($before, '<script>');
            $lineupBefore = substr($dashboard, 0, $lineupMarker);
            $lineupScriptStart = strrpos($lineupBefore, '<script>');
            if ($scriptStart !== false && $lineupScriptStart !== false && $lineupScriptStart > $scriptStart) {
                $dashboard = substr($dashboard, 0, $scriptStart) . $newNextTermScript . substr($dashboard, $lineupScriptStart);
                $messages[] = 'Der Termin-Slider wurde über die Sicherheitsroutine repariert.';
            }
        }
    }

    /* Trainer und Betreuer dürfen Reset alle sehen; normale Mitglieder weiterhin nicht. */
    $oldResetCondition = '<?php if ($is_admin || $is_teamlead): ?>';
    $newResetCondition = "<?php if (\$is_admin || \$is_teamlead || in_array(\$role_lc, ['coach','trainer'], true) || in_array(\$membership_role, ['team_lead','coach','trainer','co_trainer','cotrainer','betreuer','staff'], true)): ?>";
    if (str_contains($dashboard, $oldResetCondition)) {
        $dashboard = str_replace($oldResetCondition, $newResetCondition, $dashboard);
    }

    if ($dashboard !== $originalDashboard) {
        $backups[] = kb_r14_backup($dashboardPath);
        kb_r14_write($dashboardPath, $dashboard);
        $messages[] = 'dashboard.php wurde erfolgreich gespeichert.';
    } else {
        $messages[] = 'dashboard.php war bereits repariert oder die erwarteten Stellen wurden nicht gefunden.';
    }

    /* 4. API: aktive und bestätigte Mitgliedschaften gleichberechtigt zulassen. */
    if (is_file($pollPath) && is_readable($pollPath) && is_writable($pollPath)) {
        $poll = (string)file_get_contents($pollPath);
        $originalPoll = $poll;
        $poll = str_replace(
            "(status='approved' OR status IS NULL OR status='')",
            "(status IN ('approved','active','accepted','enabled') OR status IS NULL OR status='')",
            $poll
        );
        if ($poll !== $originalPoll) {
            $backups[] = kb_r14_backup($pollPath);
            kb_r14_write($pollPath, $poll);
            $messages[] = 'Die Anwesenheits-API akzeptiert nun alle aktiven Teammitglieder.';
        }
    }

    $ok = true;
} catch (Throwable $e) {
    $ok = false;
    $messages[] = 'FEHLER: ' . $e->getMessage();
}

?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Kickerbay Dashboard Fix R14</title>
<style>
body{margin:0;background:#07101b;color:#eef6ff;font:16px/1.5 system-ui,-apple-system,Segoe UI,sans-serif;display:grid;place-items:center;min-height:100vh;padding:24px;box-sizing:border-box}.card{width:min(760px,100%);background:#101a28;border:1px solid #36506d;border-radius:18px;padding:28px;box-shadow:0 20px 60px #0008}h1{margin-top:0}.ok{color:#7fffb5}.bad{color:#ff9b9b}li{margin:8px 0}code{background:#07101b;padding:3px 7px;border-radius:6px}.btn{display:inline-block;margin-top:16px;padding:11px 16px;border-radius:10px;background:#1f6feb;color:white;text-decoration:none;font-weight:700}.warn{margin-top:20px;padding:14px;border-radius:10px;background:#4d3715;border:1px solid #9b732d}
</style>
</head>
<body><main class="card">
<h1 class="<?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? '✓ Kickerbay R14 installiert' : '✕ Installation fehlgeschlagen' ?></h1>
<ul><?php foreach ($messages as $message): ?><li><?= kb_r14_h($message) ?></li><?php endforeach; ?></ul>
<?php if ($backups): ?><p>Sicherungen:</p><ul><?php foreach ($backups as $backup): ?><li><code><?= kb_r14_h(basename($backup)) ?></code></li><?php endforeach; ?></ul><?php endif; ?>
<?php if ($ok): ?><a class="btn" href="/dashboard.php?kb_r14=<?= time() ?>">Dashboard mit frischem Cache öffnen</a><?php endif; ?>
<div class="warn">Nach erfolgreicher Prüfung diese Installationsdatei aus <code>public_html</code> löschen.</div>
</main></body></html>
