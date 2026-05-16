<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_can_update_own_profile(): void
    {
        $user = User::factory()->create([
            'name' => 'Operator One',
            'email' => 'operator@example.test',
            'password' => Hash::make('secret-password'),
            'is_operator' => true,
        ]);

        $this->actingAs($user)
            ->patchJson(route('admin.api.me.update'), [
                'name' => 'Operator Two',
                'email' => 'operator2@example.test',
            ])
            ->assertOk()
            ->assertJsonPath('data.account.name', 'Operator Two')
            ->assertJsonPath('data.account.email', 'operator2@example.test');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Operator Two',
            'email' => 'operator2@example.test',
        ]);
    }

    public function test_operator_can_change_own_password(): void
    {
        $user = User::factory()->create([
            'name' => 'Operator One',
            'email' => 'operator@example.test',
            'password' => Hash::make('secret-password'),
            'is_operator' => true,
        ]);

        $this->actingAs($user)
            ->postJson(route('admin.api.me.password'), [
                'current_password' => 'secret-password',
                'password' => 'new-secret-password',
                'password_confirmation' => 'new-secret-password',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('new-secret-password', $user->fresh()->password));

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'new-secret-password',
        ])->assertRedirect(route('admin.dashboard'));
    }
}
