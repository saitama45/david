<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs out a user whose account was made inactive (Users > Edit > Status) while they were
 * logged in, on their next request. Logging in again is refused by LoginRequest.
 */
class EnsureUserIsActive
{
    public const MESSAGE = 'This account is inactive. Ask your administrator to make it active again.';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->isInactive()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('status', self::MESSAGE);
        }

        return $next($request);
    }
}
