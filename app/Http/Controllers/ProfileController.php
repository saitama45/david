<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

use function Pest\Laravel\json;

class ProfileController extends Controller
{

    public function index()
    {
        $user = User::with('roles')->find(Auth::user()->id);
        return Inertia::render('Profile/Index', [
            'user' => $user
        ]);
    }

    public function updateDetails(Request $request, $id)
    {
        $validated = $request->validate([
            'first_name' => ['required'],
            'middle_name' => ['nullable'],
            'last_name' => ['required'],
            'phone_number' => ['required'],
            'email' => ['required'],
        ]);

        $user = User::findOrFail($id);
        $user->update($validated);

        return back();
    }

    public function updatePassword(Request $request, $id)
    {
        $validated = $request->validate([
            'current_password' => ['required'],
            'password' => ['required', 'min:8', 'confirmed'],
        ], [
            'password.confirmed' => 'New password and confirm password field needs to match'
        ]);

        $user = User::findOrFail($id);

        if (!Hash::check($validated['current_password'], $user->password)) {
            return back()->withErrors([
                'current_password' => 'The provided password does not match your current password.',
            ]);
        }

        $user->password = Hash::make($validated['password']);
        $user->save();

        return back()->with('status', 'Password updated successfully.');
    }
}
