<?php
// index.php
// Aplikasi alarm sederhana berbasis PHP + client-side JS
// Fitur:
// - Buat / hapus alarm
// - Set alarm ke waktu tertentu (date-time) atau recurring daily
// - Pilih menggunakan TTS (browser speechSynthesis) atau upload audio (mp3/wav)
// - Alarm dijalankan di sisi client (browser) sehingga browser harus terbuka

// File penyimpanan sederhana (JSON)
$DATA_FILE = __DIR__ . '/alarms.json';
$UPLOAD_DIR = __DIR__ . '/uploads';
if (!file_exists($UPLOAD_DIR)) mkdir($UPLOAD_DIR, 0755, true);

// helper: load alarms
function load_alarms() {
    global $DATA_FILE;
    if (!file_exists($DATA_FILE)) return [];
    $s = file_get_contents($DATA_FILE);
    $a = json_decode($s, true);
    return is_array($a) ? $a : [];
}

// helper: save alarms
function save_alarms($alarms) {
    global $DATA_FILE;
    file_put_contents($DATA_FILE, json_encode($alarms, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// API actions: add, delete, list
$action = $_REQUEST['action'] ?? '';
if ($action === 'list') {
    header('Content-Type: application/json');
    echo json_encode(load_alarms());
    exit;
}

if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $label = trim($_POST['label'] ?? 'Alarm');
    $datetime = $_POST['datetime'] ?? '';
    $recurring = isset($_POST['recurring']) ? true : false; // daily recurring
    $use_tts = isset($_POST['use_tts']) ? true : false;
    $tts_text = trim($_POST['tts_text'] ?? 'Waktu Anda telah tiba');

    $audio_file = null;
    if (!$use_tts && isset($_FILES['audio']) && $_FILES['audio']['error'] === UPLOAD_ERR_OK) {
        $tmp = $_FILES['audio']['tmp_name'];
        $name = basename($_FILES['audio']['name']);
        // basic sanitize
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['mp3','wav','ogg','m4a'])) {
            echo json_encode(['ok' => false, 'error' => 'Only mp3/wav/ogg/m4a allowed']);
            exit;
        }
        $newname = uniqid('a_') . '.' . $ext;
        $dest = $UPLOAD_DIR . '/' . $newname;
        if (!move_uploaded_file($tmp, $dest)) {
            echo json_encode(['ok' => false, 'error' => 'Upload failed']);
            exit;
        }
        $audio_file = 'uploads/' . $newname;
    }

    // create alarm object
    $alarms = load_alarms();
    $id = uniqid();
    $alarms[$id] = [
        'id' => $id,
        'label' => $label,
        'datetime' => $datetime, // ISO-like from input
        'recurring' => $recurring,
        'use_tts' => $use_tts,
        'tts_text' => $tts_text,
        'audio_file' => $audio_file,
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
        // remove audio file if exists
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

// If not API, render page
$alarms = load_alarms();
?>

<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Aplikasi Alarm — PHP Web</title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;margin:12px}
    .card{border:1px solid #ddd;padding:12px;border-radius:8px;margin-bottom:12px}
    label{display:block;margin-top:8px}
    .alarms{margin-top:16px}
    .alarm{padding:8px;border:1px solid #eee;margin-bottom:8px;border-radius:6px}
    button{padding:6px 10px}
  </style>
</head>
<body>
  <h1>Aplikasi Alarm (PHP + Client JS)</h1>
  <div class="card">
    <h2>Buat Alarm Baru</h2>
    <form id="formAdd" method="post" enctype="multipart/form-data" action="?action=add">
      <label>Label<br><input type="text" name="label" value="Alarm" required></label>
      <label>Waktu (tanggal & waktu)<br><input type="datetime-local" name="datetime" required></label>
      <label><input type="checkbox" name="recurring"> Ulang tiap hari pada jam yang sama (recurring)</label>
      <hr>
      <label><input type="checkbox" name="use_tts" id="use_tts"> Gunakan suara TTS (browser)</label>
      <div id="tts_area" style="display:none">
        <label>Text untuk TTS<br><input type="text" name="tts_text" value="Waktu Anda telah tiba"></label>
        <label>Pilih voice (preview di samping setelah disimpan)</label>
      </div>
      <div id="upload_area">
        <label>Atau upload file suara (mp3/wav/ogg/m4a)<br><input type="file" name="audio" accept="audio/*"></label>
      </div>
      <br>
      <button type="submit">Simpan Alarm</button>
    </form>
  </div>

  <div class="card alarms">
    <h2>Daftar Alarm</h2>
    <div id="list">
      <!-- alarm list will be rendered by JS -->
    </div>
  </div>

  <script>
    //awal: ambil alarm via API
    async function fetchAlarms(){
      const res = await fetch('?action=list');
      const data = await res.json();
      return data;
    }

    function formatDateLocal(dstr){
      if(!dstr) return '-';
      try{
        const d = new Date(dstr);
        return d.toLocaleString();
      }catch(e){return dstr}
    }

    // render
    async function render(){
      const data = await fetchAlarms();
      const list = document.getElementById('list');
      list.innerHTML = '';
      const now = Date.now();
      for(const id in data){
        const a = data[id];
        const el = document.createElement('div'); el.className='alarm';
        el.innerHTML = `<strong>${a.label}</strong> <br> <small>${formatDateLocal(a.datetime)}</small> <br>` +
          `<small>${a.recurring? 'Recurring: tiap hari':''} ${a.use_tts? ' (TTS)':''}</small><br>` +
          `<button data-id="${a.id}" class="del">Hapus</button> ` +
          `${a.audio_file? `<a href="${a.audio_file}" target="_blank">(audio)</a>`:''} ` +
          `${a.use_tts? `<button data-id="${a.id}" class="preview_tts">Preview TTS</button>`:''}`;
        list.appendChild(el);
      }
      // bind delete
      document.querySelectorAll('.del').forEach(b=>b.addEventListener('click', async e=>{
        const id = e.target.dataset.id;
        if(!confirm('Hapus alarm?')) return;
        const fd = new FormData(); fd.append('id', id);
        await fetch('?action=delete', {method:'POST', body:fd});
        await render();
      }));

      // bind tts preview
      document.querySelectorAll('.preview_tts').forEach(b=>b.addEventListener('click', async e=>{
        const id = e.target.dataset.id;
        const data = await fetchAlarms();
        const alarm = data[id];
        if(!alarm) return;
        speakText(alarm.tts_text || 'Waktu Anda telah tiba');
      }));

      scheduleAlarms(data);
    }

    // scheduling: clear previous timers
    let scheduled = {};
    function clearSchedules(){
      for(const k in scheduled){
        clearTimeout(scheduled[k]);
      }
      scheduled = {};
    }

    function scheduleAlarms(data){
      clearSchedules();
      const now = Date.now();
      for(const id in data){
        const a = data[id];
        if(!a.enabled) continue;
        if(!a.datetime) continue;
        // parse datetime-local (server saved) — assume ISO or compatible
        const target = new Date(a.datetime).getTime();
        if(isNaN(target)) continue;
        let wait = target - now;
        if(a.recurring && wait < 0){
          // if recurring daily, add days until in future
          const d = new Date(a.datetime);
          // take hours/minutes
          const hh = d.getHours();
          const mm = d.getMinutes();
          const t = new Date();
          t.setHours(hh, mm, 0, 0);
          if(t.getTime() <= now) t.setDate(t.getDate()+1);
          wait = t.getTime() - now;
        }
        if(wait < 0) continue; // past alarm; skip
        // safety: cap wait to 24.8 days for setTimeout (max ~2^31-1 ms)
        const MAX = 2147483647;
        if(wait > MAX) continue; // too far
        scheduled[id] = setTimeout(() => {
          triggerAlarm(a);
          // if recurring, reschedule for next day
          if(a.recurring){
            // schedule next by re-rendering
            render();
          } else {
            // non-recurring: we should disable it on server or remove — but simple approach: remove from DOM and let user delete
            render();
          }
        }, wait);
      }
    }

    function triggerAlarm(alarm){
      // show notification (if permission)
      if (window.Notification && Notification.permission === 'granted'){
        new Notification(alarm.label || 'Alarm', {body: alarm.tts_text || 'Waktu!', silent: true});
      }
      // play audio or tts
      if(alarm.use_tts){
        speakText(alarm.tts_text || 'Waktu Anda telah tiba');
      } else if(alarm.audio_file){
        const a = new Audio(alarm.audio_file);
        a.play().catch(e=>{
          // if playback blocked, fall back to TTS
          speakText(alarm.tts_text || 'Waktu Anda telah tiba');
        });
      } else {
        speakText(alarm.tts_text || 'Waktu Anda telah tiba');
      }
      // also simple popup
      alert('Alarm: ' + (alarm.label || 'Alarm'));
    }

    // TTS helper
    function speakText(text){
      if('speechSynthesis' in window){
        const u = new SpeechSynthesisUtterance(text);
        // choose best voice (optional) - leave browser default
        const voices = speechSynthesis.getVoices();
        if(voices.length) u.voice = voices[0];
        speechSynthesis.cancel();
        speechSynthesis.speak(u);
      } else {
        // not supported
        console.log('TTS not supported');
      }
    }

    // Permission for notifications
    if(window.Notification && Notification.permission !== 'granted'){
      Notification.requestPermission();
    }

    // form submission via fetch to avoid reload
    document.getElementById('formAdd').addEventListener('submit', async function(e){
      e.preventDefault();
      const form = e.target;
      const fd = new FormData(form);
      const res = await fetch('?action=add', {method:'POST', body:fd});
      const j = await res.json();
      if(j.ok){
        form.reset();
        document.getElementById('tts_area').style.display='none';
        await render();
        alert('Alarm tersimpan');
      } else {
        alert('Gagal: ' + (j.error || 'unknown'));
      }
    });

    // show/hide tts/upload
    document.getElementById('use_tts').addEventListener('change', function(e){
      const v = e.target.checked;
      document.getElementById('tts_area').style.display = v ? 'block' : 'none';
      document.getElementById('upload_area').style.display = v ? 'none' : 'block';
    });

    // initial render
    render();

    // update voices (some browsers load voices asynchronously)
    window.speechSynthesis && window.speechSynthesis.addEventListener('voiceschanged', ()=>{
      // no UI for voice selection in this simple app; extension possible
    });

  </script>

  <hr>
  <small>Catatan: aplikasi ini menjalankan alarm di sisi klien (browser). Browser harus terbuka dan tab aktif (atau minimal tidak sepenuhnya tertutup) agar suara bisa diputar. Jika Anda butuh alarm server-side yang memanggil telepon/WA, itu memerlukan layanan eksternal (cron, push service, atau integrasi telephony/API).</small>

</body>
</html>
