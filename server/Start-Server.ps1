$ErrorActionPreference = "Stop"
Set-StrictMode -Version Latest

Push-Location $PSScriptRoot
try {
    if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
        throw "Docker Desktop is not installed or docker is missing from PATH."
    }
    docker info --format '{{.ServerVersion}}'
    if ($LASTEXITCODE -ne 0) { throw "Start Docker Desktop and try again." }

    if (-not (Test-Path -LiteralPath ".env")) {
        $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
        try {
            $keyBytes = New-Object byte[] 32
            $tokenBytes = New-Object byte[] 32
            $rng.GetBytes($keyBytes)
            $rng.GetBytes($tokenBytes)
            $key = "base64:" + [Convert]::ToBase64String($keyBytes)
            $token = [BitConverter]::ToString($tokenBytes).Replace("-", "").ToLowerInvariant()
            $content = Get-Content -LiteralPath ".env.example" -Raw
            $content = [regex]::Replace($content, '(?m)^APP_KEY=.*$', "APP_KEY=$key")
            $content = [regex]::Replace($content, '(?m)^TELEMETRY_TOKEN=.*$', "TELEMETRY_TOKEN=$token")
            $encoding = New-Object System.Text.UTF8Encoding($false)
            [System.IO.File]::WriteAllText((Join-Path $PSScriptRoot ".env"), $content, $encoding)
        } finally {
            $rng.Dispose()
        }
        Write-Host "Created local .env. Keys remain on this computer."
    }

    docker compose up --build -d
    if ($LASTEXITCODE -ne 0) { throw "Server startup failed. See the Docker output above." }
    docker compose ps
    Write-Host "To inspect data: powershell -ExecutionPolicy Bypass -File .\Check-Server.ps1"
} finally {
    Pop-Location
}
