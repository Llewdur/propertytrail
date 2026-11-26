<?php

declare(strict_types=1);

use App\Factories\UserWriteFactory;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withHeader;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    resetTenant();
    ['organisation' => $this->organisation, 'user' => $this->user] = tenantUser('team');
});

it('lets an admin invite a manager and the manager accepts once', function (): void {
    $manager = app(UserWriteFactory::class)->create([
        'name' => 'Manager',
        'email' => 'manager-' . uniqid() . '@example.test',
        'password' => 'password',
    ]);

    $response = actingAs($this->user)
        ->withHeader('X-Organisation-Id', (string) $this->organisation->id)
        ->postJson("/api/v1/organisations/{$this->organisation->id}/invitations", [
            'email' => $manager->email,
            'role' => 'property_manager',
        ])
        ->assertCreated();

    $token = $response->json('token');
    expect($token)->toBeString();

    // The invitee belongs to no organisation yet, so no tenant may be left over from the admin's request.
    resetTenant();

    actingAs($manager)->postJson("/api/v1/invitations/{$token}/accept")->assertOk();

    expect($manager->fresh()->roleIn($this->organisation))->toBe('property_manager');

    actingAs($manager)
        ->withHeader('X-Organisation-Id', (string) $this->organisation->id)
        ->postJson("/api/v1/invitations/{$token}/accept")
        ->assertConflict();
});

it('refuses to invite an admin', function (): void {
    actingAs($this->user)
        ->withHeader('X-Organisation-Id', (string) $this->organisation->id)
        ->postJson("/api/v1/organisations/{$this->organisation->id}/invitations", [
            'email' => 'new-admin@example.test',
            'role' => 'admin',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('role');
});

it('does not let a manager manage the team', function (): void {
    $manager = app(UserWriteFactory::class)->create([
        'name' => 'Manager',
        'email' => 'manager-' . uniqid() . '@example.test',
        'password' => 'password',
    ]);
    app(UserWriteFactory::class)->attachToOrganisation($manager, $this->organisation, 'property_manager');

    actingAs($manager)
        ->withHeader('X-Organisation-Id', (string) $this->organisation->id)
        ->getJson("/api/v1/organisations/{$this->organisation->id}/members")
        ->assertForbidden();
});

it('can re-invite an email once the earlier invitation is no longer pending', function (): void {
    $url = "/api/v1/organisations/{$this->organisation->id}/invitations";
    $admin = fn() => actingAs($this->user)->withHeader('X-Organisation-Id', (string) $this->organisation->id);
    $invite = ['email' => 'again@example.test', 'role' => 'viewer'];

    $admin()->postJson($url, $invite)->assertCreated();
    $admin()->postJson($url, $invite)->assertUnprocessable()->assertJsonValidationErrors('email');

    $this->travel(8)->days();

    $admin()->postJson($url, $invite)->assertCreated();
});

it('changes the role of an existing member who accepts, but never demotes an admin', function (): void {
    $users = app(UserWriteFactory::class);
    $member = $users->create([
        'name' => 'Member',
        'email' => 'member-' . uniqid() . '@example.test',
        'password' => 'password',
    ]);
    $users->attachToOrganisation($member, $this->organisation, 'viewer');
    $url = "/api/v1/organisations/{$this->organisation->id}/invitations";
    $as = fn($user) => actingAs($user)->withHeader('X-Organisation-Id', (string) $this->organisation->id);

    $token = $as($this->user)->postJson($url, ['email' => $member->email, 'role' => 'property_manager'])->json('token');
    $as($member)->postJson("/api/v1/invitations/{$token}/accept")->assertOk();

    expect($member->fresh()->roleIn($this->organisation))->toBe('property_manager');

    $token = $as($this->user)->postJson($url, ['email' => $this->user->email, 'role' => 'viewer'])->json('token');
    $as($this->user)->postJson("/api/v1/invitations/{$token}/accept")->assertConflict();

    expect($this->user->fresh()->roleIn($this->organisation))->toBe('admin');
});

it('lets only an admin change the organisation', function (): void {
    $users = app(UserWriteFactory::class);
    $member = $users->create([
        'name' => 'Member',
        'email' => 'member-' . uniqid() . '@example.test',
        'password' => 'password',
    ]);
    $users->attachToOrganisation($member, $this->organisation, 'viewer');

    actingAs($member)
        ->withHeader('X-Organisation-Id', (string) $this->organisation->id)
        ->putJson("/api/v1/organisations/{$this->organisation->id}", ['name' => 'Renamed'])
        ->assertForbidden();
});
