<?php

namespace App\Http\Controllers;

use App\Enum\UserRole;
use App\Exports\UsersExport;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Services\UserService;
use App\Models\StoreBranch;
use App\Models\User;
use App\Models\Supplier;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Role;
use App\Models\Role as ExtendedRole;
use Illuminate\Support\Facades\Hash;

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
        $suppliers = Supplier::reportOptions()->toArray();

        return Inertia::render('User/Create', [
            'roles' => $roles,
            'branches' => $branches,
            'suppliers' => $suppliers,
            'user' => $user ?? null
        ]);
    }

    public function edit(User $user)
    {
        $user->load(['roles', 'store_branches', 'suppliers']);
        $roles = Role::select(['id', 'name'])->pluck('name', 'id');
        $branches = StoreBranch::options();
        $suppliers = Supplier::reportOptions()->toArray();

        Log::debug('UserController@edit: User object being passed to Inertia:', ['user' => $user]);

        return Inertia::render('User/Edit', [
            'user' => $user,
            'roles' => $roles,
            'branches' => $branches,
            'suppliers' => $suppliers
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
            Log::error("Error creating user: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function destroy(User $user)
    {
        try {
            $user->load(['usage_records', 'store_orders']);
            if ($user->usage_records->count() > 0 || $user->store_orders->count() > 0) {
                throw new \Exception("Can't delete this user because there are data associated with it.");
            }
            $this->userService->deleteUser($user);
        } catch (Exception $e) {
            Log::error("Error deleting user: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
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
            Log::error("Error updating user: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function show(User $user)
    {
        $user->load(['roles', 'store_branches', 'suppliers']);
        $suppliers = Supplier::options()->toArray();
        return Inertia::render('User/Show', [
            'user' => $user,
            'suppliers' => $suppliers,
        ]);
    }

    public function debugPassword(Request $request)
    {
        $email = $request->input('email', 'sysadmin@gmail.com');
        $plainPassword = $request->input('password', 'password123');

        $user = User::where('email', $email)->first();

        $output = "<h1>Password Debugging for User: {$email}</h1>";

        if (!$user) {
            $output .= "<p style='color: red;'>User not found with email: {$email}</p>";
            return response($output);
        }

        $dbHashedPassword = $user->password;
        $output .= "<p>User found. Database Hashed Password: <strong>{$dbHashedPassword}</strong></p>";
        $output .= "<p>Plain Text Password to check: <strong>{$plainPassword}</strong></p>";

        $checkResult = Hash::check($plainPassword, $dbHashedPassword);
        $output .= "<p>Result of `Hash::check(\"{$plainPassword}\", \"{$dbHashedPassword}\")`: <strong>" . ($checkResult ? 'MATCHES' : 'DOES NOT MATCH') . "</strong></p>";

        $newlyHashedPassword = Hash::make($plainPassword);
        $output .= "<p>Newly Generated Hash from `Hash::make(\"{$plainPassword}\")`: <strong>{$newlyHashedPassword}</strong></p>";

        $compareNewlyHashed = Hash::check($plainPassword, $newlyHashedPassword);
        $output .= "<p>Result of `Hash::check(\"{$plainPassword}\", \"{$newlyHashedPassword}\")`: <strong>" . ($compareNewlyHashed ? 'MATCHES' : 'DOES NOT MATCH') . "</strong></p>";

        $stringComparison = ($newlyHashedPassword === $dbHashedPassword);
        $output .= "<p>Direct String Comparison of Newly Generated Hash vs. DB Hash: <strong>" . ($stringComparison ? 'SAME' : 'DIFFERENT') . "</strong></p>";

        return response($output);
    }
}
