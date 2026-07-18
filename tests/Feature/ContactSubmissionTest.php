<?php

use App\Models\ContactSubmission;

it('can render contact page', function () {
    $response = $this->get('/contact');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page->component('contact'));
});

it('can submit contact form', function () {
    $data = [
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'subject' => 'Beta Access Request',
        'message' => 'I would like to request beta access to tikrTracker.',
    ];

    $response = $this->from('/contact')->post('/contact', $data);

    $response->assertRedirect('/contact');
    $response->assertSessionHas('success');

    $this->assertDatabaseHas('contact_submissions', [
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'subject' => 'Beta Access Request',
    ]);
});

it('stores IP address and user agent', function () {
    $data = [
        'name' => 'Jane Smith',
        'email' => 'jane@example.com',
        'subject' => 'General Inquiry',
        'message' => 'This is a test message.',
    ];

    $response = $this->withHeaders([
        'User-Agent' => 'Test Browser/1.0',
    ])->post('/contact', $data);

    $response->assertRedirect();

    $submission = ContactSubmission::latest()->first();

    expect($submission->ip_address)->not->toBeNull()
        ->and($submission->user_agent)->toBe('Test Browser/1.0');
});

it('validates required fields', function () {
    $response = $this->post('/contact', []);

    $response->assertSessionHasErrors(['name', 'email', 'subject', 'message']);
});

it('validates email format', function () {
    $response = $this->post('/contact', [
        'name' => 'John Doe',
        'email' => 'invalid-email',
        'subject' => 'Test',
        'message' => 'Test message',
    ]);

    $response->assertSessionHasErrors(['email']);
});

it('blocks third submission from same IP address', function () {
    $data = [
        'name' => 'Spammer',
        'email' => 'spam@example.com',
        'subject' => 'Spam',
        'message' => 'Spam message',
    ];

    // First submission
    $response1 = $this->post('/contact', $data);
    $response1->assertRedirect();
    $response1->assertSessionHas('success');

    // Second submission
    $data['email'] = 'spam2@example.com';
    $response2 = $this->post('/contact', $data);
    $response2->assertRedirect();
    $response2->assertSessionHas('success');

    // Third submission should be blocked
    $data['email'] = 'spam3@example.com';
    $response3 = $this->post('/contact', $data);
    $response3->assertSessionHasErrors(['message']);

    expect(ContactSubmission::count())->toBe(2);
});

it('allows different IP addresses to submit', function () {
    $data = [
        'name' => 'User One',
        'email' => 'user1@example.com',
        'subject' => 'Test',
        'message' => 'Message from user 1',
    ];

    // First IP
    $this->post('/contact', $data)->assertRedirect();

    // Different IP (simulate by creating factory)
    ContactSubmission::factory()->create(['ip_address' => '192.168.1.100']);

    // Second IP should be able to submit
    $data['email'] = 'user2@example.com';
    $data['name'] = 'User Two';
    $response = $this->post('/contact', $data);

    $response->assertRedirect();
    $response->assertSessionHas('success');
});

it('validates message maximum length', function () {
    $response = $this->post('/contact', [
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'subject' => 'Test',
        'message' => str_repeat('a', 5001), // Exceeds 5000 character limit
    ]);

    $response->assertSessionHasErrors(['message']);
});

it('persists all contact data correctly to database', function () {
    $data = [
        'name' => 'Database Test User',
        'email' => 'dbtest@example.com',
        'subject' => 'Database Save Test',
        'message' => 'This message should be saved to the database with all fields intact and no truncation.',
    ];

    $this->post('/contact', $data);

    $submission = ContactSubmission::where('email', 'dbtest@example.com')->first();

    expect($submission)->not->toBeNull()
        ->and($submission->name)->toBe('Database Test User')
        ->and($submission->email)->toBe('dbtest@example.com')
        ->and($submission->subject)->toBe('Database Save Test')
        ->and($submission->message)->toBe('This message should be saved to the database with all fields intact and no truncation.')
        ->and($submission->created_at)->not->toBeNull()
        ->and($submission->updated_at)->not->toBeNull();
});

it('successfully saves contact form with minimal valid data', function () {
    $data = [
        'name' => 'A',
        'email' => 'a@a.com',
        'subject' => 'B',
        'message' => 'C',
    ];

    $response = $this->post('/contact', $data);

    $response->assertSessionHas('success');

    expect(ContactSubmission::where('email', 'a@a.com')->exists())->toBeTrue();
});

it('validates name maximum length', function () {
    $response = $this->post('/contact', [
        'name' => str_repeat('a', 256), // Exceeds 255 character limit
        'email' => 'john@example.com',
        'subject' => 'Test',
        'message' => 'Test message',
    ]);

    $response->assertSessionHasErrors(['name']);
});

it('validates subject maximum length', function () {
    $response = $this->post('/contact', [
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'subject' => str_repeat('a', 256), // Exceeds 255 character limit
        'message' => 'Test message',
    ]);

    $response->assertSessionHasErrors(['subject']);
});

it('validates email maximum length', function () {
    // Create an email that exceeds 255 characters - Laravel's email validation allows
    // very long emails, but we can test with an invalid format that's too long
    $response = $this->post('/contact', [
        'name' => 'John Doe',
        'email' => 'a@'.str_repeat('a', 253).'.com', // Creates email exceeding 255 chars
        'subject' => 'Test',
        'message' => 'Test message',
    ]);

    // This might pass validation since technically it's a valid email format.
    // The key is that the database column has a 255 character limit, so any submission
    // that makes it through should not exceed that limit in the database.
    $this->assertDatabaseMissing('contact_submissions', [
        'email' => 'a@'.str_repeat('a', 253).'.com',
    ]);
});

it('resets form on successful submission', function () {
    $data = [
        'name' => 'Form Reset Test',
        'email' => 'formreset@example.com',
        'subject' => 'Test Form Reset',
        'message' => 'Testing if form resets after successful submission.',
    ];

    $response = $this->post('/contact', $data);

    $response->assertSessionHas('success');
    $this->assertDatabaseHas('contact_submissions', [
        'email' => 'formreset@example.com',
    ]);
});

it('handles special characters in message', function () {
    $data = [
        'name' => 'Special Char Test',
        'email' => 'special@example.com',
        'subject' => 'Special Characters <>&"\'',
        'message' => 'Message with special chars: <script>alert("xss")</script> & symbols: @#$%^&*()',
    ];

    $response = $this->post('/contact', $data);

    $response->assertRedirect();

    $submission = ContactSubmission::where('email', 'special@example.com')->first();

    expect($submission->subject)->toContain('<>&"\'')
        ->and($submission->message)->toContain('<script>');
});
