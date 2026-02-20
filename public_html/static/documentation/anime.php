<?php
 define('DEV_LOCK_ENABLED', true);
 require_once __DIR__ . '/../../dev_lock.php';
?>
<!--
================================================================================
AnimeFX — Dokumentasi & Playground (tanpa Tailwind)
================================================================================

Tujuan file ini:
- Menjadi "satu halaman dokumentasi" cara pakai seluruh animasi AnimeFX kamu.
- Menjelaskan trigger: load (onload), scroll (IntersectionObserver), manual (API).
- Menjelaskan timing: duration, delay, ease.
- Menjelaskan utilities: once/repeat, stagger, moving-line vars, wave-span, typewrite,
  flip-logo, frag-reveal (image fragments), pulse, shake.
- Memberikan contoh siap-copy untuk HTML produksi + playground interaktif.

CATATAN PENTING
1) Pastikan anime.css & anime.js kamu di-load:
   - /static/assets/css/anime.css
   - /static/assets/js/anime.js
2) Trigger klik tidak built-in di AnimeFX (kecuali kamu bikin sendiri).
   Demo klik di bawah hanya "test harness" yang toggle class .show untuk latihan.
3) "expand-center" (clip-path) tidak ada di versi CSS kamu.
   Yang ada: expand-center-safe.
4) wave-text di contoh lama = SALAH. Yang benar: wave-span.
5) frag-reveal TIDAK boleh ditempel langsung di <img>. Wajib wrapper (figure/div).

================================================================================
-->

