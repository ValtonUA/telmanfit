Warning: truncated output (original token count: 13980)
Total output lines: 891

[CmdletBinding()]
param(
    [switch] $NoGitSync,
    [string] $ExpectedHead,
    [string] $AcknowledgeJournal,
    [switch] $IsolatedTestMode,
    [string] $TestProjectName,
    [int] $TestPort,
    [string] $TestComposeOverride
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$script:ProjectName = 'telmanfit-local'
$script:ProjectPrefix = 'telmanfit-local'
$script:PublishedPort = '8098'
$script:TestMode = $false

function Initialize-IsolationMode {
    if (-not $IsolatedTestMode) {
        if ($TestProjectName -or $TestPort -or $TestComposeOverride) { throw 'Test-only parameters require -IsolatedTestMode.' }
        return
    }
    if (-not $NoGitSync -or -not $ExpectedHead) { throw 'Isolated mode requires -NoGitSync and -ExpectedHead.' }
    $context = New-IsolatedTestContext -ProjectName $TestProjectName -Port $TestPort
    if (-not $TestComposeOverride) { throw 'Isolated mode requires a test Compose override.' }
    $tempRoot = [IO.Path]::GetFullPath((Join-Path ([IO.Path]::GetTempPath()) 'TelmanFit-E2E')).TrimEnd('\')
    $override = [IO.Path]::GetFullPath($TestComposeOverride)
    if (-not $override.StartsWith($tempRoot + '\', [StringComparison]::OrdinalIgnoreCase)) {
        throw 'Isolated Compose override must be inside the private TelmanFit-E2E temp directory.'
    }
    if (-not (Test-Path -LiteralPath $override -PathType Leaf)) { throw 'Isolated Compose override does not exist.' }
    $script:ProjectName = $context.project
    $script:ProjectPrefix = $context.project
    $script:PublishedPort = [string] $context.port
    $env:TELMANFIT_LOCAL_WEB_PORT = $script:PublishedPort
    $script:TestMode = $true
}

function Get-ContainerName {
    param([Parameter(Mandatory)][string] $Service)
    return "$($script:ProjectName)-$Service-1"
}

function Get-VolumeName {
    param([Parameter(Mandatory)][string] $Volume)
    return "$($script:ProjectName)_$Volume"
}

function Get-ImageName {
    param([Parameter(Mandatory)][string] $Service)
    if ($script:TestMode) { return "$($script:ProjectName)-$Service" }
    return "telmanfit-local-$Service"
}

function New-IsolatedTestContext {
    param([Parameter(Mandatory)][string] $ProjectName, [Parameter(Mandatory)][int] $Port)
    if ($ProjectName -cnotmatch '^telmanfit-e2e-[a-f0-9]{12}$' -or $ProjectName -ceq 'telmanfit-local') {
        throw 'Ізольований проєкт має формат telmanfit-e2e-<12 lowercase hex>.'
    }
    if ($Port -lt 20000 -or $Port -gt 59999 -or $Port -eq 8098) { throw 'Ізольований порт має бути в діапазоні 20000..59999.' }
    $kinds = @('database', 'database-secrets', 'local-state', 'epoch', 'storage')
    $workingVolumes = @($kinds | ForEach-Object { "telmanfit-local_$_" })
    $testVolumes = @($kinds | ForEach-Object { "${ProjectName}_$_" })
    if (@($testVolumes | Where-Object { $_ -in $workingVolumes }).Count -ne 0) { throw 'Ізольований volume name зіставився з робочим TelmanFit volume.' }
    $services = @('setup', 'database', 'app', 'worker', 'scheduler', 'web')
    $imageNames = @('app', 'web', 'database')
    [pscustomobject]@{
        project = $ProjectName
        port = $Port
        containers = @($services | ForEach-Object { "$ProjectName-$_-1" })
        volumes = $testVolumes
        images = @($imageNames | ForEach-Object { "$ProjectName-$_" })
        backup_root = Join-Path (Join-Path ([IO.Path]::GetTempPath()) 'TelmanFit-E2E') $ProjectName
    }
}

function Get-IsolatedTestResourceNames {
    param([Parameter(Mandatory)][string] $ProjectName)
    $services = @('setup', 'database', 'app', 'worker', 'scheduler', 'web')
    [pscustomobject]@{ containers = @($services | ForEach-Object { "$ProjectName-$_-1" }) }
}

function Write-UpdateLog {
    param([Parameter(Mandatory)][string] $Message)

    $line = '{0} {1}' -f (Get-Date).ToUniversalTime().ToString('o'), $Message
    if ($script:LogPath) {
        Add-Content -LiteralPath $script:LogPath -Value $line -Encoding utf8
    }
    Write-Host $line
}

function Invoke-Native {
    param(
        [Parameter(Mandatory)][string] $Command,
        [Parameter(Mandatory)][string[]] $Arguments,
        [switch] $AllowFailure
    )

    $output = @(& $Command @Arguments 2>&1)
    $exitCode = $LASTEXITCODE
    if ($exitCode -ne 0 -and -not $AllowFailure) {
        $safeOutput = ($output | ForEach-Object { [string] $_ }) -join [Environment]::NewLine
        throw "$Command failed with exit code $exitCode. $safeOutput"
    }
    return [pscustomobject]@{ ExitCode = $exitCode; Output = $output }
}

function Invoke-Git {
    param([Parameter(Mandatory)][string[]] $Arguments, [switch] $AllowFailure)
    return Invoke-Native -Command 'git' -Arguments $Arguments -AllowFailure:$AllowFailure
}

function Invoke-Docker {
    param([Parameter(Mandatory)][string[]] $Arguments, [switch] $AllowFailure)
    return Invoke-Native -Command 'docker' -Arguments $Arguments -AllowFailure:$AllowFailure
}

function Get-OutputText {
    param([Parameter(Mandatory)] $Result)
    return (@($Result.Output | ForEach-Object { [string] $_ }) -join [Environment]::NewLine).Trim()
}

function Invoke-Compose {
    param([Parameter(Mandatory)][string[]] $Arguments, [switch] $AllowFailure)
    return Invoke-Docker -Arguments (@('compose') + $script:ComposeArguments + $Arguments) -AllowFailure:$AllowFailure
}

function Set-PrivateDirectoryAcl {
    param([Parameter(Mandatory)][string] $Path)

    New-Item -ItemType Directory -Path $Path -Force | Out-Null
    $sid = [System.Security.Principal.WindowsIdentity]::GetCurrent().User.Value
    $null = Invoke-Native -Command 'icacls' -Arguments @($Path, '/inheritance:r', '/grant:r', "*${sid}:(OI)(CI)F", '*S-1-5-18:(OI)(CI)F')
}

function Enter-UpdateLock {
    param([Parameter(Mandatory)][string] $LockPath)

    New-Item -ItemType Directory -Path (Split-Path -Parent $LockPath) -Force | Out-Null
    try {
        $stream = [System.IO.File]::Open($LockPath, [System.IO.FileMode]::OpenOrCreate, [System.IO.FileAccess]::ReadWrite, [System.IO.FileShare]::None)
    } catch {
        throw "Інший local-update процес уже працює або lock недоступний: $LockPath"
    }
    $stream.SetLength(0)
    $bytes = [Text.Encoding]::UTF8.GetBytes("pid=$PID`nstarted_utc=$((Get-Date).ToUniversalTime().ToString('o'))`n")
    $stream.Write($bytes, 0, $bytes.Length)
    $stream.Flush()
    return $stream
}

function Test-ExpectedRemote {
    param([Parameter(Mandatory)][string] $Remote)

    return $Remote -match '^(https://github\.com/ValtonUA/telmanfit(?:\.git)?|git@github\.com:ValtonUA/telmanfit\.git)$'
}

function Assert-SafeGitTree {
    param([Parameter(Mandatory)][string] $Root)

    $status = Invoke-Git -Arguments @('-C', $Root, 'status', '--porcelain=v1', '--untracked-files=all')
    foreach ($lineObject in $status.Output) {
        $line = [string] $lineObject
        if ($line -eq '?? compose.r2-runtime.yaml') {
            continue
        }
        if ($line.Trim()) {
            throw "Робоче дерево має локальні зміни; нічого не буде перезаписано: $line"
        }
    }
}

function Get-Allowlist {
    param([Parameter(Mandatory)][string] $Root)

    $path = Join-Path $Root 'scripts/local-migration-allowlist.json'
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
        throw 'Migration allowlist відсутній.'
    }
    $config = Get-Content -LiteralPath $path -Raw | ConvertFrom-Json
    if ($config.version -ne 1 -or $config.postgres_major -ne 18 -or @($config.migrations).Count -eq 0) {
        throw 'Migration allowlist має невідому версію або порожній перелік.'
    }
    $names = @{}
    $orders = @{}
    foreach ($item in $config.migrations) {
        if ($item.name -notmatch '^\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+$' -or $item.sha256 -notmatch '^[A-Fa-f0-9]{64}$') {
            throw 'Migration allowlist містить неправильну назву або SHA256.'
        }
        if ($names.ContainsKey($item.name) -or $orders.ContainsKey([int] $item.order)) {
            throw 'Migration allowlist має повтор назви або порядку.'
        }
        $names[$item.name] = $item
        $orders[[int] $item.order] = $true
    }
    return $config
}

function Get-PendingPlan {
    param(
        [Parameter(Mandatory)] $Snapshot,
        [Parameter(Mandatory)] $Allowlist,
        [Parameter(Mandatory)][string] $Root
    )

    $migrationByName = @{}
    foreach ($migration in $Allowlist.migrations) { $migrationByName[$migration.name] = $migration }
    $plan = @()
    foreach ($name in @($Snapshot.migrations.pending)) {
        if (-not $migrationByName.ContainsKey($name)) {
            throw "Міграція '$name' не має явного дозволу; upgrade зупинено до зупинки сервісів."
        }
        $migration = $migrationByName[$name]
        $file = Join-Path $Root "database/migrations/$name.php"
        if (-not (Test-Path -LiteralPath $file -PathType Leaf)) {
            throw "Файл allowlisted міграції '$name' відсутній."
        }
        $actualHash = (Get-FileHash -LiteralPath $file -Algorithm SHA256).Hash
        if ($actualHash -ne $migration.sha256) {
            throw "SHA256 міграції '$name' не збігається з allowlist."
        }
        foreach ($source in @($migration.sources)) {
            if ($source.path -notmatch '^database/(?:sql|migrations)/[A-Za-z0-9_./-]+$' -or $source.path.Contains('..') -or $source.sha256 -notmatch '^[A-Fa-f0-9]{64}$') {
                throw "Allowlist для '$name' містить некоректний dependency hash."
            }
            $sourceFile = Join-Path $Root ($source.path -replace '/', [IO.Path]::DirectorySeparatorChar)
            if (-not (Test-Path -LiteralPath $sourceFile -PathType Leaf) -or (Get-FileHash -LiteralPath $sourceFile -Algorithm SHA256).Hash -cne $source.sha256) {
                throw "SHA256 залежності '$($source.path)' для '$name' не збігається з allowlist."
            }
        }
        $plan += $migration
    }

    $plan = @($plan | Sort-Object { [int] $_.order })
    $pendingNames = @($Snapshot.migrations.pending)
    $plannedNames = @($plan | ForEach-Object { $_.name })
    if (($pendingNames -join "`n") -cne ($plannedNames -join "`n")) {
        throw 'Pending-міграції не є точним впорядкованим піднабором allowlist.'
    }
    return ,$plan
}

function Assert-MigrationPreconditions {
    param([Parameter(Mandatory)] $Snapshot, [Parameter(Mandatory)] $Migration, [string[]] $PlannedTables = @())

    $tables = @($Snapshot.schema.tables)
    foreach ($name in @($Migration.preconditions.required_tables)) {
        if ($name -notin $tables -and $name -notin $PlannedTables) { throw "Передумова міграції $($Migration.name) відсутня: таблиця $name." }
    }
    foreach ($name in @($Migration.preconditions.absent_tables)) {
        if ($name -in $tables) { throw "Передумова міграції $($Migration.name) не виконана: таблиця $name вже існує." }
    }
}

function Assert-MigrationPostconditions {
    param([Parameter(Mandatory)] $Snapshot, [Parameter(Mandatory)] $Migration)

    foreach ($kind in @('required_tables', 'required_functions', 'required_triggers', 'required_views')) {
        $required = @($Migration.postconditions.$kind)
        if (-not $required.Count) { continue }
        $actual = switch ($kind) {
            'required_tables' { @($Snapshot.schema.tables) }
            'required_functions' { @($Snapshot.schema.functions) }
            'required_triggers' { @($Snapshot.schema.triggers) }
            'required_views' { @($Snapshot.schema.views) }
        }
        foreach ($name in $required) {
            if ($name -notin $actual) { throw "Postcondition міграції $($Migration.name) не виконана: $kind '$name' відсутня." }
        }
    }
}

function Assert-SnapshotUnchanged {
    param([Parameter(Mandatory)] $Before, [Parameter(Mandatory)] $After)

    if ($Before.restore_fence -ne 'PASS' -or $After.restore_fence -ne 'PASS') {
        throw 'RestoreFence не пройшов до або після міграцій.'
    }
    if ($Before.catalog.rows -ne $After.catalog.rows -or $Before.catalog.urls -ne $After.catalog.urls -or $Before.catalog.fingerprint -cne $After.catalog.fingerprint) {
        throw 'Змінився каталог або його image_url під час міграцій.'
    }
    foreach ($table in $Before.tables.PSObject.Properties) {
        if ($table.Name -eq 'migrations') { continue }
        $afterTable = $After.tables.PSObject.Properties[$table.Name]
        if (-not $afterTable -or $table.Value.rows -ne $afterTable.Value.rows -or $table.Value.fingerprint -cne $afterTable.Value.fingerprint) {
            throw "Змінилися рядки наявної таблиці '$($table.Name)' під час міграцій."
        }
    }
}

function Assert-ComposeConfig {
    param([Parameter(Mandatory)] $Config)

    if ($Config.name -cne $script:ProjectName) { throw "Compose project name має бути '$script:ProjectName'." }
    $expectedServices = @('setup', 'database', 'app', 'worker', 'scheduler', 'web')
    $serviceNames = @($Config.services.PSObject.Properties.Name | Sort-Object)
    if (($serviceNames -join ',') -cne (($expectedServices | Sort-Object) -join ',')) {
        throw 'Compose service inventory не збігається з локальним TelmanFit.'
    }
    $expectedVolumes = @('database', 'database-secrets', 'epoch', 'local-state', 'storage')
    $volumeNames = @($Config.volumes.PSObject.Properties.Name | Sort-Object)
    if (($volumeNames -join ',') -cne (($expectedVolumes | Sort-Object) -join ',')) {
        throw 'Compose volume inventory не збігається з локальним TelmanFit.'
    }
    $databaseService = $Config.services.PSObject.Properties['database'].Value
    $databasePorts = $databaseService.PSObject.Properties['ports']
    if ($databasePorts -and @($databasePorts.Value).Count -ne 0) { throw 'PostgreSQL не повинен мати опублікованого порту.' }
    $webService = $Config.services.PSObject.Properties['web'].Value
    $webPortsProperty = $webService.PSObject.Properties['ports']
    if (-not $webPortsProperty) { throw 'Web service має містити один опублікований loopback port.' }
    $webPorts = @($webPortsProperty.Value)
    if ($webPorts.Count -ne 1 -or [string]$webPorts[0].published -ne $script:PublishedPort -or $webPorts[0].host_ip -ne '127.0.0.1') {
        throw 'Web port не відповідає loopback-only конфігурації.'
    }
    if ($script:TestMode) {
        if ($Config.networks.local.internal -ne $true) { throw 'Ізольована Compose network має бути internal.' }
        foreach ($service in @('setup', 'app', 'worker', 'scheduler', 'web', 'database')) {
            $expectedImageService = if ($service -in @('setup', 'app', 'worker', 'scheduler')) { 'app' } else { $service }
            if ($Config.services.$service.image -cne (Get-ImageName -Service $expectedImageService)) { throw "Ізольований образ сервісу $service має неунікальне ім’я." }
        }
    }
    foreach ($service in @('app', 'worker', 'scheduler')) {
        $sources = @($Config.services.$service.volumes | ForEach-Object { $_.source } | Sort-Object)
        if (($sources -join ',') -cne 'epoch,local-state,storage') { throw "Volumes сервісу $service не збігаються з очікуваними." }
    }
    $databaseSources = @($Config.services.database.volumes | ForEach-Object { $_.source } | Sort…5980 tokens truncated…th]::GetFullPath($actualRoot).TrimEnd('\') -ine $root) { throw 'Скрипт має запускатися з кореня репозиторію.' }

    if (-not $env:LOCALAPPDATA) { throw 'LOCALAPPDATA не визначено; coordinator потребує локального приватного сховища backup/log.' }
    $stateRoot = if ($script:TestMode) { Join-Path (Join-Path $env:TEMP 'TelmanFit-E2E') $script:ProjectName } else { Join-Path $env:LOCALAPPDATA 'TelmanFit' }
    if ($script:TestMode -and (Test-Path -LiteralPath $stateRoot)) { throw 'Ізольований backup/log каталог уже існує; відмова використовувати чи перезаписувати його.' }
    Set-PrivateDirectoryAcl -Path $stateRoot
    $lockStream = Enter-UpdateLock -LockPath (Join-Path $stateRoot 'local-update.lock')
    $script:RunId = '{0}-{1}' -f (Get-Date).ToUniversalTime().ToString('yyyyMMddTHHmmssZ'), ([guid]::NewGuid().ToString('N').Substring(0, 8))
    $script:LogPath = Join-Path (Join-Path $stateRoot 'updates') "$script:RunId.log"
    $script:JournalPath = Join-Path (Join-Path $stateRoot 'updates') "$script:RunId.json"
    Set-PrivateDirectoryAcl -Path (Split-Path -Parent $script:LogPath)
    $script:Phase = 'preflight'
    $script:BackupDirectory = $null
    $script:RepositoryHead = $null

    try {
        try {
            Assert-NoUnresolvedJournals -Directory (Split-Path -Parent $script:JournalPath) -AcknowledgedRunId $AcknowledgeJournal
        } catch {
            $script:Phase = 'journal_gate_blocked'
            throw
        }
        Write-UpdateLog "local update started; target=$script:ProjectName; isolated_test=$($script:TestMode.ToString().ToLowerInvariant()); no production credentials are read"
        $remote = Get-OutputText (Invoke-Git -Arguments @('-C', $root, 'remote', 'get-url', 'origin'))
        if (-not (Test-ExpectedRemote -Remote $remote)) { throw 'Git origin не відповідає ValtonUA/telmanfit.' }
        Assert-SafeGitTree -Root $root

        if ($NoGitSync) {
            $head = Get-OutputText (Invoke-Git -Arguments @('-C', $root, 'rev-parse', 'HEAD'))
            if ($ExpectedHead -and $head -cne $ExpectedHead) { throw 'HEAD змінився після синхронізації Git.' }
        } else {
            $branch = Get-OutputText (Invoke-Git -Arguments @('-C', $root, 'branch', '--show-current'))
            if ($branch -ne 'master') { $null = Invoke-Git -Arguments @('-C', $root, 'switch', 'master') }
            $scriptHashBefore = (Get-FileHash -LiteralPath $PSCommandPath -Algorithm SHA256).Hash
            $null = Invoke-Git -Arguments @('-C', $root, 'fetch', 'origin', 'master')
            $null = Invoke-Git -Arguments @('-C', $root, 'pull', '--ff-only', 'origin', 'master')
            Assert-SafeGitTree -Root $root
            $script:RepositoryHead = Get-OutputText (Invoke-Git -Arguments @('-C', $root, 'rev-parse', 'HEAD'))
            $scriptHashAfter = (Get-FileHash -LiteralPath $PSCommandPath -Algorithm SHA256).Hash
            if ($scriptHashBefore -cne $scriptHashAfter) {
                throw 'Сам coordinator оновився під час Git pull. Повторіть команду, щоб виконати нову перевірену версію.'
            }
        }
        if (-not $script:RepositoryHead) { $script:RepositoryHead = Get-OutputText (Invoke-Git -Arguments @('-C', $root, 'rev-parse', 'HEAD')) }
        Write-UpdateLog "git_head=$script:RepositoryHead"

        if (Test-Path -LiteralPath (Join-Path $root 'compose.r2-runtime.yaml')) {
            $status = Invoke-Git -Arguments @('-C', $root, 'status', '--porcelain=v1', '--untracked-files=all')
            $overlayStatus = @($status.Output | ForEach-Object { [string] $_ } | Where-Object { $_ -eq '?? compose.r2-runtime.yaml' })
            if ($overlayStatus.Count -gt 0) { Write-UpdateLog 'preserved untracked compose.r2-runtime.yaml; base compose.yaml remains selected' }
        }

        $context = Get-OutputText (Invoke-Docker -Arguments @('context', 'show'))
        if ($context -ne 'desktop-linux' -or (Get-OutputText (Invoke-Docker -Arguments @('info', '--format', '{{.OSType}}'))) -ne 'linux') {
            throw 'Docker Desktop desktop-linux/Linux containers не доступні.'
        }
        $script:ComposeArguments = @('-p', $script:ProjectName, '--project-directory', $root, '-f', (Join-Path $root 'compose.yaml'))
        if ($script:TestMode) { $script:ComposeArguments += @('-f', [IO.Path]::GetFullPath($TestComposeOverride)) }
        $composeConfig = Get-SafeComposeConfig -Root $root
        Assert-TestProjectDisjoint
        $databaseContainer = Assert-LocalProject -Root $root
        $script:DatabaseImage = $databaseContainer.Image
        $appInspect = Invoke-Docker -Arguments @('inspect', (Get-ContainerName app), '--format', '{{.Image}}') -AllowFailure
        $script:AppImage = if ($appInspect.ExitCode -eq 0) { Get-OutputText $appInspect } elseif ($script:TestMode) { Get-OutputText (Invoke-Docker -Arguments @('image', 'inspect', (Get-ImageName app), '--format', '{{.Id}}')) } else { $null }
        if (-not $script:AppImage) { throw 'Не вдалося визначити локальний app image.' }

        Set-Phase -Name 'build' -State 'started'
        $null = Invoke-Compose -Arguments @('build', 'app', 'web')
        Set-Phase -Name 'build' -State 'complete'
        $script:AppImage = Get-OutputText (Invoke-Docker -Arguments @('image', 'inspect', (Get-ImageName app), '--format', '{{.Id}}'))

        Set-Phase -Name 'identity_and_migrations' -State 'started'
        $before = Get-DatabaseSnapshot
        if ($before.status -ne 'PASS' -or $before.identity.database -notmatch '^telmanfit_local_[a-f0-9]{16}$' -or $before.identity.postgres_major -ne 18 -or $before.restore_fence -ne 'PASS') {
            throw 'Preflight DB identity або RestoreFence не пройшли.'
        }
        $script:DatabaseName = $before.identity.database
        $allowlist = Get-Allowlist -Root $root
        $plan = Get-PendingPlan -Snapshot $before -Allowlist $allowlist -Root $root
        if ($plan.Count -eq 0) {
            Write-UpdateLog 'pending_migrations=0 backup=skipped'
            Set-Phase -Name 'start_services' -State 'started'
            $null = Invoke-Compose -Arguments @('up', '-d', '--no-deps', 'app', 'worker', 'scheduler', 'web')
            Set-Phase -Name 'start_services' -State 'complete'
        } else {
            $plannedTables = @()
            foreach ($migration in $plan) {
                Assert-MigrationPreconditions -Snapshot $before -Migration $migration -PlannedTables $plannedTables
                $plannedTables += @($migration.postconditions.required_tables)
            }
            $backupRoot = Join-Path $stateRoot 'backups'
            Set-PrivateDirectoryAcl -Path $backupRoot
            $script:BackupDirectory = Join-Path $backupRoot "$script:RunId-$($script:RepositoryHead.Substring(0, 8))"
            if (Test-Path -LiteralPath $script:BackupDirectory) { throw 'Backup destination already exists; refusing overwrite.' }
            New-Item -ItemType Directory -Path $script:BackupDirectory | Out-Null
            Set-PrivateDirectoryAcl -Path $script:BackupDirectory

            Set-Phase -Name 'stop_writers' -State 'started'
            $null = Invoke-Compose -Arguments @('stop', '--timeout', '60', 'app', 'worker', 'scheduler', 'web')
            Assert-WritersStopped
            Set-Phase -Name 'stop_writers' -State 'complete'

            $dumpName = "postgres-$script:RunId.dump"
            $containerDump = "/tmp/$dumpName"
            Set-Phase -Name 'backup' -State 'started'
            $null = Invoke-Compose -Arguments @('exec', '-T', 'database', 'pg_dump', '--format=custom', '--no-owner', '--no-privileges', '-U', 'postgres', '-d', $script:DatabaseName, '-f', $containerDump)
            $dumpPath = Join-Path $script:BackupDirectory 'postgresql.dump'
            $null = Invoke-Docker -Arguments @('cp', "$(Get-ContainerName database):$containerDump", $dumpPath)
            $tocResult = Invoke-Compose -Arguments @('exec', '-T', 'database', 'pg_restore', '--list', $containerDump)
            if (@($tocResult.Output).Count -lt 10) { throw 'pg_restore не прочитав custom-format backup TOC.' }
            $null = Invoke-Compose -Arguments @('exec', '-T', 'database', 'rm', '-f', $containerDump)
            $dumpMetadata = Assert-BackupMetadata -Path $dumpPath

            $null = Invoke-Compose -Arguments @('stop', '--timeout', '60', 'database')
            foreach ($volume in @('database', 'database-secrets', 'local-state', 'epoch', 'storage')) {
                $volumeName = Get-VolumeName $volume
                $null = Invoke-Docker -Arguments @('volume', 'inspect', $volumeName)
                Invoke-TarArchive -DatabaseImage $script:DatabaseImage -Volume $volumeName -BackupDirectory $script:BackupDirectory -Archive "$volume.tar"
                Invoke-TarArchive -DatabaseImage $script:DatabaseImage -Volume $volumeName -BackupDirectory $script:BackupDirectory -Archive "$volume.tar" -Compare
            }

            $volumeManifest = @()
            foreach ($volume in @('database', 'database-secrets', 'local-state', 'epoch', 'storage')) {
                $archive = "$volume.tar"
                $metadata = Assert-BackupMetadata -Path (Join-Path $script:BackupDirectory $archive)
                $entries = Get-ArchiveInventory -DatabaseImage $script:DatabaseImage -BackupDirectory $script:BackupDirectory -Archive $archive
                $volumeManifest += [ordered]@{ volume = (Get-VolumeName $volume); archive = $archive; bytes = $metadata.bytes; sha256 = $metadata.sha256; entries = $entries }
            }
            $manifest = [ordered]@{
                format_version = 1
                run_id = $script:RunId
                created_utc = (Get-Date).ToUniversalTime().ToString('o')
                project = $script:ProjectName
                source_sha = $script:RepositoryHead
                database = $script:DatabaseName
                postgres_major = 18
                database_identity = $before.identity.cluster_proof
                app_image = $script:AppImage
                database_image = $script:DatabaseImage
                dump = [ordered]@{ archive = 'postgresql.dump'; bytes = $dumpMetadata.bytes; sha256 = $dumpMetadata.sha256; toc_entries = @($tocResult.Output).Count }
                volumes = $volumeManifest
                pre_upgrade = [ordered]@{ catalog_rows = $before.catalog.rows; image_urls = $before.catalog.urls; catalog_fingerprint = $before.catalog.fingerprint; migration_count = @($before.migrations.ran).Count; pending = @($before.migrations.pending) }
                verification = [ordered]@{ tar_compare = 'PASS'; pg_restore_list = 'PASS'; restore_rehearsal = 'pending' }
            }
            $manifestPath = Join-Path $script:BackupDirectory 'manifest.json'
            $manifest | ConvertTo-Json -Depth 12 | Set-Content -LiteralPath $manifestPath -Encoding utf8
            $sid = [System.Security.Principal.WindowsIdentity]::GetCurrent().User.Value
            $null = Invoke-Native -Command 'icacls' -Arguments @($manifestPath, '/inheritance:r', '/grant:r', "*${sid}:F", '*S-1-5-18:F')
            Set-Phase -Name 'backup' -State 'archives_complete'

            Invoke-BackupRestoreRehearsal -BackupDirectory $script:BackupDirectory -DatabaseImage $script:DatabaseImage -BeforeSnapshot $before -CustomDump $dumpPath
            $manifest.verification.restore_rehearsal = 'PASS'
            $manifest | ConvertTo-Json -Depth 12 | Set-Content -LiteralPath $manifestPath -Encoding utf8
            Set-Phase -Name 'backup' -State 'complete'

            Set-Phase -Name 'restart_database' -State 'started'
            Start-LocalDatabase
            $afterDatabase = Get-DatabaseSnapshot
            Assert-SnapshotUnchanged -Before $before -After $afterDatabase
            Set-Phase -Name 'restart_database' -State 'complete'

            $current = $afterDatabase
            foreach ($migration in $plan) {
                Assert-MigrationPreconditions -Snapshot $current -Migration $migration
                Set-Phase -Name "migration_$($migration.name)" -State 'started'
                $null = Invoke-Compose -Arguments @('run', '--rm', '--no-deps', '--user', 'www-data', '--entrypoint', 'php', 'app', '-d', 'auto_prepend_file=/var/www/html/docker/local/load-env.php', 'artisan', 'migrate', '--path=database/migrations/'+$migration.name+'.php', '--force', '--no-interaction')
                Set-Phase -Name "migration_$($migration.name)" -State 'command_complete'
                $current = Get-DatabaseSnapshot
                if ($migration.name -notin @($current.migrations.ran)) { throw "Міграція $($migration.name) не записана у migrations." }
                Assert-MigrationPostconditions -Snapshot $current -Migration $migration
                Set-Phase -Name "migration_$($migration.name)" -State 'complete'
            }
            if (@($current.migrations.pending).Count -ne 0) { throw 'Після allowlisted міграцій залишилися pending migration files.' }
            Assert-SnapshotUnchanged -Before $before -After $current
            $manifest.verification.post_migration_snapshot = 'PASS'
            $manifest | ConvertTo-Json -Depth 12 | Set-Content -LiteralPath $manifestPath -Encoding utf8

            Set-Phase -Name 'start_services' -State 'started'
            $null = Invoke-Compose -Arguments @('up', '-d', '--no-deps', 'app', 'worker', 'scheduler', 'web')
            Set-Phase -Name 'start_services' -State 'started_waiting_health'
        }

        $deadline = (Get-Date).AddMinutes(3)
        $expected = @{
            'database' = 'healthy'
            'app' = 'healthy'
            'web' = 'healthy'
            'worker' = 'running'
            'scheduler' = 'running'
        }
        do {
            $states = @{}
            foreach ($service in $expected.Keys) {
                $name = Get-ContainerName $service
                $format = if ($service -in @('database', 'app', 'web')) { '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' } else { '{{.State.Status}}' }
                $result = Invoke-Docker -Arguments @('inspect', '--format', $format, $name) -AllowFailure
                $states[$service] = if ($result.ExitCode -eq 0) { Get-OutputText $result } else { 'missing' }
            }
            $allReady = $true
            foreach ($service in $expected.Keys) { if ($states[$service] -ne $expected[$service]) { $allReady = $false } }
            if ($allReady) { break }
            Start-Sleep -Seconds 3
        } while ((Get-Date) -lt $deadline)
        foreach ($service in $expected.Keys) {
            if ($states[$service] -ne $expected[$service]) { throw "Health gate: $service state=$($states[$service]); expected=$($expected[$service])." }
        }
        Set-Phase -Name 'complete' -State 'PASS'
        Write-UpdateLog "backup_directory=$script:BackupDirectory"
        Write-UpdateLog 'local update completed successfully'
    } catch {
        if ($script:Phase -eq 'journal_gate_blocked') {
            Write-UpdateLog "journal_gate_blocked=$($_.Exception.Message)"
            throw
        }
        Set-Phase -Name $(if ($script:Phase) { $script:Phase } else { 'unknown' }) -State 'FAILED'
        Write-UpdateLog "error=$($_.Exception.Message)"
        Write-UpdateLog 'Fail closed: no rollback, restore, volume removal, or service auto-resume was attempted.'
        throw
    } finally {
        if ($lockStream) { $lockStream.Dispose() }
    }
}

$libraryOnly = Get-Variable -Name LocalUpdateLibraryOnly -Scope Script -ValueOnly -ErrorAction SilentlyContinue
if ($MyInvocation.InvocationName -ne '.' -and -not $libraryOnly) {
    Invoke-LocalUpdate
}

