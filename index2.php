<?php
// index.php — Aplikasi Alarm Modern (semua fitur: dark mode, default sound, weekdays, animations, responsive)
$DATA_FILE = __DIR__ . '/alarms.json';
$UPLOAD_DIR = __DIR__ . '/uploads';
if (!file_exists($UPLOAD_DIR)) mkdir($UPLOAD_DIR, 0755, true);

function load_alarms() {
    global $DATA_FILE;
    if (!file_exists($DATA_FILE)) return [];
    $s = file_get_contents($DATA_FILE);
    $a = json_decode($s, true);
    return is_array($a) ? $a : [];
}

function save_alarms($alarms) {
    global $DATA_FILE;
    file_put_contents($DATA_FILE, json_encode($alarms, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

$action = $_REQUEST['action'] ?? '';
if ($action === 'list') {
    header('Content-Type: application/json');
    echo json_encode(load_alarms());
    exit;
}

if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $label = trim($_POST['label'] ?? 'Alarm');
    $datetime = $_POST['datetime'] ?? '';
    $recurring = isset($_POST['recurring']);
    $use_tts = isset($_POST['use_tts']);
    $tts_text = trim($_POST['tts_text'] ?? 'Waktu Anda telah tiba');
    $loop_count = max(1, intval($_POST['loop_count'] ?? 1));
    // weekdays: array of numbers 0 (Sun) - 6 (Sat)
    $weekdays = [];
    if (isset($_POST['weekday']) && is_array($_POST['weekday'])) {
        // sanitize to ints 0-6
        foreach ($_POST['weekday'] as $w) {
            $i = intval($w);
            if ($i >= 0 && $i <= 6) $weekdays[] = $i;
        }
    }
    $audio_file = null;
    if (!$use_tts && isset($_FILES['audio']) && $_FILES['audio']['error'] === UPLOAD_ERR_OK) {
        $tmp = $_FILES['audio']['tmp_name'];
        $name = basename($_FILES['audio']['name']);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['mp3', 'wav', 'ogg', 'm4a'])) {
            echo json_encode(['ok' => false, 'error' => 'Format file tidak didukung.']);
            exit;
        }
        $newname = uniqid('a_') . '.' . $ext;
        $dest = $UPLOAD_DIR . '/' . $newname;
        if (!move_uploaded_file($tmp, $dest)) {
            echo json_encode(['ok' => false, 'error' => 'Gagal upload file.']);
            exit;
        }
        $audio_file = 'uploads/' . $newname;
    }

    $alarms = load_alarms();
    $id = uniqid('al_');
    $alarms[$id] = [
        'id' => $id,
        'label' => $label,
        'datetime' => $datetime,
        'recurring' => $recurring,
        'weekdays' => $weekdays, // [] atau [1,2,3,4,5]
        'use_tts' => $use_tts,
        'tts_text' => $tts_text,
        'audio_file' => $audio_file,
        'loop_count' => $loop_count,
        'enabled' => true,
        'created_at' => date(DATE_ATOM)
    ];
    save_alarms($alarms);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'alarm' => $alarms[$id]]);
    exit;
}

