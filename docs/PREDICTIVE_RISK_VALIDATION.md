# Validación previa a `main` y producción — Alerta Temprana Inteligente

Este procedimiento se ejecuta **solo en la rama de desarrollo** `tesis-intervenciones-contrafactual-dashboard`. No fusionar a `main` ni desplegar a producción hasta completar la validación con datos reales.

## 1. Definición del riesgo

La variable objetivo es: **al menos un curso crítico en el bimestre siguiente ya cerrado**.

Un registro es crítico cuando:

- la letra es `C`; o
- la nota numérica es `< 10.5`.

Las deudas, pagos o morosidad no forman parte del modelo predictivo.

## 2. Política temporal v3

La inferencia en vivo y el entrenamiento histórico cumplen reglas distintas:

- **Inferencia en vivo:** último bimestre cerrado `N` → riesgo estimado en `N+1`, aunque `N+1` esté actualmente en proceso.
- **Entrenamiento histórico:** una fila `N → N+1` solo se crea cuando ambos bimestres están cerrados.
- Las notas parciales del bimestre en proceso no se usan como resultado histórico.
- La asistencia se corta en la fecha segura del cierre del bimestre base, no en la fecha actual.

Ejemplo con I y II cerrados y III en proceso:

- base de la alerta: II;
- objetivo de la alerta: III;
- `II → III` no entra todavía al entrenamiento hasta que III cierre.

El modelo actual usa `schema_version = 3`. Cualquier `risk_model.json` anterior debe reentrenarse.

## 3. Asistencia faltante

Si no existen registros suficientes en la ventana de 30 días:

- no se interpreta como `0 %`;
- no se interpreta como `100 %`;
- se exporta como dato faltante;
- se imputa con la mediana aprendida únicamente en entrenamiento;
- no se presenta al usuario como factor observado;
- los escenarios contrafactuales no proponen mejoras de asistencia si no existen datos observados.

## 4. Verificar cierres académicos

Antes de exportar:

```powershell
C:\xampp\php\php.exe tools\predictive_period_status.php --school=ID_COLEGIO
```

Debe identificar correctamente qué bimestres están cerrados y cuál es la base válida para la alerta en vivo.

## 5. Exportar dataset histórico

```powershell
C:\xampp\php\php.exe tools\export_risk_dataset.php --school=ID_COLEGIO --output=storage\risk_dataset.csv
```

Para la validación final no usar cutoffs estimados.

El dataset incluye, entre otros campos:

- `academic_year_id`;
- `student_id`;
- `bimester`;
- `target_bimester`;
- `cutoff_date`;
- `cutoff_source`;
- variables académicas y de asistencia;
- `target_next_bimester_risk`.

## 6. Entrenar modelo v3

```powershell
python ml\train_risk_model.py --input storage\risk_dataset.csv --output edusync\storage\ai_models\risk_model.json
```

El modelo desplegable sigue siendo regresión logística para que la inferencia y las contribuciones sean reproducibles en PHP. Random Forest se mantiene únicamente como benchmark.

## 7. Auditoría completa

```powershell
python ml\validate_risk_model.py --input storage\risk_dataset.csv --model edusync\storage\ai_models\risk_model.json --output storage\risk_validation_report.json --splits 10
```

El script ejecuta dos validaciones complementarias.

### A. Validación agrupada por estudiante

Usa `Repeated GroupShuffleSplit` por `student_id` para impedir que el mismo estudiante aparezca simultáneamente en entrenamiento y prueba dentro de cada split.

Sirve principalmente para medir **generalización a estudiantes no vistos**.

Reporta:

- Recall;
- Precision;
- F1;
- Balanced Accuracy;
- ROC-AUC;
- PR-AUC;
- matriz de confusión;
- media y desviación entre splits.

### B. Validación temporal walk-forward

Ordena los periodos por `cutoff_date`. Para evaluar un periodo, entrena exclusivamente con filas cuya fecha de corte es anterior.

