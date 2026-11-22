<?php
session_start();
if (empty($_SESSION['logged_in'])) { header('Location: login.php'); exit; }
if (isset($_GET['logout'])) { session_destroy(); header('Location: login.php'); exit; }
require_once 'config/db.php';
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

/* Tables for the User tab are created automatically the first time */
$conn->query("CREATE TABLE IF NOT EXISTS stick_user (
    id INT PRIMARY KEY, full_name VARCHAR(100) NOT NULL DEFAULT '', age INT NULL,
    phone VARCHAR(20) NOT NULL DEFAULT '', address VARCHAR(255) NOT NULL DEFAULT '', notes VARCHAR(255) NOT NULL DEFAULT '')");
$conn->query("CREATE TABLE IF NOT EXISTS stick_contacts (
    id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL, relation VARCHAR(50) NOT NULL DEFAULT '',
    phone VARCHAR(20) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");

function latest_reading($conn) {
    $result = $conn->query("SELECT * FROM sensor_data ORDER BY id DESC LIMIT 1");
    $row = $result ? $result->fetch_assoc() : null;

    $history = [];
    $h = $conn->query("SELECT obstacle_distance FROM sensor_data ORDER BY id DESC LIMIT 20");
    if ($h) while ($r = $h->fetch_assoc()) $history[] = (float) $r['obstacle_distance'];

    return [
        'has_data' => (bool) $row,
        'distance' => isset($row['obstacle_distance']) ? (float) $row['obstacle_distance'] : null,
        'water'    => $row['water_status'] ?? 'Safe',
        'tilt'     => $row['tilt_status'] ?? 'Safe',
        'lat'      => isset($row['latitude'])  ? (float) $row['latitude']  : 0,
        'lng'      => isset($row['longitude']) ? (float) $row['longitude'] : 0,
        'time'     => $row['created_at'] ?? $row['timestamp'] ?? null,
        'history'  => array_reverse($history),
    ];
}

function json_out($d, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($d);
    exit;
}

function user_payload($conn) {
    $u = $conn->query("SELECT full_name, age, phone, address, notes FROM stick_user WHERE id = 1");
    $user = ($u && ($r = $u->fetch_assoc())) ? $r : ['full_name' => '', 'age' => null, 'phone' => '', 'address' => '', 'notes' => ''];
    $contacts = [];
    $c = $conn->query("SELECT id, name, relation, phone FROM stick_contacts ORDER BY id");
    if ($c) while ($r = $c->fetch_assoc()) $contacts[] = $r;
    return ['user' => $user, 'contacts' => $contacts];
}

function clean_phone($p) { return preg_replace('/[\s\-()]/', '', trim($p)); }
function valid_phone($p) { return (bool) preg_match('/^\+?[0-9]{7,15}$/', $p); }

/* User tab API */
if (isset($_GET['api'])) {
    $api = $_GET['api'];
    if ($api === 'user') json_out(user_payload($conn));

    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? ''))
        json_out(['error' => 'Your session expired. Reload the page and try again.'], 403);

    if ($api === 'save_user') {
        $name = trim($_POST['full_name'] ?? '');
        $age  = ($_POST['age'] ?? '') === '' ? null : (int) $_POST['age'];
        $phone = clean_phone($_POST['phone'] ?? '');
        $addr = trim($_POST['address'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        if ($name === '') json_out(['error' => 'Enter the user\'s name.'], 422);
        if ($age !== null && ($age < 1 || $age > 120)) json_out(['error' => 'Age must be between 1 and 120.'], 422);
        if ($phone !== '' && !valid_phone($phone)) json_out(['error' => 'Enter a valid phone number, for example +919876543210.'], 422);
        $st = $conn->prepare("INSERT INTO stick_user (id, full_name, age, phone, address, notes) VALUES (1,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE full_name=VALUES(full_name), age=VALUES(age), phone=VALUES(phone), address=VALUES(address), notes=VALUES(notes)");
        $st->bind_param('sisss', $name, $age, $phone, $addr, $notes);
        $st->execute();
        json_out(user_payload($conn));
    }

    if ($api === 'add_contact') {
        $name = trim($_POST['name'] ?? '');
        $rel  = trim($_POST['relation'] ?? '');
        $phone = clean_phone($_POST['phone'] ?? '');
        if ($name === '') json_out(['error' => 'Enter the contact\'s name.'], 422);
        if (!valid_phone($phone)) json_out(['error' => 'Enter a valid phone number, for example +919876543210.'], 422);
        $n = $conn->query("SELECT COUNT(*) AS n FROM stick_contacts")->fetch_assoc()['n'];
        if ($n >= 5) json_out(['error' => 'You can save up to 5 emergency contacts. Remove one first.'], 422);
        $st = $conn->prepare("INSERT INTO stick_contacts (name, relation, phone) VALUES (?,?,?)");
        $st->bind_param('sss', $name, $rel, $phone);
        $st->execute();
        json_out(user_payload($conn));
    }

    if ($api === 'delete_contact') {
        $id = (int) ($_POST['id'] ?? 0);
        $st = $conn->prepare("DELETE FROM stick_contacts WHERE id = ?");
        $st->bind_param('i', $id);
        $st->execute();
        json_out(user_payload($conn));
    }
    json_out(['error' => 'Unknown request.'], 400);
}

$data = latest_reading($conn);
if (isset($_GET['json'])) json_out($data);
$initial = user_payload($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VisionAid | Smart Stick Monitor</title>
    <link href="https://fonts.googleapis.com/css2?family=Atkinson+Hyperlegible:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --paper:#eef1ee; --surface:#fff; --ink:#14283a; --muted:#5b6b78; --line:#d7ddd9;
            --safe:#0e7c61; --safe-bg:#dff3ec; --warn:#a65b00; --warn-bg:#fdecc8;
            --danger:#c4261d; --danger-bg:#fde0dd; --idle:#5b6b78; --idle-bg:#e4e8e6;
            --map:#2457d6; --map-bg:#e1e9fb;
        }
        * { box-sizing:border-box; margin:0; padding:0; }
        body { font-family:'Atkinson Hyperlegible',system-ui,sans-serif; background:var(--paper); color:var(--ink); line-height:1.45; min-height:100vh; }
        :focus-visible { outline:3px solid var(--map); outline-offset:3px; }
        [hidden] { display:none !important; }
        [data-state="safe"]    { --c:var(--safe);   --c-bg:var(--safe-bg); }
        [data-state="caution"] { --c:var(--warn);   --c-bg:var(--warn-bg); }
        [data-state="danger"]  { --c:var(--danger); --c-bg:var(--danger-bg); }
        [data-state="idle"]    { --c:var(--idle);   --c-bg:var(--idle-bg); }

        .wrap { max-width:1080px; margin:0 auto; padding:28px 24px 56px; }
        .top { display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap; margin-bottom:20px; }
        .brand { display:flex; align-items:center; gap:14px; }
        .brand-mark { width:46px; height:46px; border-radius:14px; background:var(--ink); color:#fff; display:grid; place-items:center; font-size:1.3rem; }
        .brand h1 { font-size:1.4rem; line-height:1.1; }
        .brand p { color:var(--muted); font-size:.95rem; }
        .tools { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
        .pill { display:flex; align-items:center; gap:10px; background:var(--surface); border:1px solid var(--line); padding:9px 16px; border-radius:999px; font:inherit; font-size:.95rem; color:var(--ink); text-decoration:none; cursor:pointer; }
        .pill:hover { border-color:var(--ink); }
        .pill[aria-pressed="true"] { background:var(--ink); color:#fff; border-color:var(--ink); }
        .live .dot { width:10px; height:10px; border-radius:50%; background:var(--c); animation:pulse 2s infinite; }
        @keyframes pulse { 50% { opacity:.35; } }
        @media (prefers-reduced-motion:reduce) { .live .dot { animation:none; } * { transition:none !important; } }

        /* Tabs */
        .tabs { display:flex; gap:6px; background:var(--surface); border:1px solid var(--line); border-radius:16px; padding:6px; margin-bottom:22px; width:fit-content; }
        .tabs button { font:inherit; font-weight:700; font-size:1rem; border:0; background:none; color:var(--muted); padding:11px 22px; border-radius:11px; cursor:pointer; display:flex; align-items:center; gap:9px; transition:background .15s,color .15s; }
        .tabs button:hover { color:var(--ink); }
        .tabs button[aria-selected="true"] { background:var(--ink); color:#fff; }

        .summary { display:flex; align-items:center; gap:16px; padding:18px 22px; border-radius:18px; background:var(--c-bg); color:var(--c); margin-bottom:22px; border:2px solid var(--c); }
        .summary i { font-size:1.6rem; }
        .summary strong { display:block; font-size:1.2rem; color:var(--ink); }
        .summary span { color:var(--ink); opacity:.8; }

        .grid { display:grid; grid-template-columns:1.35fr 1fr; gap:22px; }
        .panel { background:var(--surface); border:1px solid var(--line); border-radius:22px; padding:26px; }
        .panel h2 { font-size:1.05rem; color:var(--muted); font-weight:400; margin-bottom:14px; }
        .sonar { grid-row:span 2; display:flex; flex-direction:column; }
        .wide { grid-column:1 / -1; }

        .sonar svg { width:100%; height:auto; display:block; margin:4px 0 8px; }
        .ring { fill:none; stroke:var(--line); stroke-width:2; stroke-dasharray:3 7; stroke-linecap:round; }
        .ring-label { fill:var(--muted); font-size:12px; text-anchor:middle; }
        .blip { fill:none; stroke:var(--c); stroke-width:12; stroke-linecap:round; transition:d .4s; }
        .stick { fill:var(--ink); }
        .reading { display:flex; align-items:baseline; gap:8px; color:var(--c); }
        .reading b { font-size:4rem; line-height:1; font-variant-numeric:tabular-nums; }
        .reading span { font-size:1.3rem; color:var(--muted); }
        .verdict { margin-top:6px; font-size:1.15rem; }

        .state { display:flex; align-items:center; gap:18px; }
        .state .icon { width:58px; height:58px; flex:none; border-radius:16px; background:var(--c-bg); color:var(--c); display:grid; place-items:center; font-size:1.5rem; }
        .state h2 { margin-bottom:2px; }
        .state .value { font-size:1.8rem; font-weight:700; color:var(--c); line-height:1.15; }
        .state .hint { color:var(--muted); font-size:.95rem; }

        /* History */
        .hist svg { width:100%; height:auto; display:block; }
        .hist .grid-line { stroke:var(--line); stroke-width:1; }
        .hist .line { fill:none; stroke:var(--c); stroke-width:3; stroke-linejoin:round; stroke-linecap:round; }
        .hist circle { fill:var(--c); }
        .hist circle:hover { r:6; }
        .hist-meta { display:flex; gap:24px; flex-wrap:wrap; color:var(--muted); font-size:.95rem; margin-top:8px; }
        .hist-meta b { color:var(--ink); }

        .loc-body { display:grid; grid-template-columns:1fr 280px; gap:22px; }
        .map { min-height:260px; border-radius:16px; overflow:hidden; background:var(--map-bg); border:1px solid var(--line); position:relative; }
        .map iframe { width:100%; height:100%; min-height:260px; border:0; display:block; }
        .map .empty { position:absolute; inset:0; display:grid; place-items:center; text-align:center; padding:20px; color:var(--map); }
        .coords { display:flex; flex-direction:column; justify-content:center; gap:18px; }
        .coords dt { color:var(--muted); font-size:.95rem; }
        .coords dd { font-size:1.6rem; font-weight:700; font-variant-numeric:tabular-nums; }
        .btn { display:inline-flex; align-items:center; justify-content:center; gap:10px; background:var(--map); color:#fff; text-decoration:none; padding:14px 18px; border-radius:14px; font:inherit; font-weight:700; border:0; cursor:pointer; }
        .btn:hover { filter:brightness(1.1); }
        .btn[aria-disabled="true"] { background:var(--idle-bg); color:var(--muted); pointer-events:none; }
        .btn.ghost { background:var(--idle-bg); color:var(--ink); }

        /* User tab */
        .who { display:flex; align-items:center; gap:18px; margin-bottom:22px; }
        .avatar { width:68px; height:68px; border-radius:50%; background:var(--ink); color:#fff; display:grid; place-items:center; font-size:1.5rem; font-weight:700; flex:none; }
        .who h2 { color:var(--ink); font-size:1.5rem; margin:0; font-weight:700; }
        .who p { color:var(--muted); }
        .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
        .form-grid .full { grid-column:1 / -1; }
        label { display:block; font-size:.95rem; color:var(--muted); margin-bottom:6px; }
        input, textarea { width:100%; font:inherit; font-size:1.05rem; color:var(--ink); padding:12px 14px; border:2px solid var(--line); border-radius:12px; background:#fff; }
        input:focus, textarea:focus { border-color:var(--map); outline:none; }
        textarea { resize:vertical; min-height:76px; }
        .actions { display:flex; gap:12px; margin-top:18px; flex-wrap:wrap; }
        .contact { display:flex; align-items:center; gap:14px; padding:14px 0; border-bottom:1px solid var(--line); }
        .contact:last-of-type { border-bottom:0; }
        .contact .ci { width:44px; height:44px; border-radius:12px; background:var(--map-bg); color:var(--map); display:grid; place-items:center; flex:none; }
        .contact .info { flex:1; min-width:0; }
        .contact .info b { display:block; }
        .contact .info span { color:var(--muted); font-size:.95rem; }
        .icon-btn { width:44px; height:44px; border-radius:12px; border:2px solid var(--line); background:#fff; color:var(--ink); cursor:pointer; display:grid; place-items:center; text-decoration:none; font:inherit; }
        .icon-btn:hover { border-color:var(--ink); }
        .icon-btn.confirm { width:auto; padding:0 14px; background:var(--danger); border-color:var(--danger); color:#fff; font-weight:700; }
        .empty-note { color:var(--muted); padding:8px 0 16px; }
        .count { color:var(--muted); font-size:.95rem; margin-bottom:8px; }

        .toast { position:fixed; left:50%; bottom:26px; translate:-50% 0; background:var(--ink); color:#fff; padding:13px 20px; border-radius:14px; font-weight:700; max-width:92vw; box-shadow:0 10px 30px rgba(0,0,0,.25); }
        .toast.err { background:var(--danger); }

        @media (max-width:860px) {
            .grid, .loc-body, .form-grid { grid-template-columns:1fr; }
            .sonar { grid-row:auto; }
            .wrap { padding:20px 16px 40px; }
            .tabs { width:100%; } .tabs button { flex:1; justify-content:center; }
        }
    </style>
</head>
<body>
<div class="wrap">

    <header class="top">
        <div class="brand">
            <div class="brand-mark"><i class="fa-solid fa-staff-snake" aria-hidden="true"></i></div>
            <div><h1>VisionAid</h1><p>Smart stick monitor</p></div>
        </div>
        <div class="tools">
            <button class="pill" id="voiceBtn" aria-pressed="false" type="button"><i class="fa-solid fa-volume-high" aria-hidden="true"></i> Voice alerts: off</button>
            <div class="pill live" id="live" data-state="idle" role="status"><span class="dot"></span><span id="liveText">Connecting…</span></div>
            <a class="pill" href="?logout=1"><i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i> Log out</a>
        </div>
    </header>

    <div class="tabs" role="tablist" aria-label="Sections">
        <button role="tab" id="tab-monitor" aria-controls="panel-monitor" aria-selected="true" type="button"><i class="fa-solid fa-gauge-high" aria-hidden="true"></i> Monitor</button>
        <button role="tab" id="tab-user" aria-controls="panel-user" aria-selected="false" tabindex="-1" type="button"><i class="fa-solid fa-user" aria-hidden="true"></i> User</button>
    </div>

    <!-- MONITOR TAB -->
    <div id="panel-monitor" role="tabpanel" aria-labelledby="tab-monitor">
        <div class="summary" id="summary" data-state="idle" role="status" aria-live="polite">
            <i class="fa-solid fa-circle-info" id="summaryIcon" aria-hidden="true"></i>
            <div><strong id="summaryTitle">Waiting for the stick</strong><span id="summaryText">No readings have arrived yet.</span></div>
        </div>

        <main class="grid">
            <section class="panel sonar" id="sonar" data-state="idle" aria-labelledby="sonarTitle">
                <h2 id="sonarTitle">Obstacle ahead</h2>
                <svg viewBox="0 0 400 228" role="img" aria-label="Distance to the nearest obstacle in front of the stick">
                    <g id="rings"></g>
                    <path id="blip" class="blip" d=""></path>
                    <circle class="stick" cx="200" cy="200" r="9"></circle>
                </svg>
                <div class="reading"><b id="distance">--</b><span>cm</span></div>
                <p class="verdict" id="verdict">No reading yet</p>
            </section>

            <section class="panel state" id="water" data-state="idle">
                <div class="icon"><i class="fa-solid fa-droplet" aria-hidden="true"></i></div>
                <div><h2>Floor surface</h2><div class="value" id="waterValue">--</div><div class="hint" id="waterHint">Waiting for data</div></div>
            </section>

            <section class="panel state" id="tilt" data-state="idle">
                <div class="icon"><i class="fa-solid fa-person-falling" aria-hidden="true"></i></div>
                <div><h2>Stick position</h2><div class="value" id="tiltValue">--</div><div class="hint" id="tiltHint">Waiting for data</div></div>
            </section>

            <section class="panel wide hist" id="hist" data-state="idle" aria-labelledby="histTitle">
                <h2 id="histTitle">Distance over the last 20 readings</h2>
                <svg viewBox="0 0 600 130" role="img" aria-label="Line chart of recent obstacle distances">
                    <line class="grid-line" x1="0" x2="600" y1="20" y2="20"></line>
                    <line class="grid-line" x1="0" x2="600" y1="65" y2="65"></line>
                    <line class="grid-line" x1="0" x2="600" y1="110" y2="110"></line>
                    <polyline class="line" id="histLine" points=""></polyline>
                    <g id="histDots"></g>
                </svg>
                <div class="hist-meta"><span>Closest: <b id="histMin">--</b></span><span>Furthest: <b id="histMax">--</b></span><span>Hover a dot to see its value</span></div>
            </section>

            <section class="panel wide" aria-labelledby="locTitle">
                <h2 id="locTitle">Where the stick is now</h2>
                <div class="loc-body">
                    <div class="map" id="map">
                        <div class="empty" id="mapEmpty"><div><i class="fa-solid fa-satellite-dish fa-2x" aria-hidden="true"></i><p style="margin-top:10px">Waiting for a GPS fix</p></div></div>
                    </div>
                    <dl class="coords">
                        <div><dt>Latitude</dt><dd id="lat">--</dd></div>
                        <div><dt>Longitude</dt><dd id="lng">--</dd></div>
                        <a class="btn" id="mapLink" href="#" target="_blank" rel="noopener" aria-disabled="true"><i class="fa-solid fa-location-arrow" aria-hidden="true"></i> Open in Google Maps</a>
                    </dl>
                </div>
            </section>
        </main>
    </div>

    <!-- USER TAB -->
    <div id="panel-user" role="tabpanel" aria-labelledby="tab-user" hidden>
        <div class="who">
            <div class="avatar" id="avatar" aria-hidden="true">?</div>
            <div><h2 id="whoName">Stick user</h2><p id="whoMeta">Add the user's details below.</p></div>
        </div>
        <div class="grid" style="grid-template-columns:1fr 1fr">
            <section class="panel" aria-labelledby="profTitle">
                <h2 id="profTitle">User details</h2>
                <form id="userForm" novalidate>
                    <div class="form-grid">
                        <div class="full"><label for="f_name">Full name</label><input id="f_name" name="full_name" autocomplete="off" required></div>
                        <div><label for="f_age">Age</label><input id="f_age" name="age" type="number" min="1" max="120" inputmode="numeric"></div>
                        <div><label for="f_phone">Phone</label><input id="f_phone" name="phone" type="tel" placeholder="+919876543210"></div>
                        <div class="full"><label for="f_addr">Home address</label><input id="f_addr" name="address" autocomplete="off"></div>
                        <div class="full"><label for="f_notes">Notes for responders</label><textarea id="f_notes" name="notes" maxlength="255" placeholder="Anything that helps someone find or assist the user"></textarea></div>
                    </div>
                    <div class="actions"><button class="btn" type="submit"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Save details</button></div>
                </form>
            </section>

            <section class="panel" aria-labelledby="conTitle">
                <h2 id="conTitle">Emergency contacts</h2>
                <p class="count" id="conCount"></p>
                <div id="contactList"></div>
                <form id="contactForm" novalidate>
                    <div class="form-grid">
                        <div><label for="c_name">Name</label><input id="c_name" autocomplete="off"></div>
                        <div><label for="c_rel">Relation</label><input id="c_rel" placeholder="Mother, friend…" autocomplete="off"></div>
                        <div class="full"><label for="c_phone">Phone</label><input id="c_phone" type="tel" placeholder="+919876543210"></div>
                    </div>
                    <div class="actions"><button class="btn" type="submit"><i class="fa-solid fa-user-plus" aria-hidden="true"></i> Add contact</button></div>
                </form>
            </section>
        </div>
    </div>
</div>

<div class="toast" id="toast" role="status" aria-live="polite" hidden></div>

<script>
const CSRF = <?= json_encode($_SESSION['csrf']) ?>;
const DANGER_CM = 20, CAUTION_CM = 50, MAX_CM = 200, POLL_MS = 3000;
const $ = id => document.getElementById(id);
const setState = (el, s) => el.dataset.state = s;

/* ---------- Tabs ---------- */
const tabs = [$('tab-monitor'), $('tab-user')];
function showTab(name, focus) {
    tabs.forEach(t => {
        const on = t.id === 'tab-' + name;
        t.setAttribute('aria-selected', on);
        t.tabIndex = on ? 0 : -1;
        $('panel-' + t.id.slice(4)).hidden = !on;
        if (on && focus) t.focus();
    });
    history.replaceState(null, '', '#' + name);
}
tabs.forEach((t, i) => {
    t.addEventListener('click', () => showTab(t.id.slice(4)));
    t.addEventListener('keydown', e => {
        if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
            e.preventDefault();
            showTab(tabs[(i + 1) % tabs.length].id.slice(4), true);
        }
    });
});
if (location.hash === '#user') showTab('user');

/* ---------- Toast ---------- */
let toastTimer;
function toast(msg, err) {
    const t = $('toast');
    t.textContent = msg; t.className = 'toast' + (err ? ' err' : ''); t.hidden = false;
    clearTimeout(toastTimer); toastTimer = setTimeout(() => t.hidden = true, 3200);
}

/* ---------- Voice alerts ---------- */
let voiceOn = false, lastSpoken = '';
try { voiceOn = localStorage.getItem('va_voice') === '1'; } catch (e) {}
function paintVoice() {
    $('voiceBtn').setAttribute('aria-pressed', voiceOn);
    $('voiceBtn').lastChild.textContent = ' Voice alerts: ' + (voiceOn ? 'on' : 'off');
}
$('voiceBtn').addEventListener('click', () => {
    voiceOn = !voiceOn; lastSpoken = '';
    try { localStorage.setItem('va_voice', voiceOn ? '1' : '0'); } catch (e) {}
    paintVoice();
    if (voiceOn && 'speechSynthesis' in window) speechSynthesis.speak(new SpeechSynthesisUtterance('Voice alerts on'));
});
paintVoice();
function speak(msg) {
    if (!voiceOn || !('speechSynthesis' in window) || msg === lastSpoken) return;
    lastSpoken = msg; speechSynthesis.cancel(); speechSynthesis.speak(new SpeechSynthesisUtterance(msg));
}

/* ---------- Sonar ---------- */
const ORIGIN = { x: 200, y: 200 }, R = 170;
function arcPath(r) {
    const pt = a => [ORIGIN.x + r * Math.cos(a * Math.PI / 180), ORIGIN.y - r * Math.sin(a * Math.PI / 180)];
    const [s, e] = [pt(160), pt(20)];
    return `M${s[0].toFixed(1)} ${s[1].toFixed(1)}A${r} ${r} 0 0 1 ${e[0].toFixed(1)} ${e[1].toFixed(1)}`;
}
$('rings').innerHTML = [50, 100, 200].map(cm => {
    const r = cm / MAX_CM * R;
    return `<path class="ring" d="${arcPath(r)}"></path><text class="ring-label" x="${ORIGIN.x - r * Math.cos(20 * Math.PI / 180)}" y="222">${cm} cm</text>`;
}).join('');

/* ---------- History chart ---------- */
function drawHistory(list, state) {
    setState($('hist'), state);
    const vals = (list || []).filter(v => v > 0);
    const NS = 'http://www.w3.org/2000/svg';
    $('histDots').innerHTML = '';
    if (!list || list.length < 2) { $('histLine').setAttribute('points', ''); $('histMin').textContent = $('histMax').textContent = '--'; return; }
    const step = 600 / (list.length - 1);
    const pts = list.map((v, i) => [i * step, 110 - Math.min(Math.max(v, 0), MAX_CM) / MAX_CM * 90]);
    $('histLine').setAttribute('points', pts.map(p => p.map(n => n.toFixed(1)).join(',')).join(' '));
    list.forEach((v, i) => {
        const c = document.createElementNS(NS, 'circle');
        c.setAttribute('cx', pts[i][0]); c.setAttribute('cy', pts[i][1]); c.setAttribute('r', 4);
        const t = document.createElementNS(NS, 'title'); t.textContent = v > 0 ? Math.round(v) + ' cm' : 'No echo';
        c.appendChild(t); $('histDots').appendChild(c);
    });
    $('histMin').textContent = vals.length ? Math.round(Math.min(...vals)) + ' cm' : '--';
    $('histMax').textContent = vals.length ? Math.round(Math.max(...vals)) + ' cm' : '--';
}

/* ---------- Monitor render ---------- */
function render(d) {
    const alerts = [];
    const dist = d.has_data ? d.distance : null;
    let dState = 'idle', verdict = 'No reading yet';
    if (dist !== null && dist > 0) {
        if (dist <= DANGER_CM)       { dState = 'danger';  verdict = 'Obstacle very close. Stop.'; alerts.push('obstacle ' + Math.round(dist) + ' cm away'); }
        else if (dist <= CAUTION_CM) { dState = 'caution'; verdict = 'Obstacle ahead. Slow down.'; }
        else                         { dState = 'safe';    verdict = 'Path ahead is clear'; }
    } else if (dist === 0) { verdict = 'No echo from the sensor'; }
    setState($('sonar'), dState);
    $('distance').textContent = (dist !== null && dist > 0) ? Math.round(dist) : '--';
    $('verdict').textContent = verdict;
    $('blip').setAttribute('d', (dist > 0) ? arcPath(Math.min(dist, MAX_CM) / MAX_CM * R) : '');
    drawHistory(d.history, dState);

    const wet = d.water === 'Danger';
    setState($('water'), d.has_data ? (wet ? 'danger' : 'safe') : 'idle');
    $('waterValue').textContent = d.has_data ? (wet ? 'Wet surface' : 'Dry') : '--';
    $('waterHint').textContent  = d.has_data ? (wet ? 'Slip risk on the path' : 'No water detected') : 'Waiting for data';
    if (d.has_data && wet) alerts.push('wet floor');

    const fell = d.tilt === 'Danger';
    setState($('tilt'), d.has_data ? (fell ? 'danger' : 'safe') : 'idle');
    $('tiltValue').textContent = d.has_data ? (fell ? 'Tilted or fallen' : 'Upright') : '--';
    $('tiltHint').textContent  = d.has_data ? (fell ? 'The user may have fallen' : 'Stick is held normally') : 'Waiting for data';
    if (d.has_data && fell) alerts.push('possible fall');

    const sum = $('summary');
    if (!d.has_data) {
        setState(sum, 'idle'); $('summaryIcon').className = 'fa-solid fa-circle-info';
        $('summaryTitle').textContent = 'Waiting for the stick'; $('summaryText').textContent = 'No readings have arrived yet.';
    } else if (alerts.length) {
        setState(sum, 'danger'); $('summaryIcon').className = 'fa-solid fa-triangle-exclamation';
        $('summaryTitle').textContent = alerts.length === 1 ? 'Needs attention' : alerts.length + ' alerts need attention';
        $('summaryText').textContent = alerts.join(', ').replace(/^./, c => c.toUpperCase()) + '.';
    } else if (dState === 'caution') {
        setState(sum, 'caution'); $('summaryIcon').className = 'fa-solid fa-circle-exclamation';
        $('summaryTitle').textContent = 'Obstacle nearby'; $('summaryText').textContent = 'The user is approaching something. No other alerts.';
    } else {
        setState(sum, 'safe'); $('summaryIcon').className = 'fa-solid fa-circle-check';
        $('summaryTitle').textContent = 'Everything looks normal'; $('summaryText').textContent = 'Path clear, floor dry, stick upright.';
    }
    alerts.length ? speak('Alert. ' + alerts.join(', ')) : (lastSpoken = '');

    const hasFix = d.has_data && (d.lat !== 0 || d.lng !== 0);
    $('lat').textContent = hasFix ? d.lat.toFixed(6) : '--';
    $('lng').textContent = hasFix ? d.lng.toFixed(6) : '--';
    const link = $('mapLink');
    if (hasFix) {
        link.href = `https://www.google.com/maps?q=${d.lat},${d.lng}`;
        link.removeAttribute('aria-disabled');
        const b = 0.004;
        const src = `https://www.openstreetmap.org/export/embed.html?bbox=${d.lng - b},${d.lat - b},${d.lng + b},${d.lat + b}&layer=mapnik&marker=${d.lat},${d.lng}`;
        let frame = $('mapFrame');
        if (!frame) {
            frame = document.createElement('iframe'); frame.id = 'mapFrame'; frame.title = 'Current location of the stick';
            $('map').appendChild(frame); $('mapEmpty').hidden = true;
        }
        if (frame.dataset.src !== src) { frame.src = src; frame.dataset.src = src; }
    } else { link.href = '#'; link.setAttribute('aria-disabled', 'true'); }
}

function setLive(ok) {
    setState($('live'), ok ? 'safe' : 'caution');
    $('liveText').textContent = ok
        ? 'Live · updated ' + new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' })
        : 'Connection lost. Retrying…';
}
async function poll() {
    try {
        const res = await fetch('?json=1', { cache: 'no-store' });
        if (!res.ok) throw new Error(res.status);
        render(await res.json()); setLive(true);
    } catch (e) { setLive(false); }
}

/* ---------- User tab ---------- */
async function api(name, body) {
    const fd = new FormData(); fd.append('csrf', CSRF);
    Object.entries(body || {}).forEach(([k, v]) => fd.append(k, v));
    const res = await fetch('?api=' + name, { method: 'POST', body: fd });
    const j = await res.json();
    if (!res.ok) throw new Error(j.error || 'Something went wrong.');
    return j;
}

function renderUser(p) {
    const u = p.user;
    $('f_name').value = u.full_name; $('f_age').value = u.age ?? '';
    $('f_phone').value = u.phone; $('f_addr').value = u.address; $('f_notes').value = u.notes;
    $('whoName').textContent = u.full_name || 'Stick user';
    $('whoMeta').textContent = [u.age ? u.age + ' years old' : '', u.phone].filter(Boolean).join(' · ') || 'Add the user\'s details below.';
    $('avatar').textContent = u.full_name ? u.full_name.trim().split(/\s+/).slice(0, 2).map(w => w[0]).join('').toUpperCase() : '?';
    renderContacts(p.contacts);
}

function renderContacts(list) {
    const box = $('contactList'); box.innerHTML = '';
    $('conCount').textContent = list.length + ' of 5 saved';
    if (!list.length) {
        const n = document.createElement('p'); n.className = 'empty-note';
        n.textContent = 'No contacts yet. Add someone who should be called in an emergency.';
        box.appendChild(n);
    }
    list.forEach(c => {
        const row = document.createElement('div'); row.className = 'contact';
        row.innerHTML = '<div class="ci"><i class="fa-solid fa-phone" aria-hidden="true"></i></div><div class="info"><b></b><span></span></div>';
        row.querySelector('b').textContent = c.name;
        row.querySelector('span').textContent = [c.relation, c.phone].filter(Boolean).join(' · ');

        const call = document.createElement('a'); call.className = 'icon-btn'; call.href = 'tel:' + c.phone;
        call.setAttribute('aria-label', 'Call ' + c.name); call.innerHTML = '<i class="fa-solid fa-phone-volume" aria-hidden="true"></i>';

        const del = document.createElement('button'); del.type = 'button'; del.className = 'icon-btn';
        del.setAttribute('aria-label', 'Remove ' + c.name); del.innerHTML = '<i class="fa-solid fa-trash" aria-hidden="true"></i>';
        let armed = false, timer;
        del.addEventListener('click', async () => {
            if (!armed) {
                armed = true; del.className = 'icon-btn confirm'; del.textContent = 'Tap again to remove';
                timer = setTimeout(() => { armed = false; del.className = 'icon-btn'; del.innerHTML = '<i class="fa-solid fa-trash" aria-hidden="true"></i>'; }, 3000);
                return;
            }
            clearTimeout(timer);
            try { renderUser(await api('delete_contact', { id: c.id })); toast('Contact removed'); }
            catch (e) { toast(e.message, true); }
        });
        row.append(call, del); box.appendChild(row);
    });
}

$('userForm').addEventListener('submit', async e => {
    e.preventDefault();
    try {
        renderUser(await api('save_user', {
            full_name: $('f_name').value, age: $('f_age').value, phone: $('f_phone').value,
            address: $('f_addr').value, notes: $('f_notes').value
        }));
        toast('User details saved');
    } catch (err) { toast(err.message, true); }
});

$('contactForm').addEventListener('submit', async e => {
    e.preventDefault();
    try {
        renderUser(await api('add_contact', { name: $('c_name').value, relation: $('c_rel').value, phone: $('c_phone').value }));
        $('c_name').value = $('c_rel').value = $('c_phone').value = '';
        toast('Contact added');
    } catch (err) { toast(err.message, true); }
});

render(<?= json_encode($data) ?>);
renderUser(<?= json_encode($initial) ?>);
setLive(true);
setInterval(poll, POLL_MS);
</script>
</body>
</html>
