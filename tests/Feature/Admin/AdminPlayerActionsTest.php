<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminPlayerActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_reset_player_password_to_qnf(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $player = User::factory()->create([
            'role' => 'player',
            'password' => Hash::make('old-password'),
        ]);

        $this->actingAs($admin)
            ->from(route('admin.players'))
            ->post(route('admin.players.reset-password', $player))
            ->assertRedirect(route('admin.players'));

        $player->refresh();

        $this->assertTrue(Hash::check('qnf', $player->password));
        $this->assertFalse(Hash::check('old-password', $player->password));
    }

    public function test_admin_cannot_reset_another_admin_password(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $otherAdmin = User::factory()->create([
            'role' => 'admin',
            'password' => Hash::make('secret'),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.players.reset-password', $otherAdmin))
            ->assertForbidden();

        $this->assertTrue(Hash::check('secret', $otherAdmin->fresh()->password));
    }

    public function test_admin_can_toggle_guest_flag(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $player = User::factory()->create([
            'role' => 'player',
            'guest' => false,
            'phone' => '555199294672',
        ]);

        $this->actingAs($admin)
            ->from(route('admin.players'))
            ->post(route('admin.players.update', $player), [
                'name' => $player->name,
                'phone' => $player->phone,
                'position' => $player->position->value,
                'active' => 1,
                'guest' => 1,
            ])
            ->assertRedirect(route('admin.players'))
            ->assertSessionHasNoErrors();

        $this->assertTrue($player->fresh()->guest);

        $this->actingAs($admin)
            ->from(route('admin.players'))
            ->post(route('admin.players.update', $player), [
                'name' => $player->name,
                'phone' => $player->phone,
                'position' => $player->position->value,
                'active' => 1,
                'guest' => 0,
            ])
            ->assertRedirect(route('admin.players'))
            ->assertSessionHasNoErrors();

        $this->assertFalse($player->fresh()->guest);
    }

    public function test_non_admin_cannot_reset_password(): void
    {
        $player = User::factory()->create(['role' => 'player']);
        $target = User::factory()->create(['role' => 'player']);

        $this->actingAs($player)
            ->post(route('admin.players.reset-password', $target))
            ->assertForbidden();
    }
}
