#requires -Version 5.1
$ErrorActionPreference = 'Stop'
$testRoot = Join-Path ([IO.Path]::GetTempPath()) ('TelmanFit bootstrap test ' + [guid]::NewGuid().ToString('N'))
$variables = @('LOCALAPPDATA', 'GIT_CONFIG_COUNT', 'GIT_CONFIG_KEY_0', 'GIT_CONFIG_VALUE_0')
$saved = @{}
foreach ($name in $variables) { $saved[$name] = [Environment]::GetEnvironmentVariable($name, 'Process') }
$policy = Get-ExecutionPolicy -List | ConvertTo-Json -Compress
try {
    $null = New-Item -ItemType Directory -Path $testRoot
    $fixture = Join-Path $testRoot 'fixture'
    $null = New-Item -ItemType Directory -Path (Join-Path $fixture 'scripts') -Force
    $observer = @'
param([string] $Feature, [string] $ExpectedHead)
@{ script = $PSCommandPath; feature = $Feature; head = $ExpectedHead } | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $env:LOCALAPPDATA 'capture.json') -Encoding UTF8
exit 0
'@
    [IO.File]::WriteAllText((Join-Path $fixture 'scripts\feature.ps1'), $observer, [Text.Encoding]::ASCII)
    & git init --quiet $fixture
    if ($LASTEXITCODE -ne 0) { throw 'fixture init failed' }
    & git -C $fixture -c user.name=Fixture -c user.email=fixture@example.invalid add .
    & git -C $fixture -c user.name=Fixture -c user.email=fixture@example.invalid commit --quiet -m synthetic
    if ($LASTEXITCODE -ne 0) { throw 'fixture commit failed' }
    $headSha = & git -C $fixture rev-parse HEAD
    $stable = Join-Path $testRoot 'stable'
    $null = New-Item -ItemType Directory -Path $stable
    [IO.File]::WriteAllText((Join-Path $stable '.env'), 'synthetic-stable-marker')
    $env:LOCALAPPDATA = Join-Path $testRoot 'local app data'
    $null = New-Item -ItemType Directory -Path $env:LOCALAPPDATA
    # Real native Git clone/fetch/checkout, rewritten exclusively to a synthetic local repo.
    $env:GIT_CONFIG_COUNT = '1'
    $env:GIT_CONFIG_KEY_0 = 'url.' + ($fixture -replace '\\', '/') + '.insteadOf'
    $env:GIT_CONFIG_VALUE_0 = 'https://github.com/ValtonUA/telmanfit.git'
    $bootstrap = Join-Path $PSScriptRoot '..\scripts\feature-bootstrap.ps1'
    $executable = (Get-Process -Id $PID).Path
    Push-Location $stable
    try {
        & $executable -NoLogo -NoProfile -File $bootstrap -Head $headSha
        if ($LASTEXITCODE -ne 0) { throw 'bootstrap failed' }
        $capture = Join-Path $env:LOCALAPPDATA 'capture.json'
        $result = Get-Content -LiteralPath $capture -Raw | ConvertFrom-Json
        if ($result.head -cne $headSha -or $result.feature -cne 'feature/isolated-feature-environments' -or -not $result.script.StartsWith($env:LOCALAPPDATA, [StringComparison]::OrdinalIgnoreCase)) { throw 'wrong checkout/arguments' }
        $checkout = Split-Path (Split-Path $result.script)
        $actual = & git -C $checkout rev-parse HEAD
        if ($actual -cne $headSha) { throw 'checkout SHA mismatch' }
        Remove-Item -LiteralPath $capture
        & $executable -NoLogo -NoProfile -File $bootstrap -Head ('0' * 40)
        if ($LASTEXITCODE -eq 0 -or (Test-Path -LiteralPath $capture)) { throw 'unknown SHA unexpectedly launched' }
        if ([IO.File]::ReadAllText((Join-Path $stable '.env')) -cne 'synthetic-stable-marker' -or (Get-ChildItem -LiteralPath $stable -Force).Count -ne 1) { throw 'stable checkout touched' }
    } finally { Pop-Location }
    if ((Get-ExecutionPolicy -List | ConvertTo-Json -Compress) -cne $policy) { throw 'execution policy changed' }
    Write-Output "Bootstrap real native Git clone/fetch/detached SHA, isolated path, failure and unchanged stable/policy: PASS ($($PSVersionTable.PSVersion)); synthetic launcher only, no Docker/DB/network."
} finally {
    foreach ($name in $variables) { [Environment]::SetEnvironmentVariable($name, $saved[$name], 'Process') }
    if (Test-Path -LiteralPath $testRoot) { Remove-Item -LiteralPath $testRoot -Recurse -Force }
}
