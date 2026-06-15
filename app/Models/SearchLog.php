<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SearchLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'query',
        'word',
        'speaker',
        'video_id',
        'results_count',
        'videos_count',
        'min_score',
        'ip_address',
        'user_agent',
        'response_time_ms',
        'search_type',
        'filters',
    ];

    protected $casts = [
        'min_score' => 'float',
        'results_count' => 'integer',
        'videos_count' => 'integer',
        'response_time_ms' => 'integer',
        'filters' => 'array',
    ];

    /**
     * Get the user that made the search
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get searches per day for the last N days
     */
    public static function getSearchesPerDay($days = 7)
    {
        return static::selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->where('created_at', '>=', now()->subDays($days))
            ->groupBy('date')
            ->orderBy('date', 'asc')
            ->pluck('count', 'date');
    }

    /**
     * Get most searched keywords
     */
    public static function getMostSearchedKeywords($limit = 10)
    {
        return static::selectRaw('COALESCE(query, word) as keyword, COUNT(*) as count')
            ->whereNotNull('query')
            ->orWhereNotNull('word')
            ->groupBy('keyword')
            ->orderByDesc('count')
            ->limit($limit)
            ->get();
    }

    /**
     * Get most searched keywords with pagination
     */
    public static function getMostSearchedKeywordsPaginated($page = 1, $perPage = 10)
    {
        $offset = ($page - 1) * $perPage;
        
        $query = static::selectRaw('COALESCE(query, word) as keyword, COUNT(*) as count')
            ->where(function($q) {
                $q->whereNotNull('query')
                  ->orWhereNotNull('word');
            })
            ->groupBy('keyword')
            ->orderByDesc('count');
        
        $total = $query->get()->count();
        
        $keywords = $query->offset($offset)
            ->limit($perPage)
            ->get();
        
        return [
            'data' => $keywords,
            'total' => $total,
            'current_page' => $page,
            'per_page' => $perPage,
            'last_page' => ceil($total / $perPage)
        ];
    }

    /**
     * Get search statistics
     */
    public static function getStatistics()
    {
        $today = static::whereDate('created_at', today())->count();
        $thisWeek = static::where('created_at', '>=', now()->startOfWeek())->count();
        $thisMonth = static::where('created_at', '>=', now()->startOfMonth())->count();
        $total = static::count();
        $avgResponseTime = static::avg('response_time_ms');
        $avgResultsCount = static::avg('results_count');

        return [
            'today' => $today,
            'this_week' => $thisWeek,
            'this_month' => $thisMonth,
            'total' => $total,
            'avg_response_time_ms' => round($avgResponseTime ?? 0, 2),
            'avg_results_count' => round($avgResultsCount ?? 0, 2),
        ];
    }
}
