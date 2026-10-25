<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\LoanController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FeaturedBookController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PetugasController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\StokController;
use App\Http\Controllers\StatistikController;

// ============================================================
// Halaman Pengunjung (Public & Anggota Perpustakaan)
// Diproteksi prevent.staff: Admin & Petugas tidak boleh mengakses
// Jika Admin/Petugas mencoba akses, dipaksa redirect ke Dashboard masing-masing
// ============================================================
Route::middleware('prevent.staff')->group(function () {
    // Public Visitor Routes
    Route::get('/', [BookController::class, 'publicIndex'])->name('home');
    Route::get('/books/{book}', [BookController::class, 'publicShow'])->name('books.show');
    Route::get('/koleksi', [BookController::class, 'collection'])->name('books.collection');

    // Authenticated Pengunjung Routes (membutuhkan login akun pengunjung)
    Route::middleware('auth')->group(function () {
        Route::get('/my-loans', [LoanController::class, 'myLoans'])->name('my-loans');
        Route::get('/my-attendance', [AttendanceController::class, 'userHistory'])->name('my-attendance');
        Route::post('/borrow', [LoanController::class, 'borrow'])->name('borrow');

        // Return request (user generates return barcode)
        Route::post('/loans/{loan}/request-return', [LoanController::class, 'requestReturn'])->name('loans.request-return');
        Route::post('/loans/{loan}/cancel', [LoanController::class, 'cancelLoan'])->name('loans.cancel');
        Route::post('/loans/{loan}/cancel-return', [LoanController::class, 'cancelReturn'])->name('loans.cancel-return');
        Route::get('/loans/{loan}/barcode-svg/{type}', [LoanController::class, 'barcodeSvg'])->name('loans.barcode-svg');

        // Profile & Biodata Routes
        Route::get('/profile', [ProfileController::class, 'show'])->name('profile');
        Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

        // Kartu Anggota: generate barcode jika belum ada
        Route::post('/member-barcode/generate', [AttendanceController::class, 'generateBarcode'])->name('member-barcode.generate');
    });
});

// ============================================================
// Petugas Routes (hanya untuk petugas dan admin)
// ============================================================
Route::middleware(['auth', 'petugas'])->prefix('petugas')->name('petugas.')->group(function () {
    Route::get('/dashboard', [PetugasController::class, 'dashboard'])->name('dashboard');
    Route::get('/scanner-sirkulasi', [PetugasController::class, 'scannerSirkulasi'])->name('scanner.sirkulasi');
    Route::get('/scanner-presensi', [PetugasController::class, 'scannerPresensi'])->name('scanner.presensi');
    Route::post('/scan/loan', [PetugasController::class, 'scanLoan'])->name('scan.loan');
    Route::post('/scan/return', [PetugasController::class, 'scanReturn'])->name('scan.return');
    Route::post('/api/scan', [PetugasController::class, 'apiScan'])->name('api.scan');

    // Presensi / Kartu Anggota
    Route::post('/api/scan-member', [AttendanceController::class, 'apiScan'])->name('api.scan-member');

    // Bantuan Pengunjung: Buat Akun & Pinjaman Manual (Menggunakan Controller & View Admin)
    Route::get('/pengunjung/create', [UserController::class, 'create'])->name('users.create');
    Route::post('/pengunjung', [UserController::class, 'store'])->name('users.store');
    Route::get('/loans/create', [LoanController::class, 'adminCreate'])->name('loans.create');
    Route::post('/loans', [LoanController::class, 'adminStore'])->name('loans.store');
});

