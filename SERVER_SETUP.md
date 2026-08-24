# Server Setup

> Catatan konfigurasi server untuk menjalankan repo ini di perangkat lain.
> Ganti nilai dalam kurung siku `[...]` sesuai environment masing-masing.

## Prasyarat

- PHP 8.4 + PHP-FPM
- MySQL / MariaDB
- nginx
- Composer (jika ada dependency)
- Cloudflare Tunnel (cloudflared) — **wajib** untuk HTTPS (atau SSL langsung)

## 1. User & Groups

Pastikan user CLI (`[user]`) dan `www-data` saling berada di grup masing-masing:

```bash
sudo usermod -aG www-data [user]
sudo usermod -aG [user] www-data
```

Verifikasi:
```bash
groups [user]     # harus ada www-data
id www-data       # harus ada [user]
```

## 2. Direktori & Permission

Semua file proyek harus grup `www-data` dengan permission yang sesuai.

```bash
# Root proyek
sudo chown -R [user]:www-data /path/to/project
sudo chmod -R g+rwX /path/to/project

# Session save path (custom — sesuaikan dengan SESSION_SAVE_PATH di .env)
sudo chmod -R 770 /path/to/project/cfg/var
sudo chgrp -R www-data /path/to/project/cfg/var
sudo chmod g+s /path/to/project/cfg/var
sudo chmod g+s /path/to/project/cfg/var/sessions

# Private files (writeable by PHP-FPM)
sudo chmod -R g+w /path/to/project/private_files
sudo chmod g+s /path/to/project/private_files
sudo chmod g+s /path/to/project/private_files/media

# Session files (fix permission jika ada)
sudo find /path/to/project/cfg/var/sessions -type f -exec chmod 660 {} \;
```

## Outgoing Email

Core menyediakan Mail API dengan transport bawaan `native`. Transport ini menyerahkan pesan ke konfigurasi `mail()` milik PHP/hosting; Core tidak menjalankan mail server sendiri.

- Atur identitas pengirim dan transport melalui **Settings > Email**.
- Pastikan konfigurasi PHP-FPM, bukan hanya PHP CLI, memiliki transport email yang berfungsi.
- Gunakan tombol test email dan periksa SPF, DKIM, serta DMARC domain pengirim.
- Nilai `true` dari transport native hanya berarti pesan diterima oleh transport lokal, bukan dipastikan masuk inbox.
- SMTP atau provider API dapat disediakan plugin dengan mendaftarkan transport ke `jy_mail_register_transport()`; plugin fitur tetap memanggil `jy_mail_send()`.

