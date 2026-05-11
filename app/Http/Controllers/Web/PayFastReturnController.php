<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PayFastReturnController extends Controller
{
    public function success(Request $request): RedirectResponse
    {
        $target = (string) (env('PAYFAST_SUCCESS_REDIRECT_URL', env('FRONTEND_URL', rtrim((string) config('app.url'), '/').'/login')));

        return redirect()->away($this->appendQuery($target, 'payment', 'success'));
    }

    public function cancel(Request $request): RedirectResponse
    {
        $target = (string) (env('PAYFAST_CANCEL_REDIRECT_URL', env('FRONTEND_URL', rtrim((string) config('app.url'), '/').'/login')));

        return redirect()->away($this->appendQuery($target, 'payment', 'cancelled'));
    }

    private function appendQuery(string $base, string $key, string $value): string
    {
        $separator = str_contains($base, '?') ? '&' : '?';

        return $base.$separator.urlencode($key).'='.urlencode($value);
    }
}
