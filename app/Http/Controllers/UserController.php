<?php

namespace App\Http\Controllers;

use App\Enum\UserRole;
use App\Exports\UsersExport;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Services\UserService;
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
use App\Models\Role as ExtendedRole;

class UserController extends Controller
{
    protected $userService;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }

    public function index()
    {
        $usersList = User::usersOption();
        $users = $this->userService->getUsersList();

        return Inertia::render('User/Index', [
            'users' => $users,
            'filters' => request()->only(['search']),
            'usersList' => $usersList
        ]);
    }

    public function create()
    {

        $user = $this->userService->getUserFromTemplate();
        $roles = ExtendedRole::rolesOption();
        $branches = StoreBranch::options();

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
        try {
            $this->userService->createUser($request->validated());
            return redirect()->route('users.index');
        } catch (Exception $e) {
            DB::rollBack();
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function destroy(User $user)
    {
        try {
            $this->userService->deleteUser($user);
        } catch (Exception $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }
        return to_route('users.index');
    }

    public function update(UpdateUserRequest $request, User $user)
    {
        try {
            $this->userService->updateUser($request->validated(), $user);
            return redirect()->route('users.index');
        } catch (Exception $e) {
            DB::rollBack();
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function show(User $user)
    {
        $user->load(['roles', 'store_branches']);
        return Inertia::render('User/Show', [
            'user' => $user
        ]);
    }
}