<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>AnimeFX Documentation & Playground</title>

  <!-- ✅ Load library kamu -->
  <link rel="stylesheet" href="/static/assets/css/anime.css">
  <script src="/static/assets/js/anime.js" defer></script>

  <style>
    /* =========================
      Page-only styles (aman)
    ========================= */
    :root{
      --doc-bg: #0b0e12;
      --doc-card: rgba(255,255,255,.06);
      --doc-border: rgba(255,255,255,.10);
      --doc-text: rgba(255,255,255,.92);
      --doc-muted: rgba(255,255,255,.64);
      --doc-soft: rgba(255,255,255,.45);
      --doc-accent: rgba(0, 168, 158, .95);
      --doc-shadow: 0 18px 60px rgba(0,0,0,.35);
    }
    body{
      margin:0;
      font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
      background: radial-gradient(1200px 600px at 20% 10%, rgba(0,168,158,.10), transparent 60%),
                  radial-gradient(900px 600px at 80% 30%, rgba(140, 90, 255,.10), transparent 60%),
                  var(--doc-bg);
      color: var(--doc-text);
      line-height: 1.55;
    }
    a{ color: inherit; }
    .wrap{
      max-width: 1150px;
      margin: 0 auto;
      padding: 28px 16px 100px;
    }

    .hero{
      border: 1px solid var(--doc-border);
      background: linear-gradient(180deg, rgba(255,255,255,.07), rgba(255,255,255,.03));
      border-radius: 18px;
      padding: 18px;
      box-shadow: var(--doc-shadow);
      overflow: hidden;
      position: relative;
    }
    .hero:before{
      content:"";
      position:absolute;
      inset:-2px;
      background: radial-gradient(600px 280px at 20% 0%, rgba(0,168,158,.22), transparent 55%),
                  radial-gradient(520px 260px at 85% 35%, rgba(140, 90, 255,.18), transparent 60%);
      pointer-events:none;
      opacity:.9;
    }
    .hero > *{ position: relative; }
    .hero h1{
      margin: 0 0 6px;
      font-size: 20px;
      letter-spacing:.2px;
    }
    .hero p{ margin: 0; color: var(--doc-muted); font-size: 13px; }

    .topbar{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap: 10px;
      flex-wrap: wrap;
      margin-top: 12px;
    }
    .pill{
      display:inline-flex;
      align-items:center;
      gap:8px;
      font-size: 12px;
      color: var(--doc-muted);
      padding: 6px 10px;
      border-radius: 999px;
      border: 1px solid var(--doc-border);
      background: rgba(255,255,255,.05);
      user-select:none;
      white-space: nowrap;
    }
    .dot{
      width: 8px; height: 8px;
      border-radius: 99px;
      background: var(--doc-accent);
      box-shadow: 0 0 0 4px rgba(0,168,158,.12);
    }

    .nav{
      margin-top: 14px;
      display:flex;
      flex-wrap: wrap;
      gap: 8px;
    }
    .nav a{
      text-decoration:none;
      border: 1px solid var(--doc-border);
      background: rgba(255,255,255,.05);
      padding: 8px 10px;
      border-radius: 12px;
      font-size: 12px;
      color: var(--doc-muted);
      transition: transform .15s ease, background .15s ease, border-color .15s ease;
    }
    .nav a:hover{
      transform: translateY(-1px);
      background: rgba(255,255,255,.07);
      border-color: rgba(255,255,255,.16);
      color: var(--doc-text);
    }

    .section{
      margin-top: 16px;
      padding: 16px;
      border: 1px solid var(--doc-border);
      background: rgba(255,255,255,.04);
      border-radius: 18px;
      box-shadow: 0 12px 40px rgba(0,0,0,.25);
    }
    .section h2{
      margin: 0 0 8px;
      font-size: 16px;
    }
    .section p{
      margin: 0 0 10px;
      color: var(--doc-muted);
      font-size: 13px;
    }
    .grid{
      display:grid;
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
      gap: 12px;
      margin-top: 12px;
    }
    .card{
      border: 1px solid var(--doc-border);
      background: rgba(255,255,255,.05);
      border-radius: 16px;
      padding: 12px;
      position: relative;
      overflow: hidden;
      box-shadow: 0 14px 44px rgba(0,0,0,.22);
    }
    .card h3{
      margin: 0 0 6px;
      font-size: 13px;
      letter-spacing: .15px;
    }
    .card .meta{
      margin: 0 0 10px;
      color: var(--doc-soft);
      font-size: 12px;
    }
    .demo{
      border: 1px dashed rgba(255,255,255,.14);
      background: rgba(0,0,0,.18);
      border-radius: 14px;
      padding: 12px;
      min-height: 68px;
      display:flex;
      align-items:center;
      justify-content:center;
      gap: 10px;
      position: relative;
      overflow:hidden;
    }
    .demo small{ color: var(--doc-soft); }

    .badge{
      display:inline-flex;
      gap: 6px;
      align-items:center;
      font-size: 11px;
      font-weight: 800;
      padding: 4px 8px;
      border-radius: 999px;
      border: 1px solid rgba(255,255,255,.14);
      background: rgba(255,255,255,.06);
      color: rgba(255,255,255,.85);
    }
    .kbd{
      font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
      font-size: 11px;
      padding: 2px 6px;
      border-radius: 8px;
      border: 1px solid rgba(255,255,255,.14);
      background: rgba(0,0,0,.22);
      color: rgba(255,255,255,.82);
      user-select:none;
      white-space: nowrap;
    }

    pre{
      margin: 10px 0 0;
      padding: 12px;
      border-radius: 14px;
      border: 1px solid rgba(255,255,255,.10);
      background: rgba(0,0,0,.32);
      overflow:auto;
      color: rgba(255,255,255,.88);
      font-size: 12px;
    }
    code{ font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }

    .actions{
      display:flex;
      flex-wrap: wrap;
      gap: 10px;
      margin-top: 10px;
    }
    .btn{
      appearance:none;
      border: 1px solid rgba(255,255,255,.12);
      background: rgba(255,255,255,.06);
      color: rgba(255,255,255,.9);
      border-radius: 12px;
      padding: 10px 12px;
      font-weight: 800;
      cursor:pointer;
      transition: transform .15s ease, background .15s ease, border-color .15s ease;
      user-select:none;
      font-size: 12px;
    }
    .btn:hover{
      transform: translateY(-1px);
      background: rgba(255,255,255,.08);
      border-color: rgba(255,255,255,.16);
    }
    .btn:active{ transform: scale(.98); }

    .spacer{
      height: 40vh;
      display:flex;
      align-items:center;
      justify-content:center;
      color: rgba(255,255,255,.35);
      font-size: 12px;
      user-select:none;
    }

    /* Image demo helpers */
    .imgbox{
      width:100%;
      max-width: 480px;
      border-radius: 14px;
      overflow:hidden;
      border: 1px solid rgba(255,255,255,.12);
      box-shadow: 0 16px 50px rgba(0,0,0,.28);
      background: rgba(0,0,0,.18);
    }
    .imgbox img{ width:100%; height:auto; display:block; }

    .brand{
      display:flex;
      align-items:center;
      gap: 10px;
      padding: 10px 12px;
      border-radius: 14px;
      border: 1px solid rgba(255,255,255,.12);
      background: rgba(0,0,0,.20);
    }
    .brand strong{ font-size: 13px; }
    .brand span{ font-size: 12px; color: var(--doc-muted); }

    /* Ensure moving-line looks nice inside demo area */
    .demo.moving-line{ padding-bottom: 18px; }

    /* Optional: highlight scroll demo area */
    .scroll-zone{
      border: 1px solid rgba(255,255,255,.12);
      background: rgba(255,255,255,.03);
      border-radius: 18px;
      padding: 14px;
    }
    .scroll-zone h3{ margin: 0 0 8px; font-size: 13px; }
    .scroll-zone p{ margin: 0 0 10px; color: var(--doc-muted); font-size: 12px; }

    /* Note blocks */
    .note{
      padding: 10px 12px;
      border-radius: 14px;
      border: 1px solid rgba(255,255,255,.12);
      background: rgba(0,168,158,.06);
      color: rgba(255,255,255,.82);
      font-size: 12px;
    }

    .warn{
      background: rgba(255, 200, 0, .06);
      border-color: rgba(255, 200, 0, .18);
    }

    .mono{ font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }

    /* Demo cards clickable */
    [data-demo-toggle="1"]{ cursor: pointer; }
  </style>
</head>

<body>
  <div class="wrap">
    <div class="hero" id="top">
      <h1 class="wave-span onload" data-anim-trigger="load" data-wave-step="14">
        AnimeFX — Dokumentasi Penggunaan & Playground
      </h1>
      <p>
        Semua animasi di koleksi kamu (fade/slide/zoom/blur/rotate/flip/pop, expand-center-safe, typewrite, wave-span,
        moving-line, flip-logo, frag-reveal, pulse, shake) + cara trigger load/scroll/manual + timing (delay/duration/ease).
      </p>

      <div class="topbar">
        <div class="pill"><span class="dot"></span> Trigger: <span class="kbd">scroll</span> (default), <span class="kbd">load</span>, <span class="kbd">manual</span></div>
        <div class="pill">Timing: <span class="kbd">data-duration</span>, <span class="kbd">data-delay</span>, <span class="kbd">data-ease</span></div>
        <div class="pill">State: <span class="kbd">.show</span> di-toggle oleh AnimeFX</div>
      </div>

      <div class="nav">
        <a href="#setup">Setup</a>
        <a href="#core">Konsep Inti</a>
        <a href="#timing">Delay / Duration / Ease</a>
        <a href="#triggers">Trigger Load / Scroll / Manual</a>
        <a href="#base">Base Reveal</a>
        <a href="#text">Text: Typewrite & Wave</a>
        <a href="#line">Moving Line</a>
        <a href="#logo">Flip Logo</a>
        <a href="#images">Images: Frag Reveal</a>
        <a href="#loop">Loop: Pulse & Shake</a>
        <a href="#stagger">Stagger</a>
        <a href="#recipes">Resep Produksi</a>
        <a href="#troubleshoot">Troubleshooting</a>
      </div>
    </div>

    <!-- =========================
      SETUP
    ========================= -->
    <section class="section" id="setup">
      <h2>1) Setup Minimal</h2>
      <p>
        Pastikan file CSS & JS kamu sudah ter-load. Idealnya: CSS di <span class="kbd">&lt;head&gt;</span>,
        JS di akhir body atau pakai <span class="kbd">defer</span>.
      </p>
      <pre><code>&lt;link rel="stylesheet" href="/static/assets/css/anime.css"&gt;
