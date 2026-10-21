<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Machine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MachineAccessTest extends TestCase
{
    use RefreshDatabase;

    private function machineOf(User $owner): Machine
    {
        $client = Client::create(['user_id' => $owner->id, 'name' => 'Cliente de '.$owner->name]);

        return Machine::create([
            'client_id' => $client->id,
            'name' => 'PC-'.$owner->id,
            'hardware_id' => 'HW-'.$owner->id,
        ]);
    }

    public function test_dashboard_only_lists_own_clients(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->machineOf($owner);
        $this->machineOf($other);

        $this->actingAs($owner)->get('/dashboard')
            ->assertOk()
            ->assertSee('Cliente de '.$owner->name)
            ->assertDontSee('Cliente de '.$other->name);
    }

    public function test_user_cannot_manage_machines_of_another_user(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $machine = $this->machineOf($owner);

        $this->actingAs($intruder);

        $this->postJson("/machines/{$machine->id}/open-tunnel", ['service_port' => 3389])->assertForbidden();
        $this->getJson("/machines/{$machine->id}/tunnel-status")->assertForbidden();
        $this->delete("/machines/{$machine->id}")->assertForbidden();
        $this->delete("/clients/{$machine->client_id}")->assertForbidden();

        $this->post('/machines', [
            'client_id' => $machine->client_id,
            'name' => 'Intrusa',
            'hardware_id' => 'HW-INTRUSA',
        ])->assertSessionHasErrors('client_id');

        $this->assertDatabaseHas('machines', ['id' => $machine->id]);
        $this->assertDatabaseMissing('machines', ['hardware_id' => 'HW-INTRUSA']);
        $this->assertDatabaseCount('connection_requests', 0);
    }

    public function test_owner_can_manage_own_machine(): void
    {
        $owner = User::factory()->create();
        $machine = $this->machineOf($owner);

        $this->actingAs($owner);

        $this->getJson("/machines/{$machine->id}/tunnel-status")
            ->assertOk()
            ->assertJson(['status' => 'no_connection']);

        $this->delete("/machines/{$machine->id}")->assertRedirect(route('dashboard'));
        $this->assertDatabaseMissing('machines', ['id' => $machine->id]);
    }
}
