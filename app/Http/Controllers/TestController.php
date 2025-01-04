<?php

namespace App\Http\Controllers;

use App\Models\ImageAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class TestController extends Controller
{
    public function index()
    {
        $images = ImageAttachment::all()->map(function ($image) {
            return [
                'id' => $image->id,
                'image_url' => Storage::url($image->file_path),
            ];
        });

        return Inertia::render('Camera', [
            'images' => $images,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'image' => 'required',
            'store_order_id' => 'required'
        ]);

        $image = $request->file('image');

        $filename = time() . '_' . uniqid() . '.' . $image->getClientOriginalExtension();


        $path = $image->storeAs('test', $filename);
        $path = $request->file('image')->store('images', 'public');

        ImageAttachment::create([
            'store_order_id' => $validated['store_order_id'],
            'file_path' => $path,
            'mime_type' => $image->getMimeType(),
        ]);

        return redirect()->back()->with('success', 'Image uploaded successfully');
    }

    public function destroy()
    {
        $validated = request()->validate([
            'id' => 'required',
        ]);
        try {
            $image = ImageAttachment::findOrFail($validated['id']);

            if (Storage::disk('public')->exists($image->file_path)) {
                Storage::disk('public')->delete($image->file_path);
            }

            $image->delete();

            return redirect()->back()->with('success', 'Image deleted successfully');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Failed to delete image');
        }
    }

    public function uploadImageToFolders() {}
}
