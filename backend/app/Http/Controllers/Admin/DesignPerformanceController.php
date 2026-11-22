<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DesignExperiment;
use App\Models\DesignInsight;
use App\Models\Market;
use App\Tenancy\CurrentStore;
use App\Themes\AiDesigner;
use App\Themes\DesignExperiments;
use App\Themes\DesignInsights;
use App\Themes\ThemeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Online store › 装修效果: each site's numbers, design A/B tests, weekly findings and suggestions. */
class DesignPerformanceController extends Controller
{
    public function index(Request $request, CurrentStore $current, DesignInsights $insights): JsonResponse
    {
        $days = (int) ($request->validate(['days' => ['nullable', 'integer', 'in:7,14,28,90']])['days'] ?? 0) ?: 28;
        $store = $current->getOrFail();
        $markets = Market::pluck('name', 'id');

        return response()->json([
            'days' => $days,
            'currency' => $store->baseCurrency(),
            'theme_id' => app(ThemeService::class)->ensureLive($store)->id,   // for "去装修" links
            'sites' => $insights->overview($store, $days),
            'experiments' => DesignExperiment::latest('id')->limit(20)->get()->map(fn ($e) => [
                ...$e->only('id', 'name', 'status', 'winner', 'note', 'started_at', 'ended_at', 'market_id', 'store_theme_id'),
                'market' => $e->market_id ? ($markets[$e->market_id] ?? null) : null,
                'stats' => DesignExperiments::stats($e),
            ]),
            'insights' => DesignInsight::orderByDesc('week')->orderBy('market_id')->limit(30)->get()->unique('market_id')->values()->map(fn ($i) => [
                ...$i->only('id', 'market_id', 'findings', 'suggestions', 'updated_at'), 'week' => $i->week->toDateString(),
                'market' => $i->market_id ? ($markets[$i->market_id] ?? null) : null,
            ]),
            'ai' => AiDesigner::status($store),
        ]);
    }

    /** Run this week's look now (instead of waiting for Monday). */
    public function refresh(CurrentStore $current, DesignInsights $insights): JsonResponse
    {
        return response()->json(['sites' => $insights->weekly($current->getOrFail())]);
    }

    /** End a design A/B test: "b" publishes the tested design, "a" keeps the live one, null just stops. */
    public function finish(Request $request, DesignExperiment $experiment, DesignExperiments $tests): JsonResponse
    {
        $winner = $request->validate(['winner' => ['nullable', 'in:a,b']])['winner'] ?? null;
        $e = $tests->finish($experiment, $winner, $request->user()?->id);

        return response()->json(['data' => [...$e->only('id', 'status', 'winner'), 'stats' => DesignExperiments::stats($e)]]);
    }
}
