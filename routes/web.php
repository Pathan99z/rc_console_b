<?php

use App\Http\Controllers\Web\PublicQuoteController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/quotes/public/{token}', [PublicQuoteController::class, 'show'])->name('quotes.public.show');
Route::get('/quotes/public/{token}/accept', [PublicQuoteController::class, 'accept'])->name('quotes.public.accept');
Route::get('/quotes/public/{token}/reject', [PublicQuoteController::class, 'reject'])->name('quotes.public.reject');
