<?php

namespace App\Http\Controllers;

use App\Models\Sentiment;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SentimentController extends Controller
{
    public function index(Request $request): \Inertia\Response
    {
        // If no date specified, get the most recent date with sentiment data
        if ($request->get('date')) {
            $date = Carbon::parse($request->get('date'));
        } else {
            $latestSentimentDate = Sentiment::max('sentiment_date');
            // Use Eastern time for consistency with how sentiment data is generated
            $easternToday = now()->setTimezone('America/New_York');
            $date = $latestSentimentDate ? Carbon::parse($latestSentimentDate) : $easternToday;
        }

        // Get sentiments for the specified date, ordered by symbol
        $sentiments = Sentiment::with('asset:id,symbol,common_name,sector')
            ->forDate($date)
            ->orderBy('symbol')
            ->get()
            ->map(function ($sentiment) {
                return [
                    'id' => $sentiment->id,
                    'symbol' => $sentiment->symbol,
                    'sentiment_text' => $sentiment->sentiment_text,
                    'sentiment_type' => $sentiment->sentiment_type,
                    'confidence_score' => $sentiment->confidence_score,
                    'sentiment_date' => $sentiment->sentiment_date,
                    'asset' => $sentiment->asset ? [
                        'id' => $sentiment->asset->id,
                        'symbol' => $sentiment->asset->symbol,
                        'common_name' => $sentiment->asset->common_name,
                        'sector' => $sentiment->asset->sector,
                    ] : null,
                ];
            });

        // Get available dates with sentiment data for date picker
        $availableDates = Sentiment::selectRaw('DISTINCT sentiment_date')
            ->orderBy('sentiment_date', 'desc')
            ->limit(30) // Last 30 days with data
            ->pluck('sentiment_date')
            ->map(fn ($date) => Carbon::parse($date)->toDateString());

        return Inertia::render('sentiments', [
            'sentiments' => $sentiments,
            'selectedDate' => $date->toDateString(),
            'availableDates' => $availableDates,
        ]);
    }
}
