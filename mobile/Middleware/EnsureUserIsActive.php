<?php

namespace App\Mobile\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user() || !$request->user()->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Account is disabled. Contact administrator.',
            ], 423);
        }

        return $next($request);
    }
}
