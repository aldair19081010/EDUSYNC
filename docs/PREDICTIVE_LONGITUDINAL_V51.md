# EduSync — Candidato v5.1 y Alerta Temprana Inteligente

## Decisión metodológica

La v5 multianual redujo falsos negativos en el último holdout, pero empeoró precisión, Balanced Accuracy, Brier y ECE temporal. Por esa razón no reemplaza a la v4.

La v5.1 prueba una hipótesis más conservadora: usar la trayectoria de los bimestres cerrados del **mismo año académico**, sin permitir que años anteriores modifiquen todavía la probabilidad.

### Comportamiento esperado

- I cerrado -> predice II usando I.
- I + II cerrados -> predice III usando I y II.
- I + II + III cerrados -> predice IV usando la trayectoria I, II y III.
- Los años anteriores pueden mostrarse como contexto, pero no son predictores v5.1.

## Variables candidatas v5.1

- `grade_mean_current`
- `grade_trend_same_year`
- `year_periods_available`
- `year_grade_trend`
- `critical_records_current`
- `critical_courses_current`
- `critical_courses_year_mean`
- `attendance_rate_30d`
- `attendance_trend_same_year`
- `late_30d`
- `absent_30d`

La variable `year_periods_available` distingue el I bimestre, donde no existe tendencia previa del mismo año, de una tendencia realmente estable.

## Archivos

- `edusync/includes/predictive_longitudinal_v51.php`
- `tools/export_risk_dataset_v51.php`
- `ml/train_risk_model_v51.py`
- `ml/validate_risk_model_v51.py`
- `tools/predictive_longitudinal_v51_smoke_test.php`

## Pruebas locales

```powershell
cd C:\xampp\htdocs\interfaz
git fetch origin
git switch tesis-intervenciones-contrafactual-dashboard
git pull --ff-only origin tesis-intervenciones-contrafactual-dashboard

C:\xampp\php\php.exe -l edusync\includes\predictive_longitudinal_v51.php
C:\xampp\php\php.exe -l edusync\includes\risk_dashboard_enhanced.php
C:\xampp\php\php.exe -l edusync\risk_dashboard_api.php
C:\xampp\php\php.exe -l edusync\pages\risk_dashboard.php
C:\xampp\php\php.exe -l tools\export_risk_dataset_v51.php
C:\xampp\php\php.exe -l tools\predictive_longitudinal_v51_smoke_test.php
python -m py_compile ml\train_risk_model_v51.py
python -m py_compile ml\validate_risk_model_v51.py
```

Smoke test:

```powershell
C:\xampp\php\php.exe tools\predictive_longitudinal_v51_smoke_test.php
```

Exportar dataset:

```powershell
C:\xampp\php\php.exe tools\export_risk_dataset_v51.php --school=ID_REAL --output=storage\risk_dataset_v51.csv
```

Entrenar candidato:

```powershell
python ml\train_risk_model_v51.py --input storage\risk_dataset_v51.csv --output edusync\storage\ai_models\risk_model_v51_candidate.json
```

Comparar contra v4:

```powershell
python ml\validate_risk_model_v51.py --input storage\risk_dataset_v51.csv --model edusync\storage\ai_models\risk_model_v51_candidate.json --output storage\risk_validation_v51_comparison.json --splits 10
```

No reemplazar `risk_model.json` hasta revisar la comparación temporal.

## Alerta Temprana Inteligente — mejora de interfaz

El panel fue reorganizado para explicar claramente:

1. qué bimestre cerrado aporta la información;
2. qué bimestre se está estimando;
3. qué significa el porcentaje de alerta.

Los filtros ahora incluyen búsqueda de estudiante, nivel, grado dinámico, sección dinámica, nivel de alerta, bimestre base y estado de intervención. Grados y secciones se obtienen de estudiantes activos del colegio en vez de quedar escritos manualmente en la vista.

Cada estudiante dispone de `Detalle`, `Simular mejora` y `Registrar intervención`. El detalle muestra promedio, tendencia, registros críticos, cursos críticos, asistencia, tardanzas, ausencias y factores que elevan o reducen la estimación.
