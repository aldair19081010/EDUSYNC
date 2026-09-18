# EduSync — Intervenciones, contrafactuales y dashboard de alerta temprana

## Objetivo

Este módulo convierte la predicción académica en un sistema de apoyo a decisiones. EduSync permite:

1. priorizar estudiantes por riesgo académico estimado;
2. explicar los factores del modelo;
3. simular escenarios contrafactuales;
4. registrar una intervención decidida por una persona responsable;
5. hacer seguimiento de la intervención;
6. medir un riesgo posterior cuando exista un nuevo bimestre cerrado y comparable.

La predicción no ejecuta decisiones automáticas sobre estudiantes.

## Política temporal

La alerta en vivo siempre parte del **último bimestre cerrado** y estima riesgo en el bimestre siguiente.

Ejemplo:

```text
I cerrado
II cerrado
III en proceso

Base de la alerta: II
Objetivo: riesgo académico en III
```

Las notas parciales del III no convierten al III en bimestre base.

Para entrenamiento histórico solo se usan pares `N -> N+1` cuando ambos bimestres están cerrados.

## Modelo v4

El modelo v4 utiliza:

- promedio académico actual;
- tendencia académica cuando existe un bimestre anterior;
- indicador `previous_bimester_available`;
- registros críticos actuales;
- cursos críticos actuales;
- asistencia, tardanzas y ausencias de los 30 días previos al cierre.

`grade_mean_previous` ya no es predictor para evitar redundancia exacta. El promedio previo observado se conserva únicamente como dato diagnóstico cuando existe.

Las probabilidades se calibran mediante Platt scaling aprendido sobre predicciones fuera de muestra agrupadas por estudiante. Los niveles Alto/Medio/Bajo se calculan sobre la probabilidad calibrada.

Las deudas, pagos y morosidad no intervienen en el riesgo académico.

## Arquitectura

```text
Datos hasta cierre de N
        ↓
Modelo logístico explicable
        ↓
Calibración probabilística
        ↓
Probabilidad y nivel de riesgo en N+1
        ↓
Factores del modelo
        ↓
Simulación contrafactual
        ↓
Decisión humana
        ↓
Intervención
        ↓
Seguimiento
        ↓
Nuevo cierre académico
        ↓
Medición posterior disponible
```

Groq no calcula probabilidades, coeficientes ni contrafactuales. El chatbot presenta resultados producidos por el motor predictivo.

## Migración requerida

Ejecutar una sola vez antes de utilizar intervenciones:

`sql/predictive_interventions_upgrade.sql`

Crea:

- `student_risk_interventions`;
- `student_risk_intervention_log`.

## Dashboard

Administración accede desde:

`index.php?page=risk_dashboard`

Incluye:

- riesgo Alto / Medio / Bajo;
- ranking de estudiantes;
- filtros por nivel, grado, sección y bimestre base cerrado;
- factores predominantes;
- escenarios simulados;
- alta y seguimiento de intervenciones;
- medición descriptiva antes/después cuando existe un nuevo cierre válido.

## Simulación contrafactual

Los escenarios modifican variables del mismo modelo y vuelven a puntuar el vector con el mismo escalado, coeficientes y calibración.

Ejemplo conceptual:

```text
Riesgo calibrado actual: Alto 78 %
Escenario simulado:
- promedio 11.5 -> 13.0
- cursos críticos 2 -> 1
- asistencia 82 % -> 92 %
Riesgo calibrado bajo ese perfil: Medio 54 %
```

Esto significa únicamente que **un perfil con esos valores recibe una probabilidad menor según el modelo**. No demuestra que la intervención cause exactamente esa reducción.

Si el estudiante no tiene asistencia observada, el sistema no inventa cambios hipotéticos de asistencia.

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

Al crear una intervención se conserva la probabilidad calibrada y el nivel de riesgo como línea base.

Una intervención puede marcarse como `Completada` mientras el bimestre objetivo continúa abierto. En ese caso, **no se inventa una probabilidad posterior con notas parciales**: `post_risk_probability` permanece pendiente hasta que exista un nuevo bimestre cerrado que permita una nueva predicción temporalmente válida.

La comparación antes/después es descriptiva y no prueba causalidad.

## Seguridad

- primera versión limitada a administración/dirección;
- colegio tomado de la sesión autenticada;
- estudiantes validados nuevamente por `school_id`;
- mutaciones mediante POST y CSRF;
- consultas preparadas;
- tipos, estados y resultados limitados a listas permitidas;
- chatbot de solo lectura respecto de intervenciones.

## Validación antes de `main`

Antes de fusionar el PR deben completarse:

1. dataset v4 con cierres reales;
2. `risk_model.json` schema v4;
3. validación agrupada por estudiante;
4. validación temporal walk-forward;
5. revisión de Recall, Precision, F1, Balanced Accuracy, ROC-AUC y PR-AUC;
6. revisión de Brier Score y ECE;
7. comparación del Brier con el baseline de prevalencia histórica;
8. auditoría de falsos negativos;
9. smoke test PHP;
10. prueba funcional del dashboard, chatbot, contrafactuales e intervenciones.

`APTO_PARA_PILOTO` no significa automáticamente `APTO_PARA_PRODUCCIÓN`.

## Despliegue posterior

Cuando se autorice un piloto, el servidor necesitará:

- archivos PHP del módulo;
- `risk_model.json` v4 entrenado y validado;
- migración de intervenciones ejecutada;
- permisos MySQL correspondientes.

Python y scikit-learn son necesarios para entrenamiento y validación, no para inferencia PHP.
