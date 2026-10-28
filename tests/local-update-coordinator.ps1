#requires -Version 5.1
$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$script:LocalUpdateLibraryOnly = $true
$coordinatorSource = [IO.File]::ReadAllText((Join-Path $root 'scripts/local-update.ps1'))
$coordinatorSource = [regex]::Replace($coordinatorSource, '\A\[CmdletBinding\(\)\]\s*param\(.*?\)\s*', '', [Text.RegularExpressions.RegexOptions]::Singleline)
$coordinatorSource = $coordinatorSource.Replace('$PSScriptRoot', "'$(Join-Path $root 'scripts')'")
Invoke-Expression $coordinatorSource

$script:assertions = 0
function Assert-True {
    param([bool] $Condition, [string] $Message)
    $script:assertions++
    if (-not $Condition) { throw "FAIL: $Message" }
}

function Assert-Throws {
    param([scriptblock] $Action, [string] $Message)
    $script:assertions++
    try { & $Action } catch { return }
    throw "FAIL: expected refusal: $Message"
}

$defaultProject = $script:ProjectName
Assert-True ($defaultProject -eq 'telmanfit-local' -and (Get-ContainerName database) -eq 'telmanfit-local-database-1') 'ordinary coordinator defaults remain pinned to telmanfit-local'
Assert-Throws { New-IsolatedTestContext -ProjectName 'telmanfit-local' -Port 28981 } 'isolated mode refuses the working project name'
Assert-Throws { New-IsolatedTestContext -ProjectName 'telmanfit-e2e-not-a-guid' -Port 28981 } 'isolated mode requires a unique hexadecimal project suffix'
Assert-Throws { New-IsolatedTestContext -ProjectName 'telmanfit-e2e-0123456789ab' -Port 8098 } 'isolated mode refuses the working web port'
$isolated = New-IsolatedTestContext -ProjectName 'telmanfit-e2e-0123456789ab' -Port 28981
Assert-True ($isolated.project -eq 'telmanfit-e2e-0123456789ab') 'test Compose project uses its explicit unique namespace'
Assert-True (@($isolated.volumes | Where-Object { $_ -like 'telmanfit-local_*' }).Count -eq 0) 'test volumes cannot use working volume names'
Assert-True (@($isolated.images | Where-Object { $_ -like 'telmanfit-local-*' }).Count -eq 0) 'test images cannot replace working image tags'
Assert-True ($isolated.port -ne 8098 -and $isolated.backup_root -like '*TelmanFit-E2E*') 'test port and backup root are isolated'

$script:ProjectName = $isolated.project
$script:ProjectPrefix = $isolated.project
$script:PublishedPort = [string]$isolated.port
$script:TestMode = $true
Assert-True ((Get-ContainerName app) -eq "$($isolated.project)-app-1") 'test coordinator addresses only its app container'
Assert-True ((Get-VolumeName database) -eq "$($isolated.project)_database") 'test coordinator addresses only its database volume'
Assert-True ((Get-ImageName app) -eq "$($isolated.project)-app") 'test coordinator builds a unique app image tag'
Assert-Throws { Assert-ComposeConfig -Config ([pscustomobject]@{ name = 'telmanfit-local'; services = [pscustomobject]@{}; volumes = [pscustomobject]@{} }) } 'test config refuses effective project-name collision'
$script:ProjectName = 'telmanfit-local'
$script:ProjectPrefix = 'telmanfit-local'
$script:PublishedPort = '8098'
$script:TestMode = $false

