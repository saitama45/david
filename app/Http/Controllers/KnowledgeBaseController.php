<?php

namespace App\Http\Controllers;

use App\Models\KnowledgeBase;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;

class KnowledgeBaseController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = KnowledgeBase::with('author')
            ->where('is_published', true);

        if ($request->search) {
            $query->where(function($q) use ($request) {
                $q->where('title', 'like', "%{$request->search}%")
                  ->orWhere('content', 'like', "%{$request->search}%")
                  ->orWhere('category', 'like', "%{$request->search}%");
            });
        }

        if ($request->category) {
            $query->where('category', $request->category);
        }

        $articles = $query->orderBy('created_at', 'desc')
            ->paginate(12) // Using grid, so maybe 12 is better
            ->withQueryString();

        // Get distinct categories that have published articles
        $categories = KnowledgeBase::where('is_published', true)
            ->select('category')
            ->distinct()
            ->whereNotNull('category')
            ->pluck('category');

        return Inertia::render('KnowledgeBase/Index', [
            'articles' => $articles,
            'categories' => $categories,
            'filters' => $request->only(['search', 'category'])
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('KnowledgeBase/Create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'category' => 'nullable|string|max:50',
            'is_published' => 'boolean'
        ]);

        $validated['author_id'] = Auth::id();

        KnowledgeBase::create($validated);

        return redirect()->route('knowledge-base.index')
            ->with('success', 'Article created successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show(KnowledgeBase $knowledgeBase)
    {
        return Inertia::render('KnowledgeBase/Show', [
            'article' => $knowledgeBase->load('author')
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(KnowledgeBase $knowledgeBase)
    {
        return Inertia::render('KnowledgeBase/Edit', [
            'article' => $knowledgeBase
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, KnowledgeBase $knowledgeBase)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'category' => 'nullable|string|max:50',
            'is_published' => 'boolean'
        ]);

        $knowledgeBase->update($validated);

        return redirect()->route('knowledge-base.index')
            ->with('success', 'Article updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(KnowledgeBase $knowledgeBase)
    {
        $knowledgeBase->delete();

        return redirect()->route('knowledge-base.index')
            ->with('success', 'Article deleted successfully.');
    }
}