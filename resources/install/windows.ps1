# Instalador do cliente ssh-tunnel para Windows, gerado pelo painel em
# "Instalar em Nova Máquina". O código abaixo vale para uma única máquina.
#
#   PowerShell como administrador:  irm <painel>/instalar/<código>/windows | iex
#
# Instala o cliente OpenSSH do Windows (se faltar) e um Python portátil oficial
# em C:\ProgramData\ssh-tunnel-client, registra esta máquina no painel e cria a
# tarefa agendada "ssh-tunnel-client", que roda o cliente como SYSTEM desde a
# inicialização do Windows.

# Tudo fica dentro de um bloco: com "irm | iex", um erro não fecha a janela do PowerShell
& {
    $ErrorActionPreference = 'Stop'
    $ProgressPreference = 'SilentlyContinue'  # a barra de progresso deixa o download muito lento no PowerShell 5.1
    [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12

    $PanelUrl = '__PANEL_URL__'
    $EnrollToken = '__TOKEN__'

    $InstallDir = Join-Path $env:ProgramData 'ssh-tunnel-client'
    $TaskName = 'ssh-tunnel-client'

    # Python portátil oficial (python.org), conferido pelo SHA-256 publicado pelo python.org
    $PythonVersion = '3.14.8'
    $PythonPackages = @{
        'AMD64' = 'a93abe456ab01bd96d7a085b3cdb6566b3063f4241360d114142fbdb07f0a310'
        'ARM64' = '155be84ccb57c6331cf0e39001c78a1dfac3be62f403f3ff5f2e29b80dda7ebe'
    }

    # SIDs em vez de nomes: "Administradores", "SISTEMA" etc. mudam com o idioma do Windows
    $System = '*S-1-5-18'
    $Administrators = '*S-1-5-32-544'

    function Info($Message) { Write-Host "==> $Message" -ForegroundColor Cyan }
    function Fail($Message) { throw "Erro: $Message" }

    # Prefere o curl.exe do Windows 10/11, bem mais rápido que o Invoke-WebRequest do PowerShell 5.1
    function Download($Url, $Path) {
        $curl = Join-Path $env:WINDIR 'System32\curl.exe'
        if (Test-Path $curl) {
            & $curl -fsSL --retry 3 -o $Path $Url
            if ($LASTEXITCODE -ne 0) { Fail "não foi possível baixar $Url." }
        } else {
            (New-Object Net.WebClient).DownloadFile($Url, $Path)
        }
    }

    # Confere se o arquivo baixado é mesmo o cliente em Python (e não, por exemplo, uma página de erro)
    function Test-PythonSource($Python, $Path) {
        & $Python -c 'import ast, sys; ast.parse(open(sys.argv[1], ''rb'').read())' $Path
        return $LASTEXITCODE -eq 0
    }

    try {
        $identity = [Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()
        if (-not $identity.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
            Fail 'abra o PowerShell como administrador e rode o comando de novo.'
        }

        # 1. Cliente OpenSSH do Windows
        $sshDir = Join-Path $env:WINDIR 'System32\OpenSSH'
        if (-not (Test-Path (Join-Path $sshDir 'ssh.exe'))) {
            Info 'Instalando o cliente OpenSSH do Windows...'
            Add-WindowsCapability -Online -Name 'OpenSSH.Client~~~~0.0.1.0' | Out-Null
            if (-not (Test-Path (Join-Path $sshDir 'ssh.exe'))) {
                Fail 'não foi possível instalar o cliente OpenSSH. Instale-o em Configurações > Aplicativos > Recursos opcionais.'
            }
        }

        # 2. Para uma instalação anterior antes de trocar os arquivos
        if (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue) {
            Stop-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
        }
        Get-Process -Name python -ErrorAction SilentlyContinue |
            Where-Object { $_.Path -like (Join-Path $InstallDir '*') } |
            Stop-Process -Force -ErrorAction SilentlyContinue

        # 3. Pasta de instalação, acessível só por SYSTEM e Administradores. Se ela já existia
        #    (talvez criada por outro usuário, que pode ter deixado arquivos lá), o dono passa a
        #    ser Administradores e as permissões de tudo dentro dela são refeitas antes do uso.
        New-Item -ItemType Directory -Force -Path $InstallDir | Out-Null
        & icacls.exe $InstallDir /setowner $Administrators /T /C /Q | Out-Null
        & icacls.exe $InstallDir /reset /T /C /Q | Out-Null
        & icacls.exe $InstallDir /inheritance:r /grant:r "${System}:(OI)(CI)F" "${Administrators}:(OI)(CI)F" | Out-Null
        if ($LASTEXITCODE -ne 0) { Fail "não foi possível ajustar as permissões de $InstallDir." }

        # 4. Python portátil: o zip fica guardado na pasta e é conferido pelo SHA-256 a cada
        #    instalação; o python.exe é sempre extraído de novo dele
        $arch = if ($env:PROCESSOR_ARCHITEW6432) { $env:PROCESSOR_ARCHITEW6432 } else { $env:PROCESSOR_ARCHITECTURE }
        if (-not $PythonPackages.ContainsKey($arch)) { Fail "arquitetura não suportada: $arch." }

        $zipName = "python-$PythonVersion-embed-$($arch.ToLower()).zip"
        $zip = Join-Path $InstallDir $zipName
        $expectedHash = $PythonPackages[$arch]
        if (-not (Test-Path $zip) -or (Get-FileHash -Algorithm SHA256 -Path $zip).Hash.ToLower() -ne $expectedHash) {
            Info "Baixando o Python $PythonVersion portátil (python.org)..."
            Get-ChildItem -Path $InstallDir -Filter 'python-*-embed-*.zip' | Remove-Item -Force
            Download "https://www.python.org/ftp/python/$PythonVersion/$zipName" $zip
            if ((Get-FileHash -Algorithm SHA256 -Path $zip).Hash.ToLower() -ne $expectedHash) {
                Remove-Item $zip -Force
                Fail "o arquivo $zipName baixado não confere com o SHA-256 esperado."
            }
        }

        $pythonDir = Join-Path $InstallDir 'python'
        $python = Join-Path $pythonDir 'python.exe'
        if (Test-Path $pythonDir) { Remove-Item $pythonDir -Recurse -Force }
        Expand-Archive -Path $zip -DestinationPath $pythonDir -Force

        # 5. Cliente
        Info 'Baixando o cliente...'
        $script = Join-Path $InstallDir 'script_cliente.py'
        Download "$PanelUrl/instalar/cliente.py" "$script.new"
        if (-not (Test-PythonSource $python "$script.new")) { Fail "o arquivo baixado de $PanelUrl/instalar/cliente.py não é o cliente." }
        Move-Item -Force "$script.new" $script

        # 6. Registro no painel: gera a chave SSH desta máquina e cadastra a máquina
        $config = Join-Path $InstallDir 'config.json'
        & $python $script --config $config --enroll $PanelUrl $EnrollToken
        if ($LASTEXITCODE -ne 0) { Fail 'não foi possível registrar a máquina no painel (veja a mensagem acima).' }

        # O ssh do Windows recusa a chave se outras contas puderem lê-la: o dono passa a ser
        # Administradores e o acesso fica só o herdado da pasta (SYSTEM e Administradores)
        $key = Join-Path $InstallDir 'id_ed25519'
        & icacls.exe $key /setowner $Administrators | Out-Null
        & icacls.exe $key /reset | Out-Null
        if ($LASTEXITCODE -ne 0) { Fail 'não foi possível ajustar as permissões da chave SSH.' }

        # 7. Tarefa agendada: roda como SYSTEM desde a inicialização. A repetição a
        #    cada 5 minutos só tem efeito se o cliente tiver parado (IgnoreNew).
        Info "Configurando a tarefa agendada $TaskName..."
        $log = Join-Path $InstallDir 'client.log'
        $action = New-ScheduledTaskAction -Execute $python -WorkingDirectory $InstallDir `
            -Argument "-u `"$script`" --config `"$config`" --log `"$log`""
        $triggers = @(
            (New-ScheduledTaskTrigger -AtStartup),
            (New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 5))
        )
        $principal = New-ScheduledTaskPrincipal -UserId 'S-1-5-18' -LogonType ServiceAccount -RunLevel Highest
        $settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable `
            -MultipleInstances IgnoreNew -ExecutionTimeLimit ([TimeSpan]::Zero) `
            -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1)
        Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $triggers -Principal $principal -Settings $settings `
            -Description 'Cliente ssh-tunnel: abre o túnel SSH reverso pedido no painel.' -Force | Out-Null
        Start-ScheduledTask -TaskName $TaskName

        Info 'Pronto! A máquina já aparece no painel e o cliente está rodando.'
        Write-Host "    Ver o log:     Get-Content '$log' -Wait"
        Write-Host "    Desinstalar:   Unregister-ScheduledTask -TaskName $TaskName -Confirm:`$false; Remove-Item '$InstallDir' -Recurse -Force"
    }
    catch {
        Write-Host $_.Exception.Message -ForegroundColor Red
    }
}
