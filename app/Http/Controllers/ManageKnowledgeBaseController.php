<?php

namespace App\Http\Controllers;

use App\Models\KnowledgeBase;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log; // Added Log import

class ManageKnowledgeBaseController extends Controller
{
    public function index(Request $request)
    {
        $query = KnowledgeBase::with('author');

        if ($request->search) {
            $query->where(function($q) use ($request) {
                $q->where('title', 'like', "%{$request->search}%")
                  ->orWhere('category', 'like', "%{$request->search}%");
            });
        }

        $articles = $query->orderBy('created_at', 'desc')->paginate(10)->withQueryString();
        
        // For the dropdown options in the view (similar to usersList)
        $articlesList = KnowledgeBase::select('id', 'title as label', 'id as value')->get();

        return Inertia::render('ManageKnowledgeBase/Index', [
            'articles' => $articles,
            'filters' => $request->only(['search']),
            'articlesList' => $articlesList
        ]);
    }

    public function create()
    {
        return Inertia::render('ManageKnowledgeBase/Create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'category' => 'nullable|string|max:50',
            'is_published' => 'boolean'
        ]);

        $validated['author_id'] = Auth::id();

        try {
            KnowledgeBase::create($validated);
            return redirect()->route('manage-knowledge-base.index')
                ->with('success', 'Article created successfully.');
        } catch (\Exception $e) {
            Log::error("Error creating knowledge base article: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return back()->withErrors(['error' => 'Failed to create article.'])->withInput();
        }
    }

    public function show($id)
    {
        $knowledgeBase = KnowledgeBase::findOrFail($id);
        return Inertia::render('ManageKnowledgeBase/Show', [
            'article' => $knowledgeBase->load('author')
        ]);
    }

    public function edit($id) // Changed type hint to $id for debugging
    {
        $knowledgeBase = KnowledgeBase::findOrFail($id); // Explicitly find the model
        Log::debug('ManageKnowledgeBaseController@edit: KnowledgeBase object AFTER findOrFail:', ['knowledgeBase' => $knowledgeBase]);
        return Inertia::render('ManageKnowledgeBase/Edit', [
            'article' => $knowledgeBase
        ]);
    }

    public function update(Request $request, $id)
    {
        $knowledgeBase = KnowledgeBase::findOrFail($id);
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'category' => 'nullable|string|max:50',
            'is_published' => 'boolean'
        ]);

        try {
            $knowledgeBase->update($validated);
            return redirect()->route('manage-knowledge-base.index')
                ->with('success', 'Article updated successfully.');
        } catch (\Exception $e) {
            Log::error("Error updating knowledge base article: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return back()->withErrors(['error' => 'Failed to update article.'])->withInput();
        }
    }

    public function destroy($id)
    {
        $knowledgeBase = KnowledgeBase::findOrFail($id);
        try {
            $knowledgeBase->delete();
            return redirect()->back()->with('success', 'Article deleted successfully.');
        } catch (\Exception $e) {
            Log::error("Error deleting knowledge base article: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return back()->withErrors(['error' => 'Failed to delete article.']);
        }
    }

    public function export(Request $request)
    {
        // Placeholder for export
        return redirect()->back(); 
    }
}