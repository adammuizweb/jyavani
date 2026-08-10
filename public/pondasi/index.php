<?php
// pondasi/index.php — Jyavani CMS one-time web installer
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

// ---------- paths ----------
$configuredBackend = trim((string)getenv('BACKEND_PATH'));
$cfgDir = $configuredBackend !== '' ? realpath($configuredBackend) : realpath(__DIR__ . '/../../cfg');
if ($cfgDir === false || !is_dir($cfgDir)) {
    throw new RuntimeException('Backend path not configured. Set BACKEND_PATH to the absolute cfg directory.');
}
$projectRoot = dirname($cfgDir);
$publicDir = realpath(__DIR__ . '/..');
if ($publicDir === false) throw new RuntimeException('Public directory could not be resolved.');
if (str_contains($publicDir, "\n") || str_contains($publicDir, "\r") || str_contains($publicDir, "\0")) {
    throw new RuntimeException('Public directory contains unsupported characters.');
}
$envFile = $cfgDir . '/.env';
$schemaDir = $projectRoot . '/schema';
$sessionDir = $cfgDir . '/var/sessions';

// ---------- lock check ----------
function is_installed(): bool {
    global $envFile;
    if (!is_file($envFile)) return false;
    $raw = file_get_contents($envFile);
    return preg_match('/^SESSION_SECRET\s*=\s*[^\s]+/m', $raw) === 1;
}
if (is_installed()) {
    http_response_code(404);
    echo '<!doctype html><meta charset="utf-8"><title>404</title><p>Not found.</p>';
    exit;
}

// ---------- helpers ----------
function random_secret(): string { return bin2hex(random_bytes(32)); }

function split_sql_statements(string $sql): array {
    $statements = [];
    $current = '';
    $inString = false;
    $stringChar = '';
    $len = strlen($sql);
    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        $prev = $i > 0 ? $sql[$i - 1] : '';
        if ($inString) {
            if ($ch === $stringChar && $prev !== '\\') {
                $inString = false;
            }
            $current .= $ch;
        } elseif ($ch === "'" || $ch === '"') {
            $inString = true;
            $stringChar = $ch;
            $current .= $ch;
        } elseif ($ch === ';') {
            $trimmed = trim($current);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
            $current = '';
        } else {
            $current .= $ch;
        }
    }
    $trimmed = trim($current);
    if ($trimmed !== '') {
        $statements[] = $trimmed;
    }
    return $statements;
}

function copy_demo_assets(string $sourceDir, string $targetDir): int {
    if (!is_dir($sourceDir)) return 0;
    $copied = 0;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $fileinfo) {
        $srcPath = $fileinfo->getPathname();
        $relPath = substr($srcPath, strlen($sourceDir));
        $dstPath = $targetDir . $relPath;
        if ($fileinfo->isDir()) {
            if (!is_dir($dstPath)) {
                mkdir($dstPath, 0755, true);
            }
        } else {
            $dir = dirname($dstPath);
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            if (@copy($srcPath, $dstPath)) {
                $copied++;
            }
        }
    }
    return $copied;
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function detect_host(): string {
    $h = $_SERVER['HTTP_HOST'] ?? '';
    return preg_replace('/:\d+$/', '', $h);
}

function input(string $name, string $label, string $type = 'text', string $default = '', string $hint = ''): string {
    $val = h($_POST[$name] ?? $default);
    $tip = $hint ? '<p class="hint">' . h($hint) . '</p>' : '';
    return '<label>' . h($label) . '<input type="' . $type . '" name="' . h($name) . '" value="' . $val . '" required>' . $tip . '</label>';
}

function pass(string $name, string $label): string {
    return '<label>' . h($label) . '<input type="password" name="' . h($name) . '" required minlength="6"></label>';
}

