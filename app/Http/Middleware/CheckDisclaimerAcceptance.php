<?php

namespace App\Http\Middleware;

use App\Models\DisclaimerAcceptance;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckDisclaimerAcceptance
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip check for disclaimer routes themselves and API routes
        if ($this->shouldSkipCheck($request)) {
            return $next($request);
        }

        $ipAddress = DisclaimerAcceptance::getCurrentIpAddress();

        // Track any page visits (for users who haven't accepted) - before disclaimer check
        if (! DisclaimerAcceptance::hasAcceptedAll($ipAddress)) {
            DisclaimerAcceptance::incrementPageVisit($ipAddress);
        }

        // Check if this IP should be shown the disclaimer based on new logic
        if (DisclaimerAcceptance::shouldShowDisclaimer($ipAddress)) {
            // Store the intended URL to redirect back after acceptance
            $request->session()->put('disclaimer_redirect_url', $request->fullUrl());

            return redirect()->route('disclaimer');
        }

        // Update last access for tracking (if already accepted)
        if (DisclaimerAcceptance::hasAcceptedAll($ipAddress)) {
            DisclaimerAcceptance::updateLastAccess($ipAddress);
        }

        return $next($request);
    }

    /**
     * Routes that should skip the disclaimer check
     */
    private function shouldSkipCheck(Request $request): bool
    {
        $skipRoutes = [
            'disclaimer',
            'disclaimer.accept',
        ];

        $skipPaths = [
            '/disclaimer',
            '/api/*',
            '/build/*',
            '/_debugbar/*',
        ];

        // Skip if current route is in skip list
        if (in_array($request->route()?->getName(), $skipRoutes)) {
            return true;
        }

        // Skip if path matches skip patterns
        foreach ($skipPaths as $pattern) {
            if ($request->is($pattern)) {
                return true;
            }
        }

        return false;
    }
}
