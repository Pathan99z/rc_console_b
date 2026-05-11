<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Payment\PayFastItnService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PayFastWebhookController extends Controller
{
    public function __construct(private readonly PayFastItnService $payFastItnService)
    {
    }

    public function handle(Request $request): Response
    {
        return $this->payFastItnService->handle($request);
    }
}
