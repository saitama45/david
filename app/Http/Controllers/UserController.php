<?php

namespace App\Http\Controllers;

use App\Enum\UserRole;
use App\Exports\UsersExport;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Services\UserService;
use App\Models\StoreBranch;
use App\Models\User;
use App\Models\Supplier; // Import the Supplier model
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
        $suppliers = Supplier::options()->toArray(); // Fetch all suppliers

        return Inertia::render('User/Create', [
            'roles' => $roles,
            'branches' => $branches,
            'suppliers' => $suppliers, // Pass all suppliers to the view
            'user' => $user ?? null
        ]);
    }

    public function edit(User $user)
    {
        // Load the suppliers relationship
        $user->load(['roles', 'store_branches', 'suppliers']);
        $roles = Role::select(['id', 'name'])->pluck('name', 'id');
        $branches = StoreBranch::options();
        $suppliers = Supplier::options()->toArray(); // Fetch all suppliers

        return Inertia::render('User/Edit', [
            'user' => $user,
            'roles' => $roles,
            'branches' => $branches,
            'suppliers' => $suppliers // Pass all suppliers to the view
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
        DB::beginTransaction(); // Start transaction
        try {
            $this->userService->createUser($request->validated());
            DB::commit(); // Commit transaction
            return redirect()->route('users.index');
        } catch (Exception $e) {
            DB::rollBack(); // Rollback on error
            Log::error("Error creating user: " . $e->getMessage()); // Log the error
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function destroy(User $user)
    {
        DB::beginTransaction(); // Start transaction
        try {
            $this->userService->deleteUser($user);
            DB::commit(); // Commit transaction
        } catch (Exception $e) {
            DB::rollBack(); // Rollback on error
            Log::error("Error deleting user: " . $e->getMessage()); // Log the error
            return back()->withErrors(['error' => $e->getMessage()]);
        }
        return to_route('users.index');
    }

    public function update(UpdateUserRequest $request, User $user)
    {
        DB::beginTransaction(); // Start transaction
        try {
            $this->userService->updateUser($request->validated(), $user);
            DB::commit(); // Commit transaction
            return redirect()->route('users.index');
        } catch (Exception $e) {
            DB::rollBack(); // Rollback on error
            Log::error("Error updating user: " . $e->getMessage()); // Log the error
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function show(User $user)
    {
        // Load the suppliers relationship
        $user->load(['roles', 'store_branches', 'suppliers']);
        $suppliers = Supplier::options()->toArray(); // Fetch all suppliers
        return Inertia::render('User/Show', [
            'user' => $user,
            'suppliers' => $suppliers, // Pass all suppliers to the view
        ]);
    }
}