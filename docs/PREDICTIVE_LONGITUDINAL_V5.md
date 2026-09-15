# EduSync — Candidato longitudinal v5

## Propósito

La v4 validada utiliza principalmente la situación del último bimestre cerrado y la comparación inmediata con el bimestre anterior del mismo año. La v5 se construye como **candidato en paralelo** para comprobar si aprovechar la trayectoria histórica del mismo estudiante mejora la alerta temprana.

La v4 permanece activa hasta que una comparación con datos reales demuestre que v5 aporta una mejora suficiente.

## Qué cambia

La predicción mantiene el mismo objetivo:

`información disponible hasta el cierre de N -> riesgo de al menos un curso crítico al cierre de N+1`.

La diferencia es que las variables de N pueden resumir también periodos anteriores ya cerrados del mismo estudiante, incluso de años académicos previos.

### Ejemplo: primer bimestre

Si I-2026 ya cerró y el estudiante tiene historia en 2025:

```text
III-2025 -> IV-2025 -> I-2026 -> predecir II-2026
```

La tendencia reciente puede comparar I-2026 con IV-2025. También se calculan resúmenes históricos de los periodos cerrados disponibles.

Si el alumno es nuevo y solo existe I-2026:

```text
I-2026 -> predecir II-2026
```

No se inventa una tendencia anterior. Las variables históricas quedan ausentes o indican un único periodo disponible.

### Ejemplo: tres bimestres cerrados

Si I, II y III de 2026 están cerrados:

```text
I-2026 -> II-2026 -> III-2026 -> predecir IV-2026
```

La v5 conoce el promedio actual, el cambio II->III y además resume la trayectoria I->II->III y cualquier historia anterior válida.

## Variables longitudinales candidatas

Además de las variables actuales, el candidato incorpora:

- `grade_trend_recent`: cambio entre el periodo actual y el periodo cerrado inmediatamente anterior, incluso si pertenece al año anterior;
- `previous_period_available`: indica si existe un periodo previo comparable;
- `history_periods_available`: cantidad de periodos cerrados del estudiante disponibles hasta N;
- `historical_years_available`: cantidad de años académicos representados en esa historia;
- `previous_academic_year_history`: indica si existe información de un año anterior;
- `historical_mean_prior`: promedio de los periodos previos;
- `long_term_grade_trend`: pendiente de los últimos cuatro periodos académicos disponibles, incluyendo el actual;
- `grade_volatility_recent`: variabilidad del rendimiento reciente;
- `critical_courses_recent_3_mean`: media de cursos críticos en hasta tres periodos recientes;
- `critical_period_rate_prior`: proporción de periodos previos con al menos un curso crítico;
- `attendance_historical_mean`: asistencia media de hasta tres periodos previos con información;
- `attendance_trend_recent`: cambio de asistencia respecto al último periodo comparable;
- `late_mean_recent_3`: media de tardanzas en hasta tres periodos recientes;
- `absent_mean_recent_3`: media de ausencias en hasta tres periodos recientes.

La información histórica solo proviene de periodos cerrados. No se utilizan notas del futuro, notas parciales del bimestre objetivo ni información financiera.

## Por qué no sustituimos v4 directamente

Más variables no garantizan un mejor modelo. La trayectoria podría aportar señal útil, pero también introducir ruido o sobreajuste. Por eso v4 y v5 deben evaluarse con:

- las mismas filas históricas;
- los mismos splits por `student_id`;
- el mismo walk-forward temporal;
- la misma calibración Platt;
- Recall, Precision, F1, Balanced Accuracy, ROC-AUC y PR-AUC;
- Brier y ECE;
- TP/TN/FP/FN, especialmente falsos negativos.

`ml/validate_risk_model_v5.py` entrena ambos enfoques sobre exactamente las mismas particiones y reporta `delta = v5 - v4`.

## Archivos

- `edusync/includes/predictive_longitudinal.php`
- `tools/export_risk_dataset_v5.php`
- `ml/train_risk_model_v5.py`
- `ml/validate_risk_model_v5.py`
- `tools/predictive_longitudinal_smoke_test.php`

## Ejecución

Actualizar rama y validar sintaxis:

```powershell
cd C:\xampp\htdocs\interfaz
git fetch origin
git switch tesis-intervenciones-contrafactual-dashboard
git pull --ff-only origin tesis-intervenciones-contrafactual-dashboard

C:\xampp\php\php.exe -l edusync\includes\predictive_longitudinal.php
C:\xampp\php\php.exe -l tools\export_risk_dataset_v5.php
C:\xampp\php\php.exe -l tools\predictive_longitudinal_smoke_test.php
python -m py_compile ml\train_risk_model_v5.py
python -m py_compile ml\validate_risk_model_v5.py
```

Smoke test:

```powershell
C:\xampp\php\php.exe tools\predictive_longitudinal_smoke_test.php
```

Exportar dataset candidato usando el ID real del colegio:

```powershell
C:\xampp\php\php.exe tools\export_risk_dataset_v5.php --school=ID_REAL --output=storage\risk_dataset_v5.csv
```

La salida muestra cuántas filas tienen un periodo previo y cuántas encuentran historia de un año académico anterior. Si la segunda cifra es inesperadamente baja, revisar primero cómo se mantiene la identidad del estudiante entre años académicos.

Entrenar candidato:

```powershell
python ml\train_risk_model_v5.py --input storage\risk_dataset_v5.csv --output edusync\storage\ai_models\risk_model_v5_candidate.json
```

Comparar v4 vs v5:

```powershell
python ml\validate_risk_model_v5.py --input storage\risk_dataset_v5.csv --model edusync\storage\ai_models\risk_model_v5_candidate.json --output storage\risk_validation_v5_comparison.json --splits 10
```

## Regla de promoción

No copiar ni renombrar `risk_model_v5_candidate.json` como `risk_model.json` solo porque el script termine correctamente. Primero revisar la comparación real. La promoción del candidato al modelo activo será un cambio separado y explícito.
