# Validación previa a `main` y producción — Alerta Temprana Inteligente v4

Este procedimiento se ejecuta **solo en la rama** `tesis-intervenciones-contrafactual-dashboard` hasta completar la validación con datos reales. No fusionar a `main` ni desplegar a producción antes de terminar esta revisión.

## 1. Variable objetivo y política temporal

El objetivo es: **al menos un curso crítico en el bimestre siguiente**.

Registro crítico:
- letra `C`; o
- nota numérica `< 10.5`.

Cada fila histórica representa:

`información disponible al cierre del bimestre N -> resultado observado al cierre del bimestre N+1`.

Un bimestre con notas parciales no se usa como resultado histórico final. En inferencia en vivo, el último bimestre cerrado sí puede utilizarse para estimar riesgo en el siguiente bimestre aunque este se encuentre en proceso.

Las deudas, pagos o morosidad no forman parte del modelo.

## 2. Cambios del esquema v4

El esquema v4 corrige dos problemas detectados durante la auditoría de falsos negativos.

### 2.1 Historial previo

Antes se usaban simultáneamente:
- `grade_mean_current`;
- `grade_mean_previous`;
- `grade_trend = current - previous`.

Esto introducía redundancia exacta y, en el I bimestre, obligaba a simular `previous = current`, haciendo indistinguibles dos situaciones distintas: tendencia realmente estable y ausencia de historial previo.

Ahora el modelo utiliza:
- `grade_mean_current`;
- `grade_trend`;
- `previous_bimester_available`;
- variables de cursos/registros críticos;
- asistencia/tardanzas/ausencias.

`grade_mean_previous_observed` se conserva únicamente como columna diagnóstica del CSV, no como predictor.

### 2.2 Calibración probabilística

La regresión logística continúa usando `class_weight="balanced"` para clasificación, pero sus probabilidades se calibran mediante **Platt scaling**.

El calibrador se ajusta sobre logits fuera de muestra generados con `GroupKFold` por `student_id`. De este modo no se calibra usando directamente las mismas predicciones con las que se entrenó el modelo base.

El artefacto resultante usa `schema_version = 4` y contiene:
- coeficientes del modelo base;
- escalador e imputación;
- coeficiente e intercepto de calibración;
- Brier Score;
- Expected Calibration Error (ECE);
- bins de calibración de 0–10 %, 10–20 %, ..., 90–100 %.

PHP reproduce la inferencia completa: modelo base -> logit -> calibración Platt -> probabilidad mostrada al usuario.

## 3. Asistencia faltante

Si no existen registros de asistencia en los 30 días previos al cierre:
- no se interpreta como 0 %;
- no se interpreta como 100 %;
- se exporta como dato faltante;
- el entrenamiento imputa la mediana aprendida solo del conjunto de entrenamiento;
- el valor imputado no se presenta como factor observado;
- los contrafactuales no inventan mejoras de asistencia.

## 4. Actualizar rama y validar sintaxis

```powershell
cd C:\xampp\htdocs\interfaz
git fetch origin
git switch tesis-intervenciones-contrafactual-dashboard
git pull --ff-only origin tesis-intervenciones-contrafactual-dashboard

C:\xampp\php\php.exe -l edusync\includes\predictive_risk.php
C:\xampp\php\php.exe -l edusync\includes\predictive_interventions.php
C:\xampp\php\php.exe -l tools\export_risk_dataset.php
C:\xampp\php\php.exe -l tools\predictive_interventions_smoke_test.php
python -m py_compile ml\train_risk_model.py
python -m py_compile ml\validate_risk_model.py
```

## 5. Reexportar dataset v4

El CSV v3 ya no sirve porque no contiene `previous_bimester_available`.

```powershell
C:\xampp\php\php.exe tools\export_risk_dataset.php --school=ID_COLEGIO --output=storage\risk_dataset.csv
```

Para la validación final no usar `--allow-estimated-cutoffs=1` salvo exploración.

La salida debe indicar `Esquema de variables: v4`.

## 6. Reentrenar modelo v4

El `risk_model.json` v3 queda obsoleto intencionalmente.

```powershell
python ml\train_risk_model.py --input storage\risk_dataset.csv --output edusync\storage\ai_models\risk_model.json
```

El artefacto debe indicar:
- `schema_version: 4`;
- `previous_bimester_available` entre las features;
- ausencia de `grade_mean_previous` entre las features;
- `calibration.method = platt_grouped_oof`.

## 7. Validación completa

```powershell
python ml\validate_risk_model.py --input storage\risk_dataset.csv --model edusync\storage\ai_models\risk_model.json --output storage\risk_validation_report.json --splits 10
```

El script realiza dos evaluaciones complementarias.

### Generalización por estudiante

`Repeated GroupShuffleSplit` evita que el mismo estudiante aparezca simultáneamente en entrenamiento y prueba.

### Validación temporal

Walk-forward temporal: cada periodo futuro se evalúa usando exclusivamente filas con `cutoff_date` anterior.

El último periodo válido funciona como holdout temporal principal.

## 8. Métricas

Se revisan:
- Recall;
- Precision;
- F1;
- Balanced Accuracy;
- ROC-AUC;
- PR-AUC;
- Brier Score;
- ECE;
- matriz de confusión;
- curva de calibración por rangos.

Interpretación de calibración:
- **Brier Score:** menor es mejor. El validador lo compara con un baseline de prevalencia histórica.
- **ECE:** cercano a 0 es mejor. Una advertencia se activa provisionalmente si ECE > 0.10.

Los umbrales son criterios operativos provisionales y deben justificarse en la tesis; no son estándares universales.

## 9. Auditoría de errores

El JSON incluye verdaderos positivos, verdaderos negativos, falsos positivos y falsos negativos. Los estudiantes se anonimizan por defecto.

Para auditoría local:

```powershell
python ml\validate_risk_model.py --input storage\risk_dataset.csv --model edusync\storage\ai_models\risk_model.json --output storage\risk_validation_report_local.json --splits 10 --include-student-id
```

Los IDs reales no deben publicarse en la tesis.

## 10. Smoke test

```powershell
C:\xampp\php\php.exe tools\predictive_interventions_smoke_test.php
```

Debe comprobar:
- esquema v4;
- probabilidad base y calibrada;
- ausencia/presencia de historial previo;
- asistencia faltante;
- contrafactuales con probabilidad calibrada.

## 11. Criterio antes de `main`

No fusionar únicamente porque el dashboard funcione. Antes deben cumplirse:
- sin bloqueadores metodológicos;
- dataset v4 con cierres reales;
- modelo v4 calibrado;
- varias particiones agrupadas válidas;
- validación temporal satisfactoria;
- Brier menor que el baseline de prevalencia del último holdout;
- ECE revisado y razonable;
- falsos negativos revisados;
- smoke test correcto;
- prueba funcional local de dashboard, chatbot, escenarios e intervenciones;
- piloto inicialmente limitado a administración.

`APTO_PARA_PILOTO` no equivale automáticamente a `APTO_PARA_PRODUCCIÓN`.
