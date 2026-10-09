@echo off
rem One-step install for Windows: double-click or run install.bat
rem Creates a private Python environment, installs dependencies and starts the guided setup.
setlocal
cd /d "%~dp0"

where py >nul 2>&1 && (set "PY=py -3") || (set "PY=python")
%PY% -c "import sys; sys.exit(0 if sys.version_info >= (3, 11) else 1)" >nul 2>&1
if errorlevel 1 (
  echo Python 3.11 or newer is required.
  where winget >nul 2>&1 && (
    echo Installing Python with winget, please accept the prompts...
    winget install -e --id Python.Python.3.12 --accept-package-agreements --accept-source-agreements
    echo.
    echo Python was installed. CLOSE this window and open INICIAR.bat again.
  ) || echo Download it from https://www.python.org/downloads/ ^(tick "Add python.exe to PATH"^) and open INICIAR.bat again.
  pause
  exit /b 1
)

rem Creates .venv, installs the libraries and the browser, showing a live progress meter (log: instalacion.log)
%PY% instalar.py
if errorlevel 1 (
  echo.
  echo La instalacion fallo. Revisa instalacion.log
  pause
  exit /b 1
)

if /i "%~1"=="--no-setup" (
  if not defined LEHIGH_LAUNCHER echo Next: .venv\Scripts\python.exe main.py setup
  exit /b 0
)
if "%~1"=="" (
  findstr /r /c:"^GEMINI_API_KEY=.." .env >nul 2>&1 && findstr /r /c:"^WP_AUTH_TOKEN=.." .env >nul 2>&1 && (
    echo.
    echo El archivo .env ya esta configurado. Para encender los agentes abre INICIAR.bat
    echo Para volver a configurar: .venv\Scripts\python.exe main.py setup
    pause
    exit /b 0
  )
)
.venv\Scripts\python.exe main.py setup %*
pause
