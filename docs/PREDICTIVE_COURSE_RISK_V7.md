# Alerta Temprana Inteligente v7 — modelo longitudinal por estudiante + curso

## Objetivo

La v7 mantiene la unidad predictiva:

`estudiante + curso + bimestre cerrado`

y responde:

> Con toda la información disponible hasta el cierre del bimestre N, ¿qué probabilidad existe de que el mismo curso presente rendimiento crítico en N+1?

No usa información del bimestre objetivo como predictor.

## Qué añade frente a v6

### Trayectoria del mismo año
- promedio actual;
- promedio anterior;
- cambio frente al bimestre anterior;
- pendiente del año;
- promedio y mínimo del año;
- cantidad de periodos disponibles;
- periodo actual crítico;
- periodo anterior crítico;
- cantidad de bimestres críticos consecutivos;
- proporción de bimestres críticos del año.

### Historial de años anteriores
El alumno se vincula por DNI cuando existe y, como respaldo, por nombre dentro del mismo colegio.

Variables:
- periodos históricos disponibles;
- promedio histórico del mismo curso;
- último promedio histórico;
- pendiente histórica;
- proporción de periodos críticos;
- último periodo histórico crítico;
- promedio del mismo bimestre en años previos;
- persistencia histórica después de un periodo crítico.

Solo se usan años anteriores al año que se está prediciendo.

### Dinámica de evaluaciones
- última evaluación;
- promedio de las últimas tres;
- tendencia de evaluaciones del bimestre;
- dispersión de evaluaciones;
- proporción de evaluaciones bajas;
- racha de evaluaciones bajas.

Cuando existe fecha académica real de evaluación se utiliza para ordenar. Si no existe, se usa el orden de los registros como respaldo.

### Competencias
- competencias calificadas;
- número y proporción de competencias críticas;
- peso porcentual de competencias críticas;
- competencia con menor promedio;
- dispersión entre competencias.

El promedio del curso sigue alineado con EduSync y utiliza los porcentajes de las competencias.

Reglas de cierre del piloto:
- las competencias/evaluaciones con peso 0% (por ejemplo, **Evaluación No Oficial (No Promedia)**) se excluyen por completo del promedio y de las señales predictivas;
- para datos históricos heredados, si los pesos positivos no suman 100%, el exportador los normaliza proporcionalmente para conservar ese periodo sin alterar la relación entre competencias;
- para la inferencia actual, las competencias oficiales con peso positivo deben sumar 100% (tolerancia ±0.1); si no, el curso no se predice hasta corregir su configuración;
- Libro de Notas muestra una advertencia cuando el total oficial no es 100%.

### Asistencia y contexto
- asistencia de los 30 días previos;
- cambio de asistencia frente al periodo anterior;
- ausencias;
- tardanzas;
- promedio del aula en el mismo curso;
- proporción de calificaciones bajas del aula;
- proporción de estudiantes del aula en zona crítica;
- diferencia estudiante vs aula.

`teacher_id` no es predictor.

## Normalización de letras

Para cálculo ponderado y trayectoria:

- C = 5
- B = 12
- A = 15.5
- AD = 19

Esta es la misma escala canónica usada por el Libro de Notas. Una nota vacía nunca se convierte en cero.

Rendimiento crítico operativo:

- promedio ponderado del curso < 10.5

## Umbrales visibles congelados para el piloto

- Bajo: < 0.20
- Medio: 0.20 a < 0.40
- Alto: >= 0.40

Los umbrales no alteran la probabilidad del modelo; solo convierten una probabilidad calibrada en una categoría visible.

## Archivos v7

- `tools/export_course_risk_dataset_v7.php`
- `ml/train_course_risk_model_v7.py`
- `ml/validate_course_risk_model_v7.py`
- `ml/compare_course_risk_models_v7.py`
- `edusync/includes/predictive_course_risk.php`
- `edusync/includes/risk_dashboard_course_fast.php`

El artefacto continúa en:

`edusync/storage/ai_models/course_risk_model.json`

pero v7 usa:

- `schema_version = 7`
- `model_variant = student_course_longitudinal_v7`

## Prueba local

Desde la raíz del proyecto:

```powershell
git fetch origin
git switch tesis-intervenciones-contrafactual-dashboard
git pull --ff-only origin tesis-intervenciones-contrafactual-dashboard
```

### 1. Lint PHP

```powershell
C:\xampp\php\php.exe -l edusync\includes\predictive_course_risk.php
C:\xampp\php\php.exe -l edusync\includes\risk_dashboard_course.php
C:\xampp\php\php.exe -l edusync\includes\risk_dashboard_course_fast.php
C:\xampp\php\php.exe -l edusync\risk_dashboard_api.php
C:\xampp\php\php.exe -l tools\export_course_risk_dataset_v7.php
C:\xampp\php\php.exe -l edusync\gradebook_api.php
C:\xampp\php\php.exe -l edusync\pages\grades.php
C:\xampp\php\php.exe tools\predictive_course_risk_smoke_test.php
```

### 2. Compilar Python

```powershell
python -m py_compile ml\train_course_risk_model_v7.py
python -m py_compile ml\validate_course_risk_model_v7.py
python -m py_compile ml\compare_course_risk_models_v7.py
```

### 3. Exportar dataset

Usa el ID real del colegio:

```powershell
C:\xampp\php\php.exe tools\export_course_risk_dataset_v7.php --school=ID_REAL --output=storage\course_risk_dataset_v7.csv
```

No uses `--year` si quieres que el modelo aprenda de todos los años disponibles.

### 4. Entrenar v7

```powershell
python ml\train_course_risk_model_v7.py --input storage\course_risk_dataset_v7.csv --output edusync\storage\ai_models\course_risk_model.json
```

### 5. Validar

```powershell
python ml\validate_course_risk_model_v7.py --input storage\course_risk_dataset_v7.csv --model edusync\storage\ai_models\course_risk_model.json --output storage\course_risk_validation_report_v7.json --splits 10
```

La validación incluye un subgrupo específico:

`previous_course_critical = 1 AND current_course_critical = 1`

para comprobar casos como 8.3 → 9.3 y medir si la probabilidad predicha está subestimando la tasa real del siguiente bimestre.

### 6. Comparar familias de modelos

```powershell
python ml\compare_course_risk_models_v7.py --input storage\course_risk_dataset_v7.csv --target-recall 0.80
```

Compara sobre el mismo holdout temporal:

- Logistic Regression
- Random Forest
- Extra Trees
- HistGradientBoosting

La comparación no cambia automáticamente el modelo activo.

## Regla para decidir si v7 puede avanzar

No pasar a `main` ni producción hasta revisar:

1. tamaño y prevalencia del dataset;
2. ausencia de errores de lint;
3. validación agrupada por alumno estable;
4. último holdout temporal;
5. Recall, Precision, F1, PR-AUC y ROC-AUC;
6. Brier y ECE;
7. falsos negativos;
8. subgrupo de bimestres críticos consecutivos;
9. comparación logística vs no lineales;
10. prueba funcional de dashboard, detalle, filtros, chatbot e intervenciones.

## Caso de control recomendado

Usar el caso real detectado en Física como prueba manual después de entrenar v7:

- I bimestre: 8.3
- II bimestre: 9.3
- promedio del aula II: 14.8

No se debe imponer manualmente que el resultado sea Alto. Se debe comprobar si v7, después de aprender del historial, evaluaciones, competencias y contexto, asigna una probabilidad coherente con la frecuencia real observada en casos históricos comparables.
