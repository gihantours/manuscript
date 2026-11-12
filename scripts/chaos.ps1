param(
    [ValidateSet('worker', 'similarity')]
    [string]$Action = 'worker',
    [int]$Seconds = 15
)

$ErrorActionPreference = 'Stop'
Set-Location (Split-Path -Parent $PSScriptRoot)

if ($Action -eq 'worker') {
    Write-Host "Killing the Horizon worker mid-run..."
    docker compose kill horizon
    Start-Sleep -Seconds $Seconds
    Write-Host "Restarting the worker. In-flight jobs should be recovered, not lost."
    docker compose start horizon
    Write-Host "Watch the manuscripts page and Horizon for retries or duplicate side effects."
    exit 0
}

Write-Host "Stopping the similarity/email mock for $Seconds seconds..."
docker compose stop mock
Start-Sleep -Seconds $Seconds
Write-Host "Restarting the mock. Similarity jobs should back off and then resume."
docker compose start mock
