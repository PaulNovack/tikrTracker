<?php

namespace App\Http\Controllers;

use App\Models\DisclaimerAcceptance;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DisclaimerController extends Controller
{
    public function index(): Response
    {
        // Check if user has already accepted disclaimer
        $ipAddress = DisclaimerAcceptance::getCurrentIpAddress();
        $hasAcceptedDisclaimer = DisclaimerAcceptance::hasAcceptedAll($ipAddress);

        return Inertia::render('Disclaimer', [
            'hasAcceptedDisclaimer' => $hasAcceptedDisclaimer,
            'isAuthenticated' => auth()->check(),
        ]);
    }

    public function accept(Request $request)
    {
        \Log::info('Disclaimer acceptance attempt', [
            'ip' => DisclaimerAcceptance::getCurrentIpAddress(),
            'user_agent' => $request->userAgent(),
            'data' => $request->all(),
        ]);

        // Validate the acceptance
        $request->validate([
            'disclaimer_accepted' => 'required|boolean|accepted',
            'cookies_accepted' => 'required|boolean|accepted',
        ]);

        $ipAddress = DisclaimerAcceptance::getCurrentIpAddress();
        $userAgent = $request->userAgent();

        \Log::info('Validation passed, recording acceptance', [
            'ip' => $ipAddress,
            'user_agent' => $userAgent,
        ]);

        // Record the acceptance
        DisclaimerAcceptance::recordAcceptance($ipAddress, $userAgent);

        // Clear any stored disclaimer redirect URL and redirect to intended page or home
        $redirectUrl = $request->session()->pull('disclaimer_redirect_url', '/');

        \Log::info('Redirecting after disclaimer acceptance', [
            'redirect_url' => $redirectUrl,
        ]);

        return redirect($redirectUrl)->with('success', 'Thank you for accepting the disclaimer and privacy policy.');
    }

    public function deletePersonalData(Request $request)
    {
        // Validate the deletion request
        $request->validate([
            'confirm_deletion' => 'required|boolean|accepted',
            'confirmation_text' => 'required|string|in:DELETE MY DATA',
        ]);

        $ipAddress = DisclaimerAcceptance::getCurrentIpAddress();

        \Log::warning('Personal data deletion requested', [
            'ip' => $ipAddress,
            'user_agent' => $request->userAgent(),
            'timestamp' => now(),
        ]);

        // Delete the disclaimer acceptance record for this IP
        $deleted = DisclaimerAcceptance::where('ip_address', $ipAddress)->delete();

        \Log::warning('Personal data deletion completed', [
            'ip' => $ipAddress,
            'records_deleted' => $deleted,
            'timestamp' => now(),
        ]);

        return redirect('/')->with('warning', 'Your personal data has been permanently deleted. You will need to re-accept the disclaimer to continue using this application.');
    }
}
