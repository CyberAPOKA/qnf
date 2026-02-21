<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileInformationTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_information_can_be_updated(): void
    {
        $this->actingAs($user = User::factory()->create());

        $this->put('/user/profile-information', [
            'name' => 'Test Name',
            'phone' => '5511888888888',
        ]);

        $this->assertEquals('Test Name', $user->fresh()->name);
        $this->assertEquals('5511888888888', $user->fresh()->phone);
    }
}
