<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SpotPriceVideoService;
use App\Services\WeeklyOffersVideoService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API endpoints for video generation (Remotion).
 *
 * These endpoints provide structured data for generating
 * promotional videos featuring spot price information.
 */
class VideoController extends Controller
{
    public function __construct(
        private readonly SpotPriceVideoService $videoService,
        private readonly WeeklyOffersVideoService $weeklyOffersService,
    ) {
    }

    /**
     * Get daily spot price data for video generation.
     *
     * Returns all data needed for a daily spot price video including:
     * - Current price
     * - Today's hourly prices with chart data
     * - Statistics (min, max, average)
     * - Appliance recommendations (sauna, EV, laundry, etc.)
     * - Historical comparison
     *
     * @queryParam date string Optional date in Y-m-d format. Defaults to today.
     */
    public function daily(Request $request): JsonResponse
    {
        $date = null;
        if ($request->has('date')) {
            try {
                $date = Carbon::parse($request->input('date'))->setTimezone('Europe/Helsinki');
            } catch (\Exception $e) {
                return response()->json([
                    'error' => 'Invalid date format. Use Y-m-d (e.g., 2024-01-15)',
                ], 400);
            }
        }

        $data = $this->videoService->getDailyVideoData($date);

        return response()->json([
            'data' => $data,
        ]);
    }

    /**
     * Get weekly summary data for video generation.
     *
     * Returns aggregated data for the past 7 days including:
     * - Daily averages, min, max
     * - Best and worst days
     * - Comparison to rolling averages
     */
    public function weekly(): JsonResponse
    {
        $data = $this->videoService->getWeeklySummaryData();

        return response()->json([
            'data' => $data,
        ]);
    }

    /**
     * Get weekly offers data for video generation.
     *
     * Returns contracts with active discounts including:
     * - Contract details with company info
     * - Discount information
     * - Calculated costs at different consumption levels
     * - Savings compared to non-discounted prices
     */
    public function weeklyOffers(): JsonResponse
    {
        $data = $this->weeklyOffersService->getWeeklyOffersData();

        return response()->json([
            'data' => $data,
        ]);
    }
}
