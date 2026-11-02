<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckModulePermission
{
    public function handle(Request $request, Closure $next, string $module, string $ability = 'view')
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['status' => 401, 'message' => 'Unauthenticated'], 401);
        }

        if ($user->role === 'admin') {
            return $next($request);
        }

        // Everyone can view every page — only "manage" (add/edit/delete) is gated.
        if ($ability === 'view') {
            return $next($request);
        }

        $allowed = ($user->permissions[$module] ?? false) === true;

        if (!$allowed) {
            return response()->json([
                'status'  => 403,
                'message' => 'You do not have permission to perform this action.',
            ], 403);
        }

        return $next($request);
    }
}