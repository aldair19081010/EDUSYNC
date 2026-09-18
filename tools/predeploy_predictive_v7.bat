@echo off
setlocal enabledelayedexpansion

cd /d "%~dp0\.."

set "PHP=C:\xampp\php\php.exe"
set "DATASET=storage\course_risk_dataset_v7.csv"
set "MODEL=edusync\storage\ai_models\course_risk_model.json"
set "REPORT=storage\course_risk_validation_report_v7.json"

echo ==========================================
echo EduSync - Gate de preproduccion v7
echo ==========================================

if not exist "%PHP%" (
  echo [ERROR] No se encontro PHP en %PHP%
  exit /b 1
)

if not exist "%DATASET%" (
  echo [ERROR] No existe %DATASET%
  echo Regenera primero el dataset v7.
  exit /b 1
)

if not exist "%MODEL%" (
  echo [ERROR] No existe %MODEL%
  echo Entrena primero el modelo v7.
  exit /b 1
)

echo.
echo [1/4] Lint PHP...
for %%F in (
  edusync\includes\predictive_course_risk.php
  edusync\includes\risk_dashboard_course_fast.php
  edusync\risk_dashboard_api.php
  edusync\gradebook_api.php
  edusync\pages\grades.php
  tools\export_course_risk_dataset_v7.php
  tools\predictive_course_risk_smoke_test.php
) do (
  echo   %%F
  "%PHP%" -l "%%F"
  if errorlevel 1 exit /b 1
)

echo.
echo [2/4] Compilacion Python...
python -m py_compile ^
  ml\train_course_risk_model_v7.py ^
  ml\validate_course_risk_model_v7.py ^
  ml\compare_course_risk_models_v7.py
if errorlevel 1 exit /b 1

echo.
echo [3/4] Smoke test predictivo...
"%PHP%" tools\predictive_course_risk_smoke_test.php
if errorlevel 1 exit /b 1

echo.
echo [4/4] Validacion temporal v7...
python ml\validate_course_risk_model_v7.py --input "%DATASET%" --model "%MODEL%" --output "%REPORT%" --splits 10
if errorlevel 1 exit /b 1

findstr /C:"\"status\": \"APTO_PARA_PILOTO\"" "%REPORT%" >nul 2>&1
if errorlevel 1 (
  echo.
  echo [ADVERTENCIA] Revisa el estado impreso por el validador.
)

echo.
echo Comparacion de modelos...
python ml\compare_course_risk_models_v7.py --input "%DATASET%" --target-recall 0.80
if errorlevel 1 exit /b 1

echo.
echo ==========================================
echo GATE COMPLETADO SIN ERRORES DE EJECUCION
echo Revisa que el validador indique APTO_PARA_PILOTO
echo antes de desplegar a produccion.
echo ==========================================
exit /b 0
