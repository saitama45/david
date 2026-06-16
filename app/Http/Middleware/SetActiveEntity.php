<?php

namespace App\Http\Middleware;

use App\Support\EntityContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active entity for the authenticated user and binds it into the
 * EntityContext so model scoping applies for the rest of the request.
 *
 * Precedence: session('active_entity_id') -> user's last_entity_id -> first
 * allowed entity. The chosen id is always validated against the user's allowed
 * entities to prevent tampering / stale sessions.
 */
class SetActiveEntity
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            $allowed = $user->accessibleEntities()->where('is_active', true)->pluck('entities.id');
            $entityId = $request->session()->get('active_entity_id');

            if (! $entityId || ! $allowed->contains((int) $entityId)) {
                $entityId = $allowed->contains((int) $user->last_entity_id)
                    ? (int) $user->last_entity_id
                    : $allowed->first();

                if ($entityId) {
                    $request->session()->put('active_entity_id', $entityId);
                }
            }

            if ($entityId) {
                app(EntityContext::class)->set((int) $entityId);
            }
        }

        return $next($request);
    }
}
