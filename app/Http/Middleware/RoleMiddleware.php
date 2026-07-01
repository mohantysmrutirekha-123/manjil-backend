<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $user = $request->user();

        // Not logged in
        if (!$user) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 401);
        }

        // Check role
        if (!in_array($user->role, $roles)) {
            return response()->json([
                'message' => 'Forbidden - Access denied'
            ], 403);
        }

        return $next($request);
    }
}