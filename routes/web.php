<?php

use Illuminate\Support\Facades\Route;

// Panel Filament adalah SATU-SATUNYA antarmuka aplikasi ini — tidak ada
// halaman publik apa pun. Root diarahkan ke panel supaya kasir yang mengetik
// alamat server saja (tanpa /admin) tetap sampai ke tempat yang benar.
Route::redirect('/', '/admin');
