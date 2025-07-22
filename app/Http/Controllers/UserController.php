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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Role;
use App\Models\Role as ExtendedRole;
use Illuminate\Support\Facades\Hash; // CRITICAL FIX: Import Hash facade for debugging

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
        $suppliers = Supplier::options()->toArray();

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
        $suppliers = Supplier::options()->toArray();

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
        DB::beginTransaction();
        try {
            $this->userService->createUser($request->validated());
            DB::commit();
            return redirect()->route('users.index');
        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Error creating user: " . $e->getMessage());
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function destroy(User $user)
    {
        DB::beginTransaction();
        try {
            $user->load(['usage_records', 'store_orders']);
            if ($user->usage_records->count() > 0 || $user->store_orders->count() > 0) {
                throw new \Exception("Can't delete this user because there are data associated with it.");
            }
            $this->userService->deleteUser($user);
            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Error deleting user: " . $e->getMessage());
            return back()->withErrors(['error' => $e->getMessage()]);
        }
        return to_route('users.index');
    }

    public function update(UpdateUserRequest $request, User $user)
    {
        DB::beginTransaction();
        try {
            $this->userService->updateUser($request->validated(), $user);
            DB::commit();
            return redirect()->route('users.index');
        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Error updating user: " . $e->getMessage());
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

    // CRITICAL FIX: Temporary debug method for password hashing
    public function debugPassword(Request $request)
    {
        $email = $request->input('email', 'sysadmin@gmail.com'); // Default to sysadmin
        $plainPassword = $request->input('password', 'password123'); // Default to password123

        $user = User::where('email', $email)->first();

        $output = "<h1>Password Debugging for User: {$email}</h1>";

        if (!$user) {
            $output .= "<p style='color: red;'>User not found with email: {$email}</p>";
            return response($output);
        }

        $dbHashedPassword = $user->password;
        $output .= "<p>User found. Database Hashed Password: <strong>{$dbHashedPassword}</strong></p>";
        $output .= "<p>Plain Text Password to check: <strong>{$plainPassword}</strong></p>";

        // Test 1: Direct Hash::check() against database hash
        $checkResult = Hash::check($plainPassword, $dbHashedPassword);
        $output .= "<p>Result of `Hash::check(\"{$plainPassword}\", \"{$dbHashedPassword}\")`: <strong>" . ($checkResult ? 'MATCHES' : 'DOES NOT MATCH') . "</strong></p>";

        // Test 2: Generate a new hash from the plain password and compare
        $newlyHashedPassword = Hash::make($plainPassword);
        $output .= "<p>Newly Generated Hash from `Hash::make(\"{$plainPassword}\")`: <strong>{$newlyHashedPassword}</strong></p>";

        // Test 3: Compare the newly generated hash with the database hash (should be different but `Hash::check` handles this)
        $compareNewlyHashed = Hash::check($plainPassword, $newlyHashedPassword);
        $output .= "<p>Result of `Hash::check(\"{$plainPassword}\", \"{$newlyHashedPassword}\")`: <strong>" . ($compareNewlyHashed ? 'MATCHES' : 'DOES NOT MATCH') . "</strong> (This should always MATCH if hashing is consistent)</p>";

        // Test 4: Compare the newly generated hash with the database hash as strings (will likely be different)
        $stringComparison = ($newlyHashedPassword === $dbHashedPassword);
        $output .= "<p>Direct String Comparison of Newly Generated Hash vs. DB Hash: <strong>" . ($stringComparison ? 'SAME' : 'DIFFERENT') . "</strong> (Expected: DIFFERENT)</p>";


        $output .= "<p style='color: blue;'>If `Hash::check` (Test 1) returns 'DOES NOT MATCH' but `Hash::check` (Test 3) returns 'MATCHES', and the database hash looks correct, there might be a subtle environment or PHP version difference causing `Hash::check` to behave unexpectedly with *existing* hashes. However, this is extremely rare.</p>";
        $output .= "<p style='color: blue;'>If `Hash::check` (Test 1) and `Hash::check` (Test 3) both return 'DOES NOT MATCH', then the `Hash::make` function itself might be producing an incompatible hash, which is even rarer and points to a server environment issue.</p>";
        $output .= "<p style='color: green;'>If `Hash::check` (Test 1) returns 'MATCHES', then the login issue is not related to password hashing/verification, but something else in the authentication flow (e.g., session, guard, or a very specific middleware).</p>";

        return response($output);
    }
}
