<style>
/* =========================
   HERO SECTION
========================= */
.webdev-hero-section {
    position: relative;
    min-height: 100vh;
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    background: var(--bg);
    overflow: visible; /* penting agar foto bisa keluar */
}

/* =========================
   CIRCLE
========================= */
.webdev-circle-bg {
    position: absolute;
    width: clamp(300px, 45vw, 650px);
    height: clamp(300px, 45vw, 650px);
    background: var(--let-accent);
    border-radius: 50%;
    z-index: 1;
}

/* =========================
   SIDE TEXT
========================= */
.webdev-actors-layer {
    position: absolute;
    width: 100%;
    top: 50%;
    transform: translateY(-50%);
    display: flex;
    justify-content: space-between;
    padding: 0 8%;
    font-size: clamp(1rem, 2.5vw, 3rem);
    font-weight: 900;
    letter-spacing: 6px;
    opacity: 0.12;
    text-transform: uppercase;
    z-index: 2;
    pointer-events: none;
    color: var(--text);
}

/* =========================
   MAIN TITLE
========================= */
.webdev-main-title {
    font-size: clamp(3rem, 10vw, 10rem);
    font-weight: 950;
    letter-spacing: -4px;
    line-height: 0.9;
    z-index: 3;
    position: relative;
    color: var(--text);
}

/* =========================
   CHARACTER IMAGE
========================= */
.webdev-character-img {
    position: absolute;
    left: 50%;
    transform: translateX(-50%);
    bottom: -35vh; /* ini bikin tembus ke section bawah */
    height: clamp(600px, 110vh, 1200px);
    z-index: 10;
    pointer-events: none;

    -webkit-mask-image: linear-gradient(to bottom, black 75%, transparent 100%);
    mask-image: linear-gradient(to bottom, black 75%, transparent 100%);
}

/* =========================
   BOTTOM INFO
========================= */
.webdev-bottom-wrapper {
    position: absolute;
    bottom: 80px;
    width: 100%;
    display: flex;
    justify-content: space-between;
    padding: 0 8%;
    z-index: 20;
}

.webdev-info-box {
    max-width: 450px;
    text-align: left;
}

.webdev-subtitle {
    color: var(--let-accent);
    font-weight: bold;
    font-size: 0.9rem;
    letter-spacing: 2px;
    margin-bottom: 10px;
}

.webdev-text-detail {
    font-size: 0.9rem;
    line-height: 1.6;
    color: var(--text);
}

.webdev-action-group {
    display: flex;
    gap: 15px;
}

.webdev-btn {
    padding: 12px 28px;
    border-radius: 30px;
    font-weight: bold;
    font-size: 0.8rem;
    border: 2px solid var(--text);
    transition: 0.3s ease;
    cursor: pointer;
}

.webdev-btn-primary {
    background: var(--text);
    color: var(--bg);
}

.webdev-btn-secondary {
    background: transparent;
    color: var(--text);
}

/* =========================
   WAVE
========================= */
.webdev-wave-container {
    position: absolute;
    bottom: 0;
    width: 100%;
    transform: rotate(180deg);
    z-index: 5;
}

.webdev-wave-container svg {
    width: 100%;
    height: 100px;
}

.webdev-wave-fill {
    fill: lightgray;
}

/* =========================
   SECTION TWO
========================= */
.webdev-section-two {
    position: relative;
    background: lightgray;
    padding-top: 50vh; /* kasih ruang untuk badan */
    min-height: 100vh;
    text-align: center;
    z-index: 1;
}

/* =========================
   RESPONSIVE
========================= */
@media (max-width: 768px) {

    .webdev-bottom-wrapper {
        flex-direction: column;
        gap: 20px;
        text-align: center;
    }

    .webdev-info-box {
        text-align: center;
    }

    .webdev-character-img {
        bottom: -25vh;
        height: 90vh;
    }
}
</style>

<section class="webdev-hero-section">

    <div class="webdev-circle-bg"></div>

    <div class="webdev-actors-layer">
        <span>FRONTEND</span>
        <span>BACKEND</span>
    </div>

    <h1 class="webdev-main-title">WEB DEV</h1>

    <img src="https://jyavani.com/adam.png"
         alt="Web Developer"
         class="webdev-character-img">

    <div class="webdev-wave-container">
        <svg viewBox="0 0 1200 120" preserveAspectRatio="none">
            <path d="M321.39,56.44c58-10.79,114.16-30.13,172-41.86,82.39-16.72,168.19-17.73,250.45-.39C823.78,31,906.67,72,985.66,92.83c70.05,18.48,146.53,26.09,214.34,3V0H0V27.35A600.21,600.21,0,0,0,321.39,56.44Z"
                  class="webdev-wave-fill"></path>
        </svg>
    </div>

    <div class="webdev-bottom-wrapper">
        <div class="webdev-info-box">
            <p class="webdev-subtitle">THE ULTIMATE WEB EXPERIENCE</p>
            <p class="webdev-text-detail">
                Membangun solusi digital yang modern, responsif, dan berperforma tinggi.
                Membawa ide-ide brilian Anda ke dalam dunia realitas digital.
            </p>
        </div>

        <div class="webdev-action-group">
            <button class="webdev-btn webdev-btn-primary">HIRE</button>
            <button class="webdev-btn webdev-btn-secondary">ABOUT</button>
        </div>
    </div>

</section>

<section class="webdev-section-two">
    <h2>Layanan Kami</h2>
</section>