$validComposeLabels = '{"com.docker.compose.project":"telmanfit-local","com.docker.compose.project.working_dir":"C:\\Projects\\telmanfit"}' | ConvertFrom-Json
Assert-True (Assert-ComposeProjectIdentity -Labels $validComposeLabels -ExpectedProject 'telmanfit-local' -ExpectedWorkingDirectory 'C:\Projects\telmanfit' -Resource 'synthetic container') 'valid dotted Docker labels confirm Compose project identity'
$labelsWithoutWorkingDirectory = '{"com.docker.compose.project":"telmanfit-local"}' | ConvertFrom-Json
$missingWorkingDirectoryError = $null
try { $null = Assert-ComposeProjectIdentity -Labels $labelsWithoutWorkingDirectory -ExpectedProject 'telmanfit-local' -ExpectedWorkingDirectory 'C:\Projects\telmanfit' -Resource 'synthetic container' } catch { $missingWorkingDirectoryError = $_.Exception.Message }
Assert-True ($missingWorkingDirectoryError -like "*com.docker.compose.project.working_dir*") 'missing working_dir label fails closed with a clear ownership error'
$labelsWithWrongProject = '{"com.docker.compose.project":"another-project","com.docker.compose.project.working_dir":"C:\\Projects\\telmanfit"}' | ConvertFrom-Json
Assert-Throws { Assert-ComposeProjectIdentity -Labels $labelsWithWrongProject -ExpectedProject 'telmanfit-local' -ExpectedWorkingDirectory 'C:\Projects\telmanfit' -Resource 'synthetic container' } 'wrong Compose project label is rejected'
$labelsWithWrongWorkingDirectory = '{"com.docker.compose.project":"telmanfit-local","com.docker.compose.project.working_dir":"C:\\Projects\\other"}' | ConvertFrom-Json
Assert-Throws { Assert-ComposeProjectIdentity -Labels $labelsWithWrongWorkingDirectory -ExpectedProject 'telmanfit-local' -ExpectedWorkingDirectory 'C:\Projects\telmanfit' -Resource 'synthetic container' } 'wrong Compose working_dir label is rejected'
$dictionaryLabels = @{
    'com.docker.compose.project' = 'telmanfit-local'
    'com.docker.compose.project.working_dir' = 'C:\Projects\telmanfit'
}
Assert-True (Assert-ComposeProjectIdentity -Labels $dictionaryLabels -ExpectedProject 'telmanfit-local' -ExpectedWorkingDirectory 'C:\Projects\telmanfit' -Resource 'synthetic hashtable container') 'dictionary-backed labels are read and validated safely'
$missingLabels = Get-DockerLabels -Inspect ([pscustomobject]@{ Config = [pscustomobject]@{} }) -Resource 'synthetic container' -ConfigLabels
Assert-Throws { Assert-ComposeProjectIdentity -Labels $missingLabels -ExpectedProject 'telmanfit-local' -ExpectedWorkingDirectory 'C:\Projects\telmanfit' -Resource 'synthetic container without Labels' } 'inspect object without Labels fails closed without PropertyNotFoundException'

function New-Snapshot {
    param([string[]] $Pending = @())
    $tables = [pscustomobject]@{
        users = [pscustomobject]@{ rows = 2; fingerprint = 'users-fingerprint' }
        user_totp_factors = [pscustomobject]@{ rows = 1; fingerprint = 'totp-fingerprint' }
        mfa_recovery_codes = [pscustomobject]@{ rows = 2; fingerprint = 'recovery-fingerprint' }
        migrations = [pscustomobject]@{ rows = 28; fingerprint = 'migration-fingerprint' }
    }
    [pscustomobject]@{
        status = 'PASS'
        restore_fence = 'PASS'
        identity = [pscustomobject]@{ database = 'telmanfit_local_0123456789abcdef'; postgres_major = 18 }
        migrations = [pscustomobject]@{ ran = @('old_migration'); pending = $Pending }
        schema = [pscustomobject]@{ tables = @('users', 'meal_plan_versions', 'meal_plan_days', 'meals', 'meal_items', 'recipe_versions', 'recipe_ingredients', 'recipe_batch_formats', 'recipe_batch_quantities', 'client_meal_plan_assignments', 'meal_plans', 'recipes'); functions = @(); triggers = @(); views = @() }
        tables = $tables
        catalog = [pscustomobject]@{ rows = 108; urls = 85; fingerprint = 'catalog-fingerprint' }
    }
}

$allowlist = Get-Allowlist -Root $root
Assert-True ($allowlist.migrations.Count -eq 2) 'allowlist contains only the two explicitly approved migrations'
Assert-True (($allowlist.migrations.name -join ',') -eq '2026_10_28_000000_create_cooking_templates,2026_10_28_010000_add_cooking_selection') 'allowlist contains the reviewed cooking migration names only'
$empty = New-Snapshot
$emptyPlan = Get-PendingPlan -Snapshot $empty -Allowlist $allowlist -Root $root
Assert-True ($emptyPlan.Count -eq 0) 'no pending migrations produce an empty plan'

