<?php
function show_success_and_redirect($message, $redirectUrl, $delay = 1500) {
    ?>
    <!doctype html>
    <html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Operasi Berhasil</title>
        <style>
            #successModal {
                position:fixed;inset:0;background:rgba(0,0,0,0.4);
                display:flex;align-items:center;justify-content:center;
                z-index:4000;
            }
            #successModal .box {
                background:#fff;padding:1.5rem 2rem;border-radius:10px;
                max-width:420px;width:90%;
                box-shadow:0 4px 16px rgba(0,0,0,0.25);
                text-align:center;font-family:sans-serif;
            }
            #successModal h3 { margin:0 0 .5rem 0; color:#246; }
            #successModal p { margin:0.5rem 0; color:#333; }
        </style>
    </head>
    <body>
    <div id="successModal">
        <div class="box">
            <h3>✅ Operasi Berhasil</h3>
            <p><?= htmlspecialchars($message) ?></p>
            <p>🔁 Mengalihkan ke daftar theme...</p>
        </div>
    </div>
    <script>
        setTimeout(() => { window.location.href = "<?= htmlspecialchars($redirectUrl, ENT_QUOTES) ?>"; }, <?= (int)$delay ?>);
    </script>
    </body>
    </html>
    <?php
    exit;
}