&lt;script src="/static/assets/js/anime.js" defer&gt;&lt;/script&gt;</code></pre>

      <div class="note warn" style="margin-top:10px">
        <b>Catatan:</b> AnimeFX otomatis scan DOM saat load + MutationObserver untuk elemen baru.
        Kalau kamu render konten via AJAX, kamu bisa pakai <span class="kbd">AnimeFX.refresh()</span>.
      </div>
    </section>

    <!-- =========================
      CORE CONCEPT
    ========================= -->
    <section class="section" id="core">
      <h2>2) Konsep Inti</h2>
      <p>
        Semua animasi “reveal” bekerja dengan cara yang sama:
        elemen mulai dalam keadaan “awal” (opacity 0 / transform / blur),
        lalu saat class <span class="kbd">.show</span> ditambahkan oleh AnimeFX → animasi jalan.
      </p>

      <div class="grid">
        <div class="card">
          <h3>State</h3>
          <p class="meta">
            <span class="badge">Tanpa .show</span> = hidden / transform awal<br>
            <span class="badge">Dengan .show</span> = tampil / transform normal
          </p>
          <div class="demo">
            <div class="fade-up" data-anim-trigger="manual" id="stateBox">
              <b>Halo!</b> <small>(toggle .show)</small>
            </div>
          </div>
          <div class="actions">
            <button class="btn" data-action="show" data-target="#stateBox">Add .show</button>
            <button class="btn" data-action="hide" data-target="#stateBox">Remove .show</button>
          </div>
          <pre><code>&lt;div class="fade-up" data-anim-trigger="manual" id="stateBox"&gt;...&lt;/div&gt;
AnimeFX.show('#stateBox'); // add .show
AnimeFX.hide('#stateBox'); // remove .show</code></pre>
        </div>

        <div class="card">
          <h3>Trigger</h3>
          <p class="meta">
            <span class="badge">scroll</span> default (IntersectionObserver)<br>
            <span class="badge">load</span> jalan setelah render 2 frame<br>
            <span class="badge">manual</span> kamu panggil via API
          </p>
          <div class="note">
            Tip: <span class="kbd">.onload</span> otomatis dianggap trigger <span class="kbd">load</span>
            (fallback), tapi lebih jelas kalau kamu set <span class="kbd">data-anim-trigger="load"</span>.
          </div>
        </div>

        <div class="card">
          <h3>Atribut (nama lama & nama baru)</h3>
          <p class="meta">
            AnimeFX kamu support dua versi atribut:
          </p>
          <pre><code>// trigger
data-anim-trigger="load|scroll|manual"
data-anime-trigger="..."   // legacy (masih kebaca)

// timing
data-duration / data-delay / data-ease           // legacy
data-anim-duration / data-anim-delay / data-anim-ease // baru (lebih jelas)

// once/repeat
data-anim-once="1|0"   (default 1)
data-anim-repeat="1|0" (default 0)</code></pre>
        </div>
      </div>
    </section>

    <!-- =========================
      TIMING
    ========================= -->
    <section class="section" id="timing">
      <h2>3) Delay / Duration / Ease</h2>
      <p>
        Timing dipasang per-elemen via atribut. Angka = milidetik (ms). Ease = string CSS easing.
      </p>

      <div class="grid">
        <div class="card">
          <h3>Contoh timing</h3>
          <div class="demo">
            <div class="zoom-in onload"
                 data-anim-trigger="load"
                 data-duration="1200"
                 data-delay="200"
                 data-ease="cubic-bezier(.16,1,.3,1)">
              Zoom-in durasi 1200ms, delay 200ms
            </div>
          </div>
          <pre><code>&lt;div class="zoom-in onload"
  data-anim-trigger="load"
  data-duration="1200"
  data-delay="200"
  data-ease="cubic-bezier(.16,1,.3,1)"&gt;...&lt;/div&gt;</code></pre>
        </div>

        <div class="card">
          <h3>Alternatif: utility class</h3>
          <p class="meta">
            Kamu punya utility delay/duration class: <span class="kbd">delay-200</span>, <span class="kbd">dur-1200</span>, dst.
          </p>
          <div class="demo">
            <div class="fade-up onload delay-200 dur-1200" data-anim-trigger="load">
              fade-up + delay-200 + dur-1200
            </div>
          </div>
          <pre><code>&lt;div class="fade-up onload delay-200 dur-1200" data-anim-trigger="load"&gt;...&lt;/div&gt;</code></pre>
        </div>

        <div class="card">
          <h3>Catatan ease</h3>
          <p class="meta">
            Default: <span class="kbd">cubic-bezier(.2,.8,.2,1)</span> dari :root.<br>
            Kamu bisa override per-elemen.
          </p>
          <pre><code>data-ease="cubic-bezier(.16,1,.3,1)"   // smooth & punchy
