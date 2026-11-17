<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class InstallTest extends TestCase
{
    public function test_installer_is_closed_when_no_token_is_configured(): void
    {
        config(['install.token' => null]);

        $this->get('/install')->assertOk()->assertSee('Store setup is closed')->assertDontSee('Setup token');
        $this->post('/install')->assertNotFound();
        $this->assertFalse(Schema::hasTable('users'));
    }

    public function test_fresh_install_requires_the_token_and_prevents_a_second_admin_setup(): void
    {
        $token = Str::random(40);
        $password = Str::random(24);
        config(['install.token' => $token]);
        $inputs = [
            'install_token' => $token,
            'name' => 'Store Owner',
            'email' => 'owner@example.test',
            'password' => $password,
            'password_confirmation' => $password,
        ];

        $this->get('/install')->assertOk()->assertSee('Install Adornia Jems')->assertDontSee($token);
        $this->from('/install')->post('/install', array_replace($inputs, ['install_token' => Str::random(40)]))
            ->assertRedirect('/install')
            ->assertSessionHasErrors('install_token')
            ->assertSessionMissing('_old_input.install_token');
        $this->assertFalse(Schema::hasTable('users'));

        $this->post('/install', $inputs)->assertRedirect('/admin/login');
        $owner = User::where('email', 'owner@example.test')->firstOrFail();
        $this->assertTrue($owner->is_admin);
        $this->assertTrue(Hash::check($password, $owner->password));
        $this->assertDatabaseHas('homepage_sections', ['type' => 'hero']);
        $this->assertDatabaseHas('delivery_rates', ['state' => 'Lagos', 'active' => false]);
        $this->assertDatabaseCount('products', 0);

        $this->get('/install')->assertOk()->assertSee('Store setup is closed');
        $this->post('/install', array_replace($inputs, ['email' => 'another@example.test']))->assertNotFound();
        $this->assertDatabaseCount('users', 1);
    }
}
