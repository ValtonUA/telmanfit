#requires -Version 5.1
$ErrorActionPreference = 'Stop'
$root = Join-Path ([IO.Path]::GetTempPath()) ('TelmanFit feature launch ' + [guid]::NewGuid().ToString('N'))
$previousPath = $env:PATH
$policy = (Get-ExecutionPolicy -List | ConvertTo-Json -Compress)
try {
    $null = New-Item -ItemType Directory -Path $root
    Copy-Item -LiteralPath (Join-Path $PSScriptRoot '..\scripts\feature.ps1') -Destination (Join-Path $root 'feature.ps1')
    $capture = Join-Path $root 'arguments.json'
    $pythonLauncher = (Get-Command py.exe -ErrorAction Stop).Source
    $observer = Join-Path $root 'capture.py'
    [IO.File]::WriteAllText($observer, "import json, sys`nfrom pathlib import Path`nPath(__file__).with_name('arguments.json').write_text(json.dumps(sys.argv[1:]), encoding='utf-8')`n", [Text.Encoding]::ASCII)
    $stub = "@echo off`r`n`"$pythonLauncher`" -3 `"$observer`" %*`r`nexit /b %errorlevel%`r`n"
    [IO.File]::WriteAllText((Join-Path $root 'py.cmd'), $stub, [Text.Encoding]::ASCII)
    $env:PATH = $root + ';' + $previousPath
    $executable = (Get-Process -Id $PID).Path
    foreach ($action in @('start', 'update', 'fresh', 'stop', 'resume')) {
        & $executable -NoLogo -NoProfile -File (Join-Path $root 'feature.ps1') -Feature feature/synthetic -Action $action -Project telmanfit-feature-aaaaaaaaaaaaaaaaaaaaaaaa
        if ($LASTEXITCODE -ne 0) { throw "Launcher failed: $action" }
        $arguments = [IO.File]::ReadAllText($capture) | ConvertFrom-Json
        if ($arguments.Count -ne 9 -or $arguments[0] -cne '-3' -or $arguments[3] -cne (Join-Path $root 'feature_env.py') -or $arguments[4] -cne 'feature/synthetic' -or $arguments[6] -cne $action -or $arguments[8] -cne 'telmanfit-feature-aaaaaaaaaaaaaaaaaaaaaaaa') {
            throw 'Native argument forwarding failed.'
        }
    }
    if ((Get-ExecutionPolicy -List | ConvertTo-Json -Compress) -cne $policy) { throw 'Execution policy changed.' }
    Write-Output "Feature native launcher without policy override: PASS ($($PSVersionTable.PSVersion)); synthetic Python only, no Docker/DB."
} finally {
    $env:PATH = $previousPath
    if (Test-Path -LiteralPath $root) { Remove-Item -LiteralPath $root -Recurse -Force }
}
