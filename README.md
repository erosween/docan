# Docan Outlet

PWA kasir outlet berbasis Laravel 12 untuk penjualan pulsa, paket data, voucher, e-wallet, PPOB, dan token PLN.

## Menjalankan dengan Docker

Persyaratan: Docker Engine dengan Docker Compose v2.

```bash
cp .env.docker.example .env.docker
```

Ganti `DB_PASSWORD` di `.env.docker`, kemudian jalankan:

```bash
docker compose --env-file .env.docker up -d --build
docker compose --env-file .env.docker ps
```

Aplikasi tersedia di `http://localhost:8080`. Pada konfigurasi demo:

- Email: `kasir@outlet.test`
- Password: `password`

Akun super admin demo:

- Email: `admin@docan.test`
- Password: `password`

Super admin dapat membuka `/admin`, mengunduh seluruh transaksi dalam CSV, dan mengatur master denom.

Container otomatis menunggu PostgreSQL, menjalankan migrasi, lalu mengoptimalkan Laravel. Seeder hanya dijalankan satu kali agar restart/deploy ulang tidak menimpa katalog, harga, atau stok outlet. Database dan file runtime disimpan dalam named volume sehingga tetap ada setelah container dibuat ulang.

Arsitektur production menjalankan gateway load balancer, beberapa instance aplikasi, worker queue, scheduler, PostgreSQL, dan Redis. Session/cache tidak lagi membebani database sehingga user dapat berpindah ke instance aplikasi mana pun tanpa logout.

### Checklist deployment server

1. Upload seluruh source code ke server.
2. Salin `.env.docker.example` menjadi `.env.docker`.
3. Isi `APP_URL` dengan domain HTTPS dan ganti `DB_PASSWORD`.
4. Jalankan `docker compose --env-file .env.docker up -d --build`.
5. Pastikan status `app` dan `database` sudah `healthy` melalui `docker compose --env-file .env.docker ps`.
6. Setelah instalasi awal, ubah `SEED_DATABASE=false` agar maksud konfigurasinya tegas.

Untuk domain HTTPS, set `APP_URL=https://domain-client.com` dan `SESSION_SECURE_COOKIE=true`. Letakkan Cloudflare Tunnel atau reverse proxy TLS di depan port gateway.

## Perintah operasional

```bash
# Melihat log
docker compose --env-file .env.docker logs -f app

# Menjalankan migrasi manual
docker compose --env-file .env.docker exec app php artisan migrate --force

# Menghentikan layanan tanpa menghapus data
docker compose --env-file .env.docker down

# Update setelah menerima source code baru
docker compose --env-file .env.docker up -d --build

# Melihat status dan health check
docker compose --env-file .env.docker ps
```

Jangan menjalankan `docker compose down -v` di server production karena opsi `-v` menghapus volume database.

`APP_KEY` boleh dikosongkan. Container akan membuatnya sekali dan menyimpan key tersebut pada volume aplikasi. Jangan mengganti atau menghapus volume aplikasi setelah sistem mulai menyimpan data terenkripsi/session.

## Kapasitas dan scaling

Template production menjalankan 4 instance aplikasi dan 2 queue worker. Sesuaikan tanpa mengubah source code melalui `.env.docker`:

```dotenv
APP_REPLICAS=4
QUEUE_REPLICAS=4
APP_MEMORY_LIMIT=768M
```

Kemudian terapkan:

```bash
docker compose --env-file .env.docker up -d --build --remove-orphans
```

Rekomendasi awal untuk 200–500 user aktif bersamaan adalah 8 vCPU, RAM 16 GB, `APP_REPLICAS=4`, dan `QUEUE_REPLICAS=2`. Untuk mendekati ribuan user aktif, gunakan managed PostgreSQL/Redis atau pisahkan database ke server tersendiri, lalu tambah replika aplikasi berdasarkan hasil load test. Angka kapasitas final harus ditentukan dari pola transaksi dan hasil pengujian server client.

## Load test sebelum go-live

Load test login sekali lalu mensimulasikan banyak sesi PWA yang membuka halaman kasir. Jalankan pada staging, jangan pada database production:

```bash
LOADTEST_VUS=100 LOADTEST_DURATION=3m LOADTEST_RAMP_DURATION=30s \
  docker compose --env-file .env.docker --profile tools run --rm loadtest
```

Target bawaan: error di bawah 1%, respons p95 di bawah 1 detik, dan p99 di bawah 2 detik. Naikkan bertahap ke 250, 500, lalu 1.000 VU sambil memantau CPU, RAM, koneksi PostgreSQL, Redis, dan error log.

## Backup PostgreSQL

```bash
docker compose --env-file .env.docker exec -T database \
  sh -lc 'pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB"' > backup-docan.sql
```

## Reverse proxy dan domain

Container membuka HTTP pada port `APP_PORT` (default `8080`). Client dapat menempatkan Cloudflare Tunnel, Nginx, Traefik, atau load balancer di depannya untuk domain dan HTTPS. Port PostgreSQL tidak dipublikasikan ke host.

Untuk deployment publik, ubah `APP_URL`, gunakan password database kuat, set `SEED_DATABASE=false`, dan buat akun production melalui seeder/admin yang aman.

## Pengembangan tanpa Docker

Membutuhkan PHP 8.3 dan Composer:

```bash
composer install
php artisan migrate --seed
php artisan serve
```

## Local dengan PostgreSQL

SQLite tetap tersedia untuk prototipe ringan, tetapi pengembangan yang menyerupai production sebaiknya memakai PostgreSQL lokal:

```bash
# Simpan database SQLite lama, jangan dihapus.
cp database/database.sqlite database/database.sqlite.backup

# Jalankan PostgreSQL dan Redis lokal.
docker compose -f compose.local.yml up -d

# Gunakan konfigurasi PostgreSQL lokal.
cp .env.local-postgres.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

PostgreSQL lokal tersedia hanya di `127.0.0.1:54329`. Volume `postgres_local_data` membuat datanya tetap ada saat container direstart. Jangan menjalankan `docker compose -f compose.local.yml down -v` kecuali memang ingin menghapus seluruh database local.
