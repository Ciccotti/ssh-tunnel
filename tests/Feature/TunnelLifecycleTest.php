<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ConnectionRequest;
use App\Models\Machine;
use App\Models\User;
use App\Services\TunnelManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class TunnelLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const IP = '203.0.113.7';

    /** @var array<string, \Closure> respostas falsas por programa (ufw, lsof, kill, ss) */
    private array $processResults = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['tunnel.pending_ttl' => 300, 'tunnel.pending_grace' => 60]);

        // Nenhum comando de sistema (sudo, ufw, lsof, kill, ss) roda de verdade nos testes
        Process::preventStrayProcesses();
        Process::fake(['*' => function (PendingProcess $process) {
            $program = $process->command[0] === 'sudo' ? $process->command[1] : $process->command[0];

            return isset($this->processResults[$program])
                ? ($this->processResults[$program])($process)
                : Process::result();
        }]);
    }

    private function machineOf(User $owner, string $suffix = ''): Machine
    {
        $client = Client::firstOrCreate(['user_id' => $owner->id, 'name' => 'Cliente de '.$owner->name]);

        return Machine::create([
            'client_id' => $client->id,
            'name' => 'PC-'.$owner->id.$suffix,
            'hardware_id' => 'HW-'.$owner->id.$suffix,
        ]);
    }

    private function tunnelRequest(Machine $machine, string $status, int $port, int $ageInSeconds = 0): ConnectionRequest
    {
        $connectionRequest = ConnectionRequest::create([
            'machine_id' => $machine->id,
            'user_id' => $machine->client->user_id,
            'service_port' => 3389,
            'server_port' => $port,
            'ip_address' => self::IP,
            'status' => $status,
        ]);

        $connectionRequest->forceFill(['created_at' => now()->subSeconds($ageInSeconds)])->save();

        return $connectionRequest;
    }

    private function assertFirewallRuleRemoved(int $port): void
    {
        Process::assertRan(fn (PendingProcess $process) => $process->command === [
            'sudo', 'ufw', 'delete', 'allow', 'from', self::IP, 'to', 'any', 'port', (string) $port,
        ]);
    }

    private function assertFirewallRuleKept(int $port): void
    {
        Process::assertDidntRun(fn (PendingProcess $process) => in_array('delete', $process->command, true)
            && end($process->command) === (string) $port);
    }

    private function listening(bool $listening): void
    {
        $this->partialMock(TunnelManager::class, fn ($mock) => $mock->shouldReceive('isListening')->andReturn($listening));
    }

    public function test_client_api_ignores_expired_pending_requests(): void
    {
        $machine = $this->machineOf(User::factory()->create());
        $this->tunnelRequest($machine, 'pending', 41000, ageInSeconds: 301);

        $this->postJson('/api/check-tunnel-request', ['hardware_id' => $machine->hardware_id])
            ->assertOk()
            ->assertJson(['success' => false]);

        $this->tunnelRequest($machine, 'pending', 42000, ageInSeconds: 10);

        $this->postJson('/api/check-tunnel-request', ['hardware_id' => $machine->hardware_id])
            ->assertOk()
            ->assertJson(['success' => true, 'server_port' => 42000, 'status' => 'pending']);
    }

    public function test_monitor_fails_expired_pending_requests_and_removes_firewall_rule(): void
    {
        $machine = $this->machineOf(User::factory()->create());
        $expired = $this->tunnelRequest($machine, 'pending', 41000, ageInSeconds: 361);
        // Venceu para a API, mas ainda está na folga: o cliente pode estar subindo o túnel
        $inGrace = $this->tunnelRequest($machine, 'pending', 42000, ageInSeconds: 330);
        $fresh = $this->tunnelRequest($machine, 'pending', 43000, ageInSeconds: 10);

        $this->listening(false);
        app(TunnelManager::class)->monitor();

        $this->assertSame('failed', $expired->fresh()->status);
        $this->assertSame('pending', $inGrace->fresh()->status);
        $this->assertSame('pending', $fresh->fresh()->status);
        $this->assertFirewallRuleRemoved(41000);
        $this->assertFirewallRuleKept(42000);
        $this->assertFirewallRuleKept(43000);
    }

    public function test_monitor_marks_listening_tunnel_in_progress_and_closes_disconnected_one(): void
    {
        $machine = $this->machineOf(User::factory()->create());
        $pending = $this->tunnelRequest($machine, 'pending', 41000, ageInSeconds: 1000);

        $this->listening(true);
        app(TunnelManager::class)->monitor();

        $this->assertSame('in_progress', $pending->fresh()->status);
        $this->assertFirewallRuleKept(41000);

        $this->listening(false);
        app(TunnelManager::class)->monitor();

        $this->assertSame('completed', $pending->fresh()->status);
        $this->assertFirewallRuleRemoved(41000);
    }

    public function test_owner_can_cancel_pending_request(): void
    {
        $owner = User::factory()->create();
        $machine = $this->machineOf($owner);
        $pending = $this->tunnelRequest($machine, 'pending', 41000);

        $this->actingAs(User::factory()->create())
            ->postJson("/machines/{$machine->id}/close-tunnel")
            ->assertForbidden();
        $this->assertSame('pending', $pending->fresh()->status);

        $this->actingAs($owner)
            ->postJson("/machines/{$machine->id}/close-tunnel")
            ->assertOk()
            ->assertJson(['success' => true, 'status' => 'failed']);

        $this->assertSame('failed', $pending->fresh()->status);
        $this->assertFirewallRuleRemoved(41000);
    }

    public function test_close_only_kills_the_process_listening_on_the_tunnel_port(): void
    {
        $owner = User::factory()->create();
        $machine = $this->machineOf($owner);
        $tunnel = $this->tunnelRequest($machine, 'in_progress', 41000);

        $this->processResults['lsof'] = fn () => Process::result("1234\n");

        $this->actingAs($owner)
            ->postJson("/machines/{$machine->id}/close-tunnel")
            ->assertOk()
            ->assertJson(['success' => true, 'status' => 'completed']);

        Process::assertRan(fn (PendingProcess $process) => $process->command === ['sudo', 'lsof', '-t', '-sTCP:LISTEN', '-iTCP:41000']);
        Process::assertRan(fn (PendingProcess $process) => $process->command === ['sudo', 'kill', '1234']);
        $this->assertSame('completed', $tunnel->fresh()->status);
        $this->assertFirewallRuleRemoved(41000);
    }

    public function test_close_failure_does_not_leak_command_output(): void
    {
        $owner = User::factory()->create();
        $machine = $this->machineOf($owner);
        $tunnel = $this->tunnelRequest($machine, 'in_progress', 41000);

        $this->processResults['ufw'] = fn () => Process::result(errorOutput: 'sudo: segredo-do-servidor', exitCode: 1);

        $response = $this->actingAs($owner)
            ->postJson("/machines/{$machine->id}/close-tunnel")
            ->assertOk()
            ->assertJson(['success' => false]);

        $this->assertStringNotContainsString('segredo-do-servidor', $response->getContent());
        $this->assertSame('in_progress', $tunnel->fresh()->status);
    }

    public function test_open_reserves_a_port_that_no_other_request_is_using(): void
    {
        $owner = User::factory()->create();
        $machine = $this->machineOf($owner);
        config(['tunnel.min_port' => 45000, 'tunnel.max_port' => 45000]);

        $this->actingAs($owner)
            ->postJson("/machines/{$machine->id}/open-tunnel", ['service_port' => 3389])
            ->assertOk()
            ->assertJson(['success' => true, 'server_port' => 45000, 'status' => 'pending']);

        $this->assertDatabaseHas('connection_requests', ['machine_id' => $machine->id, 'server_port' => 45000, 'status' => 'pending']);
        Process::assertRan(fn (PendingProcess $process) => $process->command === [
            'sudo', 'ufw', 'allow', 'from', '127.0.0.1', 'to', 'any', 'port', '45000',
        ]);

        // A única porta da faixa já está com uma solicitação ativa
        $this->postJson("/machines/{$machine->id}/open-tunnel", ['service_port' => 22])
            ->assertOk()
            ->assertJson(['success' => false]);

        $this->assertDatabaseCount('connection_requests', 1);
    }

    public function test_open_skips_ports_already_in_use_on_the_server(): void
    {
        $owner = User::factory()->create();
        $machine = $this->machineOf($owner);
        config(['tunnel.min_port' => 45000, 'tunnel.max_port' => 45000]);

        $this->processResults['ss'] = fn () => Process::result("tcp LISTEN 0 128 0.0.0.0:45000 0.0.0.0:*\n");

        $this->actingAs($owner)
            ->postJson("/machines/{$machine->id}/open-tunnel", ['service_port' => 3389])
            ->assertOk()
            ->assertJson(['success' => false]);

        $this->assertDatabaseCount('connection_requests', 0);
        Process::assertDidntRun(fn (PendingProcess $process) => in_array('ufw', $process->command, true));
    }

    public function test_open_does_not_keep_request_when_firewall_fails(): void
    {
        $owner = User::factory()->create();
        $machine = $this->machineOf($owner);

        $this->processResults['ufw'] = fn () => Process::result(errorOutput: 'ERROR', exitCode: 1);

        $this->actingAs($owner)
            ->postJson("/machines/{$machine->id}/open-tunnel", ['service_port' => 3389])
            ->assertOk()
            ->assertJson(['success' => false, 'message' => 'Erro ao abrir a porta no firewall.']);

        $this->assertDatabaseCount('connection_requests', 0);
    }

    public function test_deleting_machine_closes_its_tunnels_first(): void
    {
        $owner = User::factory()->create();
        $machine = $this->machineOf($owner);
        $this->tunnelRequest($machine, 'in_progress', 41000);
        $this->tunnelRequest($machine, 'pending', 42000);
        $this->tunnelRequest($machine, 'completed', 43000);

        $this->actingAs($owner)->delete("/machines/{$machine->id}")->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('machines', ['id' => $machine->id]);
        $this->assertFirewallRuleRemoved(41000);
        $this->assertFirewallRuleRemoved(42000);
        $this->assertFirewallRuleKept(43000);
        Process::assertRan(fn (PendingProcess $process) => $process->command === ['sudo', 'lsof', '-t', '-sTCP:LISTEN', '-iTCP:41000']);
    }

    public function test_machine_is_kept_when_its_tunnel_cannot_be_closed(): void
    {
        $owner = User::factory()->create();
        $machine = $this->machineOf($owner);
        $tunnel = $this->tunnelRequest($machine, 'in_progress', 41000);

        $this->processResults['ufw'] = fn () => Process::result(errorOutput: 'ERROR', exitCode: 1);

        $this->actingAs($owner)->delete("/machines/{$machine->id}")
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('machines', ['id' => $machine->id]);
        $this->assertSame('in_progress', $tunnel->fresh()->status);
    }

    public function test_deleting_client_closes_tunnels_of_all_its_machines(): void
    {
        $owner = User::factory()->create();
        $first = $this->machineOf($owner, '-a');
        $second = $this->machineOf($owner, '-b');
        $this->tunnelRequest($first, 'in_progress', 41000);
        $this->tunnelRequest($second, 'pending', 42000);

        // Túnel de outro usuário não pode ser afetado
        $this->tunnelRequest($this->machineOf(User::factory()->create()), 'in_progress', 43000);

        $this->actingAs($owner)->delete("/clients/{$first->client_id}")->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('clients', ['id' => $first->client_id]);
        $this->assertFirewallRuleRemoved(41000);
        $this->assertFirewallRuleRemoved(42000);
        $this->assertFirewallRuleKept(43000);
    }

    public function test_dashboard_shows_configured_server_address_after_reload(): void
    {
        config(['tunnel.server_host' => '198.51.100.20']);
        $owner = User::factory()->create();
        $this->tunnelRequest($this->machineOf($owner, '-a'), 'in_progress', 41000);
        $this->tunnelRequest($this->machineOf($owner, '-b'), 'pending', 42000);

        $this->actingAs($owner)->get('/dashboard')
            ->assertOk()
            ->assertSee('198.51.100.20:41000')
            ->assertSee('198.51.100.20:42000')
            ->assertSee('data-status="pending"', false)
            ->assertDontSee('195.200.5.90');
    }

    public function test_tunnel_status_returns_server_port(): void
    {
        $owner = User::factory()->create();
        $machine = $this->machineOf($owner);
        $this->tunnelRequest($machine, 'in_progress', 41000);

        $this->actingAs($owner)->getJson("/machines/{$machine->id}/tunnel-status")
            ->assertOk()
            ->assertJson(['status' => 'in_progress', 'server_port' => 41000]);
    }
}