data-ease="ease-out"
data-ease="linear"</code></pre>
        </div>
      </div>
    </section>

    <!-- =========================
      TRIGGERS
    ========================= -->
    <section class="section" id="triggers">
      <h2>4) Trigger: Load / Scroll / Manual</h2>
      <p>
        AnimeFX menentukan kapan menambahkan <span class="kbd">.show</span>.
      </p>

      <div class="grid">
        <div class="card">
          <h3>Trigger: load</h3>
          <p class="meta">Jalan otomatis setelah render (2 frame), cocok untuk hero/header.</p>
          <div class="demo">
            <div class="flip-y onload"
                 data-anim-trigger="load"
                 data-duration="900"
                 data-delay="120">
              Load Trigger (flip-y)
            </div>
          </div>
          <pre><code>&lt;div class="flip-y onload" data-anim-trigger="load"&gt;...&lt;/div&gt;</code></pre>
        </div>

        <div class="card">
          <h3>Trigger: scroll (default)</h3>
          <p class="meta">Muncul saat masuk viewport (IntersectionObserver). Tanpa onload.</p>
          <div class="note">
            Scroll demo ada di bawah: cari section <span class="kbd">Scroll Playground</span>.
          </div>
          <pre><code>&lt;div class="fade-up" data-duration="900"&gt;...&lt;/div&gt;
// Tanpa data-anim-trigger + tanpa .onload => default scroll</code></pre>
        </div>

        <div class="card">
          <h3>Trigger: manual</h3>
          <p class="meta">Kamu kontrol via JS.</p>
          <div class="demo">
            <div class="slide-right" data-anim-trigger="manual" id="manualBox">
              Manual Trigger (slide-right)
            </div>
          </div>
          <div class="actions">
            <button class="btn" data-action="show" data-target="#manualBox">AnimeFX.show()</button>
            <button class="btn" data-action="hide" data-target="#manualBox">AnimeFX.hide()</button>
          </div>
          <pre><code>&lt;div id="manualBox" class="slide-right" data-anim-trigger="manual"&gt;...&lt;/div&gt;

AnimeFX.show('#manualBox');
AnimeFX.hide('#manualBox');</code></pre>
        </div>
      </div>

      <div class="spacer">Scroll ke bawah untuk melihat demo trigger <span class="mono">scroll</span> (elemen muncul saat masuk viewport).</div>

      <div class="scroll-zone">
        <h3>Scroll Playground</h3>
        <p>Semua item di bawah ini <b>tanpa</b> <span class="kbd">.onload</span> → default scroll. Coba scroll pelan.</p>

        <div class="grid">
          <div class="card">
            <h3>fade-up (scroll)</h3>
            <div class="demo">
              <div class="fade-up" data-duration="900">Scroll reveal: fade-up</div>
            </div>
            <pre><code>&lt;div class="fade-up" data-duration="900"&gt;...&lt;/div&gt;</code></pre>
          </div>

          <div class="card">
            <h3>zoom-in (scroll)</h3>
            <div class="demo">
              <div class="zoom-in" data-duration="900">Scroll reveal: zoom-in</div>
            </div>
            <pre><code>&lt;div class="zoom-in" data-duration="900"&gt;...&lt;/div&gt;</code></pre>
          </div>

          <div class="card">
            <h3>flip-y (scroll)</h3>
            <div class="demo">
              <div class="flip-y" data-duration="900">Scroll reveal: flip-y</div>
            </div>
            <pre><code>&lt;div class="flip-y" data-duration="900"&gt;...&lt;/div&gt;</code></pre>
          </div>
        </div>
      </div>

    </section>

    <!-- =========================
      BASE REVEAL ANIMATIONS
    ========================= -->
    <section class="section" id="base">
      <h2>5) Base Reveal Animations (butuh .show)</h2>
      <p>
        Animasi berikut memakai mekanisme yang sama (transition-based):
        fade/slide/zoom/blur/rotate/flip/pop + class <span class="kbd">anim</span> (opsional via data-anim).
      </p>

      <div class="note" style="margin-bottom:10px">
        <b>Tips:</b> Untuk produksi, kamu cukup pakai salah satu:
        <span class="kbd">class="fade-up onload" data-anim-trigger="load"</span> atau
        <span class="kbd">class="fade-up"</span> (default scroll).
      </div>

      <div class="grid" id="baseGrid">
        <!-- Cards will be generated by JS so docs stays clean -->
      </div>

      <div class="note warn" style="margin-top:12px">
        <b>Catatan:</b> <span class="kbd">.anim</span> adalah base class yang bisa dipakai via <span class="kbd">data-anim</span>.
        Contoh: <span class="kbd">&lt;div data-anim="fade-up"&gt;</span> akan diubah AnimeFX jadi class <span class="kbd">anim fade-up</span>.
      </div>

      <pre><code>&lt;!-- Cara 1: pakai class langsung --&gt;
&lt;div class="fade-up" data-duration="900"&gt;...&lt;/div&gt;

