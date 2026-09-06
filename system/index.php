<?php
session_start();
session_unset();
session_destroy();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CenLearn — Bago City College</title>
  <link rel="stylesheet" href="bower_components/font-awesome/css/font-awesome.min.css">
  <link rel="stylesheet" href="dist/css/cenlearn.css?v=2.6">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <script src="bower_components/jquery/dist/jquery.min.js"></script>
  <script src="dist/js/cenlearn-network.js?v=2.6"></script>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html { font-family: 'Inter', sans-serif; overflow-x: hidden; }
    body { font-family: 'Inter', sans-serif; }

    /* ══ LANDING ══ */
    #landing {
      position: relative;
      background: #000;
      display: flex; flex-direction: column;
      min-height: 100vh;
    }
    .hero {
      flex: 1; position: relative; overflow: hidden;
      display: flex; flex-direction: column;
    }
    .hero-bg {
      position: absolute; inset: 0;
      background: url('dist/img/bcc_entrance_hd.jpg') center/cover no-repeat;
      filter: brightness(.55);
      transform: scale(1.04);
      transition: transform 7s ease-out;
    }
    .hero-bg.loaded { transform: scale(1); }
    .hero-overlay {
      position: absolute; inset: 0;
      background: linear-gradient(to bottom,
        rgba(0,0,0,.55) 0%, rgba(0,0,0,.12) 35%,
        rgba(0,0,0,.12) 60%, rgba(0,0,0,.68) 100%);
    }
    
    /* Floating Particles */
    #particles {
      position: absolute; inset: 0; overflow: hidden; z-index: 1; pointer-events: none;
    }
    .particle {
      position: absolute; width: 4px; height: 4px;
      background: #2dd4bf; border-radius: 50%;
      opacity: 0.6; box-shadow: 0 0 10px #2dd4bf;
      animation: floatUp linear infinite;
    }
    @keyframes floatUp {
      0% { transform: translateY(100vh) rotate(0deg); opacity: 0; }
      10% { opacity: 0.6; }
      90% { opacity: 0.6; }
      100% { transform: translateY(-100px) rotate(360deg); opacity: 0; }
    }

    /* Nav */
    .landing-nav {
      position: relative; z-index: 10;
      display: flex; align-items: center; justify-content: space-between;
      padding: 16px 40px;
      background: rgba(0,0,0,.22);
      backdrop-filter: blur(6px);
      border-bottom: 1px solid rgba(255,255,255,.07);
    }
    .nav-logo { display: flex; align-items: center; gap: 11px; text-decoration: none; }
    .nav-logo-img {
      width: 46px; height: 46px; object-fit: cover;
      border-radius: 50%;
      border: 2px solid rgba(255,255,255,.3);
      box-shadow: 0 2px 8px rgba(0,0,0,.4);
      transition: transform .3s;
    }
    .nav-logo:hover .nav-logo-img { transform: scale(1.1); }
    .nav-logo-text { font-size: 18px; font-weight: 800; color: #fff; letter-spacing: -.3px; }
    .nav-logo-text span { color: #2dd4bf; }
    .nav-links { display: flex; align-items: center; gap: 6px; }
    .nav-link {
      padding: 7px 15px; border-radius: 7px; font-size: 13px; font-weight: 500;
      color: rgba(255,255,255,.85); text-decoration: none;
      transition: color .15s, background .15s;
    }
    .nav-link:hover { color: #fff; background: rgba(255,255,255,.1); }
    .nav-btn {
      display: inline-flex; align-items: center; gap: 7px;
      padding: 9px 20px; border-radius: 8px; font-size: 13px; font-weight: 700;
      background: #1792bb; color: #fff; border: none; cursor: pointer;
      font-family: 'Inter', sans-serif; transition: background .2s, transform .1s;
    }
    .nav-btn:hover { background: #0f5f80; transform: translateY(-1px); }

    /* Hero body */
    .hero-body {
      flex: 1; position: relative; z-index: 2;
      display: flex; flex-direction: column; justify-content: flex-end; align-items: flex-start;
      padding: 0 48px 52px;
      animation: fadeUp .8s .1s both;
    }
    @keyframes fadeUp { from{opacity:0;transform:translateY(20px);} to{opacity:1;transform:translateY(0);} }
    .hero-body h1 {
      font-size: clamp(20px, 3vw, 34px);
      font-weight: 800; color: #fff; line-height: 1.2;
      margin-bottom: 6px; max-width: 900px;
      white-space: normal;
      text-shadow: 0 2px 12px rgba(0,0,0,.4);
    }
    .hero-body p { font-size: 14px; color: rgba(255,255,255,.75); margin-bottom: 24px; }
    .btn-get-started {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 8px 18px; border-radius: 6px; border: none; cursor: pointer;
      background: #2dd4bf; color: #0f172a;
      font-size: 11px; font-weight: 800; letter-spacing: .6px; text-transform: uppercase;
      font-family: 'Inter', sans-serif;
      transition: background .2s, transform .15s, box-shadow .2s;
      box-shadow: 0 4px 18px rgba(45,212,191,.4);
      width: auto;
    }
    .btn-get-started:hover { background: #14b8a6; transform: translateY(-2px); }
    
    /* About Section */
    .about-section {
      background: linear-gradient(135deg, #1a2a52 0%, #0a1633 100%);
      padding: 80px 40px;
    }
    .about-container {
      max-width: 1000px;
      margin: 0 auto;
    }
    .section-title {
      text-align: center;
      color: #fbbf24;
      font-size: 28px;
      font-weight: 800;
      margin-bottom: 45px;
    }
    .vision-mission-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
      gap: 40px;
    }
    .vm-card {
      background: rgba(255,255,255,0.05);
      border: 1px solid rgba(255,255,255,0.1);
      border-radius: 16px;
      padding: 40px 30px;
      text-align: center;
      transition: all 0.4s ease;
    }
    .vm-card:hover {
      transform: translateY(-10px);
      background: rgba(255,255,255,0.08);
      border-color: rgba(45,212,191,0.3);
      box-shadow: 0 20px 60px rgba(0,0,0,0.3);
    }
    .vm-title {
      color: #fbbf24;
      font-size: 22px;
      font-weight: 800;
      margin-bottom: 20px;
      letter-spacing: 1px;
      text-transform: uppercase;
    }
    .vm-text {
      color: rgba(255,255,255,0.85);
      font-size: 16px;
      line-height: 1.8;
    }
    
    /* Footer */
    .footer {
      background: #0a1633;
      padding: 60px 40px;
      border-top: 1px solid rgba(255,255,255,0.05);
    }
    .footer-container {
      max-width: 1000px;
      margin: 0 auto;
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
      gap: 40px;
    }
    .footer-col {
      color: #fff;
    }
    .footer-title {
      color: #fbbf24;
      font-size: 18px;
      font-weight: 800;
      margin-bottom: 20px;
    }
    .footer-link {
      display: flex;
      align-items: center;
      gap: 10px;
      margin-bottom: 12px;
    }
    .footer-link i {
      color: #2dd4bf;
      font-size: 16px;
      width: 20px;
    }
    .footer-link a {
      color: rgba(255,255,255,0.8);
      text-decoration: none;
      font-size: 14px;
      transition: color 0.2s;
    }
    .footer-link a:hover {
      color: #fff;
    }
    .footer-text {
      color: rgba(255,255,255,0.8);
      font-size: 14px;
      line-height: 1.6;
    }

    /* ══ LOGIN MODAL — overlays the landing page ══ */
    #loginModal {
      position: fixed; inset: 0; z-index: 100;
      display: flex; align-items: center; justify-content: center;
      padding: 20px;
      background: transparent;
      backdrop-filter: none;
      -webkit-backdrop-filter: none;
      opacity: 0; pointer-events: none;
      transition: opacity .3s ease;
    }
    #loginModal.open {
      opacity: 1; pointer-events: all;
    }

    /* Modal Card */
    .login-card {
      background: rgba(15, 23, 42, 0.88);
      backdrop-filter: none;
      -webkit-backdrop-filter: none;
      border: 1px solid rgba(255, 255, 255, 0.2);
      border-radius: 24px;
      box-shadow: 0 25px 70px rgba(0, 0, 0, 0.7), inset 0 1px 1px rgba(255, 255, 255, 0.2);
      width: 100%; max-width: 480px;
      overflow: hidden;
      transform: translateY(20px) scale(.97);
      transition: transform .35s cubic-bezier(.4,0,.2,1);
      position: relative;
    }
    #loginModal.open .login-card {
      transform: translateY(0) scale(1);
    }

    /* Left — form */
    .lc-left { padding: 44px 40px; }
    @media(max-width:600px){ .lc-left { padding: 32px 24px; } }
    .lc-header { display: flex; align-items: center; gap: 16px; margin-bottom: 28px; }
    .lc-logo { width: 60px; height: 60px; object-fit: cover; border-radius: 50%; border: 2px solid rgba(255,255,255,.3); box-shadow: 0 4px 16px rgba(0,0,0,.4); }
    .lc-title { font-size: 28px; font-weight: 800; color: #ffffff; margin-bottom: 4px; letter-spacing: -0.5px; }
    .lc-subtitle { font-size: 11px; font-weight: 700; color: rgba(255, 255, 255, 0.65); letter-spacing: 1.2px; text-transform: uppercase; }
    .lc-label { display: block; font-size: 13px; font-weight: 600; color: #f1f5f9; margin-bottom: 7px; }
    .lc-input-wrap { position: relative; margin-bottom: 18px; }
    .lc-input {
      width: 100%; padding: 13px 16px;
      border: 1.5px solid rgba(255, 255, 255, 0.2); border-radius: 12px;
      font-size: 14px; font-family: 'Inter', sans-serif;
      color: #ffffff; background: rgba(255, 255, 255, 0.08);
      backdrop-filter: none;
      -webkit-backdrop-filter: none;
      transition: border-color .2s, box-shadow .2s, background .2s; outline: none;
    }
    .lc-input::placeholder { color: rgba(255, 255, 255, 0.45); }
    .lc-input:focus {
      background: rgba(255, 255, 255, 0.14);
      border-color: #2dd4bf;
      box-shadow: 0 0 0 3px rgba(45, 212, 191, 0.3);
      color: #ffffff;
    }
    .lc-toggle-pw {
      position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
      background: none; border: none; cursor: pointer; color: rgba(255, 255, 255, 0.55);
      padding: 4px; transition: color .15s;
    }
    .lc-toggle-pw:hover { color: #2dd4bf; }
    .lc-toggle-pw:focus { outline: none; }
    .lc-err {
      background: rgba(239, 68, 68, 0.2); border: 1px solid rgba(239, 68, 68, 0.4); color: #fca5a5;
      border-radius: 10px; padding: 10px 14px; font-size: 13px;
      margin-bottom: 14px; display: none; backdrop-filter: none;
    }
    .lc-btn-login {
      padding: 13px 36px; border: none; border-radius: 12px; cursor: pointer;
      background: linear-gradient(135deg, #2dd4bf 0%, #0d9488 100%);
      color: #0f172a;
      font-size: 15px; font-weight: 800; font-family: 'Inter', sans-serif;
      transition: background .2s, transform .1s, box-shadow .2s;
      box-shadow: 0 6px 20px rgba(45, 212, 191, 0.4);
      display: block; margin-top: 6px;
    }
    .lc-btn-login:hover {
      background: linear-gradient(135deg, #5eead4 0%, #14b8a6 100%);
      box-shadow: 0 8px 25px rgba(45, 212, 191, 0.55);
      transform: translateY(-1px);
    }
    .lc-btn-login:active { transform: scale(.98); }
    .lc-btn-login:disabled { opacity: .65; cursor: not-allowed; }

    /* Close button */
    .modal-close {
      position: absolute; top: 16px; right: 18px;
      width: 32px; height: 32px; border-radius: 50%;
      background: rgba(255, 255, 255, 0.1);
      backdrop-filter: none;
      -webkit-backdrop-filter: none;
      border: 1px solid rgba(255, 255, 255, 0.2);
      cursor: pointer;
      display: flex; align-items: center; justify-content: center;
      font-size: 16px; color: rgba(255, 255, 255, 0.8);
      transition: background .15s, color .15s, transform .15s;
      z-index: 3;
    }
    .modal-close:hover { background: rgba(255, 255, 255, 0.25); color: #ffffff; transform: scale(1.08); }
    .login-card { position: relative; }

    @media(max-width:480px){
      .landing-nav { padding: 14px 20px; }
      .hero-body { padding: 0 20px 36px; }
      .features-strip { padding: 12px 20px; gap: 18px; }
      .nav-link { display: none; }
    }
  </style>
</head>
<body>

<!-- ══ LANDING PAGE ══ -->
<div id="landing">
  <div class="hero">
    <div class="hero-bg" id="heroBg"></div>
    <div class="hero-overlay"></div>
    <!-- Floating Particles -->
        <nav class="landing-nav">
      <a href="#" class="nav-logo">
        <img src="dist/img/bcc_logo.jpg" alt="BCC" class="nav-logo-img">
        <span class="nav-logo-text">Cen<span>Learn</span></span>
      </a>
      <div class="nav-links">
        <a href="#about-section" class="nav-link" onclick="scrollToSection(event, 'about-section')">About</a>
        <a href="#contact-section" class="nav-link" onclick="scrollToSection(event, 'contact-section')">Contact</a>
        <a href="register" class="nav-link" style="color:#2dd4bf;font-weight:600;"><i class="fa fa-user-plus"></i> Register</a>
        <button class="nav-btn" onclick="openLogin()">
          <i class="fa fa-sign-in"></i> Login
        </button>
      </div>
    </nav>

    <div class="hero-body">
      <h1>CenLearn &mdash; Centralized Learning Management System</h1>
      <p>Bago City College's</p>
      <button class="btn-get-started" onclick="openLogin()">
        <i class="fa fa-sign-in"></i> GET STARTED
      </button>
    </div>
  </div>
</div>

<!-- About Section with Vision & Mission -->
<section class="about-section" id="about-section">
  <div class="about-container">
    <h2 class="section-title">About the Program</h2>
    <div class="vision-mission-grid">
      <div class="vm-card">
        <h3 class="vm-title">MISSION</h3>
        <p class="vm-text">
          The Teacher Education Program provides affordable education for aspiring teachers who are disciplined, highly competitive, and imbued with a strong sense of pride in the teaching profession.
        </p>
      </div>
      <div class="vm-card">
        <h3 class="vm-title">VISION</h3>
        <p class="vm-text">
          The Teacher Education Program produces highly competitive teachers who are committed to the task of educating the youth locally and globally.
        </p>
      </div>
    </div>
  </div>
</section>

<!-- Footer with Contact Info -->
<footer class="footer" id="contact-section">
  <div class="footer-container">
    <div class="footer-col">
      <h3 class="footer-title">CONTACT US</h3>
      <div class="footer-link">
        <i class="fa fa-phone"></i>
        <a href="tel:+63344611038">(034) 461 1038 / (034) 461 0963</a>
      </div>
      <div class="footer-link">
        <i class="fa fa-mobile"></i>
        <a href="tel:+639157125092">0915 712 5092 / 0917 2753 029</a>
      </div>
      <div class="footer-link">
        <i class="fa fa-envelope"></i>
        <a href="mailto:info@bagocitycollege.com">info@bagocitycollege.com</a>
      </div>
    </div>
    <div class="footer-col">
      <h3 class="footer-title">ADDRESS</h3>
      <p class="footer-text">
        Rafael Salas Drive, Bago City 6101<br>
        Neg. Occ., Philippines
      </p>
      <iframe
        src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3921.4066560960925!2d122.8375427741334!3d10.541194662858478!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x33ae977ab2d5b037%3A0x8b9309f496dd6e8e!2sBago%20City%20College!5e0!3m2!1sen!2sph!4v1719600000000"
        width="100%" height="200" style="border:0; border-radius:10px; margin-top:15px;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade">
      </iframe>
    </div>
  </div>
</footer>

<!-- ══ LOGIN MODAL (overlays landing) ══ -->
<div id="loginModal" onclick="handleOverlayClick(event)">
  <div class="login-card">
    <button class="modal-close" onclick="closeLogin()" title="Close">&times;</button>

    <!-- Left — Sign in form -->
    <form class="lc-left" id="loginForm" onsubmit="event.preventDefault(); login();">
      <div class="lc-header">
        <img src="dist/img/bcc_logo.jpg" alt="Bago City College" class="lc-logo">
        <div>
          <div class="lc-title">Sign in</div>
          <div class="lc-subtitle">Bago City College &mdash; CenLearn</div>
        </div>
      </div>

      <input type="hidden" id="txtCallback" value="">
      <input type="hidden" id="txtRequestId" value="">

      <label class="lc-label">User Name</label>
      <div class="lc-input-wrap">
        <input id="txtUserName" type="text" class="lc-input" placeholder="User Name" autocomplete="username">
      </div>

      <label class="lc-label">Password</label>
      <div class="lc-input-wrap">
        <input id="txtPassword" type="password" class="lc-input" placeholder="Password" autocomplete="current-password" style="padding-right:44px;">
        <button class="lc-toggle-pw" id="togglePwBtn" onclick="togglePassword()" type="button">
          <svg id="iconEye" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          <svg id="iconEyeOff" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none;"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
        </button>
      </div>

      <div id="errBox" class="lc-err"></div>
      <button id="btnLogin" type="submit" class="lc-btn-login">Log in</button>

      <div style="margin-top:22px; padding-top:18px; border-top:1px solid rgba(255,255,255,0.12); text-align:center; font-size:13px; color:rgba(255,255,255,0.7);">
        Don't have an account? Register as<br>
        <a href="teacher/register" style="color:#2dd4bf; font-weight:700; text-decoration:none; margin-right:6px;"><i class="fa fa-graduation-cap"></i> Teacher</a> | 
        <a href="superadmin/register" style="color:#c084fc; font-weight:700; text-decoration:none; margin-left:6px;"><i class="fa fa-shield"></i> Super Admin</a>
      </div>
    </form>

  </div>
</div>

<script>
// Smooth Scroll
function scrollToSection(e, id) {
  e.preventDefault();
  const el = document.getElementById(id);
  if (el) {
    el.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }
}

// Create Floating Particles
function createParticles() {
  const container = document.getElementById('particles');
  if (!container) return;
  const particleCount = 40;
  for (let i = 0; i < particleCount; i++) {
    const particle = document.createElement('div');
    particle.className = 'particle';
    const size = Math.random() * 3 + 2;
    particle.style.width = size + 'px';
    particle.style.height = size + 'px';
    particle.style.left = Math.random() * 100 + '%';
    const duration = Math.random() * 10 + 10;
    particle.style.animationDuration = duration + 's';
    particle.style.animationDelay = Math.random() * 10 + 's';
    container.appendChild(particle);
  }
}

// Typing Animation for Hero Title
function typeWriter(text, el, speed = 80) {
  let i = 0;
  el.innerHTML = '';
  function type() {
    if (i < text.length) {
      el.innerHTML += text.charAt(i);
      i++;
      setTimeout(type, speed);
    } else {
      el.innerHTML += '<span style="opacity:.4;">|</span>';
    }
  }
  type();
}

// Parallax Effect on Mouse Move
function handleMouseMove(e) {
  const heroBg = document.getElementById('heroBg');
  const heroBody = document.querySelector('.hero-body');
  const centerX = window.innerWidth / 2;
  const centerY = window.innerHeight / 2;
  const moveX = (e.clientX - centerX) / 60;
  const moveY = (e.clientY - centerY) / 60;
  if (heroBg) {
    heroBg.style.transform = 'scale(1.04) translate(' + moveX + 'px, ' + moveY + 'px)';
  }
  if (heroBody) {
    heroBody.style.transform = 'translate(' + moveX + 'px, ' + moveY + 'px)';
  }
}

// Scroll Reveal Animation
function handleScroll() {
  const aboutSection = document.querySelector('.about-section');
  const footer = document.querySelector('.footer');
  
  if (aboutSection) {
    const rect = aboutSection.getBoundingClientRect();
    if (rect.top < window.innerHeight * 0.85) {
      aboutSection.style.opacity = '1';
      aboutSection.style.transform = 'translateY(0)';
    }
  }
  
  if (footer) {
    const rect = footer.getBoundingClientRect();
    if (rect.top < window.innerHeight) {
      footer.style.opacity = '1';
      footer.style.transform = 'translateY(0)';
    }
  }
}

// Initialize everything on load
document.addEventListener('DOMContentLoaded', function() {
  createParticles();
  const heroTitle = document.querySelector('.hero-body h1');
  if (heroTitle) {
    const originalText = heroTitle.textContent || heroTitle.innerText;
    typeWriter(originalText, heroTitle);
  }
  document.addEventListener('mousemove', handleMouseMove);
  window.addEventListener('scroll', handleScroll);
  
  // Set initial state for scroll reveal
  const aboutSection = document.querySelector('.about-section');
  const footer = document.querySelector('.footer');
  if (aboutSection) {
    aboutSection.style.transition = 'all 0.8s ease';
    aboutSection.style.opacity = '0';
    aboutSection.style.transform = 'translateY(50px)';
  }
  if (footer) {
    footer.style.transition = 'all 0.8s ease';
    footer.style.opacity = '0';
    footer.style.transform = 'translateY(50px)';
  }
  
  // Trigger initial check
  handleScroll();
});
// ── Modal ──────────────────────────────────────────────────────────────────
function openLogin(){
  document.getElementById('loginModal').classList.add('open');
  setTimeout(function(){ document.getElementById('txtUserName').focus(); }, 350);
}
function closeLogin(){
  document.getElementById('loginModal').classList.remove('open');
  document.getElementById('errBox').style.display = 'none';
}
function handleOverlayClick(e){
  if(e.target === document.getElementById('loginModal')) closeLogin();
}
document.addEventListener('keydown', function(e){ if(e.key === 'Escape') closeLogin(); });

// Hero zoom
window.addEventListener('load', function(){
  setTimeout(function(){ document.getElementById('heroBg').classList.add('loaded'); }, 80);
});

// ── Auth ───────────────────────────────────────────────────────────────────
function togglePassword(){
  var pw  = document.getElementById('txtPassword');
  var eye = document.getElementById('iconEye');
  var off = document.getElementById('iconEyeOff');
  var btn = document.getElementById('togglePwBtn');
  var show = pw.type === 'password';
  pw.type = show ? 'text' : 'password';
  eye.style.display = show ? 'none' : '';
  off.style.display = show ? '' : 'none';
  btn.style.color   = show ? '#2dd4bf' : '#94a3b8';
  pw.focus();
}

function login(){
  var user = $('#txtUserName').val().trim();
  var pass = $('#txtPassword').val().trim();
  if(!user || !pass){ showErr('Please enter your username and password.'); return; }
  $('#errBox').hide();

  // Immediately close modal and show CenLearn loading screen
  closeLogin();
  showCenLoader('Loading');

  $.ajax({
    url: 'proxy', method: 'POST', dataType: 'JSON',
    data: { txtUserName: user, txtPassword: pass, txtCallback: $('#txtCallback').val(), txtRequestId: $('#txtRequestId').val() },
    success: function(res){
      if(!res.is_valid){
        hideCenLoader();
        openLogin();
        if(res.err_msg === 'API_DOWN_NO_CACHE'){
          showErr('⚠ Authentication server is offline. <a href="set_password" style="color:#dc2626;font-weight:700;">Click here to set your password</a> and log in.');
        } else {
          showErr('Invalid username or password. Please try again.');
        }
      } else {
        var role = (res.user_group || '').toUpperCase();
        var isGrad = !!res.graduated_at;
        var profileIncomplete = role === 'STUDENT'
          && (!res.first_name || !res.program_code || !res.year_level || !res.section);
        
        var targetUrl = 'dashboard';
        if(role === 'STUDENT' && isGrad)                  targetUrl = 'graduated';
        else if(role === 'STUDENT' && profileIncomplete)  targetUrl = 'complete_profile';
        else if(role === 'STUDENT')                       targetUrl = 'dashboard';
        else if(role === 'TEACHER' || role === 'FACULTY') targetUrl = 'teacher/dashboard';
        else if(role === 'SUPERADMIN' || role === 'ADMIN') targetUrl = 'superadmin/dashboard';

        window.location.href = targetUrl;
      }
    },
    error: function(xhr, status, err){
      hideCenLoader();
      openLogin();
      if(xhr.status === 0){
        showErr('Cannot connect to server. Check your internet connection.');
      } else if(xhr.status === 500){
        showErr('Server error. Please try again.');
      } else {
        showErr('Cannot connect to server. Please try again.');
      }
    }
  });
}

function showErr(msg){ $('#errBox').html(msg).show(); }
$(document).keypress(function(e){ if(e.which === 13 && $('#loginModal').hasClass('open')) login(); });

// Fill credentials for Superadmin helper
function fillSuperadmin(){
  document.getElementById('txtUserName').value = 'SUPERADMIN';
  document.getElementById('txtPassword').value = 'superadmin123';
  var pw  = document.getElementById('txtPassword');
  var eye = document.getElementById('iconEye');
  var off = document.getElementById('iconEyeOff');
  var btn = document.getElementById('togglePwBtn');
  pw.type = 'text';
  eye.style.display = 'none';
  off.style.display = '';
  btn.style.color   = '#2dd4bf';
}

// Show kicked message if redirected from another device
<?php if(!empty($_GET['kicked'])): ?>
document.addEventListener('DOMContentLoaded', function(){
  openLogin();
  document.getElementById('errBox').style.cssText = 'display:block;background:#fef3c7;border-color:#fcd34d;color:#92400e;padding:12px;border-radius:8px;font-size:13px;line-height:1.5;margin-bottom:15px;';
  document.getElementById('errBox').textContent = '⚠ Your account was signed in on another device. You have been logged out.';
});
<?php endif; ?>

<?php if(!empty($_GET['from']) && $_GET['from'] === 'admin'): ?>
document.addEventListener('DOMContentLoaded', function(){
  openLogin();
  document.getElementById('errBox').style.cssText = 'display:block;background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8;padding:12px;border-radius:8px;font-size:13px;line-height:1.5;margin-bottom:15px;';
  document.getElementById('errBox').innerHTML = '💡 <strong>Recommendation:</strong> To access the Admin panel, please log in with your Admin credentials. If you haven\'t inserted/created an Admin account yet, log in as <a href="javascript:void(0)" onclick="fillSuperadmin()" style="color:#1d4ed8;font-weight:700;text-decoration:underline;">SUPERADMIN</a> to create one.';
});
<?php endif; ?>

<?php if(!empty($_GET['role_mismatch']) && $_GET['role_mismatch'] === 'admin'): ?>
document.addEventListener('DOMContentLoaded', function(){
  openLogin();
  document.getElementById('errBox').style.cssText = 'display:block;background:#fee2e2;border-color:#fecaca;color:#991b1b;padding:12px;border-radius:8px;font-size:13px;line-height:1.5;margin-bottom:15px;';
  document.getElementById('errBox').innerHTML = '⚠️ <strong>Access Denied:</strong> Your account does not have Admin privileges. Please log in with an Admin account, or log in as <a href="javascript:void(0)" onclick="fillSuperadmin()" style="color:#991b1b;font-weight:700;text-decoration:underline;">SUPERADMIN</a> to insert/create one.';
});
<?php endif; ?>

// Standalone fallback functions for instant loading execution
window.showCenLoader = function(msg) {
  var el = document.getElementById('cenlearn-universal-overlay');
  if(el) {
    var label = document.getElementById('cl-overlay-label');
    if(label) label.textContent = msg || 'Loading';
    var dots = document.getElementById('cl-overlay-dots');
    if(dots) dots.style.display = 'inline-block';
    var ring = document.getElementById('cl-overlay-ring');
    if(ring) { ring.classList.remove('offline', 'success'); }
    var retry = document.getElementById('cl-overlay-retry-btn');
    if(retry) retry.style.display = 'none';
    el.style.display = 'flex';
    setTimeout(function(){ el.classList.add('active'); }, 10);
  }
};

window.hideCenLoader = function() {
  var el = document.getElementById('cenlearn-universal-overlay');
  if(el) {
    el.classList.remove('active');
    setTimeout(function(){ el.style.display = 'none'; }, 250);
  }
};
</script>

<!-- Static Universal CenLearn Floating Loader & Network Overlay -->
<div id="cenlearn-universal-overlay" class="cl-fs-loader" style="display:none;">
  <div class="cl-loader-brand">
    <span class="brand-cen">Cen</span><span class="brand-learn">Learn</span>
  </div>
  <div class="cl-loader-spinner-wrap">
    <div id="cl-overlay-ring" class="cl-loader-spinner-ring"></div>
    <img id="cl-overlay-logo" src="dist/img/bcc_logo.jpg" alt="Bago City College" class="cl-loader-logo">
  </div>
  <div id="cl-overlay-text" class="cl-loader-text">
    <span id="cl-overlay-label">Loading</span><span id="cl-overlay-dots" class="loading-dots"></span>
  </div>
  <button id="cl-overlay-retry-btn" class="cl-loader-retry-btn" style="display:none;" type="button" onclick="location.reload()">
    <i class="fa fa-refresh"></i> Retry Connection
  </button>
</div>

<style>
.cl-fs-loader {
  position: fixed !important;
  inset: 0 !important;
  background: transparent !important;
  backdrop-filter: none !important;
  -webkit-backdrop-filter: none !important;
  z-index: 999999 !important;
  display: flex !important;
  flex-direction: column !important;
  align-items: center !important;
  justify-content: center !important;
  font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif !important;
  padding: 20px !important;
  opacity: 0;
  pointer-events: none;
  transition: opacity 0.25s cubic-bezier(0.4, 0, 0.2, 1);
}
.cl-fs-loader.active {
  opacity: 1 !important;
  pointer-events: all !important;
}
.cl-loader-brand {
  font-size: clamp(20px, 4.5vw, 24px) !important;
  font-weight: 800 !important;
  margin-bottom: clamp(14px, 2.5vw, 18px) !important;
  animation: clPulseFade 2s infinite ease-in-out !important;
  text-shadow: 0 4px 16px rgba(0, 0, 0, 0.95) !important;
  letter-spacing: -0.3px !important;
}
.cl-loader-brand .brand-cen { color: #60a5fa !important; }
.cl-loader-brand .brand-learn { color: #ffffff !important; }

.cl-loader-spinner-wrap {
  position: relative !important;
  width: clamp(66px, 14vw, 80px) !important;
  height: clamp(66px, 14vw, 80px) !important;
  margin-bottom: clamp(14px, 2.5vw, 18px) !important;
  display: flex !important;
  align-items: center !important;
  justify-content: center !important;
}
.cl-loader-spinner-ring {
  position: absolute !important;
  inset: 0 !important;
  border: 3.5px solid rgba(30, 58, 138, 0.55) !important;
  border-top-color: #60a5fa !important;
  border-radius: 50% !important;
  animation: clSpin 1s linear infinite !important;
  box-shadow: 0 0 20px rgba(96, 165, 250, 0.35) !important;
}
.cl-loader-spinner-ring.offline {
  border-color: rgba(239, 68, 68, 0.25) !important;
  border-top-color: #ef4444 !important;
  box-shadow: 0 0 20px rgba(239, 68, 68, 0.4) !important;
}
.cl-loader-logo {
  width: clamp(42px, 9vw, 52px) !important;
  height: clamp(42px, 9vw, 52px) !important;
  object-fit: contain !important;
  border-radius: 50% !important;
  background-color: #ffffff !important;
  padding: 3px !important;
  animation: clPulseLogo 2s infinite ease-in-out !important;
  box-shadow: 0 3px 12px rgba(0, 0, 0, 0.5) !important;
}
.cl-loader-text {
  font-size: clamp(13px, 3vw, 15px) !important;
  color: #ffffff !important;
  font-weight: 700 !important;
  letter-spacing: 0.5px !important;
  display: flex !important;
  align-items: flex-end !important;
  text-shadow: 0 2px 8px rgba(0, 0, 0, 0.95) !important;
}
.loading-dots {
  display: inline-block !important;
  overflow: hidden !important;
  vertical-align: bottom !important;
  animation: clDotsWidth 1.5s steps(4, end) infinite !important;
}
.loading-dots::after {
  content: '...' !important;
}
@keyframes clSpin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
@keyframes clPulseLogo { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.08); } }
@keyframes clPulseFade { 0%, 100% { opacity: 1; } 50% { opacity: 0.8; } }
@keyframes clDotsWidth { 0% { width: 0px; } 100% { width: 1.25em; } }
</style>

</body>
</html>
