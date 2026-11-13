<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('settings')) {
            $request->attributes->set('geetest_page', true);
        }
        $response = $next($request);
        $geetest = $request->attributes->get('geetest_page', false);
        $scripts = $geetest ? "'self' https://static.geetest.com https://gcaptcha4.geetest.com" : "'self'";
        $styles = $geetest ? "'self' 'unsafe-inline' https://static.geetest.com" : "'self'";
        $connections = $geetest ? "'self' https://gcaptcha4.geetest.com" : "'self'";
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('Content-Security-Policy', "default-src 'self'; img-src 'self' https: blob: data:; style-src {$styles}; script-src {$scripts}; connect-src {$connections}; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