&lt;!-- Cara 2: pakai data-anim (AnimeFX akan menambahkan class) --&gt;
&lt;div data-anim="fade-up" data-duration="900"&gt;...&lt;/div&gt;</code></pre>

    </section>

    <!-- =========================
      TEXT ANIMS
    ========================= -->
    <section class="section" id="text">
      <h2>6) Text Animations: Typewrite & Wave</h2>
      <p>Animasi teks punya aturan tambahan (split text / ukur lebar).</p>

      <div class="grid">
        <div class="card">
          <h3>Typewrite</h3>
          <p class="meta">
            Gunakan <span class="kbd">.typewrite</span> + <span class="kbd">data-tw-text</span> (opsional).
            Lebar dihitung saat init.
          </p>
          <div class="demo">
            <span class="typewrite onload"
                  data-anim-trigger="load"
                  data-duration="2200"
                  data-delay="120"
                  data-tw-text="Typewrite: teks muncul seperti diketik.">
              Typewrite: teks muncul seperti diketik.
            </span>
          </div>
          <pre><code>&lt;span class="typewrite onload"
  data-anim-trigger="load"
  data-duration="2200"
  data-delay="120"
  data-tw-text="Typewrite: teks muncul seperti diketik."&gt;
  Typewrite: teks muncul seperti diketik.
&lt;/span&gt;</code></pre>
        </div>

        <div class="card">
          <h3>Typewrite tanpa caret</h3>
          <div class="demo">
            <span class="typewrite no-caret onload"
                  data-anim-trigger="load"
                  data-duration="1800"
                  data-delay="100"
                  data-tw-text="Tanpa caret: lebih clean untuk UI.">
              Tanpa caret: lebih clean untuk UI.
            </span>
          </div>
          <pre><code>&lt;span class="typewrite no-caret onload"
  data-anim-trigger="load"
  data-duration="1800"
  data-tw-text="Tanpa caret..."&gt;...&lt;/span&gt;</code></pre>
        </div>

        <div class="card">
          <h3>Wave (per-letter) — benar: wave-span</h3>
          <p class="meta">
            <span class="kbd">wave-span</span> akan split text (kalau text-only) lalu animate tiap huruf.
            Atur step (stagger antar huruf) via <span class="kbd">data-wave-step</span>.
          </p>
          <div class="demo">
            <h3 class="wave-span onload"
                data-anim-trigger="load"
                data-wave-step="18"
                data-wave-duration="1200"
                style="margin:0">
              Wave effect — per letter
            </h3>
          </div>
          <pre><code>&lt;h3 class="wave-span onload"
  data-anim-trigger="load"
  data-wave-step="18"
  data-wave-duration="1200"&gt;
  Wave effect — per letter
&lt;/h3&gt;</code></pre>
        </div>
      </div>
    </section>

    <!-- =========================
      MOVING LINE
    ========================= -->
    <section class="section" id="line">
      <h2>7) Moving Line / Beam</h2>
      <p>
        Gunakan <span class="kbd">.moving-line</span>. Garis adalah pseudo-element <span class="kbd">::after</span>.
        Kamu bisa override durasi/delay khusus line via <span class="kbd">data-ml-duration</span> / <span class="kbd">data-ml-delay</span>.
      </p>

      <div class="grid">
        <div class="card">
          <h3>Moving line (load)</h3>
          <div class="demo moving-line onload"
               data-anim-trigger="load"
               data-duration="900"
               data-delay="80"
               data-ml-duration="1100"
               data-ml-delay="50">
            <b>Moving line / beam</b>
          </div>
          <pre><code>&lt;div class="moving-line onload"
  data-anim-trigger="load"
  data-ml-duration="1100"
  data-ml-delay="50"&gt;...&lt;/div&gt;</code></pre>
        </div>

        <div class="card">
          <h3>Preset helper</h3>
          <p class="meta">
            Kamu punya helper class: <span class="kbd">ml-header</span> &amp; <span class="kbd">ml-text</span>.
          </p>
          <div class="demo moving-line ml-text onload" data-anim-trigger="load" data-delay="120">
            <span>Line di bawah teks</span>
          </div>
          <pre><code>&lt;span class="moving-line ml-text onload" data-anim-trigger="load"&gt;...&lt;/span&gt;</code></pre>
        </div>
      </div>
    </section>

    <!-- =========================
      FLIP LOGO
    ========================= -->
    <section class="section" id="logo">
      <h2>8) Flip Logo (khusus brand IMG)</h2>
      <p>
        Kelas <span class="kbd">.flip-logo</span> memakai animasi keyframes sendiri, dan setelah selesai akan diberi
        <span class="kbd">.fx-done</span> supaya hover effect bisa menang.
      </p>

      <div class="grid">
        <div class="card">
          <h3>Contoh brand</h3>
          <div class="demo">
            <div class="brand">
              <img class="flip-logo onload"
                   data-anim-trigger="load"
                   data-fl-duration="1200"
                   data-fl-delay="120"
                   src="https://dummyimage.com/80x80/0aa89e/0b0e12.png&text=LOGO"
                   alt="Logo"
                   width="42" height="42" style="border-radius:12px">
              <div>
                <strong>Brand Name</strong><br>
                <span>Logo flip saat load, hover tetap hidup</span>
              </div>
            </div>
          </div>
          <pre><code>&lt;img class="flip-logo onload"
  data-anim-trigger="load"
  data-fl-duration="1200"
  data-fl-delay="120"
  src="logo.png" alt="Logo"&gt;</code></pre>
        </div>
      </div>
    </section>

    <!-- =========================
      FRAG REVEAL
    ========================= -->
    <section class="section" id="images">
      <h2>9) Image Reveal: Frag Reveal (Fragments)</h2>
      <p>
        <b>Penting:</b> <span class="kbd">frag-reveal</span> harus dipasang di <b>wrapper</b> (figure/div), bukan di <span class="kbd">&lt;img&gt;</span>.
        JS akan inject overlay fragment ke dalam wrapper.
      </p>

      <div class="note warn" style="margin-bottom:12px">
        ❌ Salah: <span class="kbd">&lt;img class="frag-reveal" ...&gt;</span><br>
        ✅ Benar: <span class="kbd">&lt;figure class="frag-reveal"&gt;&lt;img ...&gt;&lt;/figure&gt;</span>
      </div>

      <div class="grid">
        <div class="card">
          <h3>Frag reveal (load)</h3>
          <div class="demo">
            <figure class="imgbox frag-reveal onload"
                    data-anim-trigger="load"
                    data-duration="1100"
                    data-delay="120"
                    data-ease="cubic-bezier(.16,1,.3,1)"
                    data-frag-cols="14"
                    data-frag-rows="8"
                    data-frag-step="18"
                    data-frag-spread="160"
                    data-frag-rotate="16"
                    data-frag-cleanup="1"
                    style="margin:0">
              <img src="https://images.unsplash.com/photo-1500530855697-b586d89ba3ee?auto=format&fit=crop&w=1200&q=80"
                   alt="Demo"
                   loading="lazy">
            </figure>
          </div>

          <pre><code>&lt;figure class="frag-reveal onload"
  data-anim-trigger="load"
  data-duration="1100"
  data-delay="120"
  data-frag-cols="14"
  data-frag-rows="8"
  data-frag-step="18"
  data-frag-spread="160"
  data-frag-rotate="16"
  data-frag-cleanup="1"&gt;
  &lt;img src="..." alt="..." loading="lazy"&gt;
