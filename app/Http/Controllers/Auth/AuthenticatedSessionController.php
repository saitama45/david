<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
            'entities' => \App\Models\Entity::where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'logo_path']),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
    
        $request->authenticate();

        $user = $request->user();
        $entityId = (int) $request->input('entity_id');

        // The signed-in user must actually belong to the entity they picked.
        if (! $user->accessibleEntities()->where('entities.id', $entityId)->where('is_active', true)->exists()) {
            Auth::guard('web')->logout();

            throw ValidationException::withMessages([
                'entity_id' => 'You do not have access to the selected entity.',
            ]);
        }

        $request->session()->regenerate();
        $request->session()->put('active_entity_id', $entityId);
        $user->forceFill(['last_entity_id' => $entityId])->save();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
