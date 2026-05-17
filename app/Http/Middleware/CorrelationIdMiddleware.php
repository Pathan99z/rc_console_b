<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class CorrelationIdMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) config('audit.correlation_header', 'X-Correlation-Id');
        $incoming = $request->header($header);
        $id = is_string($incoming) && $incoming !== ''
            ? substr($incoming, 0, 80)
            : (string) Str::uuid();

        $request->attributes->set('correlation_id', $id);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set($header, $id);

        return $response;
    }
}
