<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BeforeAfterMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (method_exists($response, 'setContent')) {
            $content = $response->getContent();
            // Only process HTML responses
            if (is_string($content) && str_contains($content, '<html')) {
                // Inject empty ViteError placeholder
                $inject = '<div id="vite-error" style="display:none"></div>';
                $content = str_replace('</head>', $inject.'</head>', $content);
                $response->setContent($content);
            }
        }

        return $response;
    }
}
