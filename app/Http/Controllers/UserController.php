<?php

namespace App\Http\Controllers;

use App\Enum\UserRole;
use App\Exports\UsersExport;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Models\StoreBranch;
use App\Models\User;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index()
    {
        $search = request('search');
        try {
            $usersList = User::select(['id', 'first_name', 'last_name'])->get()->pluck('full_name', 'id');
            $query = User::query()->with('roles');
            if ($search) {
                $query->whereAny(['first_name', 'last_name', 'email'], 'like', "%$search%");
            }
            $users = $query->latest()->paginate(10)->withQueryString();
        } catch (Exception $e) {
            report($e);
            return back()->withErrors([
                'error' => $e->getMessage()
            ]);
        }

        return Inertia::render('User/Index', [
            'users' => $users,
            'filters' => request()->only(['search']),
            'usersList' => $usersList
        ]);
    }

    public function create()
    {
        $templateId = request('templateId');
        try {
            $user = $templateId ? User::with(['roles', 'store_branches'])->findOrFail($templateId) : null;
            $roles = Role::select(['id', 'name'])->pluck('name', 'id');
            $branches = StoreBranch::options();
            foreach ($roles as &$role) {
                $role = Str::headline($role);
            }
        } catch (Exception $e) {
            throw $e;
        }
        return Inertia::render('User/Create', [
            'roles' => $roles,
            'branches' => $branches,
            'user' => $user ?? null
        ]);
    }

    public function edit(User $user)
    {
        $user->load(['roles', 'store_branches']);
        $roles = Role::select(['id', 'name'])->pluck('name', 'id');
        $branches = StoreBranch::options();
        return Inertia::render('User/Edit', [
            'user' => $user,
            'roles' => $roles,
            'branches' => $branches
        ]);
    }

    public function export(Request $request)
    {
        $search = $request->input('search');

        return Excel::download(
            new UsersExport($search),
            'users-' . now()->format('Y-m-d') . '.xlsx'
        );
    }

    public function store(StoreUserRequest $request)
    {
        $validated = $request->validated();

        $validated['password'] = 'password';

        DB::beginTransaction();
        try {
            $user = User::create($validated);
            $roles = Role::whereIn('id', $validated['roles'])->get();
            $user->assignRole($roles);
            $validatedAssignedStoreBranches = $request->validate([
                'assignedBranches' => ['required', 'array'],
            ]);
            $user->store_branches()->attach($validatedAssignedStoreBranches['assignedBranches']);

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            return back()->withErrors(['error' => $e->getMessage()]);
        }

        return redirect()->route('users.index');
    }

    public function destroy($id)
    {
        $user = User::with(['usage_records', 'store_orders'])->findOrFail($id);
        if ($user->usage_records->count() > 0 || $user->store_orders->count() > 0) {
            return back()->withErrors([
                'message' => "Can't delete this user because there are data associated with it."
            ]);
        }
        try {
            $user->delete();
        } catch (Exception $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }
        return to_route('users.index');
    }

    public function update(UpdateUserRequest $request, User $user)
    {
        $validated = $request->validated();

        DB::beginTransaction();
        try {
            $user->update([
                'first_name' => $validated['first_name'],
                'middle_name' => $validated['middle_name'] ?? null,
                'last_name' => $validated['last_name'],
                'phone_number' => $validated['phone_number'],
                'email' => $validated['email'],
                'remarks' => $validated['remarks'] ?? null,
            ]);
            $roles = Role::whereIn('id', $validated['roles'])->get();
            $user->syncRoles($roles);

            $user->store_branches()->sync($validated['assignedBranches']);

            DB::commit();

            return redirect()->route('users.index');
        } catch (Exception $e) {
            DB::rollBack();
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function show($id)
    {
        $user = User::with(relations: ['roles', 'store_branches'])->find($id);
        return Inertia::render('User/Show', [
            'user' => $user
        ]);
    }
}