$names = @($allowlist.migrations | Sort-Object order | ForEach-Object { $_.name })
$pendingSnapshot = New-Snapshot -Pending $names
$plan = Get-PendingPlan -Snapshot $pendingSnapshot -Allowlist $allowlist -Root $root
Assert-True ($plan.Count -eq 2 -and $plan[0].order -eq 1 -and $plan[1].order -eq 2) 'approved migrations are planned in declared order'

$unknownSnapshot = New-Snapshot -Pending @($names + '2026_10_29_000000_unknown_change')
Assert-Throws { Get-PendingPlan -Snapshot $unknownSnapshot -Allowlist $allowlist -Root $root } 'unknown migration is refused'

$changedFileAllowlist = $allowlist | ConvertTo-Json -Depth 20 | ConvertFrom-Json
$changedFileAllowlist.migrations[0].sha256 = ('0' * 64)
Assert-Throws { Get-PendingPlan -Snapshot $pendingSnapshot -Allowlist $changedFileAllowlist -Root $root } 'changed migration file is refused'

$changedSqlAllowlist = $allowlist | ConvertTo-Json -Depth 20 | ConvertFrom-Json
$changedSqlAllowlist.migrations[0].sources[0].sha256 = ('0' * 64)
Assert-Throws { Get-PendingPlan -Snapshot $pendingSnapshot -Allowlist $changedSqlAllowlist -Root $root } 'changed SQL dependency is refused'

$first = $allowlist.migrations[0]
Assert-MigrationPreconditions -Snapshot $pendingSnapshot -Migration $first
$badPreconditionSnapshot = New-Snapshot -Pending $names
$badPreconditionSnapshot.schema.tables = @('users')
Assert-Throws { Assert-MigrationPreconditions -Snapshot $badPreconditionSnapshot -Migration $first } 'missing prerequisite table is refused'
$second = $allowlist.migrations[1]
Assert-Throws { Assert-MigrationPreconditions -Snapshot $pendingSnapshot -Migration $second } 'later migration cannot assume an unapplied prerequisite'
Assert-MigrationPreconditions -Snapshot $pendingSnapshot -Migration $second -PlannedTables $first.postconditions.required_tables

$post = [pscustomobject]@{ schema = [pscustomobject]@{ tables = @('cooking_templates'); functions = @(); triggers = @(); views = @('cooking_output_balances') } }
$viewRequirement = [pscustomobject]@{ name = 'test'; postconditions = [pscustomobject]@{ required_tables = @('cooking_templates'); required_functions = @(); required_triggers = @(); required_views = @('cooking_output_balances') } }
Assert-MigrationPostconditions -Snapshot $post -Migration $viewRequirement
$post.schema.views = @()
Assert-Throws { Assert-MigrationPostconditions -Snapshot $post -Migration $viewRequirement } 'missing view is refused'
$optionalPostcondition = [pscustomobject]@{ name = 'test'; postconditions = [pscustomobject]@{ required_tables = @('cooking_templates') } }
Assert-MigrationPostconditions -Snapshot $post -Migration $optionalPostcondition

$before = New-Snapshot
$same = New-Snapshot
Assert-SnapshotUnchanged -Before $before -After $same
$same.catalog.urls = 84
Assert-Throws { Assert-SnapshotUnchanged -Before $before -After $same } 'catalog URL loss is detected'
$authChanged = New-Snapshot
$authChanged.tables.user_totp_factors.fingerprint = 'changed-totp'
Assert-Throws { Assert-SnapshotUnchanged -Before $before -After $authChanged } 'MFA data mutation is detected'
$fenceChanged = New-Snapshot
$fenceChanged.restore_fence = 'FAIL'
Assert-Throws { Assert-SnapshotUnchanged -Before $before -After $fenceChanged } 'RestoreFence failure is detected'

