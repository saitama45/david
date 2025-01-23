<?php

namespace App\Http\Controllers;

use App\Enum\UserRole;
use App\Exports\UsersExport;
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
            'filters' => request()->only(['search'])
        ]);
    }

    public function create()
    {
        try {
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
            'branches' => $branches
        ]);
    }

    public function edit($id)
    {
        $user = User::with(['roles', 'store_branches'])->find($id);
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

    public function store(Request $request)
    {
        $validated = $request->validate([
            'first_name' => ['required'],
            'middle_name' => ['sometimes'],
            'last_name' => ['required'],
            'phone_number' => ['required', 'regex:/^09\d{9}$/'],
            'email' => ['required', 'unique:users,email'],
            'roles' => ['required'],
            'remarks' => ['nullable'],
            'assignedBranches' => ['required', 'array'],
        ]);

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

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'first_name' => ['required'],
            'middle_name' => ['nullable'],
            'last_name' => ['required'],
            'phone_number' => ['required', 'regex:/^09\d{9}$/'],
            'email' => ['required', 'unique:users,email,' . $id],
            'roles' => ['required', 'array'],
            'remarks' => ['nullable'],
            'assignedBranches' => ['required', 'array'],
        ]);

        DB::beginTransaction();
        try {
            $user = User::findOrFail($id);

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
