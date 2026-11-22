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
            // Out-of-stock items on create is set per entity: the one the user is working in.
            'entityName' => \App\Models\Entity::find(app(\App\Support\EntityContext::class)->id())?->name,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'required_levels' => ['required', 'integer', 'in:1,2'],
            'allow_negative_stock' => ['required', 'boolean'],
            'allow_out_of_stock_items' => ['sometimes', 'boolean'],
        ]);

        $this->settings->setRequiredLevels((int) $validated['required_levels']);
        $this->settings->setAllowNegativeStock((bool) $validated['allow_negative_stock']);

        if (array_key_exists('allow_out_of_stock_items', $validated)) {
            $this->settings->setAllowOutOfStockItems((bool) $validated['allow_out_of_stock_items']);
        }

        return back()->with('success', 'Wastage approval settings saved successfully.');
    }
}
