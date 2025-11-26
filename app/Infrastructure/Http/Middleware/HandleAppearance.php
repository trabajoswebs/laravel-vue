<?php

namespace App\Infrastructure\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class HandleAppearance
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    private const DEFAULT_THEME = 'light';
    private const ALLOWED_THEMES = ['light', 'dark', 'system'];

    public function handle(Request $request, Closure $next): Response
    {
        $requestedAppearance = $request->cookie('appearance');
        $safeAppearance = $this->normalizeAppearance($requestedAppearance);

        View::share('appearance', $safeAppearance);

        return $next($request);
    }

    private function normalizeAppearance(?string $value): string
    {
        if ($value === null) {
            return self::DEFAULT_THEME;
        }

        return in_array($value, self::ALLOWED_THEMES, true)
            ? $value
            : self::DEFAULT_THEME;
    }
}
