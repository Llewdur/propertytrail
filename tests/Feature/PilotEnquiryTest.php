<?php

declare(strict_types=1);

use App\Models\PilotEnquiry;

use function Pest\Laravel\postJson;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('records a pilot enquiry', function (): void {
    postJson('/api/v1/pilot-enquiries', [
        'name' => 'Llew',
        'email' => 'pilot@example.test',
        'company' => 'Example Estates',
        'portfolio_size' => '10-50',
        'message' => 'We would like to pilot PropertyTrail.',
    ])
        ->assertCreated()
        ->assertJsonPath('data.email', 'pilot@example.test');

    expect(PilotEnquiry::query()->count())->toBe(1);
});

it('validates pilot enquiries', function (): void {
    postJson('/api/v1/pilot-enquiries', [
        'name' => 'Llew',
        'email' => 'not-an-email',
        'company' => 'Example Estates',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'message']);
});
