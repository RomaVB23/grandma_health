$ErrorActionPreference = "Stop"
Set-StrictMode -Version Latest

Push-Location $PSScriptRoot
try {
    if (-not (Test-Path -LiteralPath ".env")) {
        throw "Run Start-Server.ps1 first."
    }
    $values = @{}
    foreach ($line in (Get-Content -LiteralPath ".env")) {
        if ($line -match '^([A-Z_]+)=(.*)$') {
            $values[$Matches[1]] = $Matches[2].Trim()
        }
    }
    if (-not $values.ContainsKey("TELEMETRY_TOKEN") -or $values["TELEMETRY_TOKEN"].Length -lt 32) {
        throw "TELEMETRY_TOKEN is missing or too short."
    }
    $port = if ($values.ContainsKey("SERVER_PORT")) { $values["SERVER_PORT"] } else { "47863" }
    $baseUrl = "http://127.0.0.1:$port"
    docker compose ps
    if ($LASTEXITCODE -ne 0) { throw "Docker status check failed." }
    $null = Invoke-RestMethod -Uri "$baseUrl/up" -TimeoutSec 10
    $headers = @{ Authorization = "Bearer " + $values["TELEMETRY_TOKEN"]; Accept = "application/json" }
    Invoke-RestMethod -Uri "$baseUrl/api/v1/status" -Headers $headers -TimeoutSec 10 |
        ConvertTo-Json -Depth 5
} finally {
    Pop-Location
}
