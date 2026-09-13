# EduSync — Intervenciones, contrafactuales y dashboard de alerta temprana

## Objetivo

Esta fase transforma la alerta predictiva en un sistema de apoyo a decisiones. EduSync no solo estima riesgo académico para el bimestre siguiente, sino que permite:

1. visualizar estudiantes priorizados y factores que elevan el riesgo;
2. simular escenarios contrafactuales dentro del modelo;
3. registrar una intervención decidida por una persona responsable;
4. hacer seguimiento de la intervención;
5. volver a calcular el riesgo al completarla y conservar una comparación antes/después.

La predicción y las simulaciones no ejecutan decisiones automáticas sobre estudiantes.

## Arquitectura

```text
Datos académicos + asistencia
          ↓
Modelo predictivo explicable
          ↓
Probabilidad y nivel de riesgo
          ↓
Factores del modelo
          ↓
Simulación contrafactual
          ↓
Decisión humana
          ↓
Intervención registrada
          ↓
Seguimiento y resultado observado
          ↓
Nuevo cálculo de riesgo
```

Groq no calcula probabilidades, no modifica coeficientes y no registra intervenciones. El chatbot solo consulta y presenta resultados deterministas del módulo.

## Migración requerida

Ejecutar una sola vez:

`sql/predictive_interventions_upgrade.sql`

Crea:

- `student_risk_interventions`: línea base, intervención, estado, seguimiento y riesgo posterior.
- `student_risk_intervention_log`: trazabilidad de creación y actualización.

No ejecutar esta migración más de una vez manualmente; utiliza `CREATE TABLE IF NOT EXISTS` para instalaciones compatibles.

## Dashboard

Administración accede desde:

`index.php?page=risk_dashboard`

El panel incluye:

- conteo de riesgo Alto / Medio / Bajo;
- estudiantes ordenados por probabilidad;
- filtros por nivel, grado, sección y bimestre base;
- factores predominantes;
- escenarios simulados;
- intervención sugerida según factores predominantes;
- alta y actualización de intervenciones;
- seguimiento vencido;
- comparación entre riesgo inicial y riesgo al completar la intervención.

## Simulación contrafactual

Los escenarios modifican exclusivamente variables que ya forman parte del modelo desplegado. Se prueban escenarios de asistencia, refuerzo académico y combinaciones de ambos. Cada escenario se vuelve a puntuar con los mismos coeficientes, escalado y umbrales del `risk_model.json`.

Ejemplo conceptual:

```text
Riesgo actual: Alto 78.0 %
Escenario: Plan combinado moderado
- asistencia: 82 % → 92 %
- ausencias: 5 → 2
- promedio: 11.5 → 13.0
- cursos críticos: 2 → 1
Riesgo estimado en el escenario: Medio 54.0 %
```

Esto significa únicamente: **si el vector de variables tomara esos valores, el modelo produciría esa probabilidad**. No significa que realizar una acción cause necesariamente esa reducción. La interfaz y el chatbot muestran explícitamente esta limitación.

## Intervenciones

Tipos permitidos:

- Reforzamiento académico
- Tutoría académica
- Seguimiento de asistencia
- Plan de puntualidad
- Comunicación con apoderado
- Acompañamiento socioeducativo
- Seguimiento tutorial

Estados:

- Pendiente
- En proceso
- Completada
- Cancelada

Al crear una intervención, el servidor calcula y almacena la probabilidad y nivel de riesgo existentes en ese momento. Al marcarla como `Completada`, vuelve a calcular el riesgo disponible y almacena `post_risk_probability`.

La comparación antes/después puede servir para análisis descriptivo, pero por sí sola **no demuestra causalidad**. Para una tesis que quiera medir impacto causal se requeriría un diseño experimental o cuasi-experimental adicional.

## Seguridad

- dashboard limitado inicialmente a tipo de usuario 1 (administración/dirección);
- colegio determinado por la sesión autenticada;
- cada estudiante se vuelve a validar contra `school_id`;
- mutaciones mediante POST y token CSRF;
- valores de tipo, estado y resultado trabajan con listas permitidas;
- consultas preparadas;
- el chatbot permanece de solo lectura respecto de intervenciones.

## Consultas del chatbot

Ejemplos:

- `¿Qué tendría que mejorar Juan Pérez para bajar su riesgo?`
- `Simula cómo reducir el riesgo de Juan Pérez.`
- `Muéstrame las intervenciones de Juan Pérez.`
- `¿Qué precisión tiene el modelo predictivo?`
- `¿Qué estudiantes tienen mayor riesgo el próximo bimestre?`

## Indicadores para la tesis

Además de ROC-AUC, F1, recall y demás métricas del modelo predictivo, esta fase permite medir indicadores de uso del sistema:

- número de alertas revisadas;
- número de intervenciones registradas;
- porcentaje de intervenciones completadas;
- seguimientos vencidos;
- intervenciones con medición antes/después;
- proporción de casos con menor probabilidad posterior;
- variación media de probabilidad en casos medidos.

Estos indicadores deben presentarse como resultados descriptivos salvo que el diseño de investigación permita inferencias causales.

## Despliegue

Además de los archivos PHP del módulo, producción necesita:

1. el `risk_model.json` entrenado y validado;
2. ejecutar `sql/predictive_interventions_upgrade.sql` en la base productiva;
3. mantener permisos de escritura sobre las tablas de intervención para la cuenta MySQL usada por EduSync.

Python y scikit-learn continúan siendo necesarios solo para entrenamiento/reentrenamiento, no para la inferencia en producción.
