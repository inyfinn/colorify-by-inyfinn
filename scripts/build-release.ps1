# Buduje colorify-by-inyfinn.zip z forward-slash paths (Linux-safe).
# Uruchom: .\scripts\build-release.ps1

$ErrorActionPreference = 'Stop'

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$pluginRoot = Split-Path -Parent $PSScriptRoot
$pluginSlug = 'colorify-by-inyfinn'
$distDir    = Join-Path $pluginRoot 'dist'
$zipPath    = Join-Path $distDir "$pluginSlug.zip"

$excludeDirs  = @('.git', 'dist', 'scripts', 'node_modules', '.idea', '.vscode')
$excludeFiles = @('.gitignore', '.DS_Store')

if (-not (Test-Path $distDir)) {
    New-Item -ItemType Directory -Path $distDir | Out-Null
}

if (Test-Path $zipPath) {
    Remove-Item $zipPath -Force
}

$zip = [System.IO.Compression.ZipFile]::Open($zipPath, [System.IO.Compression.ZipArchiveMode]::Create)
$added = 0

Get-ChildItem -Path $pluginRoot -Recurse -File | ForEach-Object {
    $full = $_.FullName
    $rel  = $full.Substring($pluginRoot.Length + 1).Replace('\', '/')
    $top  = ($rel -split '/')[0]

    if ($excludeDirs -contains $top) { return }
    if ($excludeFiles -contains $_.Name) { return }
    if ($_.Extension -ieq '.zip') { return }

    $entryName = "$pluginSlug/$rel"
    [void][System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $full, $entryName, [System.IO.Compression.CompressionLevel]::Optimal)
    $added++
}

$zip.Dispose()

Write-Host "OK: $zipPath ($added plikow)"

# Weryfikacja
$verify = [System.IO.Compression.ZipFile]::OpenRead($zipPath)
foreach ($entry in $verify.Entries) {
    if ($entry.FullName -notmatch "^$pluginSlug/") {
        throw "Zla sciezka (brak prefiksu): $($entry.FullName)"
    }
    if ($entry.FullName -match '\\|%5C') {
        throw "Backslash w zip: $($entry.FullName)"
    }
}
$verify.Dispose()

Write-Host 'Weryfikacja sciezek: OK'
