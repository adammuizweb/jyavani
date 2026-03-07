<?php
// main/homepage.php
// Expect: $context, optionally $featured (post), $posts (array of posts)
?>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;700&display=swap" rel="stylesheet">
<?php
// main/homepage.php
// Expect: $context, optionally $featured (post), $posts (array of posts)
?>
<style>
    /* Mengikuti Reset & Typography Default Kamu */
    body {
        font-family: "Comic Sans MS", "Comic Sans", Tahoma, "Times New Roman", Times, serif;
        background-color: var(--bg);
        color: var(--text);
        margin: 0;
        padding: 0;
        display: flex;
        justify-content: center;
        height: 100vh;
        overflow: hidden;
        transition: background-color 0.3s ease, color 0.3s ease;
    }

    .container {
        text-align: center;
        padding: 20px;
        animation: fadeIn 1.5s ease-out;
        z-index: 1;
    }

    h1 {
        font-size: 3.5rem;
        font-weight: 700;
        letter-spacing: -1px;
        margin-bottom: 10px;
    }

    h1 span {
        color: var(--accent); /* Mengikuti --accent tema */
    }

    p {
        font-size: 1.2rem;
        color: var(--muted); /* Mengikuti --muted tema */
        margin-bottom: 30px;
    }

    .status-badge {
        display: inline-block;
        padding: 6px 16px;
        border: 1px solid var(--accent);
        border-radius: 20px;
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: var(--accent);
        margin-bottom: 20px;
        font-weight: bold;
    }

    .links {
        display: flex;
        justify-content: center;
        gap: 25px;
    }

    .links a {
        color: var(--link); /* Mengikuti --link tema */
        text-decoration: none;
        font-weight: bold;
        transition: all 0.3s ease;
        padding-bottom: 2px;
        border-bottom: 2px solid transparent;
    }

    .links a:hover {
        color: var(--link-hover);
        border-bottom: 2px solid var(--link-hover);
    }

    /* Glow Effect yang adaptif dengan warna accent */
    .glow {
        position: absolute;
        width: 400px;
        height: 400px;
        /* Menggunakan warna accent dengan transparansi rendah */
        background: radial-gradient(circle, var(--accent) 0%, transparent 70%);
        opacity: 0.15;
        z-index: 0;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        pointer-events: none;
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
    }

    /* Mobile Responsive */
    @media (max-width: 600px) {
        h1 { font-size: 2.5rem; }
        p { font-size: 1rem; }
    }
</style>

<div class="glow"></div>

<div class="container">
    <div class="status-badge">Segera Hadir</div>
    <h1>Adam <span>Muiz</span></h1>
    <p>Sedang membangun sesuatu yang luar biasa.</p>
    
    <div class="links">
        <a href="#">LinkedIn</a>
        <a href="#">GitHub</a>
        <a href="mailto:email@example.com">Kontak</a>
    </div>
</div>