<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class TemplateController extends Controller
{
    public function index()
    {
        return Inertia::render('Template/Index');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls']
        ]);

        $destinationPath = 'excel-templates';
        $filename = 'gsi_order_template.xlsx';
        $fullPath = storage_path('app/public/' . $destinationPath . '/' . $filename);

        try {
            if (Storage::disk('public')->exists($destinationPath . '/' . $filename)) {
                Storage::disk('public')->delete($destinationPath . '/' . $filename);

            }

            $request->file('file')->storeAs($destinationPath, $filename, 'public');

            return redirect()->back()->with('success', 'Template has been updated successfully');
        } catch (\Exception $e) {


            return redirect()->back()->with('error', 'Failed to update template: ' . $e->getMessage());
        }
    }
}
