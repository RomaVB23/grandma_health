$ErrorActionPreference = "Stop"
Set-StrictMode -Version Latest

Push-Location $PSScriptRoot
try {
    docker compose --profile telegram up --build -d
    if ($LASTEXITCODE -ne 0) { throw "Bot startup failed. See the Docker output above." }
    docker compose --profile telegram ps
    Write-Host "Inspect the worker: docker compose --profile telegram logs --tail 30 telegram"
} finally {
    Pop-Location
}
