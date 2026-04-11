'use strict';

// ── Password show/hide toggle ────────────────────────────────
function togglePw(inputId, btn) {
  const inp = document.getElementById(inputId);
  if (!inp) return;
  const show = inp.type === 'password';
  inp.type = show ? 'text' : 'password';
  btn.style.opacity = show ? '1' : '0.5';
  btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
}

// ── Password strength meter ──────────────────────────────────
(function initStrength() {
  const pw  = document.getElementById('password');
  const bar = document.getElementById('pwBar');
  if (!pw || !bar) return;

  pw.addEventListener('input', function () {
    const v = pw.value;
    let score = 0;
    if (v.length >= 8)                     score++;
    if (v.length >= 12)                    score++;
    if (/[A-Z]/.test(v))                   score++;
    if (/[0-9]/.test(v))                   score++;
    if (/[^A-Za-z0-9]/.test(v))            score++;

    const pct   = Math.min(score / 5, 1) * 100;
    const color = score <= 1 ? '#e74c3c'
                : score <= 2 ? '#e67e22'
                : score <= 3 ? '#f1c40f'
                : score <= 4 ? '#2ecc71'
                :              '#00e676';
    bar.style.width      = pct + '%';
    bar.style.background = color;
  });
})();

// ── Confirm-password match hint ──────────────────────────────
(function initConfirm() {
  const pw  = document.getElementById('password');
  const cf  = document.getElementById('confirm');
  if (!pw || !cf) return;

  function check() {
    if (!cf.value) { cf.style.borderColor = ''; return; }
    cf.style.borderColor = pw.value === cf.value ? '#27ae60' : '#e74c3c';
  }
  cf.addEventListener('input', check);
  pw.addEventListener('input', check);
})();

// ── Particle canvas (same as landing page) ───────────────────
(function initCanvas() {
  const canvas = document.getElementById('authCanvas');
  if (!canvas) return;
  const ctx = canvas.getContext('2d');
  let W, H, particles;
  const COUNT  = 55;
  const YELLOW = 'rgba(255,215,0,';
  const BLUE   = 'rgba(21,101,192,';

  class Particle {
    constructor() { this.reset(true); }
    reset(rand = false) {
      this.x      = Math.random() * W;
      this.y      = rand ? Math.random() * H : H + 10;
      this.r      = Math.random() * 1.6 + 0.4;
      this.speedY = -(Math.random() * 0.45 + 0.18);
      this.speedX = (Math.random() - 0.5) * 0.28;
      this.alpha  = Math.random() * 0.55 + 0.18;
      this.color  = Math.random() > 0.5 ? YELLOW : BLUE;
    }
    update() {
      this.x += this.speedX;
      this.y += this.speedY;
      if (this.y < -10) this.reset();
    }
    draw() {
      ctx.beginPath();
      ctx.arc(this.x, this.y, this.r, 0, Math.PI * 2);
      ctx.fillStyle = this.color + this.alpha + ')';
      ctx.fill();
    }
  }

  function resize() {
    W = canvas.width  = canvas.offsetWidth;
    H = canvas.height = canvas.offsetHeight;
  }

  function init() {
    resize();
    particles = Array.from({ length: COUNT }, () => new Particle());
  }

  function animate() {
    ctx.clearRect(0, 0, W, H);
    for (let i = 0; i < particles.length; i++) {
      for (let j = i + 1; j < particles.length; j++) {
        const dx   = particles[i].x - particles[j].x;
        const dy   = particles[i].y - particles[j].y;
        const dist = Math.sqrt(dx * dx + dy * dy);
        if (dist < 90) {
          ctx.beginPath();
          ctx.moveTo(particles[i].x, particles[i].y);
          ctx.lineTo(particles[j].x, particles[j].y);
          ctx.strokeStyle = 'rgba(255,215,0,' + (0.07 * (1 - dist / 90)) + ')';
          ctx.lineWidth   = 0.5;
          ctx.stroke();
        }
      }
      particles[i].update();
      particles[i].draw();
    }
    requestAnimationFrame(animate);
  }

  new ResizeObserver(resize).observe(canvas.parentElement || canvas);
  init();
  animate();
})();