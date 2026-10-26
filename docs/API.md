# Gaming Store API

Base URL: `http://localhost:8000/api/v1`

API ini menggunakan Laravel Sanctum personal access tokens. Endpoint privat menerima:

```http
Authorization: Bearer <token>
Accept: application/json
```

## Data dan status

- `users`: pelanggan dan admin; akun baru selalu ber-role `customer`.
- `categories` dan `products`: katalog. Produk publik hanya menampilkan produk aktif.
- `product_images`: gambar tambahan; `products.image` tetap menjadi gambar utama.
- `addresses`, `carts`, `cart_items`: data privat per pelanggan.
- `orders` dan `order_items`: snapshot alamat, nama, dan harga saat checkout.
- `payments`: catatan pembayaran; tidak ada integrasi payment gateway.
- `coupons` dan `coupon_usages`: kupon, pemakaian, dan diskon.
- `reviews`: ulasan wajib terkait pesanan selesai dan hanya ulasan disetujui yang publik.
- `wishlists`: pasangan unik pelanggan dan produk.

Status pesanan: `pending`, `processing`, `shipped`, `completed`, `cancelled`.

Status pembayaran: `unpaid`, `paid`, `failed`, `refunded`.

Perubahan menjadi lunas hanya dapat dilakukan admin melalui endpoint pembayaran. API tidak menerima bukti pembayaran kartu dan tidak menganggap pembayaran berhasil dari data pelanggan.

## Endpoint publik

| Method | Path | Keterangan |
|---|---|---|
| GET | `/categories` | Daftar kategori dan jumlah produk aktif |
| GET | `/categories/{slug}` | Detail kategori berdasarkan slug |
| GET | `/products` | Daftar produk aktif, filter, dan pagination |
| GET | `/products/{slug}` | Detail produk aktif |
| GET | `/products/{product_id}/reviews` | Ulasan disetujui untuk produk aktif |

Parameter `GET /products`:

| Parameter | Nilai |
|---|---|
| `search` | Pencarian nama produk |
| `category` | Slug kategori |
| `brand` | Nama brand |
| `min_price`, `max_price` | Batas harga inklusif |
| `in_stock` | `1` untuk produk dengan stok |
| `sort` | `newest`, `price_asc`, `price_desc`, `rating` |
| `per_page` | 1–48, default 12 |
| `page` | Nomor halaman Laravel |

Contoh:

```http
GET /api/v1/products?category=gaming-mouse&min_price=50&sort=price_asc&per_page=12
```

Respons daftar menggunakan `data` dan `meta`:

```json
{
  "success": true,
  "data": [],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 12,
    "total": 0
  }
}
```

## Authentication

| Method | Path | Akses |
|---|---|---|
| POST | `/auth/register` | Publik |
| POST | `/auth/login` | Publik, maksimum 5 percobaan per menit |
| POST | `/auth/logout` | Bearer token |
| GET | `/auth/me` | Bearer token |
| PUT | `/auth/profile` | Bearer token |
| PUT | `/auth/password` | Bearer token |

Register:

```json
{
  "name": "Nama Pelanggan",
  "email": "pelanggan@example.com",
  "phone": "081234567890",
  "password": "password-yang-kuat",
  "password_confirmation": "password-yang-kuat"
}
```

`role` tidak diterima saat registrasi. Password minimal 8 karakter dan di-hash oleh Laravel.
Login menerima `email` dan `password`; token dikembalikan pada `data.token`.
Update profile menerima `name`, `email`, dan/atau `phone`. Update password menerima `current_password`, `password`, dan `password_confirmation`.

## Endpoint pelanggan

Semua endpoint bagian ini memerlukan token Sanctum. Data alamat, keranjang, dan pesanan dibatasi untuk pengguna yang sedang login.

### Alamat

| Method | Path |
|---|---|
| GET | `/addresses` |
| POST | `/addresses` |
| GET | `/addresses/{id}` |
| PUT/PATCH | `/addresses/{id}` |
| DELETE | `/addresses/{id}` |

Body create/update mendukung `recipient_name`, `phone`, `province`, `city`, `district`, `postal_code`, `address_line`, `notes`, dan `is_default`. Alamat digunakan sebagai snapshot ketika order dibuat.

### Keranjang dan checkout

| Method | Path | Body |
|---|---|---|
| GET | `/cart` | — |
| POST | `/cart/items` | `product_id`, `quantity` |
| PUT | `/cart/items/{id}` | `quantity` |
| DELETE | `/cart/items/{id}` | — |
| DELETE | `/cart` | — |
| POST | `/checkout` | `address_id`, `payment_method`, optional `coupon_code`, `notes` |

Metode pembayaran yang disiapkan: `manual_transfer` dan `cash_on_delivery`. Checkout mengambil ulang harga dan stok dari database, memeriksa alamat milik pengguna, membuat snapshot order, mengurangi stok dalam transaksi, membuat pembayaran berstatus `unpaid`, lalu mengosongkan keranjang. `total_amount`, harga item, dan ongkos kirim yang dikirim frontend tidak dipercaya.

Ongkos kirim saat ini berasal dari konfigurasi `STORE_SHIPPING_COST` dan default-nya `0`; integrasi tarif eksternal belum aktif.

### Pesanan, wishlist, dan kupon

| Method | Path | Body |
|---|---|---|
| GET | `/orders` | — |
| GET | `/orders/{order_number}` | — |
| POST | `/orders/{order_number}/cancel` | — |
| GET | `/wishlist` | — |
| POST | `/wishlist/{product_id}` | — |
| DELETE | `/wishlist/{product_id}` | — |
| POST | `/coupons/validate` | `code`, `subtotal` |

