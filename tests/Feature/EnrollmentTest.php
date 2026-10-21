<?php

namespace Tests\Feature;

use App\Console\Commands\TunnelAuthorizedKeys;
use App\Models\Client;
use App\Models\ConnectionRequest;
use App\Models\EnrollmentToken;
use App\Models\Machine;
use App\Models\MachineKey;
use App\Models\User;
use App\Support\SshPublicKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnrollmentTest extends TestCase
{
    use RefreshDatabase;

    // Chave ed25519 válida (o conteúdo em base64 começa com o próprio tipo)
    private const PUBLIC_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIHfYVrVxNE/OY3x4YECFSTQs8GHGJf+x9UASG4djp+wz';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'tunnel.server_host' => 'tunnel.example.com',
            'tunnel.ssh_user' => 'tunnel',
            'tunnel.ssh_port' => 22,
            'tunnel.ssh_host_keys' => __DIR__.'/../fixtures/ssh_host_*_key.pub',
        ]);
    }

    private function clientOf(User $owner): Client
    {
        return Client::create(['user_id' => $owner->id, 'name' => 'Cliente de '.$owner->name]);
    }

    private function enroll(string $token, array $overrides = [])
    {
        return $this->postJson('/api/enroll', array_merge([
            'token' => $token,
            'hardware_id' => 'hw-123',
            'hostname' => 'PC-RECEPCAO',
            'os' => 'Windows-11-10.0.26200-SP0',
            'public_key' => self::PUBLIC_KEY.' ssh-tunnel@PC-RECEPCAO',
        ], $overrides));
    }

    public function test_owner_generates_install_commands_for_own_client(): void
    {
        $owner = User::factory()->create();
        $client = $this->clientOf($owner);

        $response = $this->actingAs($owner)->postJson("/clients/{$client->id}/enrollment-tokens")->assertOk();

        $this->assertMatchesRegularExpression('#^\[Net\.ServicePointManager\]::SecurityProtocol = .+ -bor 3072; irm http://localhost/instalar/[A-Za-z0-9]{32}/windows \| iex$#', $response->json('commands.windows'));
        $this->assertMatchesRegularExpression('#^curl -fsSL http://localhost/instalar/[A-Za-z0-9]{32}/linux \| sudo bash$#', $response->json('commands.linux'));

        // O banco guarda só o hash do código
        preg_match('#/instalar/([A-Za-z0-9]{32})/#', $response->json('commands.linux'), $matches);
        $this->assertDatabaseHas('enrollment_tokens', ['client_id' => $client->id, 'token_hash' => hash('sha256', $matches[1])]);
        $this->assertDatabaseMissing('enrollment_tokens', ['token_hash' => $matches[1]]);
    }

    public function test_user_cannot_generate_commands_or_see_status_for_another_user(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $client = $this->clientOf($owner);
        [$token] = EnrollmentToken::issue($client);

        $this->actingAs($intruder);
        $this->postJson("/clients/{$client->id}/enrollment-tokens")->assertForbidden();
        $this->getJson("/enrollment-tokens/{$token->id}")->assertForbidden();
    }

    public function test_install_script_embeds_panel_url_and_token(): void
    {
        [, $plainToken] = EnrollmentToken::issue($this->clientOf(User::factory()->create()));

        foreach (['linux' => "PANEL_URL='http://localhost'", 'windows' => "\$PanelUrl = 'http://localhost'"] as $os => $expected) {
            $script = $this->get("/instalar/{$plainToken}/{$os}")->assertOk()->getContent();

            $this->assertStringContainsString($expected, $script);
            $this->assertStringContainsString($plainToken, $script);
            $this->assertStringNotContainsString('__PANEL_URL__', $script);
            $this->assertStringNotContainsString('__TOKEN__', $script);
        }

        $this->get('/instalar/cliente.py')->assertOk();
    }

    public function test_install_script_for_unknown_or_used_token_only_prints_an_error(): void
    {
        $script = $this->get('/instalar/'.str_repeat('a', 32).'/linux')->assertOk()->getContent();

        $this->assertStringContainsString('expirou ou já foi usado', $script);
        $this->assertStringContainsString('exit 1', $script);
        $this->assertStringNotContainsString('PANEL_URL', $script);
    }

    public function test_machine_enrolls_with_its_own_key(): void
    {
        $owner = User::factory()->create();
        $client = $this->clientOf($owner);
        [$token, $plainToken] = EnrollmentToken::issue($client);

        $response = $this->enroll($plainToken)->assertOk()->assertJson([
            'success' => true,
            'machine' => ['name' => 'PC-RECEPCAO'],
            'check_endpoint' => 'http://localhost/api/check-tunnel-request',
            'ssh' => [
                'host' => 'tunnel.example.com',
                'port' => 22,
                'user' => 'tunnel',
                'known_hosts' => ['tunnel.example.com ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIEAfVS/ZRI+80C/S/eQnW/CtjOmmhXdkiEC35KvewQcY'],
            ],
        ]);

        $machine = Machine::findOrFail($response->json('machine.id'));
        $this->assertSame($client->id, $machine->client_id);
        $this->assertSame('hw-123', $machine->hardware_id);
        $this->assertSame('Windows-11-10.0.26200-SP0', $machine->specifications);

        // O comentário da chave é descartado
        $this->assertSame(self::PUBLIC_KEY, $machine->machineKey->public_key);
        $this->assertNotNull($token->fresh()->used_at);

        $this->actingAs($owner)->getJson("/enrollment-tokens/{$token->id}")
            ->assertJson(['installed' => true, 'machine' => 'PC-RECEPCAO']);
    }

    public function test_token_works_only_once_and_until_it_expires(): void
    {
        $client = $this->clientOf(User::factory()->create());

        [, $plainToken] = EnrollmentToken::issue($client);
        $this->enroll($plainToken)->assertOk();
        $this->enroll($plainToken, ['hardware_id' => 'outra-maquina'])->assertStatus(410);

        [$expired, $expiredToken] = EnrollmentToken::issue($client);
        $expired->update(['expires_at' => now()->subMinute()]);
        $this->enroll($expiredToken, ['hardware_id' => 'mais-uma'])->assertStatus(410);

        $this->assertSame(1, Machine::count());
    }

    public function test_reinstall_updates_the_same_machine_and_key(): void
    {
        $owner = User::factory()->create();
        $first = $this->clientOf($owner);
        $second = Client::create(['user_id' => $owner->id, 'name' => 'Filial']);

        [, $token] = EnrollmentToken::issue($first);
        $machineId = $this->enroll($token)->json('machine.id');

        $newKey = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIA/wyWq2/jUHrjiMNC6XqRY3K3EkX+hHZ2DEPw0jfCCV';
        [, $token] = EnrollmentToken::issue($second);
        $this->enroll($token, ['public_key' => $newKey])->assertOk()->assertJson(['machine' => ['id' => $machineId]]);

        $this->assertSame(1, Machine::count());
        $this->assertSame(1, MachineKey::count());
        $this->assertSame($second->id, Machine::find($machineId)->client_id);
        $this->assertSame($newKey, MachineKey::first()->public_key);
    }

    public function test_machine_of_another_user_cannot_be_taken_over(): void
    {
        [, $ownerToken] = EnrollmentToken::issue($this->clientOf(User::factory()->create()));
        $this->enroll($ownerToken)->assertOk();

        [, $intruderToken] = EnrollmentToken::issue($this->clientOf(User::factory()->create()));
        $this->enroll($intruderToken, ['public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIA/wyWq2/jUHrjiMNC6XqRY3K3EkX+hHZ2DEPw0jfCCV'])
            ->assertStatus(409);

        $this->assertSame(self::PUBLIC_KEY, MachineKey::first()->public_key);
    }

    public function test_same_public_key_cannot_be_used_by_two_machines(): void
    {
        $client = $this->clientOf(User::factory()->create());

        [, $token] = EnrollmentToken::issue($client);
        $this->enroll($token)->assertOk();

        [, $token] = EnrollmentToken::issue($client);
        $this->enroll($token, ['hardware_id' => 'outra-maquina'])->assertStatus(409);

        $this->assertSame(1, Machine::count());
    }

    public function test_invalid_or_injected_public_keys_are_rejected(): void
    {
        [, $token] = EnrollmentToken::issue($this->clientOf(User::factory()->create()));

        $invalidKeys = [
            'não é uma chave',
            // Tentativa de injetar uma segunda linha no authorized_keys
            self::PUBLIC_KEY." x\nssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIA/wyWq2/jUHrjiMNC6XqRY3K3EkX+hHZ2DEPw0jfCCV",
            // Opções do authorized_keys antes da chave
            'command="/bin/sh" '.self::PUBLIC_KEY,
            // Tipo declarado diferente do conteúdo
            'ssh-rsa AAAAC3NzaC1lZDI1NTE5AAAAIHfYVrVxNE/OY3x4YECFSTQs8GHGJf+x9UASG4djp+wz',
        ];

        foreach ($invalidKeys as $key) {
            $this->assertNull(SshPublicKey::normalize($key));
            $this->enroll($token, ['public_key' => $key])->assertStatus(422);
        }

        $this->assertSame(0, Machine::count());
    }

    public function test_authorized_keys_only_allow_the_ports_of_active_requests(): void
    {
        $owner = User::factory()->create();
        [, $token] = EnrollmentToken::issue($this->clientOf($owner));
        $machine = Machine::findOrFail($this->enroll($token)->json('machine.id'));

        // Sem solicitação ativa: autentica, mas não abre túnel
        $this->artisan('tunnel:authorized-keys', ['user' => 'tunnel'])
            ->expectsOutput("restrict,command=\"/bin/true\" ".self::PUBLIC_KEY." machine-{$machine->id}")
            ->assertSuccessful();

        foreach ([['pending', 45001], ['in_progress', 45002], ['completed', 45003]] as [$status, $port]) {
            ConnectionRequest::create([
                'machine_id' => $machine->id,
                'user_id' => $owner->id,
                'service_port' => 3389,
                'server_port' => $port,
                'ip_address' => '203.0.113.7',
                'status' => $status,
            ]);
        }

        $this->artisan('tunnel:authorized-keys', ['user' => 'tunnel'])
            ->expectsOutput("restrict,port-forwarding,permitlisten=\"45001\",permitlisten=\"45002\",command=\"/bin/true\" ".self::PUBLIC_KEY." machine-{$machine->id}")
            ->assertSuccessful();

        // Outros usuários do servidor não recebem chave nenhuma
        $this->artisan('tunnel:authorized-keys', ['user' => 'root'])->doesntExpectOutputToContain('ssh-ed25519')->assertSuccessful();
    }

    public function test_authorized_keys_line_ignores_invalid_ports(): void
    {
        $this->assertSame(
            'restrict,port-forwarding,permitlisten="40000",command="/bin/true" '.self::PUBLIC_KEY.' machine-1',
            TunnelAuthorizedKeys::authorizedKeysLine(self::PUBLIC_KEY, [40000, '40000', 0, 70000, 'abc'], 'machine-1')
        );
    }
}