// ============================================================
// Petugas Stok Routes (hanya untuk petugas_stok dan admin)
// ============================================================
Route::middleware(['auth', 'petugas.stok'])->prefix('stok')->name('stok.')->group(function () {
    Route::get('/dashboard', [StokController::class, 'dashboard'])->name('dashboard');

    // CRUD Buku
    Route::get('/buku', [StokController::class, 'index'])->name('books.index');
    Route::get('/buku/tambah', [StokController::class, 'create'])->name('books.create');
    Route::post('/buku', [StokController::class, 'store'])->name('books.store');
    Route::get('/buku/{book}/edit', [StokController::class, 'edit'])->name('books.edit');
    Route::get('/buku/{book}', [StokController::class, 'show'])->name('books.show');
    Route::put('/buku/{book}', [StokController::class, 'update'])->name('books.update');
    Route::delete('/buku/{book}', [StokController::class, 'destroy'])->name('books.destroy');

    // Adjust Stok
    Route::post('/buku/{book}/stok', [StokController::class, 'adjustStock'])->name('books.adjust-stock');
});

// ============================================================
// Admin Routes (hanya untuk role admin)
// Diproteksi auth & admin: Pengunjung & Petugas tidak boleh mengakses
// ============================================================
Route::middleware(['auth', 'admin'])->prefix('admin')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('admin.dashboard');

    // Admin Users Routes (View, Edit, Delete, Create Petugas)
    Route::resource('users', UserController::class)->names([
        'index'   => 'admin.users.index',
        'create'  => 'admin.users.create',
        'store'   => 'admin.users.store',
        'show'    => 'admin.users.show',
        'edit'    => 'admin.users.edit',
        'update'  => 'admin.users.update',
        'destroy' => 'admin.users.destroy',
    ]);

    // Admin Books Routes (CRUD)
    Route::resource('books', BookController::class)->names([
        'index'   => 'admin.books.index',
        'create'  => 'admin.books.create',
        'store'   => 'admin.books.store',
        'show'    => 'admin.books.show',
        'edit'    => 'admin.books.edit',
        'update'  => 'admin.books.update',
        'destroy' => 'admin.books.destroy',
    ]);

    // Admin Featured Books (Buku Beranda) Routes
    Route::name('admin.')->group(function () {
        Route::get('/featured-books', [FeaturedBookController::class, 'index'])->name('featured-books.index');
        Route::post('/featured-books/{book}', [FeaturedBookController::class, 'update'])->name('featured-books.update');

        // Admin Loans Routes (CRUD)
        Route::get('/loans', [LoanController::class, 'adminIndex'])->name('loans.index');
        Route::get('/loans/create', [LoanController::class, 'adminCreate'])->name('loans.create');
        Route::post('/loans', [LoanController::class, 'adminStore'])->name('loans.store');
        Route::get('/loans/{loan}', [LoanController::class, 'adminShow'])->name('loans.show');
        Route::get('/loans/{loan}/edit', [LoanController::class, 'adminEdit'])->name('loans.edit');
        Route::put('/loans/{loan}', [LoanController::class, 'adminUpdate'])->name('loans.update');
        Route::delete('/loans/{loan}', [LoanController::class, 'adminDestroy'])->name('loans.destroy');

        // Admin Attendance History (Riwayat Kunjungan)
        Route::get('/attendance', [AttendanceController::class, 'history'])->name('attendance.index');

        // Statistik & Analitik
        Route::get('/statistik', [StatistikController::class, 'index'])->name('statistik');
    });
});

// Public API for featured books
Route::get('/api/featured-books', [FeaturedBookController::class, 'apiFeatured'])->name('api.featured-books');

// ============================================================
// Auth Routes
// ============================================================
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('auth.login');
Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
Route::post('/register', [AuthController::class, 'register'])->name('auth.register');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Google OAuth Routes
Route::get('/auth/google', [AuthController::class, 'redirectToGoogle'])->name('auth.google');
Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback'])->name('auth.google.callback');

// API Routes for Registration Validation
Route::post('/api/check-npm', [AuthController::class, 'checkNpm'])->name('api.check-npm');
Route::post('/api/check-email', [AuthController::class, 'checkEmail'])->name('api.check-email');
