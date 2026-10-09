@echo off
rem One-step install for Windows: double-click or run install.bat
rem Creates a private Python environment, installs dependencies and starts the guided setup.
setlocal
cd /d "%~dp0"

where py >nul 2>&1 && (set "PY=py -3") || (set "PY=python")
%PY% -c "import sys; sys.exit(0 if sys.version_info >= (3, 11) else 1)" >nul 2>&1
if errorlevel 1 (
  echo Python 3.11 or newer is required: https://www.python.org/downloads/
  pause
  exit /b 1
)

if not exist .venv\Scripts\python.exe %PY% -m venv .venv
echo Installing dependencies...
.venv\Scripts\python.exe -m pip install --quiet --upgrade pip
.venv\Scripts\python.exe -m pip install --quiet -r requirements.txt
if errorlevel 1 (
  echo Installing the dependencies failed.
  pause
  exit /b 1
)
echo Dependencies installed in .venv
echo Installing Chromium (used by Agent 4 to draw Instagram/TikTok slides)...
.venv\Scripts\python.exe -m playwright install chromium >nul 2>&1
if errorlevel 1 echo Chromium was not installed. Run: .venv\Scripts\python.exe -m playwright install chromium

if /i "%~1"=="--no-setup" (
  echo Next: .venv\Scripts\python.exe main.py setup
  exit /b 0
)
.venv\Scripts\python.exe main.py setup %*
pause
