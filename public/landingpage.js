/* ═══════════════════════════════════════════════════════════
   SportSync — script.js
   ═══════════════════════════════════════════════════════════ */

'use strict';

/* ── Utility: query helpers ── */
const $ = (sel, ctx = document) => ctx.querySelector(sel);
const $$ = (sel, ctx = document) => [...ctx.querySelectorAll(sel)];

/* ══════════════════════════════════════════════
   1. STICKY NAVBAR — add .scrolled at 50px
   ══════════════════════════════════════════════ */
(function initStickyNavbar() {
  const navbar = $('#navbar');
  if (!navbar) return;

  function onScroll() {
    navbar.classList.toggle('scrolled', window.scrollY > 50);
  }

  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll(); // run once on load
})();

/* ══════════════════════════════════════════════
   2. ACTIVE NAV LINK — highlight on scroll
   ══════════════════════════════════════════════ */
(function initActiveNavLinks() {
  const navLinks = $$('.nav-link');
  const sections = $$('section[id], div[id]');
  const navH = parseInt(getComputedStyle(document.documentElement)
    .getPropertyValue('--nav-h')) || 68;

  function updateActive() {
    let current = '';
    sections.forEach(sec => {
      if (window.scrollY >= sec.offsetTop - navH - 60) {
        current = sec.id;
      }
    });
    navLinks.forEach(link => {
      const href = link.getAttribute('href');
      link.classList.toggle('active', href === `#${current}`);
    });
  }

  window.addEventListener('scroll', updateActive, { passive: true });
  updateActive();
})();

/* ══════════════════════════════════════════════
   3. MOBILE HAMBURGER — toggle .open
   ══════════════════════════════════════════════ */
(function initHamburger() {
  const hamburger = $('#hamburger');
  const navLinks = $('#nav-links');
  if (!hamburger || !navLinks) return;

  hamburger.addEventListener('click', () => {
    const isOpen = hamburger.classList.toggle('open');
    navLinks.classList.toggle('open', isOpen);
    hamburger.setAttribute('aria-expanded', String(isOpen));
  });

  // Close on nav link click (mobile)
  $$('.nav-link').forEach(link => {
    link.addEventListener('click', () => {
      hamburger.classList.remove('open');
      navLinks.classList.remove('open');
      hamburger.setAttribute('aria-expanded', 'false');
    });
  });

  // Close when clicking outside
  document.addEventListener('click', (e) => {
    if (!hamburger.contains(e.target) && !navLinks.contains(e.target)) {
      hamburger.classList.remove('open');
      navLinks.classList.remove('open');
      hamburger.setAttribute('aria-expanded', 'false');
    }
  });
})();

/* ══════════════════════════════════════════════
   4. SPORT CARD SELECTION — .active class toggle
   ══════════════════════════════════════════════ */
(function initSportCards() {
  const cards = $$('.sport-card');
  if (!cards.length) return;

  cards.forEach(card => {
    card.addEventListener('click', (e) => {
      // Allow href navigation but also set active state
      cards.forEach(c => c.classList.remove('active'));
      card.classList.add('active');
      // Store selection in sessionStorage so it persists across navigations
      sessionStorage.setItem('activeSport', card.dataset.sport || '');
    });
  });

  // Restore active state if returning to page
  const stored = sessionStorage.getItem('activeSport');
  if (stored) {
    const match = cards.find(c => c.dataset.sport === stored);
    if (match) match.classList.add('active');
  }
})();

/* ══════════════════════════════════════════════
   5. SMOOTH SCROLL — all internal anchor links
   ══════════════════════════════════════════════ */
(function initSmoothScroll() {
  const navH = () => parseInt(
    getComputedStyle(document.documentElement).getPropertyValue('--nav-h')
  ) || 68;

  document.addEventListener('click', (e) => {
    const anchor = e.target.closest('a[href^="#"]');
    if (!anchor) return;

    const targetId = anchor.getAttribute('href').slice(1);
    if (!targetId) return;

    const target = document.getElementById(targetId);
    if (!target) return;

    e.preventDefault();
    const top = target.getBoundingClientRect().top + window.scrollY - navH();
    window.scrollTo({ top, behavior: 'smooth' });
  });
})();

/* ══════════════════════════════════════════════
   6. SCROLL REVEAL — IntersectionObserver
   ══════════════════════════════════════════════ */
(function initScrollReveal() {
  const items = $$('.reveal');
  if (!items.length) return;

  const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        entry.target.classList.add('visible');
        observer.unobserve(entry.target); // animate once
      }
    });
  }, {
    threshold: 0.12,
    rootMargin: '0px 0px -40px 0px'
  });

  // Stagger cards within grids
  $$('.sports-grid .sport-card, .features-grid .feature-card').forEach((el, i) => {
    el.style.transitionDelay = `${(i % 5) * 80}ms`;
  });

  items.forEach(el => observer.observe(el));
})();

/* ══════════════════════════════════════════════
   7. HERO PARTICLE CANVAS — animated particles
   ══════════════════════════════════════════════ */
(function initHeroCanvas() {
  const canvas = $('#heroCanvas');
  if (!canvas) return;

  const ctx = canvas.getContext('2d');
  let width, height, particles;
  const PARTICLE_COUNT = 70;
  const YELLOW = 'rgba(255, 215, 0,';
  const BLUE   = 'rgba(21, 101, 192,';

  class Particle {
    constructor() { this.reset(true); }

    reset(random = false) {
      this.x = Math.random() * width;
      this.y = random ? Math.random() * height : height + 10;
      this.r = Math.random() * 1.8 + 0.4;
      this.speedY = -(Math.random() * 0.5 + 0.2);
      this.speedX = (Math.random() - 0.5) * 0.3;
      this.alpha = Math.random() * 0.6 + 0.2;
      this.color = Math.random() > 0.5 ? YELLOW : BLUE;
    }

    update() {
      this.x += this.speedX;
      this.y += this.speedY;
      if (this.y < -10) this.reset();
    }

    draw() {
      ctx.beginPath();
      ctx.arc(this.x, this.y, this.r, 0, Math.PI * 2);
      ctx.fillStyle = `${this.color}${this.alpha})`;
      ctx.fill();
    }
  }

  function resize() {
    width  = canvas.width  = canvas.offsetWidth;
    height = canvas.height = canvas.offsetHeight;
  }

  function init() {
    resize();
    particles = Array.from({ length: PARTICLE_COUNT }, () => new Particle());
  }

  function animate() {
    ctx.clearRect(0, 0, width, height);

    // Draw connecting lines between close particles
    for (let i = 0; i < particles.length; i++) {
      for (let j = i + 1; j < particles.length; j++) {
        const dx = particles[i].x - particles[j].x;
        const dy = particles[i].y - particles[j].y;
        const dist = Math.sqrt(dx * dx + dy * dy);
        if (dist < 100) {
          ctx.beginPath();
          ctx.moveTo(particles[i].x, particles[i].y);
          ctx.lineTo(particles[j].x, particles[j].y);
          ctx.strokeStyle = `rgba(255,215,0,${0.08 * (1 - dist / 100)})`;
          ctx.lineWidth = 0.5;
          ctx.stroke();
        }
      }
      particles[i].update();
      particles[i].draw();
    }

    requestAnimationFrame(animate);
  }

  const ro = new ResizeObserver(resize);
  ro.observe(canvas.parentElement || canvas);

  init();
  animate();
})();
