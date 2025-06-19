<?php

namespace App\Http\Controllers;

use App\Models\Template;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class TemplateController extends Controller
{
    public function index()
    {
        $templates = Template::with('user')
            ->get()
            ->keyBy('template')
            ->map(function ($template) {
                return [
                    'updated_at' => $template->updated_at->format('F d, Y'),
                    'user' => $template->user ? $template->user->full_name : 'Unknown'
                ];
            });


        return Inertia::render('Template/Index', [
            'templates' => $templates
        ]);
    }

    public function store(Request $request)
    {

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls'],
            'file_name' => ['required']
        ]);

        $destinationPath = 'excel-templates';
        $filename = $validated['file_name'];
        $fullPath = storage_path('app/public/' . $destinationPath . '/' . $filename);

        Template::updateOrCreate(
            ['template' => $filename],
            ['user_id' => Auth::id()]
        );

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
