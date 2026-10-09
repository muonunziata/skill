@echo off
rem Windows: double-click to turn the agents on. The first time it also installs everything it needs.
chcp 65001 >nul
cd /d "%~dp0"
title GoLehighAcres.org - Agentes
if not exist .venv\Scripts\python.exe (
  echo Primera vez: preparando los agentes. Tarda unos minutos y solo ocurre esta vez...
  set LEHIGH_LAUNCHER=1
  call install.bat --no-setup
  if not exist .venv\Scripts\python.exe exit /b 1
)
echo.
echo Agentes encendidos. Deja esta ventana abierta; para apagarlos, cierrala.
echo Se controlan desde WordPress: News Hub - boton "Iniciar a trabajar".
echo.
.venv\Scripts\python.exe main.py watch
pause
