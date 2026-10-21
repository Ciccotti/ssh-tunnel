<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\EnrollmentToken;
use App\Models\Machine;
use App\Models\MachineKey;
use App\Support\SshPublicKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Instalação do cliente com um comando: o painel gera um código de uso único,
 * o instalador (resources/install) baixa o cliente, e o cliente se registra em
 * /api/enroll com o Hardware ID e a chave pública SSH da máquina.
 */
class EnrollmentController extends Controller
{
    private const EXPIRED_MESSAGE = 'Este comando de instalação expirou ou já foi usado. Gere um novo no painel.';

    // Painel: gera os comandos de instalação para um cliente do usuário
    public function store(Client $client)
    {
        [$token, $plainToken] = EnrollmentToken::issue($client);

        $windowsUrl = route('install.script', ['token' => $plainToken, 'os' => 'windows']);
        $linuxUrl = route('install.script', ['token' => $plainToken, 'os' => 'linux']);

        return response()->json([
            'success' => true,
            'expires_at' => $token->expires_at->toIso8601String(),
            'commands' => [
                // O TLS 1.2 é ligado antes do irm: em Windows antigos o PowerShell 5.1 ainda usa TLS 1.0 por padrão
                'windows' => "[Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor 3072; irm {$windowsUrl} | iex",
                'linux' => "curl -fsSL {$linuxUrl} | sudo bash",
            ],
            'status_url' => route('enrollment-tokens.status', $token),
        ]);
    }

    // Painel: a máquina já foi instalada com o comando?
    public function status(EnrollmentToken $enrollmentToken)
    {
        return response()->json([
            'installed' => $enrollmentToken->used_at !== null,
            'expired' => $enrollmentToken->used_at === null && $enrollmentToken->expires_at->isPast(),
            'machine' => $enrollmentToken->machine?->name,
        ]);
    }

    // Público: o instalador para Windows ou Linux, com o código embutido
    public function script(string $token, string $os)
    {
        $headers = ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store'];

        if (!EnrollmentToken::findUsable($token)) {
            $body = $os === 'windows'
                ? "Write-Host 'Erro: ".self::EXPIRED_MESSAGE."' -ForegroundColor Red\n"
                : "#!/bin/sh\necho 'Erro: ".self::EXPIRED_MESSAGE."' >&2\nexit 1\n";

            return response($body, 200, $headers);
        }

        // O endereço vai entre aspas simples no script: só aceita uma URL simples
        $panelUrl = rtrim(url('/'), '/');
        abort_unless(preg_match('/^https?:\/\/[A-Za-z0-9.\-:\[\]\/_~%]+$/', $panelUrl), 500, 'APP_URL inválido para o instalador.');

        $template = file_get_contents(resource_path('install/'.($os === 'windows' ? 'windows.ps1' : 'linux.sh')));

        return response(strtr($template, ['__PANEL_URL__' => $panelUrl, '__TOKEN__' => $token]), 200, $headers);
    }

    // Público: o script Python do cliente, baixado pelos instaladores
    public function clientScript()
    {
        return response()->file(base_path('scripts/script_cliente.py'), [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    // API: a máquina se registra usando o código do comando de instalação
    public function enroll(Request $request)
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'regex:/^[A-Za-z0-9]{32}$/'],
            'hardware_id' => 'required|string|max:255',
            'hostname' => 'required|string|max:255',
            'os' => 'nullable|string|max:255',
            'public_key' => 'required|string|max:16384',
        ]);

        $publicKey = SshPublicKey::normalize($validated['public_key']);
        if (!$publicKey) {
            return response()->json(['success' => false, 'message' => 'Chave pública SSH inválida.'], 422);
        }

        return DB::transaction(function () use ($validated, $publicKey) {
            $token = EnrollmentToken::usable()
                ->where('token_hash', EnrollmentToken::hash($validated['token']))
                ->lockForUpdate()
                ->first();

            if (!$token) {
                return response()->json(['success' => false, 'message' => self::EXPIRED_MESSAGE], 410);
            }

            $machine = Machine::with('client')->where('hardware_id', $validated['hardware_id'])->first();

            if ($machine && (int) $machine->client->user_id !== (int) $token->user_id) {
                return response()->json(['success' => false, 'message' => 'Esta máquina já está cadastrada no painel por outro usuário.'], 409);
            }

            // O sshd usa a primeira linha com a chave: duas máquinas com a mesma chave
            // fariam uma herdar as portas da outra
            $keyInUse = MachineKey::where('public_key', $publicKey)
                ->when($machine, fn ($query) => $query->where('machine_id', '!=', $machine->id))
                ->exists();
            if ($keyInUse) {
                return response()->json(['success' => false, 'message' => 'Esta chave SSH já pertence a outra máquina do painel.'], 409);
            }

            if ($machine) {
                // Reinstalação: a máquina vai para o cliente do novo comando e troca de chave
                $machine->update([
                    'client_id' => $token->client_id,
                    'specifications' => $validated['os'] ?? $machine->specifications,
                ]);
            } else {
                $machine = Machine::create([
                    'client_id' => $token->client_id,
                    'name' => $validated['hostname'],
                    'specifications' => $validated['os'] ?? null,
                    'hardware_id' => $validated['hardware_id'],
                ]);
            }

            MachineKey::updateOrCreate(['machine_id' => $machine->id], ['public_key' => $publicKey]);

            $token->update(['used_at' => now(), 'machine_id' => $machine->id]);

            Log::info("Máquina {$machine->id} ({$machine->name}) instalada pelo comando de instalação {$token->id}.");

            return response()->json([
                'success' => true,
                'machine' => ['id' => $machine->id, 'name' => $machine->name],
                'check_endpoint' => route('check-tunnel-request'),
                'ssh' => [
                    'host' => config('tunnel.server_host'),
                    'port' => config('tunnel.ssh_port'),
                    'user' => config('tunnel.ssh_user'),
                    'known_hosts' => $this->knownHosts(),
                ],
            ]);
        });
    }

    // Linhas de known_hosts com as chaves públicas do sshd deste servidor
    private function knownHosts(): array
    {
        $host = config('tunnel.server_host');
        $port = config('tunnel.ssh_port');
        $prefix = $port === 22 ? $host : "[{$host}]:{$port}";

        $lines = [];
        foreach (glob(config('tunnel.ssh_host_keys')) ?: [] as $file) {
            $key = is_readable($file) ? SshPublicKey::normalize((string) file_get_contents($file)) : null;
            if ($key) {
                $lines[] = "{$prefix} {$key}";
            }
        }

        return $lines;
    }
}
