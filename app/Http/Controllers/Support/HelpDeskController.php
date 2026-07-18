<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Http\Requests\Support\StoreHelpDeskTicketRequest;
use App\Models\HelpDeskTicket;
use Inertia\Inertia;
use Inertia\Response;

class HelpDeskController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Support/HelpDesk');
    }

    public function store(StoreHelpDeskTicketRequest $request)
    {
        $ticket = HelpDeskTicket::create([
            'user_id' => auth()->id(),
            'name' => $request->name,
            'email' => $request->email,
            'subject' => $request->subject,
            'message' => $request->message,
            'priority' => $request->priority,
        ]);

        return redirect()->back()->with('success', 'Your support ticket has been submitted successfully. We will get back to you soon!');
    }
}
