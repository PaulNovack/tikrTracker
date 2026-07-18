<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class TrainingController extends Controller
{
    public function analyzeTradeAlerts(): Response
    {
        return Inertia::render('training/analyze-trade-alerts/index');
    }

    public function retrainModels(): Response
    {
        return Inertia::render('training/retrain-models/index');
    }

    public function rescoreAlert(): Response
    {
        return Inertia::render('training/rescore-alert/index');
    }
}