$testRoot = Join-Path ([IO.Path]::GetTempPath()) "telmanfit-coordinator-lock-$([guid]::NewGuid().ToString('N'))"
$lockPath = Join-Path $testRoot 'update.lock'
$lock = Enter-UpdateLock -LockPath $lockPath
try { Assert-Throws { Enter-UpdateLock -LockPath $lockPath } 'concurrent coordinator lock is rejected' } finally { $lock.Dispose() }
Remove-Item -LiteralPath $testRoot -Recurse -Force

$script:RunId = 'unit-test'
$script:RepositoryHead = 'synthetic'
$script:BackupDirectory = $null
$journalDirectory = Join-Path ([IO.Path]::GetTempPath()) "telmanfit-coordinator-journal-$([guid]::NewGuid().ToString('N'))"
$null = New-Item -ItemType Directory -Path $journalDirectory
$script:JournalPath = Join-Path $journalDirectory 'unit-test.json'
$script:LogPath = $null
Set-Phase -Name 'backup' -State 'started'
$journal = Get-Content -LiteralPath $script:JournalPath -Raw | ConvertFrom-Json
Assert-True ($journal.phase -eq 'backup' -and $journal.state -eq 'started') 'phase journal records an interrupted operation'
Assert-Throws { Assert-NoUnresolvedJournals -Directory $journalDirectory } 'interrupted journal blocks a new upgrade'
Assert-NoUnresolvedJournals -Directory $journalDirectory -AcknowledgedRunId 'unit-test'
Assert-NoUnresolvedJournals -Directory $journalDirectory
Remove-Item -LiteralPath $journalDirectory -Recurse -Force

Assert-Throws { Invoke-Native -Command 'cmd.exe' -Arguments @('/c', 'exit', '29') } 'backup command failure propagates as fatal error'
Assert-Throws { Invoke-Native -Command 'cmd.exe' -Arguments @('/c', 'exit', '30') } 'migration command failure propagates as fatal error'