&lt;/figure&gt;</code></pre>
        </div>

        <div class="card">
          <h3>Frag reveal + link</h3>
          <p class="meta">Kalau gambar dibungkus link, tetap aman. Wrapper tetap figure.</p>
          <div class="demo">
            <figure class="imgbox frag-reveal onload"
                    data-anim-trigger="load"
                    data-duration="1000"
                    data-delay="160"
                    data-frag-cols="12"
                    data-frag-rows="7"
                    data-frag-step="14"
                    data-frag-spread="140"
                    data-frag-rotate="14"
                    data-frag-cleanup="0"
                    style="margin:0">
              <a href="#top" aria-label="Kembali ke atas">
                <img src="https://images.unsplash.com/photo-1520975682031-ae2e0a8b87ea?auto=format&fit=crop&w=1200&q=80"
                     alt="Demo link"
                     loading="lazy">
              </a>
            </figure>
          </div>
          <pre><code>&lt;figure class="frag-reveal"&gt;
  &lt;a href="..."&gt;
    &lt;img src="..." alt="..."&gt;
  &lt;/a&gt;
&lt;/figure&gt;</code></pre>
        </div>

        <div class="card">
          <h3>Parameter frag yang bisa kamu atur</h3>
          <pre><code>data-frag-cols="14"     // jumlah kolom frag (4..40)
data-frag-rows="8"      // jumlah baris frag (3..30)
data-frag-step="18"     // delay antar frag (ms)
data-frag-spread="160"  // seberapa jauh frag berhamburan (px)
data-frag-rotate="16"   // rotasi acak frag (deg)
data-frag-cleanup="1"   // 1 = overlay dihapus setelah selesai (lebih ringan)
                        // 0 = overlay tetap (kadang bagus untuk efek tertentu)</code></pre>
          <div class="note">
            <b>Performa:</b> jangan pakai cols/rows terlalu besar di mobile. Mulai dari 12x7 atau 14x8.
          </div>
        </div>
      </div>
    </section>

    <!-- =========================
      LOOP EFFECTS
    ========================= -->
    <section class="section" id="loop">
      <h2>10) Loop Effects: Pulse & Shake</h2>
      <p>
        Ini bukan “reveal show/hide”, tapi animasi loop/one-shot.
        Kamu cukup toggle class-nya.
      </p>

      <div class="grid">
        <div class="card">
          <h3>Pulse (loop)</h3>
          <p class="meta">Tambah/hapus class <span class="kbd">pulse</span>.</p>
          <div class="demo" id="pulseDemo">
            <div class="badge">PULSE BOX</div>
          </div>
          <div class="actions">
            <button class="btn" data-action="toggle-class" data-target="#pulseDemo" data-class="pulse">Toggle pulse</button>
          </div>
          <pre><code>el.classList.toggle('pulse');</code></pre>
        </div>

        <div class="card">
          <h3>Shake (one-shot)</h3>
          <p class="meta">Untuk mengulang: remove + reflow + add.</p>
          <div class="demo" id="shakeDemo">
            <div class="badge">SHAKE BOX</div>
          </div>
          <div class="actions">
            <button class="btn" data-action="shake-once" data-target="#shakeDemo">Shake once</button>
          </div>
          <pre><code>el.classList.remove('shake');
void el.offsetWidth; // reflow
el.classList.add('shake');</code></pre>
        </div>
      </div>
    </section>

    <!-- =========================
      STAGGER
    ========================= -->
    <section class="section" id="stagger">
      <h2>11) Stagger (otomatis menambah delay ke children)</h2>
      <p>
        AnimeFX kamu support container stagger: <span class="kbd">data-stagger</span> / <span class="kbd">data-anim-stagger</span>.
        Anak-anak akan diberi delay bertahap.
      </p>

      <div class="grid">
        <div class="card">
          <h3>Stagger default</h3>
          <div class="demo">
            <div data-stagger="120" data-anim-trigger="load" class="onload" style="width:100%">
              <div class="fade-up" style="padding:8px;border-radius:12px;border:1px solid rgba(255,255,255,.10);margin:6px 0">Item 1</div>
              <div class="fade-up" style="padding:8px;border-radius:12px;border:1px solid rgba(255,255,255,.10);margin:6px 0">Item 2</div>
              <div class="fade-up" style="padding:8px;border-radius:12px;border:1px solid rgba(255,255,255,.10);margin:6px 0">Item 3</div>
              <div class="fade-up" style="padding:8px;border-radius:12px;border:1px solid rgba(255,255,255,.10);margin:6px 0">Item 4</div>
            </div>
          </div>

          <pre><code>&lt;div data-stagger="120" class="onload" data-anim-trigger="load"&gt;
  &lt;div class="fade-up"&gt;Item 1&lt;/div&gt;
  &lt;div class="fade-up"&gt;Item 2&lt;/div&gt;
  ...
