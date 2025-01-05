<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        dd($request);
    }
}
