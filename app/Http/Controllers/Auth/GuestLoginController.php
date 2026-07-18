<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class GuestLoginController extends Controller
{
    /**
     * Log in as guest user and redirect to dashboard.
     */
    public function __invoke(): RedirectResponse
    {
        // Find the guest user
        $guestUser = User::where('email', 'guest@tikrtracker.com')->first();

        if (! $guestUser) {
            return redirect()->route('login')->with('error', 'Guest account not available.');
        }

        // Log in as the guest user
        Auth::login($guestUser);

        // Regenerate session for security
        request()->session()->regenerate();

        // Redirect to dashboard
        return redirect()->route('dashboard');
    }
}
