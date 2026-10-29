#requires -Version 5.1
$ErrorActionPreference = 'Stop'

$coordinator = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\scripts\local-update.ps1'))
$bytes = [IO.File]::ReadAllBytes($coordinator)
if ($bytes.Length -lt 3 -or $bytes[0] -ne 0xEF -or $bytes[1] -ne 0xBB -or $bytes[2] -ne 0xBF) {
    throw 'scripts/local-update.ps1 must be UTF-8 with BOM for Windows PowerShell 5.1.'
}
$body = [Text.UTF8Encoding]::new($false, $true).GetString($bytes, 3, $bytes.Length - 3)
if (-not $body.StartsWith('[CmdletBinding()]', [StringComparison]::Ordinal)) {
    throw 'Coordinator must begin with its script attributes; remove any copied terminal output.'
}
if ($body -match '(?im)^Warning: truncated output|^Total output lines: [0-9]+$|tokens truncated') {
    throw 'Coordinator contains terminal output truncation artifacts.'
}

$tokens = $null
$errors = $null
$null = [System.Management.Automation.Language.Parser]::ParseFile($coordinator, [ref] $tokens, [ref] $errors)
if ($errors.Count -gt 0) {
    $details = ($errors | ForEach-Object { "line $($_.Extent.StartLineNumber): $($_.Message)" }) -join '; '
    throw "scripts/local-update.ps1 has PowerShell parse errors: $details"
}

Write-Output "Coordinator syntax compatibility: PASS ($($PSVersionTable.PSEdition) $($PSVersionTable.PSVersion))"

$featureScript = Join-Path $PSScriptRoot '..\scripts\feature.ps1'
$errors = $null
$null = [System.Management.Automation.Language.Parser]::ParseFile($featureScript, [ref] $tokens, [ref] $errors)
if ($errors.Count -ne 0) { throw 'Feature launcher has PowerShell syntax errors.' }
$featureBytes = [IO.File]::ReadAllBytes($featureScript)
if ($featureBytes[0] -ne 0xEF -or $featureBytes[1] -ne 0xBB -or $featureBytes[2] -ne 0xBF) { throw 'Feature launcher requires UTF-8 BOM.' }

$bootstrap = Join-Path $PSScriptRoot '..\scripts\feature-bootstrap.ps1'
$errors = $null
$null = [System.Management.Automation.Language.Parser]::ParseFile($bootstrap, [ref] $tokens, [ref] $errors)
if ($errors.Count -ne 0) { throw 'Bootstrap has PowerShell syntax errors.' }
$bootstrapBytes = [IO.File]::ReadAllBytes($bootstrap)
if ($bootstrapBytes[0] -ne 0xEF -or $bootstrapBytes[1] -ne 0xBB -or $bootstrapBytes[2] -ne 0xBF) { throw 'Bootstrap requires UTF-8 BOM.' }
