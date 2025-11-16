@echo off
SETLOCAL
REM Update the php.exe path below if PHP is not on PATH.
php "%~dp0\..\dist\localpoc.phar" %*
ENDLOCAL
