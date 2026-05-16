<?php

namespace App\Services\DemoLinks;

use App\Models\DemoLink;
use Illuminate\Support\Facades\Http;

class DemoLinkStatusChecker
{
    /**
     * @return array{last_status: string, last_checked_at: string, http_code: int|null}
     */
    public function check(DemoLink $link): array
    {
        $checkedAt = now();
        $status = DemoLink::STATUS_UNKNOWN;
        $httpCode = null;

        try {
            $response = Http::timeout(10)
                ->withOptions(['allow_redirects' => true])
                ->head($link->demo_url);

            $httpCode = $response->status();
            $status = $response->successful() ? DemoLink::STATUS_UP : DemoLink::STATUS_DOWN;
        } catch (\Throwable) {
            try {
                $response = Http::timeout(10)->get($link->demo_url);
                $httpCode = $response->status();
                $status = $response->successful() ? DemoLink::STATUS_UP : DemoLink::STATUS_DOWN;
            } catch (\Throwable) {
                $status = DemoLink::STATUS_DOWN;
            }
        }

        return [
            'last_status' => $status,
            'last_checked_at' => $checkedAt->toIso8601String(),
            'http_code' => $httpCode,
        ];
    }
}
