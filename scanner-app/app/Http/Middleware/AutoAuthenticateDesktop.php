<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Auto-authenticate as the default user when running as a local desktop app.
 *
 * This middleware bypasses the login flow entirely for single-user
 * desktop deployments (NativePHP). It finds or creates a default
 * local user and logs them in on every request.
 */
class AutoAuthenticateDesktop
{
    public function handle(Request $request, Closure $next): Response
    {
        // Only auto-auth if not already authenticated
        if (!Auth::check()) {
            $user = User::firstOrCreate(
                ['email' => 'local@desktop.app'],
                [
                    'name' => 'Local User',
                    'email' => 'local@desktop.app',
                    'password' => bcrypt('desktop-local-' . now()->timestamp),
                    'email_verified_at' => now(),
                ]
            );

            Auth::login($user);
        }

        return $next($request);
    }
}
