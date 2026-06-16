<?php

namespace App\Http\Controllers;

use App\Models\Entity;
use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class EntityController extends Controller
{
    /**
     * Admin listing of entities with their assigned users and record counts.
     */
    public function index(): Response
    {
        $entities = Entity::query()
            ->withCount([
                // store_branches is entity-scoped, so bypass the active-entity
                // filter to count each entity's own branches.
                'store_branches' => fn ($q) => $q->withoutEntityScope(),
                'roles',
            ])
            ->with(['roles:id,name'])
            ->orderBy('name')
            ->get();

        $roles = Role::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('Entity/Index', [
            'entities' => $entities,
            'roles' => $roles,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request, null);

        $entity = new Entity();
        $entity->name = $data['name'];
        $entity->code = $data['code'];
        $entity->is_active = $data['is_active'] ?? true;

        if ($request->hasFile('logo')) {
            $entity->logo_path = $request->file('logo')->store('entity-logos', 'public');
        }

        $entity->save();
        $entity->roles()->sync($data['role_ids'] ?? []);

        return redirect()->back()->with('success', 'Entity created successfully.');
    }

    public function update(Request $request, Entity $entity): RedirectResponse
    {
        $data = $this->validateData($request, $entity->id);

        $entity->name = $data['name'];
        $entity->code = $data['code'];
        $entity->is_active = $data['is_active'] ?? $entity->is_active;

        if ($request->hasFile('logo')) {
            if ($entity->logo_path) {
                Storage::disk('public')->delete($entity->logo_path);
            }
            $entity->logo_path = $request->file('logo')->store('entity-logos', 'public');
        }

        $entity->save();
        $entity->roles()->sync($data['role_ids'] ?? []);

        return redirect()->back()->with('success', 'Entity updated successfully.');
    }

    public function destroy(Entity $entity): RedirectResponse
    {
        // Guard: don't allow deleting an entity that still owns branches.
        if ($entity->store_branches()->exists()) {
            return redirect()->back()->withErrors([
                'message' => "Can't delete this entity because branches and records are still assigned to it.",
            ]);
        }

        if ($entity->logo_path) {
            Storage::disk('public')->delete($entity->logo_path);
        }

        $entity->roles()->detach();
        $entity->delete();

        return redirect()->back()->with('success', 'Entity deleted successfully.');
    }

    /**
     * Switch the authenticated user's active entity.
     */
    public function switch(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'entity_id' => ['required', 'integer'],
        ]);

        $user = $request->user();

        $allowed = $user->accessibleEntities()->where('is_active', true)->pluck('entities.id');

        if (! $allowed->contains((int) $data['entity_id'])) {
            throw ValidationException::withMessages([
                'entity_id' => 'You do not have access to the selected entity.',
            ]);
        }

        $request->session()->put('active_entity_id', (int) $data['entity_id']);
        $user->forceFill(['last_entity_id' => (int) $data['entity_id']])->save();

        // Redirect back so every module reloads under the new entity context.
        return redirect()->back();
    }

    private function validateData(Request $request, ?int $entityId): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', Rule::unique('entities', 'code')->ignore($entityId)],
            'is_active' => ['boolean'],
            'logo' => ['nullable', 'image', 'max:2048'],
            'role_ids' => ['array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
        ]);
    }
}
