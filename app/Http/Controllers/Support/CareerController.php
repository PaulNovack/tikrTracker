<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Http\Requests\Support\StoreCareerApplicationRequest;
use App\Models\CareerApplication;
use Inertia\Inertia;
use Inertia\Response;

class CareerController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Support/Careers');
    }

    public function store(StoreCareerApplicationRequest $request)
    {
        $data = [
            'full_name' => $request->full_name,
            'email' => $request->email,
            'phone' => $request->phone,
            'position_applied' => $request->position_applied,
            'cover_letter' => $request->cover_letter,
            'linkedin_url' => $request->linkedin_url,
            'portfolio_url' => $request->portfolio_url,
        ];

        if ($request->hasFile('resume')) {
            $data['resume_path'] = $request->file('resume')->store('resumes', 'public');
        }

        $application = CareerApplication::create($data);

        return redirect()->back()->with('success', 'Your application has been submitted successfully. We will review it and get back to you soon!');
    }
}