&lt;/div&gt;</code></pre>
        </div>

        <div class="card">
          <h3>Stagger + “inherit anim”</h3>
          <p class="meta">
            Kalau children belum punya anim, kamu bisa set:
            <span class="kbd">data-anim-stagger-children="fade-up"</span>.
          </p>

          <div class="demo">
            <div data-anim-stagger="140"
                 data-anim-trigger="load"
                 data-anim-stagger-children="slide-up"
                 class="onload"
                 style="width:100%">
              <div style="padding:8px;border-radius:12px;border:1px solid rgba(255,255,255,.10);margin:6px 0">Auto slide-up 1</div>
              <div style="padding:8px;border-radius:12px;border:1px solid rgba(255,255,255,.10);margin:6px 0">Auto slide-up 2</div>
              <div style="padding:8px;border-radius:12px;border:1px solid rgba(255,255,255,.10);margin:6px 0">Auto slide-up 3</div>
            </div>
          </div>

          <pre><code>&lt;div data-anim-stagger="140"
  data-anim-trigger="load"
  data-anim-stagger-children="slide-up"
  class="onload"&gt;
  &lt;div&gt;Auto slide-up 1&lt;/div&gt;
  &lt;div&gt;Auto slide-up 2&lt;/div&gt;
  &lt;div&gt;Auto slide-up 3&lt;/div&gt;
&lt;/div&gt;</code></pre>
        </div>

        <div class="card">
          <h3>Selector children</h3>
          <p class="meta">Pilih anak tertentu untuk stagger dengan <span class="kbd">data-anim-stagger-selector</span>.</p>
          <pre><code>data-anim-stagger-selector=":scope > li"
data-anim-stagger-selector=".item"</code></pre>
        </div>
      </div>
    </section>

    <!-- =========================
      RECIPES (PRODUCTION)
    ========================= -->
    <section class="section" id="recipes">
      <h2>12) Resep Produksi (copy-paste)</h2>
      <p>Template paling sering dipakai di theme kamu (header, featured post, thumbnail, dsb).</p>

      <div class="grid">
        <div class="card">
          <h3>Hero title + meta (load, berurutan)</h3>
          <pre><code>&lt;h1 class="wave-span onload" data-anim-trigger="load" data-wave-step="18"&gt;Judul&lt;/h1&gt;

&lt;div class="fade-up onload"
  data-anim-trigger="load"
  data-duration="900"
  data-delay="200"&gt;Meta row&lt;/div&gt;

&lt;div class="slide-up onload"
  data-anim-trigger="load"
  data-duration="900"
  data-delay="380"&gt;Badges&lt;/div&gt;</code></pre>
        </div>

        <div class="card">
          <h3>Featured: YouTube (flip) vs Image (frag)</h3>
          <pre><code>&lt;!-- Jika YouTube (iframe): pakai flip/zoom pada wrapper figure --&gt;
&lt;figure class="adam-post-thumb flip-y onload"
  data-anim-trigger="load"
  data-duration="900"
  data-delay="200"&gt;
  &lt;div style="position:relative;padding-top:56.25%"&gt;
    &lt;iframe src="https://www.youtube.com/embed/ID"
      style="position:absolute;inset:0;width:100%;height:100%;border:0"
      loading="lazy" allowfullscreen&gt;&lt;/iframe&gt;
  &lt;/div&gt;
&lt;/figure&gt;

&lt;!-- Jika image fallback: frag-reveal wajib wrapper --&gt;
&lt;figure class="adam-post-thumb frag-reveal onload"
  data-anim-trigger="load"
  data-duration="1100"
  data-delay="200"
  data-frag-cols="14"
  data-frag-rows="8"
  data-frag-step="18"
  data-frag-spread="160"
  data-frag-rotate="16"
  data-frag-cleanup="1"&gt;
  &lt;img src="..." alt="..." loading="lazy"&gt;
&lt;/figure&gt;</code></pre>
        </div>

        <div class="card">
          <h3>Thumbnail biasa (flip langsung di img)</h3>
          <pre><code>&lt;img class="flip-y onload"
  data-anim-trigger="load"
  data-duration="900"
  data-delay="120"
  src="/path/thumb.jpg" alt="..." loading="lazy"&gt;</code></pre>
        </div>
      </div>
    </section>

    <!-- =========================
      TROUBLESHOOT
    ========================= -->
    <section class="section" id="troubleshoot">
      <h2>13) Troubleshooting</h2>
      <div class="grid">
        <div class="card">
          <h3>“Animasi tidak jalan”</h3>
          <pre><code>Checklist:
1) anime.css ter-load? (cek Network tab)
2) anime.js ter-load? (cek console error)
3) Elemen punya class animasi? (fade-up/flip-y/etc)
4) Trigger:
   - load: pakai .onload atau data-anim-trigger="load"
   - scroll: jangan pakai .onload, biarkan default
   - manual: panggil AnimeFX.show(...)
