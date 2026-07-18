<?php

use App\Models\Deposit;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('allows users to create a new deposit', function () {
    $user = User::factory()->create();

    actingAs($user);

    $page = visit('/deposits');

    $page->assertSee('Deposits')
        ->click('Add Deposit')
        ->wait(1)
        ->assertPathIs('/deposits/create')
        ->assertSee('Add Deposit')
        ->type('amount', '1000.00')
        ->type('deposit_date', '2025-11-18T10:00')
        ->type('notes', 'Initial investment')
        ->press('Add Deposit')
        ->wait(1)
        ->assertPathIs('/deposits')
        ->assertSee('Deposit added successfully')
        ->assertSee('$1,000.00')
        ->assertNoJavascriptErrors();

    expect(Deposit::where('user_id', $user->id)->count())->toBe(1);
});

it('allows users to edit a deposit', function () {
    $user = User::factory()->create();
    $deposit = Deposit::factory()->create([
        'user_id' => $user->id,
        'amount' => 500.00,
        'notes' => 'Original note',
    ]);

    actingAs($user);

    $page = visit('/deposits');

    $page->assertSee('$500.00')
        ->click("a[href='/deposits/{$deposit->id}/edit']")
        ->wait(1)
        ->assertPathIs("/deposits/{$deposit->id}/edit")
        ->assertSee('Edit Deposit')
        ->type('amount', '750.00')
        ->type('notes', 'Updated note')
        ->press('Update Deposit')
        ->wait(1)
        ->assertPathIs('/deposits')
        ->assertSee('Deposit updated successfully')
        ->assertSee('$750.00')
        ->assertNoJavascriptErrors();

    expect($deposit->fresh()->amount)->toBe('750.00');
});

it('allows users to delete a deposit', function () {
    $user = User::factory()->create();
    $deposit = Deposit::factory()->create([
        'user_id' => $user->id,
        'amount' => 500.00,
    ]);

    actingAs($user);

    $page = visit('/deposits');

    $page->assertSee('$500.00')
        ->click("button[aria-label='Delete deposit']")
        ->wait(1)
        ->assertSee('Are you sure?')
        ->press('Delete')
        ->wait(1)
        ->assertSee('Deposit deleted successfully')
        ->assertNoJavascriptErrors();

    expect(Deposit::find($deposit->id))->toBeNull();
});

it('displays total deposits summary', function () {
    $user = User::factory()->create();
    Deposit::factory()->count(3)->create([
        'user_id' => $user->id,
        'amount' => 1000.00,
    ]);

    actingAs($user);

    $page = visit('/deposits');

    $page->assertSee('Total Deposits')
        ->assertSee('$3,000.00')
        ->assertNoJavascriptErrors();
});
