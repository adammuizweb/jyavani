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

    root /path/to/project/public;
    index index.html index.php;

    location / {
        # try_files can select a real directory and nginx then emits 403 when
        # it has no index. Send that case through Core's themed 404 renderer.
        error_page 403 = /router.php?$args;
        try_files $uri $uri/ /router.php?$args;
    }

    # Always execute Core's root worker route; do not serve a stale physical sw.js.
    location = /sw.js {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/router.php;
        fastcgi_param HTTPS on;
    }

    # Dynamic manifest plugins use this root URL; bypass stale physical files.
    location = /manifest.webmanifest {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/router.php;
        fastcgi_param HTTPS on;
    }

    # Some nginx installations do not include webmanifest in mime.types.
    location ~* \.webmanifest$ {
        default_type application/manifest+json;
        try_files $uri =404;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_param HTTPS on;          # Cloudflare Tunnel → all requests are HTTPS
    }

    location ~ ^/cfg/ {
        deny all;
        return 403;
    }

    location ~* \.(css|js|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
        expires 7d;
        add_header Cache-Control "public, immutable";
    }
}
```

The `error_page 403` inside `location /` is required because `$uri/` can select a physical directory even when it has no index. The internal redirect preserves the original request path, allowing Core to return the active theme's `404` response instead of nginx's generic `403`. Keep explicit sensitive-path deny locations separate from this block.

`location = /sw.js` must remain an exact root route and must execute `router.php`, even if a physical `public/sw.js` remains from an older plugin. Core supplies the install/activate lifecycle and appends active plugin contributions. When no plugin contributes code, Core still returns a lifecycle-only worker so browsers replace stale push handlers. `location = /manifest.webmanifest` must likewise execute `router.php` so an active plugin route can generate it and stale files cannot take precedence. Verify both endpoints with `curl -I`; responses must be `200`, use the expected JavaScript/manifest MIME, and must not be HTML.

The web manifest must be served as `application/manifest+json`. If the system-wide `/etc/nginx/mime.types` already maps `webmanifest`, the dedicated location is still safe; alternatively add `application/manifest+json webmanifest;` to the `types` block and omit that location.

**Penting:** Baris `fastcgi_param HTTPS on;` diperlukan jika memakai reverse proxy yang terminate HTTPS (Cloudflare Tunnel, ngrok, dll). Tanpa ini:
- `$_SERVER['HTTPS']` tidak terisi → cookie session tidak mendapat flag `Secure`
- PHP menghasilkan URL `http://...` bukan `https://...` → kena **mixed content block**

Jika server langsung punya SSL (bukan via reverse proxy), hapus baris `fastcgi_param HTTPS on;` dan pakai `listen 443 ssl;` + sertifikat.

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
SESSION_COOKIE_DOMAIN=[domain]
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
- `FORCE_HTTPS=1` + `SESSION_ALLOW_INSECURE_COOKIES=1` → cookie tanpa Secure, untuk development
- `SESSION_COOKIE_DOMAIN` — kosongkan jika akses via localhost/IP

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
