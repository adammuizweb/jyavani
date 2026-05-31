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

## 3. nginx

Letakkan di `/etc/nginx/sites-enabled/[domain]`:

```nginx
server {
    listen 80;
    server_name [domain];

    root /path/to/project/public;
    index index.html index.php;

    location / {
        try_files $uri $uri/ /router.php?$args;
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
SESSION_SAVE_PATH=/path/to/project/cfg/var/sessions
SESSION_NAME=[session_name]
SESSION_COOKIE_DOMAIN=[domain]
SESSION_COOKIE_PATH=/
SESSION_PHP_COOKIE_DISABLED=1
FORCE_HTTPS=1
SESSION_ALLOW_INSECURE_COOKIES=0
SESSION_SECRET=<random-64-char-hex>
```

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
