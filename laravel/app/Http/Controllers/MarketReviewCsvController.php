<?php

namespace App\Http\Controllers;

use App\Support\MarketReviews;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Key figures of one market review as CSV (/markkinakatsaus/YYYY-MM.csv). The file is frozen
 * with the review. It holds no EEX futures values: EEX terms allow only a few quoted values.
 */
class MarketReviewCsvController extends Controller
{
    public function __invoke(string $month): BinaryFileResponse
    {
        $path = MarketReviews::csvPath($month);
        abort_if(MarketReviews::find($month) === null || ! is_file($path), 404);

        return response()->download($path, "voltikka-markkinakatsaus-{$month}.csv", [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
