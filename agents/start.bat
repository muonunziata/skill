@echo off
rem Run the agents continuously (Windows). Stop with Ctrl+C.
cd /d "%~dp0"
.venv\Scripts\python.exe main.py watch %*
