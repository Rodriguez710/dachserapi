@echo off
setlocal
C:\xampp\php\php.exe C:\xampp\htdocs\dachserapi\pruebas.php

:: Carpeta donde buscar los PDFs
set "Folder=C:\xampp\htdocs\dachserapi\etiquetas"

:: Nombre de la impresora tal cual aparece en el sistema
set "PrinterName=ET - TheBath"

:: Ruta al ejecutable de Adobe Acrobat/Reader
set "ReaderPath=C:\Program Files\Adobe\Acrobat DC\Acrobat\Acrobat.exe"

:: Recorremos todos los PDF en la carpeta
for %%F in ("%Folder%\*.pdf") do (
    echo Imprimiendo %%F ...
    "%ReaderPath%" /t "%%F" "%PrinterName%"
    timeout /t 5 /nobreak >nul
    del "%%F"
)

taskkill /IM AcroRd32.exe /F >nul 2>&1
taskkill /IM Acrobat.exe /F >nul 2>&1

endlocal
