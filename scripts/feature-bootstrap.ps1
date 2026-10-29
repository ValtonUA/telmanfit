#requires -Version 5.1
[CmdletBinding()]
param(
    [Parameter(Mandatory)][ValidatePattern('^[a-f0-9]{40}$')][string] $Head,
    [string] $Feature = 'feature/isolated-feature-environments'
)
$ErrorActionPreference = 'Stop'
# Жодних операцій у поточному/stable checkout. Кожна спроба має власний каталог.
$destination = Join-Path $env:LOCALAPPDATA ('TelmanFit\bootstrap\' + [guid]::NewGuid().ToString('N'))
$null = New-Item -ItemType Directory -Path $destination
$checkout = Join-Path $destination 'tools'
$noHooks = Join-Path $destination 'no-hooks'
$null = New-Item -ItemType Directory -Path $noHooks
& git -c "core.hooksPath=$noHooks" -c 'init.templateDir=' clone --no-checkout -- 'https://github.com/ValtonUA/telmanfit.git' $checkout
if ($LASTEXITCODE -ne 0) { throw 'Не вдалося створити окремий bootstrap checkout.' }
& git -C $checkout -c "core.hooksPath=$noHooks" fetch origin $Head
if ($LASTEXITCODE -ne 0) { throw 'Не вдалося отримати точний reviewed SHA.' }
& git -C $checkout -c "core.hooksPath=$noHooks" checkout --detach $Head
if ($LASTEXITCODE -ne 0) { throw 'Не вдалося відкрити точний reviewed SHA.' }
$actual = & git -C $checkout rev-parse HEAD
if ($LASTEXITCODE -ne 0 -or $actual -cne $Head) { throw 'Bootstrap SHA не збігається.' }
Write-Output "Окремі інструменти: $checkout"
Write-Output 'Запускається reviewed SHA; live admission повторно перевірить поточний PR head/base/CI/review.'
& (Join-Path $checkout 'scripts\feature.ps1') -Feature $Feature -ExpectedHead $Head
exit $LASTEXITCODE
