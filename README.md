# StudyConnect API 🎓

REST API untuk aplikasi mobile **StudyConnect** (Android) — dibuat untuk tugas akhir SMK.
Menangani autentikasi guru/siswa, kelas, materi, pengumuman, tugas, dan pengumpulan tugas.

Dibangun dengan **Laravel 10** + **Laravel Sanctum** (token Bearer), database MySQL.

> 📱 Aplikasi Android-nya ada di repo terpisah: **[StudyConnect](https://github.com/Zierch/StudyConnect)**

## Fitur

- **Autentikasi** — register, login, logout dengan token Sanctum; dua peran: `teacher` & `student`
- **Kelas** — buat/edit/hapus (guru), lihat daftar kelas sesuai peran, gabung & keluar lewat kode kelas, daftar siswa, arsip & pulihkan kelas
- **Materi** — CRUD materi + lampiran (gambar/PDF/DOCX) dan tautan
- **Pengumuman** — CRUD pengumuman kelas + lampiran
- **Tugas** — tiga tipe: `essay`, `multiple_choice` (2–4 pilihan), `file_upload`; tenggat, skor maksimal, lampiran
- **Pengumpulan** — siswa mengumpulkan jawaban/berkas, guru memberi nilai
- **Penjadwalan** — materi, pengumuman, dan tugas bisa dijadwalkan (`scheduled_at`) lalu otomatis terbit lewat scheduler

## Tech stack

| Bagian | Teknologi |
|---|---|
| Bahasa | PHP ^8.1 |
| Framework | Laravel 10 |
| Autentikasi | Laravel Sanctum 3 |
| Database | MySQL / MariaDB |
| HTTP client | Guzzle |
| Deploy | Nixpacks + Nginx (`nixpacks.toml`, `nginx.template.conf`) |

## Kebutuhan

- PHP >= 8.1 dengan ekstensi `pdo_mysql`, `fileinfo`, `mbstring`, `openssl`
- Composer 2
- MySQL / MariaDB
- (opsional) Node.js + npm, hanya jika ingin memakai aset Vite bawaan Laravel

## Instalasi

```bash
git clone https://github.com/Zierch/StudyConnect-API.git
cd StudyConnect-API

composer install
cp .env.example .env
php artisan key:generate
```

Sesuaikan koneksi database di `.env`:

```env
APP_URL=http://127.0.0.1:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=studyconnect
DB_USERNAME=root
DB_PASSWORD=
```

Lalu siapkan database dan jalankan server:

```bash
php artisan migrate
php artisan storage:link   # agar lampiran di storage/app/public bisa diakses
php artisan serve
```

API siap di `http://127.0.0.1:8000/api`.

### Scheduler (publish otomatis)

Materi/pengumuman/tugas berstatus `scheduled` diterbitkan tiap menit oleh
`app:publish-scheduled-data`. Untuk pengembangan cukup jalankan:

```bash
php artisan schedule:work
```

Di server produksi, tambahkan cron:

```
* * * * * cd /path/to/StudyConnect-API && php artisan schedule:run >> /dev/null 2>&1
```

## Autentikasi

Endpoint `/api/register` dan `/api/login` bersifat publik. Endpoint lain wajib menyertakan header:

```
Authorization: Bearer <token>
```

Nilai `token` dari respons login **sudah** berisi prefiks `Bearer `, jadi kirim apa adanya sebagai nilai header.

### Register

```http
POST /api/register
Content-Type: application/json

{
  "name": "Budi",
  "email": "budi@example.com",
  "role": "student",
  "password": "rahasia123"
}
```

`role`: `teacher` atau `student`. Respons `201` berisi data user.

### Login

```http
POST /api/login
Content-Type: application/json

{ "email": "budi@example.com", "password": "rahasia123" }
```

Respons `200`:

```json
{ "token": "Bearer 1|xxxxxxxx", "user_id": 1, "role": "student" }
```

## Endpoint

Semua path berawalan `/api`. Kolom **Peran** menandai pembatasan di controller (`-` = cukup login).

### Auth & user

| Method | Endpoint | Peran | Keterangan |
|---|---|---|---|
| POST | `/register` | publik | Daftar akun baru |
| POST | `/login` | publik | Ambil token |
| POST | `/logout` | - | Hapus semua token user |
| GET | `/user/{id}` | - | Detail user |

### Kelas

| Method | Endpoint | Peran | Keterangan |
|---|---|---|---|
| POST | `/classes` | guru | Body: `name` (wajib, maks 50), `subject`, `description`. Kode kelas dibuat otomatis (8 karakter) |
| GET | `/classes` | - | Guru: kelas yang ia ajar; siswa: kelas yang diikuti |
| GET | `/classes/{id}` | - | Detail kelas |
| PUT | `/classes/{id}` | guru | Ubah data kelas |
| DELETE | `/classes/{id}` | guru | Hapus kelas |
| POST | `/classes/{code}/join` | siswa | Gabung kelas lewat kode |
| POST | `/classes/{code}/leave` | siswa | Keluar dari kelas |
| GET | `/classes/{id}/students` | guru | Daftar siswa di kelas |
| POST | `/archive/{id}` | guru | Arsipkan kelas |
| POST | `/restore/{id}` | guru | Pulihkan kelas terarsip |
| GET | `/archived-classes` | guru | Daftar kelas terarsip |

### Materi

| Method | Endpoint | Peran | Body |
|---|---|---|---|
| POST | `/material/{class_id}` | guru | `title` (wajib), `description`, `link_url`, `attachment` (jpeg/png/jpg/pdf/docx, maks 8 MB), `scheduled_at` (`Y-m-d H:i`) |
| GET | `/material/{class_id}` | - | Daftar materi kelas (terbaru dulu) |
| GET | `/material/{id}/get` | - | Detail materi |
| PUT | `/material/{id}` | guru | Sama seperti POST, semua opsional |
| DELETE | `/material/{id}` | guru | Hapus materi |

### Pengumuman

| Method | Endpoint | Peran | Body |
|---|---|---|---|
| POST | `/announcement/{class_id}` | guru | `content` (wajib), `attachment`, `scheduled_at` |
| GET | `/announcement/{class_id}` | - | Daftar pengumuman kelas |
| GET | `/announcement/{id}/get` | - | Detail pengumuman |
| PUT | `/announcement/{id}` | guru | Ubah pengumuman |
| DELETE | `/announcement/{id}` | guru | Hapus pengumuman |

### Tugas

| Method | Endpoint | Peran | Body |
|---|---|---|---|
| POST | `/assignment/{class_id}` | guru | `title` (wajib), `type` (`essay`/`multiple_choice`/`file_upload`, wajib), `max_score` (0–100, wajib), `description`, `due_date`, `scheduled_at`, `link_url`, `attachment`, `options` (khusus multiple choice: 2–4 pilihan dipisah koma) |
| GET | `/assignment/{class_id}` | - | Daftar tugas kelas |
| GET | `/assignment/{id}/get` | - | Detail tugas |
| PUT | `/assignment/{id}` | guru | Ubah tugas |
| DELETE | `/assignment/{id}` | guru | Hapus tugas |

`due_date` dan `scheduled_at` memakai format `Y-m-d H:i`.

### Pengumpulan

| Method | Endpoint | Peran | Body |
|---|---|---|---|
| POST | `/assignment/{assignment_id}/submission` | siswa | `answer` untuk `essay`/`multiple_choice`, atau `attachment` (wajib, maks 8 MB) untuk `file_upload` |
| GET | `/assignment/{assignment_id}/submission` | - | Daftar pengumpulan |
| GET | `/submission/{id}/get` | - | Detail pengumpulan |
| PUT | `/submission/{id}` | guru | `score` (0–100) — status berubah jadi `graded` |

### Kode status

| Kode | Arti |
|---|---|
| 200 / 201 | Berhasil |
| 400 | Permintaan tidak valid (mis. sudah gabung kelas, tipe tugas salah) |
| 401 | Belum login / kredensial salah |
| 403 | Peran tidak diizinkan |
| 404 | Data tidak ditemukan |
| 422 | Validasi gagal (respons berisi detail per field) |

## Struktur proyek

```
app/
├── Console/Commands/PublishScheduledData.php   # publish materi/tugas/pengumuman terjadwal
├── Http/Controllers/
│   ├── AuthController.php
│   ├── ClassController.php
│   ├── MaterialController.php
│   ├── AnnouncementController.php
│   ├── AssignmentController.php
│   └── SubmissionController.php
└── Models/                                     # User, Classes, Material, Announcement, Assignment, Submission, ClassStudent
database/migrations/                            # users, classes, class_student, material, announcement, assignment, submission
routes/api.php                                  # seluruh endpoint API
```

## Deploy

Repo sudah menyertakan `nixpacks.toml` (batas upload 100 MB) dan `nginx.template.conf`
untuk platform berbasis Nixpacks (mis. Railway). Variabel yang perlu diisi di panel hosting:
`APP_KEY`, `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, serta kredensial `DB_*`.
Jalankan `php artisan migrate --force` dan `php artisan storage:link` setelah deploy.

## Lisensi

MIT