// ---------- process ----------
$step = (int)($_POST['_step'] ?? 1);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // step 1 → 2: DB config + schema + admin
    if ($step === 1) {
        $dbHost = trim($_POST['DB_HOST'] ?? 'localhost');
        $dbPort = trim($_POST['DB_PORT'] ?? '3306');
        $dbName = trim($_POST['DB_NAME'] ?? '');
        $dbUser = trim($_POST['DB_USER'] ?? '');
        $dbPass = $_POST['DB_PASS'] ?? '';

        if ($dbName === '' || $dbUser === '') {
            $error = 'Nama database dan username wajib diisi.';
        } else {
            try {
                $pdo = new PDO("mysql:host={$dbHost};port={$dbPort};charset=utf8mb4", $dbUser, $dbPass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
                ]);
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $pdo->exec("USE `{$dbName}`");

                // Ensure backslash is escape char (remove NO_BACKSLASH_ESCAPES if set)
                $pdo->exec("SET SESSION sql_mode = REPLACE(@@SESSION.sql_mode, 'NO_BACKSLASH_ESCAPES', '')");

                $defaultSql = $schemaDir . '/default.sql';
                $sql = file_get_contents($defaultSql);
                if ($sql === false) throw new RuntimeException('default.sql tidak ditemukan');
                $sql = str_replace("\\\\'", "''", $sql);
                $stmts = split_sql_statements($sql);
                foreach ($stmts as $stmt) {
                    $pdo->exec($stmt);
                }

                // Import translation seed data (id + de)
                $translationsSql = $schemaDir . '/translations.sql';
                if (is_file($translationsSql)) {
                    $tsql = file_get_contents($translationsSql);
                    if ($tsql !== false) {
                        $tsql = str_replace("\\\\'", "''", $tsql);
                        $tstmts = split_sql_statements($tsql);
                        foreach ($tstmts as $tstmt) {
                            $pdo->exec($tstmt);
                        }
                    }
                }

                // Seed schema_migrations: mark all existing migrations as applied
                $migrationsDir = $schemaDir . '/migrations';
                if (is_dir($migrationsDir)) {
                    $mfiles = glob($migrationsDir . '/*.sql');
                    if ($mfiles) {
                        sort($mfiles);
                        $pdo->exec("CREATE TABLE IF NOT EXISTS `schema_migrations` (
                            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                            `migration` varchar(255) NOT NULL,
                            `batch` int(10) unsigned NOT NULL DEFAULT 1,
                            `executed_at` datetime NOT NULL DEFAULT current_timestamp(),
                            PRIMARY KEY (`id`),
                            UNIQUE KEY `uq_migration` (`migration`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                        $insMig = $pdo->prepare("INSERT IGNORE INTO schema_migrations (migration, batch) VALUES (?, 0)");
                        foreach ($mfiles as $mfile) {
                            $insMig->execute([basename($mfile)]);
                        }
                    }
                }

                $step = 2;
                // carry DB creds forward
                $dbFields = [
                    'DB_HOST' => $dbHost, 'DB_PORT' => $dbPort,
                    'DB_NAME' => $dbName, 'DB_USER' => $dbUser, 'DB_PASS' => $dbPass,
                ];
            } catch (Throwable $e) {
                $error = 'Gagal: ' . $e->getMessage();
            }
        }
    }

    // step 2 → 3: create admin user
    if ($step === 2) {
        $siteTitle  = trim($_POST['site_title'] ?? '');
        $siteDesc   = trim($_POST['site_description'] ?? '');
        $siteUrl    = trim($_POST['site_url'] ?? '');
        $adminEmail = trim($_POST['admin_email'] ?? '');
        $adminUser  = trim($_POST['admin_user'] ?? '');
        $adminPass  = $_POST['admin_pass'] ?? '';
        $adminName  = trim($_POST['admin_name'] ?? '');

        if ($adminEmail === '' || $adminPass === '' || strlen($adminPass) < 6 || $adminUser === '' || $adminName === '' || $siteTitle === '' || $siteUrl === '') {
            $error = 'Semua field wajib diisi. Password minimal 6 karakter.';
        } else {
            $dbFields = [];
            foreach (['DB_HOST','DB_PORT','DB_NAME','DB_USER','DB_PASS'] as $k) {
                $dbFields[$k] = $_POST[$k] ?? '';
            }

            try {
                $dsn = "mysql:host={$dbFields['DB_HOST']};port={$dbFields['DB_PORT']};dbname={$dbFields['DB_NAME']};charset=utf8mb4";
                $pdo = new PDO($dsn, $dbFields['DB_USER'], $dbFields['DB_PASS'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);

                $hash = password_hash($adminPass, PASSWORD_DEFAULT);
                $st = $pdo->prepare("REPLACE INTO users (email,username,password,name,role,created_at) VALUES (?,?,?,?,'admin',NOW())");
                $st->execute([$adminEmail, $adminUser, $hash, $adminName]);

                // save site settings
                $st = $pdo->prepare("REPLACE INTO settings (`key`, `value`, `autoload`) VALUES (?, ?, 1)");
                $st->execute(['site_title', $siteTitle]);
                $st->execute(['site_description', $siteDesc]);
                $st->execute(['site_url', $siteUrl]);
                $st->execute(['admin_path', 'dashboard']);
                $st->execute(['login_path', 'login']);
                $st->execute(['register_path', 'register']);

                // write .env
                $sessionName = 'sess_' . bin2hex(random_bytes(4));
                $sessionDomain = detect_host();
                $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                    || ($_SERVER['SERVER_PORT'] ?? 443) == 443;

                $envContent = "# Database\n"
                    . "DB_HOST={$dbFields['DB_HOST']}\nDB_PORT={$dbFields['DB_PORT']}\n"
                    . "DB_NAME={$dbFields['DB_NAME']}\nDB_USER={$dbFields['DB_USER']}\n"
                    . "DB_PASS={$dbFields['DB_PASS']}\n"
                    . "\n# Absolute deployed web root (may be outside the project directory)\nPUBLIC_PATH={$publicDir}\n"
                    . "\n# reCAPTCHA (v2 checkbox)\nRECAPTCHA_SITEKEY=\nRECAPTCHA_SECRET=\n"
                    . "\n# Web Debug 1 for activate\nAPP_DEBUG=0\n"
                    . "\n# Session / Cookie\nSESSION_SAVE_PATH={$sessionDir}\n"
                    . "SESSION_LIFETIME=604800\nSESSION_IDLE_TIMEOUT=0\n"
                    . "SESSION_COOKIE_SAMESITE=Lax\n"
                    . "\n# Session cookie name\nSESSION_NAME={$sessionName}\n"
                    . "\n# Cookie domain (kosongkan untuk host-only)\nSESSION_COOKIE_DOMAIN={$sessionDomain}\n"
                    . "\n# Paths where cookies are valid\nSESSION_COOKIE_PATH=/\n"
                    . "\n# Disable PHP automatic session cookie\nSESSION_PHP_COOKIE_DISABLED=1\n"
                    . "\n# Security / Debug\nSESSION_DEBUG=0\nFORCE_HTTPS=" . ($isHttps ? '1' : '0') . "\n"
                    . "\n# Allow insecure cookies even if FORCE_HTTPS=1 (dev only)\nSESSION_ALLOW_INSECURE_COOKIES=" . ($isHttps ? '0' : '1') . "\n"
                    . "\n# Strong random secret for stateless CSRF\nSESSION_SECRET=" . random_secret() . "\n"
                    . "\n# Token secret for private file signed URLs\nPRIVATE_FILE_TOKEN_SECRET=" . random_secret() . "\n";

                // Optional demo content
                $installDemo = (int)($_POST['install_demo'] ?? 0) === 1;
                if ($installDemo) {
                    $demoSql = $schemaDir . '/demo.sql';
                    if (is_file($demoSql)) {
                        $dsql = file_get_contents($demoSql);
                        if ($dsql !== false) {
                            $dsql = str_replace("\\\\'", "''", $dsql);
                            $dstmts = split_sql_statements($dsql);
                            foreach ($dstmts as $dstmt) {
                                $pdo->exec($dstmt);
                            }
                        }
                    }
                    $demoAssetsDir = $schemaDir . '/demo-assets';
                    copy_demo_assets($demoAssetsDir, $publicDir);
                }

                $written = @file_put_contents($envFile, $envContent, LOCK_EX);
                if ($written === false) {
                    $manualEnv = $envContent;
                    $step = 3; // show manual copy
                } else {
                    @chmod($envFile, 0640);
                    $step = 4; // done
                }
            } catch (Throwable $e) {
                $error = 'Gagal: ' . $e->getMessage();
            }
        }
    }
}

// ---------- render ----------
function render(string $title, string $body): void {
    ?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=h($title)?></title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;background:#f0f2f5;color:#1a1a2e;min-height:100vh;display:flex;align-items:center;justify-content:center}
.wrap{width:100%;max-width:520px;padding:20px}
.card{background:#fff;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,.06);padding:32px}
.logo{text-align:center;margin-bottom:20px}
.logo h1{font-size:1.5rem;font-weight:700}
.logo p{color:#64748b;font-size:.875rem}
.steps{display:flex;justify-content:center;gap:8px;margin-bottom:24px}
.steps span{width:10px;height:10px;border-radius:50%;background:#e2e8f0}
.steps span.on{background:#6366f1}
.steps span.ok{background:#22c55e}
label{display:block;margin-bottom:14px;font-size:.8125rem;font-weight:600;color:#334155}
input{display:block;width:100%;margin-top:4px;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:.9375rem}
input:focus{outline:none;border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.15)}
.hint{font-weight:400;font-size:.75rem;color:#94a3b8;margin-top:2px}
.g2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.alert{padding:10px 14px;border-radius:8px;font-size:.8125rem;margin-bottom:16px;background:#fef2f2;color:#991b1b}
.alert.ok{background:#f0fdf4;color:#166534}
.btn{display:block;width:100%;padding:12px;border:none;border-radius:8px;font-size:.9375rem;font-weight:600;cursor:pointer;background:#6366f1;color:#fff;margin-top:8px}
.btn:hover{background:#4f46e5}
pre{background:#1e293b;color:#e2e8f0;padding:16px;border-radius:8px;font-size:.75rem;overflow-x:auto;white-space:pre-wrap;word-break:break-all;margin-bottom:16px}
.fin{font-size:3rem;text-align:center;margin-bottom:8px}
</style>
</head>
<body>
<div class="wrap">
<div class="logo"><h1>Jyavani CMS</h1><p><?=h($title)?></p></div>
<div class="card"><?=$body?></div>
</div>
</body>
</html><?php
}

// ---------- build body ----------
if ($step === 1) {
    $s = '<div class="steps"><span class="on"></span><span></span><span></span></div>'
        . ($error ? '<div class="alert">' . h($error) . '</div>' : '')
        . '<p style="margin-bottom:16px;font-size:.875rem;color:#475569;">Masukkan detail database MySQL/MariaDB.</p>'
        . '<form method="post"><input type="hidden" name="_step" value="1">'
        . input('DB_HOST','Host','text','localhost','Server database')
        . '<div class="g2">'
        . input('DB_PORT','Port','text','3306')
        . input('DB_NAME','Nama Database','text','','Contoh: jyavani_cms')
        . '</div>'
        . input('DB_USER','Username','text','','Contoh: root')
        . input('DB_PASS','Password','password')
        . '<button class="btn" type="submit">Lanjutkan →</button></form>';
    render('Instalasi — Langkah 1', $s);

} elseif ($step === 2) {
    // carry DB creds via hidden fields
    $hfields = '';
    foreach (['DB_HOST','DB_PORT','DB_NAME','DB_USER','DB_PASS'] as $k) {
        $v = h($_POST[$k] ?? '');
        $hfields .= '<input type="hidden" name="' . h($k) . '" value="' . $v . '">';
    }
    $s = '<div class="steps"><span class="ok"></span><span class="on"></span><span></span></div>'
        . ($error ? '<div class="alert">' . h($error) . '</div>' : '<div class="alert ok">Database berhasil dibuat.</div>')
        . '<p style="margin-bottom:16px;font-size:.875rem;color:#475569;">Konfigurasi situs dan akun admin pertama.</p>'
        . '<form method="post"><input type="hidden" name="_step" value="2">' . $hfields
        . input('site_title','Judul Situs','text','','Nama website kamu')
        . input('site_description','Deskripsi Situs','text','','Tagline atau deskripsi singkat')
        . input('site_url','URL Situs','url','','Contoh: https://example.com')
        . '<hr style="border:none;border-top:1px solid #e2e8f0;margin:20px 0">'
        . input('admin_email','Email Admin','email')
        . '<div class="g2">'
        . input('admin_user','Username Admin','text','','Nama akun untuk login')
        . input('admin_name','Nama Lengkap Admin','text','','Nama yang akan ditampilkan')
        . '</div>'
        . pass('admin_pass','Password Admin')
        . '<label style="display:flex;align-items:center;gap:8px;font-weight:400;cursor:pointer;margin:8px 0 0;"><input type="checkbox" name="install_demo" value="1" style="width:auto;margin:0"> Pasang konten demo (15 artikel + 3 halaman + kategori + menu)</label>'
        . '<div style="display:flex;gap:8px;margin-top:8px">'
        . '<a class="btn" href="/pondasi/" style="text-decoration:none;text-align:center;background:#94a3b8;flex:1">← Kembali</a>'
        . '<button class="btn" type="submit" style="flex:1">Selesai →</button></div></form>';
    render('Instalasi — Langkah 2', $s);

} elseif ($step === 3) {
    $s = '<div class="steps"><span class="ok"></span><span class="ok"></span><span class="on"></span></div>'
        . '<div class="alert">Tidak bisa menulis <code>cfg/.env</code> secara otomatis.</div>'
        . '<p style="font-size:.875rem;color:#475569;margin-bottom:12px;">Salin isi di bawah ke <code>cfg/.env</code>:</p>'
        . '<pre>' . h($manualEnv ?? '') . '</pre>'
        . '<p style="font-size:.8125rem;color:#94a3b8;text-align:center;">Setelah itu refresh halaman ini.</p>';
    render('Instalasi — Manual', $s);

} elseif ($step === 4) {
    $s = '<div class="steps"><span class="ok"></span><span class="ok"></span><span class="ok"></span></div>'
        . '<div class="fin">&#10004;</div>'
        . '<p style="text-align:center;font-size:1.125rem;font-weight:600;margin-bottom:4px;">Instalasi Selesai!</p>'
        . '<p style="text-align:center;color:#64748b;font-size:.875rem;margin-bottom:20px;">Hapus folder <code>pondasi/</code> dari server.</p>'
        . '<p style="text-align:center;font-size:.8125rem;color:#475569;margin-bottom:16px;">'
        . '<a href="/login/" style="color:#6366f1;">Login</a> &middot; '
        . '<a href="/dashboard/" style="color:#6366f1;">Dashboard</a>'
        . '</p>'
        . '<a class="btn" href="/login/" style="text-decoration:none;text-align:center;">Masuk ke Dashboard</a>';
    render('Instalasi Selesai', $s);

} else {
    http_response_code(404);
    echo '<!doctype html><meta charset="utf-8"><title>404</title><p>Not found.</p>';
}