5) prefers-reduced-motion:
   jika user setting reduce motion aktif, AnimeFX auto show tanpa anim.</code></pre>
        </div>

        <div class="card">
          <h3>“frag-reveal tidak muncul / overlay salah posisi”</h3>
          <pre><code>Checklist:
1) frag-reveal harus di WRAPPER (figure/div), bukan di &lt;img&gt;
2) Di dalam wrapper harus ada IMG (langsung atau via selector data-frag-target)
3) Gambar harus selesai load (img.complete && naturalWidth&gt;0)
4) Jangan pakai overflow visible di wrapper, karena frag butuh clipping.
   (CSS kamu sudah set overflow:hidden)</code></pre>
        </div>

        <div class="card">
          <h3>“wave-text tidak bekerja”</h3>
          <pre><code>Nama class yang benar: .wave-span
Bukan .wave-text

Contoh:
&lt;h1 class="wave-span onload" data-anim-trigger="load" data-wave-step="22"&gt;...&lt;/h1&gt;</code></pre>
        </div>
      </div>

    </section>

    <div class="spacer">— End of documentation —</div>
  </div>

  <script>
    /**
     * =================================================================================
     * Playground helpers
     * - Generate base animation cards
     * - Provide buttons for AnimeFX.show/hide
     * - Provide pulse/shake test
     * =================================================================================
     */

    (function(){
      const $ = (sel, root=document) => root.querySelector(sel);
      const $$ = (sel, root=document) => Array.from(root.querySelectorAll(sel));

      // ---------- Base grid generator ----------
      const baseAnims = [
        { cls: 'fade-up',    desc: 'Muncul dari bawah (soft).' },
        { cls: 'fade-down',  desc: 'Muncul dari atas.' },
        { cls: 'fade-left',  desc: 'Muncul dari kanan.' },
        { cls: 'fade-right', desc: 'Muncul dari kiri.' },

        { cls: 'slide-up',    desc: 'Slide lebih jauh dari fade.' },
        { cls: 'slide-down',  desc: 'Slide turun.' },
        { cls: 'slide-left',  desc: 'Slide dari kanan (lebih jauh).' },
        { cls: 'slide-right', desc: 'Slide dari kiri (lebih jauh).' },

        { cls: 'zoom-in',  desc: 'Scale dari kecil.' },
        { cls: 'zoom-out', desc: 'Scale dari besar.' },

        { cls: 'blur-in',   desc: 'Blur → tajam.' },
        { cls: 'rotate-in', desc: 'Masuk dengan rotasi kecil.' },

        { cls: 'flip-x', desc: 'Flip 3D sumbu X.' },
        { cls: 'flip-y', desc: 'Flip 3D sumbu Y.' },

        { cls: 'pop', desc: 'Pop-in (scale cepat).' },

        { cls: 'expand-center-safe', desc: 'Expand aman (tanpa clip-path).' },
      ];

      const baseGrid = $('#baseGrid');
      if (baseGrid){
        baseAnims.forEach((a, i) => {
          const id = `baseDemo_${i}`;
          const dur = (a.cls === 'pop') ? 650 : (a.cls.includes('expand') ? 1600 : 900);
          const delay = 0;

          const el = document.createElement('div');
          el.className = 'card';
          el.innerHTML = `
            <h3>.${a.cls}</h3>
            <p class="meta">${a.desc}</p>

            <div class="demo" data-demo-toggle="1" data-toggle-target="#${id}">
              <div id="${id}" class="${a.cls}"
                   data-anim-trigger="manual"
                   data-duration="${dur}"
                   data-delay="${delay}">
                Demo: <span class="badge">.${a.cls}</span>
              </div>
            </div>

            <div class="actions">
              <button class="btn" data-action="show" data-target="#${id}">Show</button>
              <button class="btn" data-action="hide" data-target="#${id}">Hide</button>
              <button class="btn" data-action="toggle-show" data-target="#${id}">Toggle</button>
            </div>

            <pre><code>&lt;div class="${a.cls}" data-duration="${dur}"&gt;...&lt;/div&gt;</code></pre>
          `;
          baseGrid.appendChild(el);
        });
      }

      // ---------- Action buttons ----------
      function onClickAction(e){
        const btn = e.target.closest('[data-action]');
        if (!btn) return;

        const action = btn.getAttribute('data-action');
        const sel = btn.getAttribute('data-target');
        const cls = btn.getAttribute('data-class');

        const el = sel ? document.querySelector(sel) : null;

        if (action === 'show' && el) {
          if (window.AnimeFX) window.AnimeFX.show(el);
          else el.classList.add('show');
        }
        if (action === 'hide' && el) {
          if (window.AnimeFX) window.AnimeFX.hide(el);
          else el.classList.remove('show');
        }
        if (action === 'toggle-show' && el) {
          el.classList.toggle('show');
        }
        if (action === 'toggle-class' && el && cls) {
          el.classList.toggle(cls);
        }
        if (action === 'shake-once' && el) {
          el.classList.remove('shake');
          void el.offsetWidth;
          el.classList.add('shake');
        }
      }
      document.addEventListener('click', onClickAction);

      // ---------- Click demo toggle (for base demos) ----------
      document.addEventListener('click', (e) => {
        const box = e.target.closest('[data-demo-toggle="1"]');
        if (!box) return;
        const targetSel = box.getAttribute('data-toggle-target');
        const t = targetSel ? document.querySelector(targetSel) : null;
        if (t) t.classList.toggle('show');
      });

    })();
  </script>
</body>
</html>
