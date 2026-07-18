<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDepositRequest;
use App\Http\Requests\UpdateDepositRequest;
use App\Models\Deposit;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class DepositController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display a listing of the resource.
     */
    public function index(): Response
    {
        $deposits = auth()->user()
            ->deposits()
            ->orderBy('deposited_at', 'desc')
            ->get();

        return Inertia::render('investments/deposits/index', [
            'deposits' => $deposits,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): Response
    {
        return Inertia::render('investments/deposits/create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreDepositRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        auth()->user()->deposits()->create($validated);

        return redirect()
            ->route('deposits.index')
            ->with('success', 'Deposit added successfully!');
    }

    /**
     * Display the specified resource.
     */
    public function show(Deposit $deposit): Response
    {
        $this->authorize('view', $deposit);

        return Inertia::render('investments/deposits/show', [
            'deposit' => $deposit,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Deposit $deposit): Response
    {
        $this->authorize('update', $deposit);

        return Inertia::render('investments/deposits/edit', [
            'deposit' => $deposit,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateDepositRequest $request, Deposit $deposit): RedirectResponse
    {
        $this->authorize('update', $deposit);

        $deposit->update($request->validated());

        return redirect()
            ->route('deposits.index')
            ->with('success', 'Deposit updated successfully!');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Deposit $deposit): RedirectResponse
    {
        $this->authorize('delete', $deposit);

        $deposit->delete();

        return redirect()
            ->route('deposits.index')
            ->with('success', 'Deposit deleted successfully!');
    }
}
