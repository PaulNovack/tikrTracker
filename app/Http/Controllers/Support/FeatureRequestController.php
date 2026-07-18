<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Http\Requests\Support\StoreFeatureRequestRequest;
use App\Models\FeatureRequest;
use Inertia\Inertia;
use Inertia\Response;

class FeatureRequestController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Support/FeatureRequest');
    }

    public function store(StoreFeatureRequestRequest $request)
    {
        $featureRequest = FeatureRequest::create([
            'user_id' => auth()->id(),
            'name' => $request->name,
            'email' => $request->email,
            'title' => $request->title,
            'description' => $request->description,
            'category' => $request->category,
        ]);

        return redirect()->back()->with('success', 'Thank you for your feature request! We appreciate your feedback and will review it carefully.');
    }
}
