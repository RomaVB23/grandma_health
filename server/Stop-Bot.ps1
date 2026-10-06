$ErrorActionPreference = "Stop"
Push-Location $PSScriptRoot
try {
    docker compose --profile telegram stop telegram
    if ($LASTEXITCODE -ne 0) { throw "Bot stop failed." }
} finally {
    Pop-Location
}