$nativeTestRoot = Join-Path $root ".native-cli-test-$([guid]::NewGuid().ToString('N'))"
$bareRepository = Join-Path $nativeTestRoot 'remote.git'
$seedRepository = Join-Path $nativeTestRoot 'seed repo'
$nativeTestDirectory = Join-Path $nativeTestRoot 'client config'
$argvProbePath = Join-Path $nativeTestRoot 'argv-probe.exe'
$argvProbeSourcePath = Join-Path $nativeTestRoot 'argv-probe.cs'
$null = New-Item -ItemType Directory -Path $nativeTestRoot -Force
try {
    # Compile a native child that returns its actual argv as ASCII-only JSON. This
    # verifies ProcessStartInfo parsing, rather than only inspecting helper output.
    $argvProbeSource = @'
using System;
using System.Text;

public static class NativeArgvProbe
{
    private static void AppendJsonString(StringBuilder output, string value)
    {
        output.Append('"');
        foreach (char character in value)
        {
            if (character == '"') output.Append("\\\"");
            else if (character == '\\') output.Append("\\\\");
            else if (character < 0x20 || character > 0x7E)
                output.Append("\\u").Append(((int)character).ToString("x4"));
            else output.Append(character);
        }
        output.Append('"');
    }

    public static int Main(string[] args)
    {
        if (args.Length == 1 && args[0] == "--streams")
        {
            Console.Out.Write("stdout-marker");
            Console.Error.Write("stderr-marker");
            return 23;
        }

        StringBuilder output = new StringBuilder("[");
        for (int index = 0; index < args.Length; index++)
        {
            if (index > 0) output.Append(',');
            AppendJsonString(output, args[index]);
        }
        output.Append(']');
        Console.Write(output.ToString());
        return 0;
    }
}
'@
    $compilerPaths = @(
        (Join-Path $env:WINDIR 'Microsoft.NET\Framework64\v4.0.30319\csc.exe')
        (Join-Path $env:WINDIR 'Microsoft.NET\Framework\v4.0.30319\csc.exe')
    )
    $compilerPath = $compilerPaths | Where-Object { Test-Path -LiteralPath $_ -PathType Leaf } | Select-Object -First 1
    if (-not $compilerPath) { throw 'Windows C# compiler is unavailable for native argv probe.' }
    [IO.File]::WriteAllText($argvProbeSourcePath, $argvProbeSource, (New-Object Text.UTF8Encoding($false)))
    $compileProbe = Invoke-Native -Command $compilerPath -Arguments @('/nologo', '/target:exe', "/out:$argvProbePath", $argvProbeSourcePath)
    Assert-True ($compileProbe.ExitCode -eq 0 -and (Test-Path -LiteralPath $argvProbePath -PathType Leaf)) 'native argv probe compiles as a Windows console executable'

    $expectedArgv = [string[]] @(
        'plain'
        'two words'
        'say "hello"'
        '\'
        '\\'
        '\\\\\\'
        'ends with slash\'
        'quote after slash \"'
        'slash before quote and trailing slash \\\"\\'
        ''
        'Українська їжа — Unicode 😀'
        'C:\Program Files\TelmanFit\дані\'
        '\\server\share\folder with spaces\'
    )
    $argvProbe = Invoke-Native -Command $argvProbePath -Arguments $expectedArgv
    $actualArgv = ConvertFrom-Json -InputObject ($argvProbe.StandardOutput -join [Environment]::NewLine)
    Assert-True ($argvProbe.ExitCode -eq 0 -and $actualArgv.Count -eq $expectedArgv.Count) 'native argv probe exits successfully and preserves argument count including empty arguments'
    for ($index = 0; $index -lt $expectedArgv.Count; $index++) {
        Assert-True ($actualArgv[$index] -ceq $expectedArgv[$index]) "native argv round-trip preserves parameterized argument $index"
    }
    $streamProbe = Invoke-Native -Command $argvProbePath -Arguments @('--streams') -AllowFailure
    Assert-True ($streamProbe.ExitCode -eq 23 -and ($streamProbe.StandardOutput -join '') -eq 'stdout-marker' -and ($streamProbe.StandardError -join '') -eq 'stderr-marker') 'native stdout, stderr, and nonzero exit code remain distinct'
    $strictFailureMessage = $null
    try { $null = Invoke-Native -Command $argvProbePath -Arguments @('--streams') } catch { $strictFailureMessage = $_.Exception.Message }
    Assert-True ($strictFailureMessage -match 'exit code 23' -and $strictFailureMessage -notmatch 'stdout-marker|stderr-marker') 'unallowed native failure reports its exit code without exposing process output'

    $null = Invoke-Native -Command 'git' -Arguments @('init', '--quiet', '--bare', '--initial-branch=master', $bareRepository)
    $null = Invoke-Native -Command 'git' -Arguments @('init', '--quiet', '--initial-branch=master', $seedRepository)
    $null = Invoke-Native -Command 'git' -Arguments @('-C', $seedRepository, 'config', 'user.name', 'Native Test')
    $null = Invoke-Native -Command 'git' -Arguments @('-C', $seedRepository, 'config', 'user.email', 'native-test@example.invalid')
    Set-Content -LiteralPath (Join-Path $seedRepository 'seed.txt') -Value 'synthetic' -NoNewline
    $null = Invoke-Native -Command 'git' -Arguments @('-C', $seedRepository, 'add', 'seed.txt')
    $null = Invoke-Native -Command 'git' -Arguments @('-C', $seedRepository, 'commit', '--quiet', '-m', 'synthetic seed')
    $null = Invoke-Native -Command 'git' -Arguments @('-C', $seedRepository, 'remote', 'add', 'origin', $bareRepository)
    $null = Invoke-Native -Command 'git' -Arguments @('-C', $seedRepository, 'push', '--quiet', '-u', 'origin', 'master')

    # A successful Git fetch writes its usual "From ..." progress to stderr.
    $fetch = Invoke-Git -Arguments @('-C', $seedRepository, 'fetch', 'origin', 'master')
    Assert-True ($fetch.ExitCode -eq 0 -and (@($fetch.StandardError | Where-Object { $_ -like 'From *' }).Count -eq 1)) 'successful Git stderr is captured without becoming NativeCommandError'
    Assert-True (@($fetch.StandardOutput | Where-Object { $_ -like 'From *' }).Count -eq 0 -and @($fetch.Output | Where-Object { $_ -like 'From *' }).Count -eq 0) 'Git stdout and stderr remain distinct and Output stays stdout-only'

    $gitFailureMessage = $null
    try { $null = Invoke-Git -Arguments @('-C', $seedRepository, 'not-a-telmanfit-command') } catch { $gitFailureMessage = $_.Exception.Message }
    $allowedGitFailure = Invoke-Git -Arguments @('-C', $seedRepository, 'not-a-telmanfit-command') -AllowFailure
    Assert-True ($allowedGitFailure.ExitCode -ne 0 -and $gitFailureMessage -match "exit code $($allowedGitFailure.ExitCode)" -and $gitFailureMessage -notmatch 'fatal:') 'Git nonzero exit blocks execution without copying raw stderr into logs'
    Assert-True ($allowedGitFailure.ExitCode -ne 0 -and @($allowedGitFailure.StandardError).Count -gt 0) 'explicitly allowed Git failure returns exit code and stderr'

    $dockerApplication = Get-Command -Name 'docker' -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($dockerApplication) {
        $dockerVersion = Invoke-Docker -Arguments @('--version')
        Assert-True ($dockerVersion.ExitCode -eq 0 -and (@($dockerVersion.StandardOutput | Where-Object { $_ -like 'Docker version *' }).Count -eq 1)) 'Docker CLI successful stdout is captured'
        $null = New-Item -ItemType Directory -Path $nativeTestDirectory -Force
        $dockerFailure = Invoke-Docker -Arguments @('--config', $nativeTestDirectory, '--context', 'telmanfit-test-context-that-does-not-exist', 'info') -AllowFailure
        Assert-True ($dockerFailure.ExitCode -ne 0 -and @($dockerFailure.StandardError).Count -gt 0) 'Docker CLI error is returned only through its nonzero exit code and stderr'
        $dockerFailureMessage = $null
        try { $null = Invoke-Docker -Arguments @('--config', $nativeTestDirectory, '--context', 'telmanfit-test-context-that-does-not-exist', 'info') } catch { $dockerFailureMessage = $_.Exception.Message }
        Assert-True ($dockerFailureMessage -match 'exit code ' -and $dockerFailureMessage -notmatch 'context-that-does-not-exist') 'Docker nonzero exit blocks execution without exposing CLI diagnostics'
    }
} finally {
    Remove-Item -LiteralPath $nativeTestRoot -Recurse -Force -ErrorAction SilentlyContinue
}

