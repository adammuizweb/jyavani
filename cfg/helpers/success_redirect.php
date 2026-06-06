<?php
// /jyavani-cfg/helpers/success_redirect.php
if (!function_exists('success_redirect')) {
    function success_redirect(string $message, string $base, int $delay = 2000, string $target = '/index.php?page=admin/posts/index'): void {
        if (headers_sent()) {
            echo '<script>window.location.href="' . htmlspecialchars($base . $target, ENT_QUOTES) . '";</script>';
            exit;
        }

        $redirectUrl = $base . $target . '&msg=' . urlencode($message);
        ?>
        <!doctype html>
        <html lang="<?=function_exists('get_locale')?h(get_locale()):'id'?>">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <title>Operasi Berhasil</title>
            <style>
            #successModal {
                position: fixed;
                inset: 0;
                background: rgba(0,0,0,0.4);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 4000;
            }
            #successModal .box {
                background: #fff;
                padding: 1.5rem 2rem;
                border-radius: 10px;
                max-width: 360px;
                width: 90%;
                box-shadow: 0 4px 16px rgba(0,0,0,0.25);
                text-align: center;
                font-family: system-ui, sans-serif;
            }
            #successModal h3 { margin: 0 0 .5rem 0; color: #246; }
            #successModal p { margin: .25rem 0; }
            </style>
        </head>
        <body>
        <div id="successModal">
            <div class="box">
                <h3>✅ Operasi Berhasil</h3>
                <p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
                <p>🥳 Akan diarahkan ke daftar artikel...</p>
            </div>
        </div>
        <script>
        setTimeout(() => {
            window.location.href = "<?= htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8') ?>";
        }, <?= (int)$delay ?>);
        </script>
        </body>
        </html>
        <?php
        exit;
    }
}

function flash_set(string $key, string $msg): void {
    ensure_session_started(true);
    $_SESSION['flash_' . $key] = $msg;
    session_write_close();
}
function flash_get(string $key): ?string {
    ensure_session_started(true);
    $k = 'flash_' . $key;
    $v = $_SESSION[$k] ?? null;
    unset($_SESSION[$k]);
    return $v;
}
