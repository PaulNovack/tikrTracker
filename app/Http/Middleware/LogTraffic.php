<?php

namespace App\Http\Middleware;

use App\Models\TrafficLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class LogTraffic
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);
        $request->attributes->set('request_start_time', $startTime);

        return $next($request);
    }

    /**
     * Handle tasks after the response has been sent to the browser.
     */
    public function terminate(Request $request, Response $response): void
    {
        if (! config('traffic_logging.enabled')) {
            return;
        }

        try {
            $startTime = $request->attributes->get('request_start_time');
            $startDateTime = \DateTime::createFromFormat('U.u', $startTime);
            $endTime = microtime(true);
            $endDateTime = \DateTime::createFromFormat('U.u', $endTime);
            $durationMs = (int) round(($endTime - $startTime) * 1000);

            $route = $request->route();
            $routeName = $route?->getName();
            $controllerAction = null;

            if ($route && $route->getController()) {
                $controller = $route->getController();
                $controllerName = class_basename($controller);
                $controllerAction = $controllerName.'@'.$route->getActionMethod();
            }

            TrafficLog::create([
                'user_id' => Auth::id(),
                'ip_address' => $request->ip(),
                'method' => $request->method(),
                'url' => '/'.$request->path(),
                'full_url' => $request->fullUrl(),
                'route_name' => $routeName,
                'controller_action' => $controllerAction,
                'status_code' => $response->getStatusCode(),
                'duration_ms' => $durationMs,
                'query_params' => $request->query->count() > 0 ? $request->query->all() : null,
                'post_data' => $request->isMethod('POST') || $request->isMethod('PUT') || $request->isMethod('PATCH')
                    ? $request->except(['_token', 'password', 'password_confirmation', 'current_password'])
                    : null,
                'headers' => [
                    'Accept' => $request->header('accept'),
                    'Content-Type' => $request->header('content-type'),
                    'User-Agent' => $request->header('user-agent'),
                ],
                'user_agent' => $request->userAgent(),
                'referer' => $request->header('referer'),
                'request_start' => $startDateTime,
                'request_end' => $endDateTime,
            ]);
        } catch (\Exception $e) {
            // Silently fail to avoid disrupting the application
        }
    }
}
