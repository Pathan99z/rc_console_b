<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\Quote\QuoteService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PublicQuoteController extends Controller
{
    public function __construct(private readonly QuoteService $service)
    {
    }

    public function show(Request $request, string $token): View
    {
        $quote = $this->service->getPublicQuote($token, $request);

        return view('quotes.public-show', [
            'quote' => $quote,
            'token' => $token,
            'statusMessage' => null,
        ]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $this->service->acceptPublicQuote($token, $request);

        return redirect()->route('quotes.public.show', ['token' => $token])
            ->with('status_message', 'Quote accepted successfully.');
    }

    public function reject(Request $request, string $token): RedirectResponse
    {
        $this->service->rejectPublicQuote($token, $request);

        return redirect()->route('quotes.public.show', ['token' => $token])
            ->with('status_message', 'Quote rejected successfully.');
    }
}
