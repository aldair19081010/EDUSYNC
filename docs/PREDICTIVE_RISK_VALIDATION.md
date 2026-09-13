# Validación previa a `main` y producción — Alerta Temprana Inteligente

Este procedimiento se ejecuta **solo en la rama de desarrollo** `tesis-intervenciones-contrafactual-dashboard` hasta que la validación con datos reales sea satisfactoria. No fusionar a `main` ni desplegar a producción antes de completar esta revisión.

## 1. Criterio de riesgo académico

La variable objetivo se define como: **al menos un curso crítico en el bimestre siguiente**.

Criterio de registro crítico:

- letra `C`; o
- nota numérica `< 10.5`.

Con notas enteras, `<10.5` equivale a 0–10 y mantiene coherencia con la escala C=0–10, B=11–13, A=14–17 y AD=18–20.

Las deudas, pagos o morosidad **no forman parte del modelo predictivo**.

## 2. Tratamiento de asistencia faltante

Si no existen registros de asistencia en la ventana de 30 días:

- no se interpreta como `0 %`;
- no se interpreta como `100 %`;
- los campos de asistencia se exportan vacíos;
- durante el entrenamiento se imputan con la mediana aprendida en los datos de entrenamiento;
- esos valores imputados no se presentan al usuario como factores observados;
- los escenarios contrafactuales no proponen mejoras de asistencia si no existe asistencia observada.

El modelo generado con esta política usa `schema_version = 2`. Un artefacto anterior debe reentrenarse.

## 3. Exportar nuevamente el dataset real

Desde la raíz del proyecto:

```powershell
C:\xampp\php\php.exe tools\export_risk_dataset.php --school=ID_COLEGIO --output=storage\risk_dataset.csv
```

Para la validación final **no usar** `--allow-estimated-cutoffs=1` salvo una prueba exploratoria. El reporte debe usar cierres o fechas reales para reducir fuga temporal.

El exportador mostrará:

- filas;
- positivos y negativos;
- filas sin asistencia suficiente;
- criterio crítico;
- bimestres omitidos por falta de cutoff confiable;
- observaciones omitidas por falta del bimestre siguiente.

## 4. Reentrenar el modelo v2

```powershell
python ml\train_risk_model.py --input storage\risk_dataset.csv --output edusync\storage\ai_models\risk_model.json
```

El artefacto incluye:

- Precision;
- Recall;
- F1;
- Balanced Accuracy;
- ROC-AUC;
- PR-AUC;
- matriz de confusión;
- balance de clases;
- tasa de datos faltantes;
- fuentes de cutoff;
- mediana usada para imputación;
- benchmark Random Forest.

La regresión logística sigue siendo el modelo desplegable porque puede reproducirse exactamente en PHP y permite explicar contribuciones. Random Forest permanece como benchmark.

## 5. Ejecutar auditoría repetida

```powershell
python ml\validate_risk_model.py --input storage\risk_dataset.csv --model edusync\storage\ai_models\risk_model.json --output storage\risk_validation_report.json --splits 10
```

El script repite separaciones por `student_id`, de modo que un mismo estudiante no aparezca simultáneamente en entrenamiento y prueba.

Estados posibles:

- `NO_APTO_PARA_MAIN`: existe al menos un bloqueador metodológico.
- `REVISAR`: no hay bloqueador duro, pero existen advertencias.
- `APTO_PARA_PILOTO`: supera las comprobaciones automáticas; todavía requiere revisión humana y prueba funcional local.

## 6. Comprobaciones automáticas

El reporte revisa, entre otras cosas:

- cantidad de filas y estudiantes distintos;
- balance de la variable objetivo;
- faltantes por variable;
- presencia de `estimated_quarter`;
- estabilidad de Recall, Precision, F1, Balanced Accuracy, ROC-AUC y PR-AUC en varios splits;
- compatibilidad del `risk_model.json` con esquema v2;
- coherencia del umbral crítico `<10.5`;
- falsos positivos y falsos negativos de un split de referencia.

Los umbrales de advertencia incluidos en el script son criterios operativos provisionales, no estándares universales. Deben justificarse en la tesis según el objetivo de detección temprana del colegio.

## 7. Revisión manual de errores

El JSON de validación incluye ejemplos identificados solo por `student_id`, año y bimestre para revisar localmente:

- **falso positivo:** el modelo alertó riesgo, pero el siguiente bimestre no tuvo curso crítico;
- **falso negativo:** el modelo no alertó, pero el siguiente bimestre sí tuvo curso crítico.

Para una alerta temprana suele ser especialmente importante revisar los falsos negativos, porque representan estudiantes en riesgo que el sistema no detectó.

## 8. Control funcional local antes del PR

Ejecutar:

```powershell
C:\xampp\php\php.exe -l edusync\includes\predictive_risk.php
C:\xampp\php\php.exe -l edusync\includes\predictive_interventions.php
C:\xampp\php\php.exe -l edusync\includes\predictive_risk_router.php
C:\xampp\php\php.exe -l edusync\risk_dashboard_api.php
C:\xampp\php\php.exe -l edusync\pages\risk_dashboard.php
python -m py_compile ml\train_risk_model.py
python -m py_compile ml\validate_risk_model.py
```

Luego probar localmente al menos 5–10 estudiantes con perfiles distintos:

1. riesgo alto con asistencia disponible;
2. riesgo alto sin asistencia disponible;
3. riesgo medio;
4. riesgo bajo;
5. estudiante sin datos suficientes;
6. explicación de factores;
7. escenario contrafactual;
8. registro y cierre de intervención.

## 9. Criterio de decisión antes de fusionar

No fusionar el PR solo porque el dashboard “se vea bien”. Antes deben cumplirse estas condiciones:

- sin bloqueadores en `risk_validation_report.json`;
- sin cutoffs estimados en el dataset final;
- modelo v2 reentrenado con datos reales;
- ambas clases presentes en entrenamiento y validación;
- varios splits válidos;
- métricas revisadas, priorizando Recall, F1 y PR-AUC;
- falsos positivos y falsos negativos revisados manualmente;
- asistencia faltante tratada como desconocida;
- prueba local completa de dashboard, chatbot e intervenciones;
- alcance limitado a administración durante el piloto.

Solo después de esta revisión se debe decidir si el PR está listo para `main` y posteriormente para un piloto controlado en producción.