$coordinatorSource = Get-Content -LiteralPath (Join-Path $root 'scripts/local-update.ps1') -Raw
Assert-True ($coordinatorSource -notmatch 'migrate:rollback|db:wipe|docker\s+compose\s+down\s+-v|git\s+reset\b|git\s+clean\b|--force-push') 'coordinator contains no automatic destructive recovery/reset path'
Assert-True ($coordinatorSource -match 'script:ProjectName' -and $coordinatorSource -match 'Get-VolumeName' -and $coordinatorSource -match 'Get-ContainerName app') 'all Compose/container/volume selection is derived from the locked project identity'
Assert-True ($coordinatorSource -match 'Assert-TestProjectDisjoint' -and $coordinatorSource -match 'TestComposeOverride') 'isolated mode validates ownership and an explicit private override before work'
$e2eSource = Get-Content -LiteralPath (Join-Path $root 'tests/local-update-e2e.ps1') -Raw
Assert-True ($e2eSource -match 'telmanfit-e2e-.*12' -and $e2eSource -match 'telmanfit-local_database-secrets' -and $e2eSource -match 'Test volume collides') 'E2E runner rejects project and working-volume collisions'
Assert-True ($e2eSource -match 'telmanfit.e2e.project' -and $e2eSource -match 'com.docker.compose.project' -and $e2eSource -match 'Refusing cleanup') 'E2E cleanup verifies exact resource ownership labels'
Assert-True ($e2eSource -notmatch 'docker\s+compose\s+down\s+-v' -and $e2eSource -match 'volume.*rm') 'E2E cleanup never uses compose down -v and removes only individually inspected volumes'

Write-Output "Local coordinator unit checks: PASS ($script:assertions assertions)"

