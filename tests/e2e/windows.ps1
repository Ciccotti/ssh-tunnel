# Teste de ponta a ponta do instalador do Windows (roda no GitHub Actions, numa VM
# descartável, como administrador): sobe o painel com SQLite, roda o comando de
# instalação exatamente como o usuário ("irm ... | iex") e confere se a tarefa
# agendada roda o cliente como SYSTEM, com a chave SSH utilizável, falando com o painel.
#
# Altera o sistema (tarefa agendada, C:\ProgramData): não rode na sua máquina.
$ErrorActionPreference = 'Stop'
Set-Location (Join-Path $PSScriptRoot '..\..')

$Panel = 'http://127.0.0.1:8000'
$InstallDir = Join-Path $env:ProgramData 'ssh-tunnel-client'
$Log = Join-Path $InstallDir 'client.log'

function Step($Message) { Write-Host "`n### $Message" -ForegroundColor Cyan }
function Fail($Message) {
    Write-Host "FALHOU: $Message" -ForegroundColor Red
    if (Test-Path $Log) { Write-Host '--- log do cliente'; Get-Content $Log }
    if (Test-Path 'storage\logs\laravel.log') { Write-Host '--- log do Laravel'; Get-Content 'storage\logs\laravel.log' -Tail 50 }
    exit 1
}

# Roda PHP com o Laravel carregado (evita passar aspas pela linha de comando do PowerShell 5.1)
function Invoke-Laravel($Code) {
    $file = Join-Path $env:TEMP 'ssh-tunnel-e2e.php'
    # UTF-8 sem BOM: o BOM sairia na saída do PHP, antes do valor
    [IO.File]::WriteAllText($file, @"
<?php
require 'vendor/autoload.php';
`$app = require 'bootstrap/app.php';
`$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$Code
"@)
    $output = & php $file
    if ($LASTEXITCODE -ne 0) { Fail "erro no PHP: $output" }
    return ($output | Out-String).Trim()
}

function New-InstallToken {
    return Invoke-Laravel '[, $token] = App\Models\EnrollmentToken::issue(App\Models\Client::firstOrFail()); echo $token;'
}

Step 'Sobe o painel'
$server = Start-Process -FilePath php -WorkingDirectory 'public' -PassThru -WindowStyle Hidden `
    -ArgumentList '-S', '127.0.0.1:8000', '..\vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php'
foreach ($i in 1..30) {
    try { Invoke-WebRequest -UseBasicParsing "$Panel/up" | Out-Null; break } catch { Start-Sleep 1 }
}
Invoke-Laravel '$user = App\Models\User::factory()->create(); App\Models\Client::create(["user_id" => $user->id, "name" => "Cliente E2E"]);' | Out-Null

Step 'Roda o comando de instalação (irm | iex)'
$token = New-InstallToken
Invoke-RestMethod "$Panel/instalar/$token/windows" | Invoke-Expression

$task = Get-ScheduledTask -TaskName 'ssh-tunnel-client' -ErrorAction SilentlyContinue
if (-not $task) { Fail 'a tarefa agendada não foi criada' }
if ($task.Principal.UserId -notin @('SYSTEM', 'S-1-5-18')) { Fail "a tarefa roda como '$($task.Principal.UserId)', não como SYSTEM" }
if (-not (Test-Path (Join-Path $InstallDir 'config.json'))) { Fail 'o config.json não foi criado' }

$machines = Invoke-Laravel 'echo App\Models\Machine::has("machineKey")->count();'
if ($machines -ne '1') { Fail "esperava 1 máquina com chave no painel, encontrei $machines" }

Step 'O cliente roda como SYSTEM e fala com o painel'
foreach ($i in 1..60) {
    if ((Test-Path $Log) -and (Select-String -Path $Log -Pattern 'Conectado ao painel' -Quiet)) { break }
    Start-Sleep 1
}
if (-not (Select-String -Path $Log -Pattern 'Conectado ao painel' -Quiet)) { Fail 'o cliente não se conectou ao painel' }
if (Select-String -Path $Log -Pattern 'consegue usar a chave' -Quiet) { Fail 'o ssh recusou as permissões da chave privada' }
if ((Get-ScheduledTask -TaskName 'ssh-tunnel-client').State -ne 'Running') { Fail 'a tarefa agendada não está rodando' }

# Só SYSTEM e Administradores acessam a chave
$acl = (Get-Acl (Join-Path $InstallDir 'id_ed25519')).Access | ForEach-Object { $_.IdentityReference.Translate([Security.Principal.SecurityIdentifier]).Value } | Sort-Object -Unique
if (Compare-Object $acl @('S-1-5-18', 'S-1-5-32-544')) { Fail "permissões inesperadas na chave: $($acl -join ', ')" }

Step 'Reinstalar com um novo comando mantém a mesma máquina'
Invoke-RestMethod "$Panel/instalar/$(New-InstallToken)/windows" | Invoke-Expression
$machines = Invoke-Laravel 'echo App\Models\Machine::count();'
if ($machines -ne '1') { Fail "a reinstalação criou outra máquina ($machines no total)" }
Start-Sleep 10
if ((Get-ScheduledTask -TaskName 'ssh-tunnel-client').State -ne 'Running') { Fail 'a tarefa não voltou a rodar depois da reinstalação' }

Write-Host '--- log do cliente'; Get-Content $Log
Stop-Process -Id $server.Id -Force
Step 'Tudo certo'
