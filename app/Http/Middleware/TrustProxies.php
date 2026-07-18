<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrustProxies
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Trust all proxies for ngrok and development
        $request->setTrustedProxies(['*'], Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT);

        // Force HTTPS if behind a proxy
        if ($request->header('X-Forwarded-Proto') === 'https' || config('app.force_https')) {
            $request->server->set('HTTPS', 'on');
            $request->server->set('REQUEST_SCHEME', 'https');
            $request->server->set('SERVER_PORT', 443);
        }

        return $next($request);
    }
}