Pelanggan hanya dapat membatalkan order yang masih `pending` dan belum dibayar. Stok dikembalikan dalam transaksi. Validasi kupon publik memberi estimasi berdasarkan subtotal yang dikirim; checkout memvalidasi ulang kupon serta menghitung ulang diskon dari subtotal database.

### Ulasan

| Method | Path | Body |
|---|---|---|
| POST | `/products/{product_id}/reviews` | `order_id`, `rating` (1–5), optional `comment` |

Ulasan hanya dapat dibuat untuk produk dalam pesanan selesai milik pengguna. Ulasan baru belum disetujui dan tidak tampil secara publik sampai dimoderasi admin.

## Endpoint admin

Semua endpoint admin membutuhkan Bearer token dengan `users.role = admin`. Customer menerima `403`.
Route kategori publik dan admin mengidentifikasi kategori menggunakan `slug` (`{category}` pada route admin), sehingga slug yang dikembalikan saat membuat kategori dapat digunakan untuk update dan delete. Kategori yang masih memiliki produk mengembalikan `409 Conflict` saat dihapus.

| Method | Path | Keterangan |
|---|---|---|
| POST/PUT/PATCH/DELETE | `/admin/categories[/{category}]` | CRUD kategori |
| POST/PUT/PATCH/DELETE | `/admin/products[/{product}]` | CRUD produk |
| POST | `/admin/products/{product}/images` | Upload gambar tambahan |
| DELETE | `/admin/product-images/{image}` | Hapus gambar tambahan |
| GET | `/admin/users` | Daftar pengguna berpaginasi |
| GET | `/admin/users/{id}` | Detail pengguna |
| GET | `/admin/orders` | Daftar order |
| GET | `/admin/orders/{order_number}` | Detail order |
| PATCH | `/admin/orders/{order_number}/status` | Body `status` |
| PATCH | `/admin/orders/{order_number}/payment` | Verifikasi status pembayaran |
| GET/POST/GET/PUT/PATCH/DELETE | `/admin/coupons[/{coupon}]` | CRUD kupon |
| GET | `/admin/reviews` | Daftar ulasan |
| PATCH | `/admin/reviews/{review}` | Body `is_approved` |
| DELETE | `/admin/reviews/{review}` | Hapus ulasan |
| GET | `/admin/dashboard` | Ringkasan jumlah dan pendapatan |

Status order hanya dapat berubah melalui alur yang diizinkan: `pending` ke `processing` atau `cancelled`, `processing` ke `shipped` atau `cancelled`, lalu `shipped` ke `completed`. Pemrosesan membutuhkan pembayaran terverifikasi kecuali metode COD. Status pembayaran admin menerima `paid`, `failed`, atau `refunded` dengan pemeriksaan transisi.

### Produk dan upload gambar

Produk admin menerima `category_id`, `name`, optional `slug`, `description`, `brand`, `price`, `stock`, `is_active`, dan optional berkas `image` utama. Gambar tambahan menggunakan multipart form-data dengan field `image`, optional `alt_text`, dan `sort_order`.

Berkas disimpan pada disk `public` di direktori `products`. Simpan path yang dikembalikan, lalu bentuk URL melalui disk public Laravel. Jalankan `php artisan storage:link` pada instalasi lokal agar URL dapat diakses melalui web server.

## Format error dan HTTP status

| Status | Arti umum |
|---|---|
| 401 | Token tidak ada/tidak valid |
| 403 | Bukan admin atau akses ditolak |
| 404 | Resource tidak ditemukan atau bukan milik pengguna |
| 409 | Konflik seperti stok tidak cukup atau resource tidak dapat dihapus |
| 422 | Validasi input atau aturan bisnis gagal |
| 429 | Batas rate limit tercapai |

Respons validasi mengikuti format JSON Laravel:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "email": ["The email field is required."]
  }
}
```

## Instalasi dan pengujian

Jalankan dari direktori project:

```powershell
php artisan migrate
php artisan db:seed
php artisan storage:link
php artisan serve
php artisan test
```

Migration yang ditambahkan bersifat aditif; jangan gunakan `migrate:fresh` pada database berisi data. Seeder katalog menggunakan `firstOrCreate` dan tidak membuat akun admin atau password default.

Test suite mengatur SQLite `:memory:` melalui `phpunit.xml`, terpisah dari database development.

Environment tambahan:

| Variable | Default | Keterangan |
|---|---:|---|
| `STORE_SHIPPING_COST` | `0` | Ongkos kirim sementara; belum memakai penyedia eksternal |

Selain itu, gunakan konfigurasi Laravel yang telah ada (`APP_URL`, `DB_*`, `FILESYSTEM_DISK`, dan pengaturan Sanctum). Jangan memasukkan secret ke repository.

Untuk memberi role admin, buat akun melalui register lalu ubah role secara manual pada lingkungan tepercaya, misalnya melalui Tinker:

```php
$user = App\Models\User::where('email', 'admin@example.com')->firstOrFail();
$user->forceFill(['role' => 'admin'])->save();
```

Tidak ada akun admin demo atau kredensial default.

## Kompatibilitas route lama

Route lama `GET /api/categories` dan `GET /api/categories/{slug}` tetap tersedia. Integrasi baru sebaiknya menggunakan prefix versi `/api/v1`.
