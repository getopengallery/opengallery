<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

it('stores database sessions without IP address or user agent', function () {
    config(['session.driver' => 'database']);
    app('session')->forgetDrivers();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->withHeader('User-Agent', 'TestBrowser/1.0')
        ->get(route('profile.edit'))
        ->assertOk();

    $session = DB::table('sessions')->sole();

    expect($session->user_id)->toBe($user->id)
        ->and($session->ip_address)->toBeNull()
        ->and($session->user_agent)->toBeNull();
});

it('clears IP addresses and user agents that older versions stored', function () {
    DB::table('sessions')->insert([
        'id' => 'old-session',
        'ip_address' => '203.0.113.7',
        'user_agent' => 'TestBrowser/1.0',
        'payload' => '',
        'last_activity' => now()->timestamp,
    ]);

    (require database_path('migrations/2026_11_14_213203_clear_ip_addresses_and_user_agents_from_sessions.php'))->up();

    expect(DB::table('sessions')->sole())
        ->ip_address->toBeNull()
        ->user_agent->toBeNull();
});
