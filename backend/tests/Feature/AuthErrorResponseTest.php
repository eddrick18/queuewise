<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthErrorResponseTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_credentials_return_json_instead_of_redirecting(): void
    {
        $this->postJson('/login', [
            'email' => 'missing@example.test',
            'password' => 'incorrect-password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('email')
            ->assertJsonPath('errors.email.0', 'The provided email or password is incorrect.')
            ->assertHeaderMissing('Location');
    }

    public function test_registration_validation_errors_return_json(): void
    {
        $this->postJson('/register', [
            'name' => 'Customer',
            'email' => 'invalid-email',
            'password' => 'short',
            'password_confirmation' => 'different',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password'])
            ->assertHeaderMissing('Location');
    }
}
