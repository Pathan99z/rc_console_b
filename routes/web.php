<?php

use App\Http\Controllers\Web\PublicQuoteController;
use App\Http\Controllers\Web\PublicPaymentController;
use App\Http\Controllers\Web\PayFastReturnController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/quotes/public/{token}', [PublicQuoteController::class, 'show'])->name('quotes.public.show');
Route::get('/quotes/public/{token}/accept', [PublicQuoteController::class, 'accept'])->name('quotes.public.accept');
Route::get('/quotes/public/{token}/reject', [PublicQuoteController::class, 'reject'])->name('quotes.public.reject');
Route::get('/payments/link/{token}', [PublicPaymentController::class, 'pay'])->name('payments.public.link');
Route::get('/billing/payfast/return', [PayFastReturnController::class, 'success'])->name('payfast.return');
Route::get('/billing/payfast/cancel', [PayFastReturnController::class, 'cancel'])->name('payfast.cancel');
