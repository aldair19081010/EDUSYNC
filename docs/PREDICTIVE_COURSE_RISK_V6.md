# Alerta Temprana Inteligente v6 — predicción por curso

## Qué cambió

El modelo anterior estimaba si un estudiante tendría al menos un registro/curso crítico en el siguiente bimestre. Ese objetivo era demasiado general para la finalidad pedagógica del módulo.

La v6 cambia la unidad de análisis a:

`estudiante + curso + bimestre cerrado`

La pregunta es:

> Con lo que EduSync conoce al cerrar el bimestre N, ¿qué probabilidad existe de que el **mismo curso** presente rendimiento crítico en N+1?

Ejemplo:

`Juan + Matemática + III cerrado -> riesgo de rendimiento crítico de Matemática en IV`.

## Terminología visible

La interfaz deja de usar como conceptos principales “factores críticos”, “registros críticos” y “cursos críticos”. Se muestran:

- **Cursos en riesgo**: cursos con probabilidad Media/Alta de rendimiento crítico en el bimestre objetivo.
- **Motivos de la alerta**: señales concretas que ayudan a entender el resultado.
- **Evaluaciones bajas**: calificaciones C o numéricas menores de 10.5.
- **Calificaciones faltantes**: datos ausentes; nunca se convierten en cero.
- **Tendencia académica**: evolución del mismo curso entre bimestres y, como contexto, entre años.
- **Prioridad general**: resumen derivado de los cursos. Alto si existe al menos un curso Alto; Medio si no hay Alto pero existe Medio; Bajo en otro caso. No se inventa un porcentaje general nuevo.

## Normalización de notas

Para clasificar una calificación baja:

- C = baja/crítica;
- número < 10.5 = bajo/crítico.

Para construir una trayectoria numérica con escalas literales, v6 usa valores representativos exclusivamente para cálculo:

- C = 10
- B = 12
- A = 15.5
- AD = 19

Una nota vacía sigue siendo `NULL`/faltante. No se convierte a 0.

El objetivo operativo es **rendimiento crítico del curso en el siguiente bimestre**, no una sentencia sobre promoción anual ni una garantía de que el estudiante “jalará”.

## Señales del modelo por curso

- promedio actual del mismo curso;
- distancia respecto al umbral 10.5;
- cambio respecto al bimestre anterior del mismo curso;
- tendencia del mismo curso durante el año;
- número de bimestres disponibles;
- proporción de evaluaciones/calificaciones bajas;
- proporción de calificaciones faltantes;
- cantidad de evaluaciones del curso;
- asistencia en los 30 días previos al cierre;
- cambio reciente de asistencia cuando hay periodo previo;
- tardanzas;
- ausencias;
- promedio del aula en ese mismo curso;
- proporción de calificaciones bajas del aula;
- proporción de estudiantes del aula con promedio crítico en ese curso;
- diferencia entre el estudiante y el promedio del aula.

`teacher_id` **no se usa como predictor**. Los patrones del aula sirven para detectar un fenómeno compartido y no para culpar al docente.

## Asistencia y evaluaciones faltantes

La asistencia es una señal asociada. El sistema puede decir que una asistencia irregular acompaña al bajo rendimiento, pero no afirma que sea la causa.

Si la tabla `evaluations` tiene una fecha académica real (`evaluation_date` o `date`), EduSync puede revisar si una calificación faltante coincide con una ausencia. `created_at` no se utiliza como fecha de examen.

Si no hay una fecha académica fiable, el sistema debe decir que no puede establecer esa coincidencia.

## Historial entre años

Para mostrar la evolución del mismo curso entre años, los registros históricos del alumno se vinculan por DNI dentro del mismo colegio. Esto permite mostrar, por ejemplo:

```text
Matemática
2025: I 14 -> II 13 -> III 12 -> IV 11
2026: I 11 -> II 10 -> III 11
```

La historia multianual se muestra como contexto explicativo. La primera versión del modelo v6 utiliza principalmente la trayectoria disponible hasta el bimestre fuente sin mirar información futura.

## Contexto del aula

EduSync calcula señales objetivas del mismo curso/sección:

- promedio del aula;
- porcentaje de estudiantes con rendimiento crítico;
- proporción de calificaciones bajas;
- cantidad de evaluaciones.

Ejemplo de interpretación:

> “La disminución no es solo individual: una proporción importante del aula presenta rendimiento bajo en Matemática.”

Esto no equivale a atribuir causalidad al docente.

## Archivos v6

- `edusync/includes/predictive_course_risk.php`
- `edusync/includes/predictive_course_history.php`
- `edusync/includes/risk_dashboard_course.php`
- `edusync/includes/predictive_course_interventions.php`
- `tools/export_course_risk_dataset.php`
- `ml/train_course_risk_model.py`
- `ml/validate_course_risk_model.py`
- `tools/predictive_course_risk_smoke_test.php`
- `sql/predictive_course_risk_upgrade.sql`

Los modelos v4/v5/v5.1 quedan únicamente como referencia experimental y no deben volver a ser la fuente principal del dashboard una vez validado v6.

## Prueba local

```powershell
cd C:\xampp\htdocs\interfaz
git fetch origin
git switch tesis-intervenciones-contrafactual-dashboard
git pull --ff-only origin tesis-intervenciones-contrafactual-dashboard

C:\xampp\php\php.exe -l edusync\includes\predictive_course_risk.php
C:\xampp\php\php.exe -l edusync\includes\predictive_course_history.php
C:\xampp\php\php.exe -l edusync\includes\risk_dashboard_course.php
C:\xampp\php\php.exe -l edusync\includes\predictive_course_interventions.php
C:\xampp\php\php.exe -l edusync\risk_dashboard_api.php
C:\xampp\php\php.exe -l edusync\pages\risk_dashboard.php
C:\xampp\php\php.exe -l edusync\includes\predictive_risk_router.php
C:\xampp\php\php.exe -l tools\export_course_risk_dataset.php
C:\xampp\php\php.exe -l tools\predictive_course_risk_smoke_test.php
python -m py_compile ml\train_course_risk_model.py
python -m py_compile ml\validate_course_risk_model.py
```

Smoke test:

```powershell
C:\xampp\php\php.exe tools\predictive_course_risk_smoke_test.php
```

Exportar con el ID real del colegio:

```powershell
C:\xampp\php\php.exe tools\export_course_risk_dataset.php --school=ID_REAL --output=storage\course_risk_dataset.csv
```

Entrenar:

```powershell
python ml\train_course_risk_model.py --input storage\course_risk_dataset.csv --output edusync\storage\ai_models\course_risk_model.json
```

Validar:

```powershell
python ml\validate_course_risk_model.py --input storage\course_risk_dataset.csv --model edusync\storage\ai_models\course_risk_model.json --output storage\course_risk_validation_report.json --splits 10
```

## Intervenciones por curso

Si `student_risk_interventions` ya existía antes de v6, ejecutar una sola vez:

```text
sql/predictive_course_risk_upgrade.sql
```

Las instalaciones nuevas pueden usar `sql/predictive_interventions_upgrade.sql`, que ya incluye `course_id` y `course_name`.

## Regla antes de main

No fusionar ni desplegar hasta:

1. pasar lint y smoke test;
2. revisar tamaño/prevalencia del dataset por curso;
3. entrenar v6;
4. pasar validación agrupada por estudiante;
5. pasar validación temporal walk-forward;
6. revisar falsos negativos/falsos positivos, Brier y ECE;
7. verificar funcionalmente dashboard, detalle, historial, asistencia, chatbot e intervenciones por curso.