Fallback environment tersedia di `cfg/.env` melalui `MAIL_TRANSPORT`, `MAIL_FALLBACK_TRANSPORT`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`, `MAIL_REPLY_TO`, dan `MAIL_LOG`. Pengaturan database dari dashboard memiliki prioritas.

## 3. nginx

Letakkan di `/etc/nginx/sites-enabled/[domain]`:

```nginx
server {
    listen 80;
    server_name [domain];
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    server_name [domain];
    root /path/to/project/public;
    index index.php index.html;

    # Configure the certificate and baseline security headers here.
    # HSTS must be enabled only after every required hostname supports HTTPS.

    location @jyavani_404 {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /path/to/project/app/frontend_404.php;
        fastcgi_param SCRIPT_NAME /frontend_404.php;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }

    location = /cfg { error_page 418 = @jyavani_404; return 418; }
    location ^~ /cfg/ { error_page 418 = @jyavani_404; return 418; }
    location = /dev_lock.php { error_page 418 = @jyavani_404; return 418; }
    location ~ (^|/)\.(?!well-known(?:/|$)) { error_page 418 = @jyavani_404; return 418; }
    location ~* \.(?:ini|env|log|sh|sql|bak|dist|ya?ml|md)(?:/|$) { error_page 418 = @jyavani_404; return 418; }
    location ~* ^/views/.*\.php(?:/|$) { error_page 418 = @jyavani_404; return 418; }

    location ~* ^/views/.*\.(?:css|js|mjs|map|png|jpe?g|gif|ico|svg|webp|avif|woff2?|ttf|eot|otf)$ {
        try_files $uri =404;
        expires 7d;
        add_header Cache-Control "public, immutable";
    }
    location /views/ { error_page 418 = @jyavani_404; return 418; }

    location / {
        error_page 403 = @jyavani_404;
        try_files $uri $uri/ /router.php?$args;
    }

    location = /sw.js {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/router.php;
        fastcgi_param SCRIPT_NAME /router.php;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }

    location = /manifest.webmanifest {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/router.php;
        fastcgi_param SCRIPT_NAME /router.php;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }

    location ~ \.php$ {
        try_files $uri /router.php =404;
        include fastcgi.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

The named `@jyavani_404` handler pins FastCGI to Core's 404 renderer. It keeps
physical directories, `/cfg/`, dotfiles, sensitive extensions, and PHP views
indistinguishable without deriving an executable script path from the request.
Keep every sensitive regex before the generic PHP and static-asset locations.

`location = /sw.js` must remain an exact root route and must execute `router.php`, even if a physical `public/sw.js` remains from an older plugin. Core supplies the install/activate lifecycle and appends active plugin contributions. When no plugin contributes code, Core still returns a lifecycle-only worker so browsers replace stale push handlers. `location = /manifest.webmanifest` must likewise execute `router.php` so an active plugin route can generate it and stale files cannot take precedence. Verify both endpoints with `curl -I`; responses must be `200`, use the expected JavaScript/manifest MIME, and must not be HTML.

The web manifest must be served as `application/manifest+json`. If the system-wide `/etc/nginx/mime.types` already maps `webmanifest`, the dedicated location is still safe; alternatively add `application/manifest+json webmanifest;` to the `types` block and omit that location.

## Apache / LiteSpeed

Core ships `public/.htaccess` for Apache-compatible deployments. Enable
`mod_rewrite` and `mod_headers`, allow the web root to use `FileInfo`, `Indexes`,
and `AuthConfig` overrides, and point the virtual-host document root to
`public/`. The rules route dotfiles, sensitive extensions, `dev_lock.php`, and
direct PHP view requests through Core's cosmetic 404 before physical-file or
per-directory authorization runs. They also apply the same browser security
headers to static responses that PHP emits for dynamic responses.

Do not replace the early sensitive-path rewrites with `[F]` or rely only on
`Require all denied`: those controls prevent execution but expose resource
existence with a server-generated 403. Keep the deny rules as defense in depth
after the rewrites. If a proxy or CDN serves static files without consulting
the origin `.htaccess`, configure the same six headers at that layer and verify
the final public response independently.

**Penting:** Baris `fastcgi_param HTTPS on;` hanya diperlukan jika reverse proxy
men-terminate HTTPS lalu mengirim HTTP ke origin. Jangan menambahkannya pada
virtual host HTTP biasa. Tanpa indikator HTTPS yang benar:
- `$_SERVER['HTTPS']` tidak terisi → cookie session tidak mendapat flag `Secure`
- PHP menghasilkan URL `http://...` bukan `https://...` → kena **mixed content block**

Jika server langsung punya SSL, `fastcgi.conf` meneruskan nilai `$https`; tidak
perlu memaksa `HTTPS on`.

## 4. Database

```bash
mysql -u root -p < schema/schema.sql
```

Konfigurasi koneksi di `cfg/.env` (copy dari `cfg/env-sample`).

## 5. Environment (.env)

```bash
cp cfg/env-sample cfg/.env
```

Isi minimal:
```env
DB_NAME=[db_name]
DB_USER=[db_user]
DB_PASS=[db_pass]
DB_SESSION_WAIT_TIMEOUT=
PUBLIC_PATH=/absolute/path/to/project/public
SESSION_SAVE_PATH=/path/to/project/cfg/var/sessions
SESSION_NAME=[session_name]
SESSION_COOKIE_DOMAIN=
SESSION_COOKIE_PATH=/
SESSION_PHP_COOKIE_DISABLED=1
FORCE_HTTPS=1
SESSION_ALLOW_INSECURE_COOKIES=0
SESSION_SECRET=<random-64-char-hex>
PLUGIN_INSTALL_TIMEOUT_SECONDS=120
PLUGIN_INSTALL_OUTPUT_LIMIT=65536
```

`PUBLIC_PATH` must be an existing absolute directory. For split deployments it may point to a sibling web root such as `/home/account/public_html`; logical release paths remain `public/...`. Configure nginx `root` to the same directory. If `cfg/` cannot be found relative to the public installer, expose `BACKEND_PATH=/absolute/path/to/project/cfg` to PHP-FPM so fresh-install bootstrap can locate it.

`DB_SESSION_WAIT_TIMEOUT` is optional. When set, it must be an integer from 1 to 31536000 and configures only the current MySQL session's idle timeout.

`PLUGIN_INSTALL_TIMEOUT_SECONDS` and `PLUGIN_INSTALL_OUTPUT_LIMIT` bound the fixed plugin `install.sh` runner. Defaults are 120 seconds and 65536 bytes; Core hard-caps them at 900 seconds and 1048576 bytes.

**Catatan per env:**
- `FORCE_HTTPS=1` + `SESSION_ALLOW_INSECURE_COOKIES=0` → cookie dengan flag Secure (wajib HTTPS)
- `FORCE_HTTPS=0` + `SESSION_ALLOW_INSECURE_COOKIES=1` → cookie HTTP untuk development lokal
- Request HTTPS selalu menghasilkan cookie `Secure`, walaupun flag development lama masih tersisa
- `SESSION_COOKIE_DOMAIN` — kosongkan untuk cookie host-only; ini juga paling aman untuk `.lan`, localhost, IP, dan alias lokal
- Pada WSL, Pondasi otomatis menempatkan session project `/mnt/<drive>/...` di direktori native Linux terisolasi di bawah temporary directory dan memverifikasi handler sebelum instalasi selesai. Untuk deployment jangka panjang, nilai itu boleh dipindahkan ke path native persisten di home Linux atau `/var/lib` dengan owner/group PHP-FPM yang benar

## 6. Private Media

Folder `private_files/` digunakan untuk menyimpan file yang tidak bisa diakses langsung via URL.

Struktur:
```
private_files/
  files/     ← file non-gambar (PDF, doc, dll)
  media/     ← gambar private, diorganisir per tahun/bulan
```

Controller: `public/controllers/PrivateMediaController.php`
Routing: `router.php` → `$prefix === 'private'` → action `media/view`

## 7. Cloudflare Tunnel

```bash
cloudflared tunnel run [tunnel-name]
```

Config tunnel mengarah ke `http://localhost:80` (nginx port).

## Ringkasan Alur Request Private Image

```
Browser HTTPS
  → [reverse proxy / Cloudflare Tunnel]
    → nginx (HTTP, port 80)
      → try_files → fallback ke /router.php?$args
        → router.php parse path /private/media/view/?id=X
          → PrivateMediaController::view($pdo)
            → cek session (is_logged_in)
            → baca file dari private_files/media/.../
            → stream via PHP (fread)
```
