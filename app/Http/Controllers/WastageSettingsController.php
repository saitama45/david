<?php

namespace App\Http\Controllers;

use App\Http\Services\WastageApprovalSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WastageSettingsController extends Controller
{
    public function __construct(
        private WastageApprovalSettingsService $settings
    ) {}

    public function index(): Response
    {
        return Inertia::render('WastageSettings/Index', [
            'config' => $this->settings->sharedConfig(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'required_levels' => ['required', 'integer', 'in:1,2'],
        ]);

        $this->settings->setRequiredLevels((int) $validated['required_levels']);

        return back()->with('success', 'Wastage approval settings saved successfully.');
    }
}
