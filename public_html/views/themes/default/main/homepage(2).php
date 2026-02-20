<style>
.webdev-hero-section {
    position: relative;
    height: 600px;
    width: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    overflow: visible; 
}

.webdev-circle-bg {
    position: absolute;
    width: 550px;
    height: 550px;
    background-color: var(--let-accent); 
    border-radius: 50%;
    z-index: 1;
    filter: brightness(0.9);
}

.webdev-actors-layer {
    position: absolute;
    top: 30%;
    width: 100%;
    display: flex;
    justify-content: space-between;
    padding: 0 8%;
    font-size: 3.5rem;
    font-weight: 900;
    letter-spacing: 2px;
    opacity: 0.9;
    z-index: 2;
    color: var(--text);
    pointer-events: none;
}

.webdev-main-title {
    font-size: 11rem;
    font-weight: 900;
    letter-spacing: -2px;
    z-index: 2;
    color: var(--text);
    position: relative;
    line-height: 1;
    text-transform: uppercase;
}

.webdev-character-img {
    position: absolute;
    bottom: -500px; 
    height: 1180px; 
    z-index: 10;
    pointer-events: none;
    transition: transform 0.3s ease;

    -webkit-mask-image: linear-gradient(to bottom, black 70%, transparent 100%);
    mask-image: linear-gradient(to bottom, black 70%, transparent 100%);
}

.webdev-bottom-wrapper {
    position: absolute;
    bottom: 40px;
    width: 100%;
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    padding: 0 5%;
    z-index: 4;
}

.webdev-info-box {
    text-align: left;
    max-width: 380px;
}

.webdev-subtitle {
    color: var(--let-accent);
    font-weight: bold;
    font-size: 1rem;
    margin-bottom: 8px;
    letter-spacing: 1px;
}

.webdev-text-detail {
    font-size: 0.85rem;
    line-height: 1.5;
    color: var(--text);
    text-transform: uppercase;
    font-family: sans-serif; 
}

.webdev-action-group {
    display: flex;
    gap: 15px;
}

.webdev-btn {
    padding: 12px 30px;
    border-radius: 30px;
    font-weight: bold;
    font-size: 0.8rem;
    letter-spacing: 1px;
    transition: all 0.3s ease;
    border: 2px solid var(--text);
}

.webdev-btn-primary {
    background-color: var(--text);
    color: var(--bg);
}

.webdev-btn-secondary {
    background-color: transparent;
    color: var(--text);
}

.webdev-btn:hover {
    transform: translateY(-3px);
    box-shadow: var(--shadow);
}
.webdev-section-two {
    height: 600px;
    background-color: lightgray;
}

@media (max-width: 768px) {
    .webdev-main-title { font-size: 5rem; }
    .webdev-actors-layer { font-size: 1.5rem; top: 40%; }
    .webdev-circle-bg { width: 300px; height: 300px; }
    .webdev-bottom-wrapper { flex-direction: column; align-items: center; gap: 20px; text-align: center; }
    .webdev-info-box { text-align: center; }
}

.webdev-wave-container {
    position: absolute;
    bottom: 0;
    left: 0;
    width: 100%;
    overflow: hidden;
    line-height: 0;
    transform: rotate(180deg);
    z-index: 5; 
}

.webdev-wave-container svg {
    position: relative;
    display: block;
    width: calc(100% + 1.3px);
    height: 80px; 
}

.webdev-wave-fill {
    fill: lightgray; 
}

.webdev-section-two {
    position: relative;
    z-index: 1;
    height: 600px;
    background-color: lightgray;
    padding-top: 120px;
}
</style>
<section class="webdev-hero-section">
    <div class="webdev-circle-bg"></div>

    <div class="webdev-actors-layer">
        <span class="webdev-side-text">FRONTEND</span>
        <span class="webdev-side-text">BACKEND</span>
    </div>

    <h1 class="webdev-main-title">WEB DEV</h1>

    <img src="https://jyavani.com/adam.png" alt="Adam Dev" class="webdev-character-img">

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
        <div class="webdev-wave-container">
        <svg data-name="Layer 1" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 120" preserveAspectRatio="none">
            <path d="M321.39,56.44c58-10.79,114.16-30.13,172-41.86,82.39-16.72,168.19-17.73,250.45-.39C823.78,31,906.67,72,985.66,92.83c70.05,18.48,146.53,26.09,214.34,3V0H0V27.35A600.21,600.21,0,0,0,321.39,56.44Z" class="webdev-wave-fill"></path>
        </svg>
    </div>
</section>
<section class="webdev-section-two">
    <h2>Layanan Kami</h2>
</section>