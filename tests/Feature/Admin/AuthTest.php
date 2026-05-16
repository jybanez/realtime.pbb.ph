<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_can_login_and_reach_dashboard(): void
    {
        $user = User::factory()->create([
            'email' => 'operator@example.test',
            'password' => Hash::make('secret-password'),
            'is_operator' => true,
        ]);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'secret-password',
        ])->assertRedirect(route('admin.dashboard'));

        $this->get(route('admin.dashboard'))->assertOk();
    }

    public function test_non_operator_is_rejected_from_admin_surface(): void
    {
        $user = User::factory()->create([
            'email' => 'user@example.test',
            'password' => Hash::make('secret-password'),
            'is_operator' => false,
        ]);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'secret-password',
        ])->assertSessionHasErrors('email');
    }

    public function test_guest_cannot_access_admin_dashboard(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('status'));
    }
}
