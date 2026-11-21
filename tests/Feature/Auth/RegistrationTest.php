<?php

test('registration screen can be rendered', function () {
    $response = $this->get('/register');

    $response->assertStatus(200);
});

test('new users can register', function () {
    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'a-long-password',
        'password_confirmation' => 'a-long-password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('passwords need at least 12 characters, as everywhere else', function () {
    $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'elevenchars',
        'password_confirmation' => 'elevenchars',
    ])->assertSessionHasErrors(['password' => 'The password field must be at least 12 characters.']);

    $this->assertGuest();
});