Ejemplo conceptual:

```text
I→II y II→III históricos
          ↓ entrenar
III→IV posterior
          ↓ probar
```

Esto se aproxima más al uso real: **el modelo nunca aprende con información posterior al periodo que está intentando predecir**.

El último periodo temporal válido se reporta como `latest_holdout` y debe revisarse especialmente.

El solapamiento de estudiantes entre train y test temporal es esperable, porque simula predecir un periodo futuro de estudiantes que ya estaban matriculados. Por eso esta validación responde una pregunta distinta a la agrupada por estudiante.

## 8. Baseline trivial

Para el último holdout temporal, el informe calcula qué ocurriría si simplemente se marcara **a todos los estudiantes como riesgo**.

Esto es importante cuando la prevalencia de riesgo es alta. Un clasificador trivial puede conseguir una accuracy aparente elevada, pero su `balanced_accuracy` será aproximadamente `0.5`.

El modelo debe superar claramente ese comportamiento trivial.

## 9. Auditoría TP / TN / FP / FN

El reporte clasifica casos en:

- verdadero positivo;
- verdadero negativo;
- falso positivo;
- falso negativo.

Los falsos negativos tienen prioridad de revisión porque representan estudiantes que realmente entraron en riesgo y no fueron alertados.

Además se resumen errores por:

- bimestre `N → N+1`;
- nivel educativo;
- disponibilidad de asistencia;
- medias de las variables predictoras.

Los estudiantes se guardan por defecto con una clave anónima `student_key`.

Para una auditoría estrictamente local donde necesites ubicar al estudiante real:

```powershell
python ml\validate_risk_model.py --input storage\risk_dataset.csv --model edusync\storage\ai_models\risk_model.json --output storage\risk_validation_report.json --splits 10 --include-student-id
```

No usar identificadores reales de menores en tablas o anexos públicos de la tesis.

## 10. Estados del validador

- `NO_APTO_PARA_MAIN`: existe un bloqueador metodológico.
- `REVISAR`: no existe bloqueador duro, pero hay advertencias.
- `APTO_PARA_PILOTO`: supera las comprobaciones automáticas, pero todavía requiere revisión humana y prueba funcional local.

`APTO_PARA_PILOTO` no significa automáticamente `APTO_PARA_PRODUCCIÓN`.

## 11. Validación funcional local

```powershell
C:\xampp\php\php.exe -l edusync\includes\predictive_risk.php
C:\xampp\php\php.exe -l edusync\includes\predictive_interventions.php
C:\xampp\php\php.exe -l edusync\includes\predictive_risk_router.php
C:\xampp\php\php.exe -l edusync\risk_dashboard_api.php
C:\xampp\php\php.exe -l edusync\pages\risk_dashboard.php
C:\xampp\php\php.exe -l tools\export_risk_dataset.php
C:\xampp\php\php.exe -l tools\predictive_period_status.php
python -m py_compile ml\train_risk_model.py
python -m py_compile ml\validate_risk_model.py
```

Después probar dashboard, chatbot, contrafactuales e intervenciones con estudiantes de distintos perfiles.

## 12. Criterio antes de fusionar

No fusionar el PR solo porque las métricas agrupadas sean altas. Antes deben cumplirse estas condiciones:

- política temporal v3 verificada;
- sin cutoffs estimados;
- `risk_model.json` v3 reentrenado;
- validación agrupada estable;
- validación temporal walk-forward revisada;
- último holdout temporal con desempeño razonable frente al baseline trivial;
- falsos positivos y, especialmente, falsos negativos auditados;
- asistencia faltante tratada como desconocida;
- pruebas locales de dashboard, chatbot e intervenciones completadas;
- primera exposición limitada a administración durante el piloto.

Solo después se decide si el PR está listo para `main` y posteriormente para un piloto controlado en producción.
