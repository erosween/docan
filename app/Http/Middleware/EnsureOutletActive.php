<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOutletActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (in_array($user?->role, ['super_admin', 'sf'], true)) {
            return $next($request);
        }

        if (! $user?->outlet || $user->outlet->status !== 'active') {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'login_id' => $user?->outlet?->status === 'pending'
                    ? 'Outlet masih menunggu persetujuan SF.'
                    : 'Outlet sedang dinonaktifkan. Hubungi SF yang menangani outlet Anda.',
            ]);
        }

        return $next($request);
    }
}
