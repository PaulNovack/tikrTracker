<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PreventGuestUserActions
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && $request->user()->email === 'guest@tikrtracker.com') {
            // Block destructive actions for guest users
            if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'])) {
                return back()->withErrors([
                    'error' => 'This action is disabled in guest mode. Request beta access to create your own account.',
                ]);
            }
        }

        return $next($request);
    }
}
