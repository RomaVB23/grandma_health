$ErrorActionPreference = "Stop"
Set-StrictMode -Version Latest

Push-Location $PSScriptRoot
try {
    docker compose run --rm --no-deps --entrypoint php -e APP_ENV=testing -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e CACHE_STORE=array -e SESSION_DRIVER=array api vendor/bin/phpunit
    if ($LASTEXITCODE -ne 0) { throw "Server tests failed." }
} finally {
    Pop-Location
}
