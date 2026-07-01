<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (!Auth::check()) {
            return redirect()->route('login');
        }

        $admin = Auth::user();

        if (!in_array($admin->role, $roles)) {
            abort(403, 'Unauthorized action.');
        }

        return $next($request);
    }
}
