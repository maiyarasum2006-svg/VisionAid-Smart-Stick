<?php
session_start();
$error = "";

// Authentication check for presentation
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $u = $_POST['username'] ?? '';
    $p = $_POST['password'] ?? '';
    if (hash_equals('admin', $u) && hash_equals('12345', $p)) {
        session_regenerate_id(true);
        $_SESSION['logged_in'] = true;
        header("Location: index.php");
        exit;
    } else {
        $error = "Access denied. Check your username and password.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VisionAid | Admin Login</title>
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@500;700&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root{
  --bg:#07141c; --panel:rgba(14,36,48,.72); --line:#1d4254; --text:#e8f4f1;
  --muted:#8fb0b5; --ping:#7ee0c3; --alert:#ff6b5b;
}
*{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%}
body{
  background:radial-gradient(ellipse at 20% 100%,#0d2a38 0%,var(--bg) 60%);
  color:var(--text);font-family:'Instrument Sans',system-ui,sans-serif;
  display:flex;justify-content:center;align-items:center;overflow:hidden;
}
#sonar{position:fixed;inset:0;width:100%;height:100%;z-index:0}

.card{
  position:relative;z-index:1;width:calc(100% - 32px);max-width:410px;padding:38px 34px 32px;
  background:var(--panel);backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);
  border:1px solid var(--line);border-radius:22px;
  box-shadow:0 24px 60px rgba(0,0,0,.45);
  transform:perspective(900px) rotateX(var(--ry,0deg)) rotateY(var(--rx,0deg));
  transition:transform .15s ease-out;
}
.card.shake{animation:shake .45s}
@keyframes shake{20%{translate:-9px 0}40%{translate:8px 0}60%{translate:-5px 0}80%{translate:3px 0}}

.brand{display:flex;align-items:center;gap:14px;margin-bottom:26px}
.brand .mark{
  width:52px;height:52px;border-radius:50%;display:grid;place-items:center;
  border:1.5px solid var(--ping);color:var(--ping);font-size:1.3rem;position:relative;
}
.brand .mark::after{
  content:"";position:absolute;inset:-1.5px;border-radius:50%;border:1.5px solid var(--ping);
  animation:pulse 2.8s ease-out infinite;
}
@keyframes pulse{0%{transform:scale(1);opacity:.7}100%{transform:scale(1.9);opacity:0}}
.brand h1{font-family:'Bricolage Grotesque',sans-serif;font-weight:700;font-size:1.65rem;letter-spacing:-.01em}
.brand p{color:var(--muted);font-size:.9rem;margin-top:2px}

.error-msg{
  display:flex;gap:10px;align-items:flex-start;color:#ffb4ab;background:rgba(255,107,91,.1);
  border:1px solid rgba(255,107,91,.35);padding:11px 13px;border-radius:12px;
  margin-bottom:18px;font-size:.9rem;
}
.field{position:relative;margin-bottom:16px}
.field i.lead{position:absolute;left:15px;top:50%;translate:0 -50%;color:var(--muted);transition:color .2s}
.field input{
  width:100%;padding:15px 44px 15px 44px;border-radius:12px;font-size:1rem;color:var(--text);
  background:rgba(7,20,28,.7);border:1px solid var(--line);outline:none;font-family:inherit;
  transition:border-color .2s,box-shadow .2s;
}
.field input::placeholder{color:#58777e}
.field input:focus{border-color:var(--ping);box-shadow:0 0 0 4px rgba(126,224,195,.14)}
.field:focus-within i.lead{color:var(--ping)}
.toggle{
  position:absolute;right:6px;top:50%;translate:0 -50%;width:38px;height:38px;border:0;border-radius:9px;
  background:none;color:var(--muted);cursor:pointer;
}
.toggle:hover,.toggle:focus-visible{color:var(--ping);outline:none;background:rgba(126,224,195,.08)}
.caps{display:none;color:#f5c26b;font-size:.82rem;margin:-6px 0 12px 2px}
.caps.on{display:block}

.btn{
  width:100%;margin-top:6px;padding:15px;border:0;border-radius:12px;cursor:pointer;
  font:600 1rem 'Instrument Sans',sans-serif;color:#04201a;background:var(--ping);
  display:flex;justify-content:center;align-items:center;gap:10px;
  transition:transform .15s,box-shadow .2s,filter .2s;
}
.btn:hover{box-shadow:0 8px 28px rgba(126,224,195,.3);filter:brightness(1.06)}
.btn:active{transform:scale(.98)}
.btn:focus-visible{outline:2px solid #fff;outline-offset:3px}
.btn.loading{pointer-events:none;filter:saturate(.6)}
.btn.loading i{animation:spin .8s linear infinite}
@keyframes spin{to{rotate:360deg}}
.foot{margin-top:20px;text-align:center;color:var(--muted);font-size:.82rem}

@media (prefers-reduced-motion:reduce){
  .brand .mark::after,.card.shake,.btn.loading i{animation:none}
  .card{transform:none;transition:none}
}
</style>
</head>
<body>
<canvas id="sonar" aria-hidden="true"></canvas>

<main class="card" id="card">
  <div class="brand">
    <div class="mark"><i class="fa-solid fa-wave-square"></i></div>
    <div>
      <h1>VisionAid</h1>
      <p>Admin portal</p>
    </div>
  </div>

  <?php if (!empty($error)): ?>
    <div class="error-msg" role="alert" id="err">
      <i class="fa-solid fa-circle-exclamation"></i><span><?= htmlspecialchars($error) ?></span>
    </div>
  <?php endif; ?>

  <form method="POST" action="" id="form" autocomplete="on">
    <div class="field">
      <i class="fa-solid fa-user lead"></i>
      <input type="text" name="username" placeholder="Username" aria-label="Username" autocomplete="username" required autofocus>
    </div>
    <div class="field">
      <i class="fa-solid fa-lock lead"></i>
      <input type="password" name="password" id="pw" placeholder="Password" aria-label="Password" autocomplete="current-password" required>
      <button type="button" class="toggle" id="toggle" aria-label="Show password"><i class="fa-solid fa-eye"></i></button>
    </div>
    <div class="caps" id="caps"><i class="fa-solid fa-arrow-up"></i> Caps Lock is on</div>
    <button type="submit" class="btn" id="btn"><span>Sign in</span><i class="fa-solid fa-arrow-right-to-bracket"></i></button>
  </form>
  <p class="foot">Live monitoring for the VisionAid smart stick</p>
</main>

<script>
(function(){
  const cv = document.getElementById('sonar'), ctx = cv.getContext('2d');
  const card = document.getElementById('card');
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
  let W, H, dpr, rings = [], pts = [], lastMove = 0;

  function resize(){
    dpr = Math.min(devicePixelRatio || 1, 2);
    W = innerWidth; H = innerHeight;
    cv.width = W * dpr; cv.height = H * dpr;
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    const n = Math.round(W * H / 14000);
    pts = Array.from({length: n}, () => ({x: Math.random()*W, y: Math.random()*H, g: 0, s: 1 + Math.random()*1.6}));
  }
  addEventListener('resize', resize); resize();

  function ping(x, y, color){
    if (reduce) return;
    rings.push({x, y, r: 0, max: Math.max(W, H) * .55, c: color || '126,224,195'});
    if (rings.length > 14) rings.shift();
  }

  // Ambient ping from bottom-left, like the stick sweeping ahead
  if (!reduce) setInterval(() => ping(W * .12, H * .95), 3200);
  setTimeout(() => ping(W * .12, H * .95), 300);

  addEventListener('pointermove', e => {
    const now = performance.now();
    if (now - lastMove > 420){ lastMove = now; ping(e.clientX, e.clientY); }
    if (!reduce && matchMedia('(hover:hover)').matches){
      const b = card.getBoundingClientRect();
      const dx = (e.clientX - (b.left + b.width/2)) / W, dy = (e.clientY - (b.top + b.height/2)) / H;
      card.style.setProperty('--rx', (dx * 7) + 'deg');
      card.style.setProperty('--ry', (-dy * 7) + 'deg');
    }
  });

  function frame(){
    ctx.clearRect(0, 0, W, H);
    for (const p of pts){
      for (const r of rings){
        if (Math.abs(Math.hypot(p.x - r.x, p.y - r.y) - r.r) < 9) p.g = 1;
      }
      p.g *= .965;
      ctx.beginPath();
      ctx.arc(p.x, p.y, p.s + p.g * 2.2, 0, 6.283);
      ctx.fillStyle = `rgba(126,224,195,${.13 + p.g * .85})`;
      ctx.fill();
    }
    for (let i = rings.length - 1; i >= 0; i--){
      const r = rings[i];
      r.r += 3.4;
      const a = Math.max(0, 1 - r.r / r.max);
      if (a <= 0){ rings.splice(i, 1); continue; }
      ctx.beginPath();
      ctx.arc(r.x, r.y, r.r, 0, 6.283);
      ctx.strokeStyle = `rgba(${r.c},${a * .5})`;
      ctx.lineWidth = 1.5;
      ctx.stroke();
    }
    requestAnimationFrame(frame);
  }
  reduce ? (function(){ frame(); })() : requestAnimationFrame(frame);

  // Ping from each field when focused
  document.querySelectorAll('.field input').forEach(i => {
    i.addEventListener('focus', () => {
      const b = i.getBoundingClientRect();
      ping(b.left + b.width/2, b.top + b.height/2);
    });
  });

  // Show / hide password
  const pw = document.getElementById('pw'), tg = document.getElementById('toggle');
  tg.addEventListener('click', () => {
    const show = pw.type === 'password';
    pw.type = show ? 'text' : 'password';
    tg.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    tg.firstElementChild.className = show ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
    pw.focus();
  });

  // Caps Lock hint
  const caps = document.getElementById('caps');
  const capsCheck = e => caps.classList.toggle('on', e.getModifierState && e.getModifierState('CapsLock'));
  pw.addEventListener('keydown', capsCheck); pw.addEventListener('keyup', capsCheck);
  pw.addEventListener('blur', () => caps.classList.remove('on'));

  // Submit state + ping from the button
  const form = document.getElementById('form'), btn = document.getElementById('btn');
  form.addEventListener('submit', () => {
    const b = btn.getBoundingClientRect();
    ping(b.left + b.width/2, b.top + b.height/2);
    btn.classList.add('loading');
    btn.firstElementChild.textContent = 'Verifying';
    btn.lastElementChild.className = 'fa-solid fa-circle-notch';
  });

  // Server-side error: shake the card and send a red ping
  if (document.getElementById('err')){
    card.classList.add('shake');
    const b = card.getBoundingClientRect();
    ping(b.left + b.width/2, b.top + b.height/2, '255,107,91');
  }
})();
</script>
</body>
</html>