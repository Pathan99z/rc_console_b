<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\Payment\QuotePaymentLinkService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class PublicPaymentController extends Controller
{
    public function __construct(private readonly QuotePaymentLinkService $quotePaymentLinkService)
    {
    }

    public function pay(Request $request, string $token): View
    {
        $payload = $this->quotePaymentLinkService->createForStoredToken($token);

        return view('payments.payfast-redirect', [
            'actionUrl' => $payload['action_url'],
            'fields' => $payload['fields'],
        ]);
    }
}