if ($action === 'delete' && isset($_POST['id'])) {
    $id = $_POST['id'];
    $alarms = load_alarms();
    if (isset($alarms[$id])) {
        if (!empty($alarms[$id]['audio_file'])) {
            $path = __DIR__ . '/' . $alarms[$id]['audio_file'];
            if (file_exists($path)) @unlink($path);
        }
        unset($alarms[$id]);
        save_alarms($alarms);
    }
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Aplikasi Alarm — Modern UI (All Features)</title>

<!-- Bootstrap 5 CSS -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
:root {
  --accent: #0078ff;
  --card-radius: 12px;
  --card-shadow: 0 6px 18px rgba(0,0,0,.06);
  --bg-light: #f3f7fb;
  --glass: rgba(255,255,255,0.98);
}

/* Dark theme vars (will be toggled by .dark on body) */
body { --bg: linear-gradient(135deg,#eef6ff,#ffffff); }
body.dark { --bg: linear-gradient(135deg,#0b1220,#0f1724); color: #e6eef8; }
body.dark .app-card { background: rgba(18,24,33,0.7); border-color: rgba(255,255,255,0.04); }
body.dark .alarm-item { background: rgba(255,255,255,0.02); border-color: rgba(255,255,255,0.04); }
body {
  font-family: "Inter", system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial;
  background: var(--bg);
  margin: 0;
  padding: 12px 0;
}

/* Navbar / header */
.navbar-custom {
  background: transparent;
  padding: 0.3rem 0;
}

/* App card style */
.app-card {
  background: var(--glass);
  border-radius: var(--card-radius);
  box-shadow: var(--card-shadow);
  padding: 18px;
  border: 1px solid rgba(0,0,0,0.04);
}

/* small UI tweaks */
.app-title { font-weight: 600; text-align:center; margin: 8px 0 18px; }
.form-label { font-weight: 600; }
.alarm-item {
  padding: 12px;
  border-radius: 10px;
  border: 1px solid rgba(0,0,0,0.03);
  margin-bottom: 12px;
  background: #fbfdff;
  transition: box-shadow .25s, transform .15s;
}
body.dark .alarm-item { background: rgba(255,255,255,0.03); }

/* active animation glow */
.alarm-active {
  box-shadow: 0 0 0 6px rgba(0,120,255,0.12), 0 6px 20px rgba(0,120,255,0.18);
  transform: translateY(-4px);
  animation: pulse 1s infinite;
}
@keyframes pulse {
  0% { box-shadow: 0 0 0 0 rgba(0,120,255,0.18); }
  70% { box-shadow: 0 0 0 12px rgba(0,120,255,0.06); }
  100% { box-shadow: 0 0 0 0 rgba(0,120,255,0.0); }
}

/* Make alarms list scrollable on tall list */
.alarms-list { max-height: 520px; overflow: auto; padding-right: 6px; }

/* Dark-mode toggle style */
.theme-toggle { cursor: pointer; border: none; background: none; color: var(--accent); }

/* Toast positioning */
#appToast { position: fixed; top: 16px; right: 16px; z-index: 1200; }

/* responsive tweaks */
@media (max-width: 575.98px) {
  .alarms-list { max-height: 360px; }
}
</style>
</head>
<body>
<!-- Navbar with dark mode toggle -->
<nav class="navbar navbar-expand-lg navbar-custom">
  <div class="container px-4">
    <a class="navbar-brand" href="#"><span style="font-size:1.2rem">🔔</span> <strong>Aplikasi Alarm Modern</strong></a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navCollapse">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navCollapse">
      <ul class="navbar-nav ms-auto align-items-center">
        <li class="nav-item me-2">
          <button id="darkToggle" class="btn theme-toggle" title="Toggle Dark Mode">🌙</button>
        </li>
        <li class="nav-item">
          <small class="text-muted">Browser must be open for alarms</small>
        </li>
      </ul>
    </div>
  </div>
</nav>

<div class="container px-4">
  <div class="app-title">
    <h1 class="h4">Aplikasi Alarm Modern</h1>
  </div>

  <!-- two-column layout with gutters (Bootstrap) -->
  <div class="row gx-5">
    <!-- left: form -->
    <div class="col-12 col-md-6">
      <div class="app-card">
        <h2 class="h6 text-primary">Tambah Alarm</h2>
        <form id="formAdd" method="post" enctype="multipart/form-data" action="?action=add" class="mt-3">
          <div class="mb-3">
            <label class="form-label">Label</label>
            <input class="form-control" type="text" name="label" value="Alarm" required>
          </div>

          <div class="mb-3">
            <label class="form-label">Waktu</label>
            <input class="form-control" type="datetime-local" name="datetime" required>
          </div>

          <div class="mb-3 form-check">
            <input class="form-check-input" type="checkbox" name="recurring" id="recurring">
            <label class="form-check-label" for="recurring">Ulang setiap hari (daily recurring)</label>
          </div>

          <div class="mb-3">
            <label class="form-label">Penjadwalan Mingguan (pilih hari aktif)</label>
            <div class="d-flex flex-wrap gap-2">
              <!-- weekdays 0 = Sun ... 6 = Sat -->
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="weekday[]" id="w0" value="0">
                <label class="form-check-label" for="w0">Ming</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="weekday[]" id="w1" value="1">
                <label class="form-check-label" for="w1">Sen</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="weekday[]" id="w2" value="2">
                <label class="form-check-label" for="w2">Sel</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="weekday[]" id="w3" value="3">
                <label class="form-check-label" for="w3">Rab</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="weekday[]" id="w4" value="4">
                <label class="form-check-label" for="w4">Kam</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="weekday[]" id="w5" value="5">
                <label class="form-check-label" for="w5">Jum</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="weekday[]" id="w6" value="6">
                <label class="form-check-label" for="w6">Sab</label>
              </div>
            </div>
            <div class="form-text">Jika tidak memilih hari => tidak dipakai / artinya alarm tdk terbatas hari tertentu.</div>
          </div>

          <div class="mb-3">
            <label class="form-label">Jumlah Pengulangan Suara (loop)</label>
            <input class="form-control" type="number" name="loop_count" value="1" min="1" max="100">
          </div>

          <hr>

          <div class="mb-3 form-check">
            <input class="form-check-input" type="checkbox" name="use_tts" id="use_tts">
            <label class="form-check-label" for="use_tts">Gunakan suara TTS (browser)</label>
          </div>

          <div id="tts_area" style="display:none" class="mb-3">
            <label class="form-label">Teks untuk TTS</label>
            <input class="form-control" type="text" name="tts_text" value="Waktu Anda telah tiba">
          </div>

          <div id="upload_area" class="mb-3">
            <label class="form-label">Atau upload file suara (mp3/wav/ogg/m4a)</label>
            <input class="form-control" type="file" name="audio" accept="audio/*">
            <div class="form-text">Jika kosong dan tidak pakai TTS, akan memakai suara default internal.</div>
          </div>

          <div class="d-grid">
            <button class="btn btn-primary btn-lg" type="submit">💾 Simpan Alarm</button>
          </div>
        </form>
      </div>
    </div>

    <!-- right: list -->
    <div class="col-12 col-md-6">
      <div class="app-card h-100 d-flex flex-column">
        <h2 class="h6 text-primary">Daftar Alarm</h2>

        <div id="list" class="alarms-list mt-3">
          <!-- rendered by JS -->
        </div>

        <div class="mt-3 d-flex justify-content-between align-items-center">
          <div id="pageInfo" class="text-muted small"></div>
          <div class="pagination-controls"></div>
        </div>
      </div>
    </div>
  </div>

  <div class="text-center text-muted small mt-4">© 2025 Aplikasi Alarm — Dibuat dengan PHP + JS</div>
</div>

<!-- Toast container -->
<div id="appToast" aria-live="polite" aria-atomic="true"></div>

<!-- Bootstrap bundle (includes JS) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
/* --- Utilities & state --- */
async function fetchAlarms(){ const res = await fetch('?action=list'); return await res.json(); }
function formatDateLocal(dstr){ if(!dstr) return '-'; try{ return new Date(dstr).toLocaleString(); } catch(e){ return dstr; } }
function escapeHtml(s){ if(!s) return ''; return s.replace(/[&<"'>]/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[m]); }

let currentPage = 1;
const ITEMS_PER_PAGE = 10;

/* --- Dark mode toggle --- */
const darkToggle = document.getElementById('darkToggle');
function setDarkMode(on){
  if(on) { document.body.classList.add('dark'); darkToggle.textContent = '☀️'; }
  else { document.body.classList.remove('dark'); darkToggle.textContent = '🌙'; }
  localStorage.setItem('alarm_dark', on? '1' : '0');
}
darkToggle.addEventListener('click', ()=> setDarkMode(!document.body.classList.contains('dark')));
if(localStorage.getItem('alarm_dark') === '1') setDarkMode(true);

/* --- Default beep using Web Audio API --- */
const audioCtx = (typeof window.AudioContext !== 'undefined') ? new AudioContext() : null;
function playDefaultBeep(loopCount=1, onEnd=null){
  if(!audioCtx){
    // fallback to TTS if no WebAudio
    speakLoop('Waktu Anda telah tiba', loopCount);
    if(onEnd) setTimeout(onEnd, 1000*loopCount);
    return;
  }
  let count = 0;
  function playOnce(){
    const o = audioCtx.createOscillator();
    const g = audioCtx.createGain();
    o.type = 'sine';
    o.frequency.value = 880; // beep freq
    g.gain.setValueAtTime(0, audioCtx.currentTime);
    g.gain.linearRampToValueAtTime(0.18, audioCtx.currentTime + 0.01);
    g.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime + 0.6);
    o.connect(g); g.connect(audioCtx.destination);
    o.start();
    setTimeout(()=>{ try{o.stop();}catch(e){}; count++; if(count < loopCount) playOnce(); else if(onEnd) onEnd(); }, 700);
  }
  // resume ctx if suspended
  if(audioCtx.state === 'suspended') audioCtx.resume().then(playOnce).catch(playOnce);
  else playOnce();
}

//* --- TTS looping (male voice, human-like, slower speed) --- */
function speakLoop(text, loopCount = 1, onEnd = null) {
  if (!('speechSynthesis' in window)) {
    console.log('TTS not supported');
    playDefaultBeep(loopCount, onEnd);
    return;
  }

  // Ambil daftar suara yang tersedia
  let voices = window.speechSynthesis.getVoices();

  // Jika belum siap, tunggu sampai daftar suara dimuat
  if (voices.length === 0) {
    window.speechSynthesis.onvoiceschanged = () => speakLoop(text, loopCount, onEnd);
    return;
  }

  // Pilih suara laki-laki paling natural
  const preferredVoice = voices.find(v =>
    /id-ID/i.test(v.lang) && /male|laki|Google|natural/i.test(v.name)
  ) || voices.find(v =>
    /en-US/i.test(v.lang) && /male|Google|natural/i.test(v.name)
  ) || voices.find(v =>
    /en-GB/i.test(v.lang) && /male|Google|natural/i.test(v.name)
  ) || voices[0]; // fallback suara pertama jika tidak ada

  let count = 0;

  function speakOnce() {
    const u = new SpeechSynthesisUtterance(text);
    u.voice = preferredVoice;
    u.lang = preferredVoice.lang;
    u.pitch = 0.95;   // sedikit lebih berat agar seperti suara pria
    u.rate = 0.9;     // kecepatan -10% untuk lebih natural
    u.volume = 1.0;   // volume penuh

    u.onend = () => {
      count++;
      if (count < loopCount) speakOnce();
      else if (onEnd) onEnd();
    };

    // hentikan suara lain sebelum mulai
    try { window.speechSynthesis.cancel(); } catch (e) {}
    window.speechSynthesis.speak(u);
  }

  speakOnce();
}



/* --- Play uploaded audio (loop) --- */
function playAudioLoop(url, loopCount=1, onEnd=null){
  let count = 0;
  const audio = new Audio(url);
  audio.addEventListener('ended', ()=>{
    count++;
    if(count < loopCount) audio.play();
    else { if(onEnd) onEnd(); }
  });
  audio.play().catch(()=> {
    // fallback to default beep
    playDefaultBeep(loopCount, onEnd);
  });
}

/* --- Toast helper --- */
function showToast(title, body){
  const container = document.getElementById('appToast');
  const id = 'toast_' + Date.now();
  container.insertAdjacentHTML('beforeend', `
    <div id="${id}" class="toast align-items-center text-bg-light border-0 show" role="alert" aria-live="assertive" aria-atomic="true">
      <div class="d-flex">
        <div class="toast-body"><strong>${escapeHtml(title)}</strong><div>${escapeHtml(body)}</div></div>
        <button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
      </div>
    </div>`);
  // auto remove after 6s
  setTimeout(()=>{ const t = document.getElementById(id); if(t) t.remove(); }, 6000);
}

/* --- Rendering & pagination --- */
async function render(){
  const data = await fetchAlarms();
  const list = document.getElementById('list');
  list.innerHTML = '';

  const keys = Object.keys(data);
  // sort newest first
  keys.sort((a,b)=> (data[b].created_at||'').localeCompare(data[a].created_at||''));

  const totalPages = Math.max(1, Math.ceil(keys.length / ITEMS_PER_PAGE));
  if(currentPage > totalPages) currentPage = totalPages;
  const start = (currentPage - 1) * ITEMS_PER_PAGE;
  const pageItems = keys.slice(start, start + ITEMS_PER_PAGE);

  if(pageItems.length === 0){
    list.innerHTML = '<div class="text-muted">Belum ada alarm. Tambah alarm menggunakan form di sebelah kiri.</div>';
  } else {
    for(const id of pageItems){
      const a = data[id];
      const item = document.createElement('div');
      item.className = 'alarm-item';
      item.id = 'alarm_' + id;
      // show weekdays label if any
      let wkLabel = '';
      if(Array.isArray(a.weekdays) && a.weekdays.length){
        const names = ['Ming','Sen','Sel','Rab','Kam','Jum','Sab'];
        wkLabel = a.weekdays.map(n => names[parseInt(n)]).join(', ');
      }
      item.innerHTML = `
        <div class="d-flex justify-content-between align-items-start">
          <div>
            <strong>${escapeHtml(a.label)}</strong>
            <small class="d-block">${formatDateLocal(a.datetime)}</small>
            <small class="d-block text-muted">
              ${a.recurring ? '🔁 Recurring (daily)' : 'Once only'} 
              ${wkLabel? ' | Hari: ' + escapeHtml(wkLabel): ''}
              | Loop: ${a.loop_count}x ${a.use_tts ? '(TTS)' : ''}
            </small>
          </div>
          <div class="text-end">
            ${a.audio_file ? `<a class="btn btn-sm btn-outline-primary mb-1" href="${a.audio_file}" target="_blank">🎵 Audio</a><br>` : ''}
            ${a.use_tts ? `<button class="btn btn-sm btn-success preview_tts mb-1" data-id="${a.id}">▶ Preview</button><br>` : ''}
            <button class="btn btn-sm btn-danger del" data-id="${a.id}">🗑 Hapus</button>
          </div>
        </div>
      `;
      list.appendChild(item);
    }
  }

  // pagination controls
  document.getElementById('pageInfo').textContent = `Halaman ${currentPage} dari ${totalPages} — Total alarm: ${keys.length}`;
  const pc = document.querySelector('.pagination-controls');
  pc.innerHTML = '';
  if(totalPages > 1){
    const prev = document.createElement('button'); prev.className = 'btn btn-sm btn-outline-secondary me-1'; prev.textContent = '⬅ Sebelumnya';
    prev.disabled = currentPage === 1; prev.onclick = ()=>{ if(currentPage>1){ currentPage--; render(); } };
    pc.appendChild(prev);

    // show up to 7 page buttons with ellipsis
    const maxButtons = 7;
    let startPage = Math.max(1, currentPage - 3);
    let endPage = Math.min(totalPages, startPage + maxButtons - 1);
    if(endPage - startPage < maxButtons - 1) startPage = Math.max(1, endPage - maxButtons + 1);
    for(let p = startPage; p <= endPage; p++){
      const btn = document.createElement('button');
      btn.className = 'btn btn-sm ' + (p === currentPage ? 'btn-primary mx-1' : 'btn-outline-secondary mx-1');
      btn.textContent = p;
      btn.onclick = ()=>{ currentPage = p; render(); };
      pc.appendChild(btn);
    }

    const next = document.createElement('button'); next.className = 'btn btn-sm btn-outline-secondary ms-2'; next.textContent = 'Berikutnya ➡';
    next.disabled = currentPage === totalPages; next.onclick = ()=>{ if(currentPage<totalPages){ currentPage++; render(); } };
    pc.appendChild(next);
  }

  // bind delete
  document.querySelectorAll('.del').forEach(b => b.addEventListener('click', async (e)=>{
    const id = e.currentTarget.dataset.id;
    const fd = new FormData(); fd.append('id', id);
    await fetch('?action=delete', { method:'POST', body: fd });
    // adjust page if needed
    const keysNow = Object.keys(await fetchAlarms());
    const newTotalPages = Math.max(1, Math.ceil(keysNow.length / ITEMS_PER_PAGE));
    if(currentPage > newTotalPages) currentPage = newTotalPages;
    await render();
  }));

  // bind preview tts
  document.querySelectorAll('.preview_tts').forEach(b => b.addEventListener('click', async (e)=>{
    const id = e.currentTarget.dataset.id;
    const all = await fetchAlarms();
    const alarm = all[id];
    if(alarm){
      // visual cue
      const el = document.getElementById('alarm_' + id);
      if(el) { el.classList.add('alarm-active'); setTimeout(()=>el.classList.remove('alarm-active'), alarm.loop_count * 800 + 500); }
      speakLoop(alarm.tts_text || 'Waktu Anda telah tiba', alarm.loop_count || 1);
    }
  }));

  scheduleAlarms(data);
}

/* --- scheduler, respects weekdays --- */
let scheduled = {};
function clearSchedules(){ for(const k in scheduled){ clearTimeout(scheduled[k]); } scheduled = {}; }

function isTodayInWeekdays(weekdays){
  if(!Array.isArray(weekdays) || weekdays.length === 0) return true; // not restricted
  return weekdays.includes(new Date().getDay());
}

function scheduleAlarms(data){
  clearSchedules();
  const now = Date.now();
  for(const id in data){
    const a = data[id];
    if(!a.enabled || !a.datetime) continue;
    const target = new Date(a.datetime).getTime();
    if(isNaN(target)) continue;
    let wait = target - now;

    // if weekdays set and not today, calculate next day among weekdays
    if(Array.isArray(a.weekdays) && a.weekdays.length){
      // compute next occurrence among selected weekdays at the time of a.datetime
      const dtime = new Date(a.datetime);
      const hh = dtime.getHours(), mm = dtime.getMinutes(), ss = dtime.getSeconds();
      const nowD = new Date();
      let found = false;
      for(let offset = 0; offset < 14 && !found; offset++){
        const candidate = new Date();
        candidate.setDate(nowD.getDate() + offset);
        candidate.setHours(hh, mm, ss, 0);
        if(candidate.getTime() <= now) continue;
        if(a.weekdays.includes(candidate.getDay())){
          wait = candidate.getTime() - now;
          found = true;
        }
      }
      if(!found) continue; // no upcoming matching day within 2 weeks
    } else {
      // recurring daily: adjust if in past
      if(a.recurring && wait < 0){
        const d = new Date(a.datetime);
        const hh = d.getHours(), mm = d.getMinutes(), ss = d.getSeconds();
        const t = new Date();
        t.setHours(hh, mm, ss, 0);
        if(t.getTime() <= now) t.setDate(t.getDate()+1);
        wait = t.getTime() - now;
      }
      if(!a.recurring && wait < 0) continue; // one-shot in past => skip
    }

    if(wait < 0) continue;
    const MAX = 2147483647;
    if(wait > MAX) continue;
    scheduled[id] = setTimeout(()=>{ triggerAlarm(a); render(); }, wait);
  }
}

/* --- trigger: play TTS / audio / default beep, and visual effects --- */
function triggerAlarm(alarm){
  // check weekdays if set
  if(Array.isArray(alarm.weekdays) && alarm.weekdays.length){
    const today = new Date().getDay();
    if(!alarm.weekdays.includes(today)) return; // not today's weekday
  }

  // visual toast
  showToast(alarm.label || 'Alarm', alarm.tts_text || 'Waktu!');

  // highlight the item if present
  const el = document.getElementById('alarm_' + alarm.id);
  if(el) {
    el.classList.add('alarm-active');
    // remove highlight after some time (approx duration)
    setTimeout(()=> el.classList.remove('alarm-active'), (alarm.loop_count || 1) * 1000 + 1200);
  }

  // notification permission
  if(window.Notification && Notification.permission === 'granted'){
    new Notification(alarm.label || 'Alarm', { body: alarm.tts_text || 'Waktu!', silent: true });
  }

  // choose playback
  if(alarm.use_tts){
    speakLoop(alarm.tts_text || 'Waktu Anda telah tiba', alarm.loop_count || 1);
  } else if(alarm.audio_file){
    playAudioLoop(alarm.audio_file, alarm.loop_count || 1);
  } else {
    // default internal beep sound
    playDefaultBeep(alarm.loop_count || 1);
  }
}

/* --- form submit handler --- */
document.getElementById('formAdd').addEventListener('submit', async e=>{
  e.preventDefault();
  const fd = new FormData(e.target);
  // include weekday[] checkboxes (they are named weekday[] already)
  const res = await fetch('?action=add', { method: 'POST', body: fd });
  const j = await res.json();
  if(j.ok){
    e.target.reset();
    document.getElementById('tts_area').style.display = 'none';
    currentPage = 1;
    await render();
  } else {
    console.error('Gagal simpan:', j.error || 'unknown');
    showToast('Gagal menyimpan', j.error || 'Terjadi kesalahan');
  }
});

/* toggle TTS / upload UI */
document.getElementById('use_tts').addEventListener('change', e=>{
  const v = e.target.checked;
  document.getElementById('tts_area').style.display = v ? 'block' : 'none';
  document.getElementById('upload_area').style.display = v ? 'none' : 'block';
});

/* request notifications permission */
if(window.Notification && Notification.permission !== 'granted') {
  Notification.requestPermission();
}

/* initialize */
render();
</script>
</body>
</html>
