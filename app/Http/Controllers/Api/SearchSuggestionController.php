<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SearchSuggestionService;
use Illuminate\Http\Request;

class SearchSuggestionController extends Controller
{
    protected $searchService;

    public function __construct(SearchSuggestionService $searchService)
    {
        $this->searchService = $searchService;
    }

    /**
     * Get search suggestions.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'q' => 'required|string|min:2|max:100',
        ]);

        $query = $validated['q'];
        
        \Illuminate\Support\Facades\Log::info("Autocomplete Suggestion Request: " . $query);
        
        $suggestions = $this->searchService->getSuggestions($query);

        return response()->json([
            'success' => true,
            'query' => $query,
            'data' => $suggestions,
        ]);
    }
}
