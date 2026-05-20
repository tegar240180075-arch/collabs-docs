# CollabDocs - Real-time Collaborative Document Editor

Aplikasi kolaborasi dokumen real-time seperti Google Docs, dibangun dengan Laravel, Reverb WebSocket, dan Vanilla JavaScript.

## Fitur Utama

- Autentikasi (Register & Login)
- CRUD Dokumen (Buat, edit, hapus)
- Kolaborasi Real-time (Edit dokumen bersamaan)
- Remote Cursor (Lihat posisi cursor user lain dengan nama & warna)
- Selection Highlight (Lihat teks yang di-select oleh user lain)
- Typing Indicator (Indikator siapa yang sedang mengetik)
- Sharing (Bagikan dokumen sebagai Editor atau Viewer)
- Version History (Simpan dan restore versi dokumen)
- Online Presence (Lihat siapa saja yang sedang online)
- Activity Log (Log aktivitas join/leave user)

## Tech Stack

- Backend: Laravel 12
- WebSocket: Laravel Reverb
- Frontend: Vanilla JavaScript, CSS
- Database: MySQL
- Editor: ContentEditable dengan rich text formatting

---

## Prasyarat

Pastikan sudah terinstal di komputer Anda:

- PHP >= 8.2
- Composer >= 2.x
- MySQL >= 5.7
- Node.js >= 18.x & NPM
- Git

Rekomendasi: Gunakan [Laragon](https://laragon.org/) (Windows) untuk kemudahan setup PHP + MySQL.

---

## Langkah Instalasi

### 1. Clone Repository

```bash
git clone https://github.com/tegar240180075-arch/collab-docs.git
cd collab-docs
```

### 2. Install Dependencies

```bash
composer install
npm install
```

### 3. Konfigurasi Environment

```bash
cp .env.example .env
php artisan key:generate
```

### 4. Setup Database

Buat database MySQL baru, lalu edit file `.env`:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=collab_docs
DB_USERNAME=root
DB_PASSWORD=
```

Jalankan migrasi:

```bash
php artisan migrate
```

### 5. Konfigurasi Reverb (WebSocket)

Pastikan konfigurasi Reverb di file `.env`:

```env
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=652434
REVERB_APP_KEY=jn9z3ejzeje4iy2gatsh
REVERB_APP_SECRET=yqzyeqowfv3u5lfibvql
REVERB_HOST="localhost"
REVERB_PORT=8085
REVERB_SERVER_PORT=8085
REVERB_SCHEME=http
```

Catatan: Pastikan port 8085 tidak digunakan oleh aplikasi lain. Jika sudah terpakai, ganti ke port lain.

---

## Menjalankan Aplikasi

Anda perlu membuka 2 terminal secara bersamaan:

### Terminal 1 - Laravel Server

```bash
php artisan serve
```

Server akan berjalan di: `http://localhost:8000`

### Terminal 2 - Reverb WebSocket Server

```bash
php artisan reverb:start
```

WebSocket server akan berjalan di port 8085.

Tambahkan `--debug` untuk melihat log koneksi WebSocket:

```bash
php artisan reverb:start --debug
```

---

## Cara Testing Kolaborasi Real-time

1. Buka browser dan akses `http://localhost:8000`
2. Register 2 akun user (User A dan User B)
3. Login sebagai User A, buat dokumen baru, klik "Bagikan", masukkan email User B sebagai Editor
4. Buka browser Incognito/Private, login sebagai User B, buka dokumen yang dibagikan
5. Mulai mengetik di salah satu browser, teks akan muncul real-time di browser lainnya

### Yang Akan Terlihat:

- Teks yang diketik muncul langsung di layar user lain
- Cursor berwarna dengan nama user tampil di posisi ketikan
- Indikator "sedang mengetik..." muncul di bawah editor
- Badge online menunjukkan jumlah user aktif
- Activity log mencatat join/leave user

---

## Struktur Project

```
collab-docs/
├── app/
│   ├── Events/
│   ├── Http/Controllers/
│   ├── Models/
│   └── Providers/
├── config/
│   ├── broadcasting.php
│   └── reverb.php
├── database/
│   └── migrations/
├── public/
│   ├── css/
│   │   ├── auth.css
│   │   ├── dashboard.css
│   │   └── editor.css
│   └── js/
│       └── collab.js
├── resources/views/
│   ├── auth/
│   └── documents/
│       ├── index.blade.php
│       └── editor.blade.php
└── routes/
    ├── channels.php
    └── web.php
```

---

## Lisensi

Project ini dibuat untuk keperluan pembelajaran.
