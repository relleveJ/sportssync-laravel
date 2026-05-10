<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PreventBackHistory
{
    /**
     * Handle an incoming request and force no-cache headers on responses.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Ensure the response forces browsers to revalidate and not serve
        // a cached authenticated page when the user navigates back after logout.
        if (method_exists($response, 'headers')) {
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Expires', 'Sat, 01 Jan 1990 00:00:00 GMT');
        }

        return $response;
    }
}
