# Alerta temprana predictiva y explicable — EduSync

## Objetivo

Este módulo estima la probabilidad de que un estudiante presente **al menos un curso con registros académicos críticos en el bimestre siguiente**. La predicción es una alerta de apoyo para directivos y no ejecuta decisiones automáticas.

La etiqueta de riesgo usa el mismo criterio operativo del chatbot actual:

- nota numérica `<= 10`, o
- nivel literal `C`.

## Variables usadas

El modelo de producción usa únicamente variables académicas y de asistencia:

1. promedio académico del bimestre actual;
2. promedio académico del bimestre anterior;
3. tendencia del promedio;
4. cantidad de registros críticos actuales;
5. cantidad de cursos con registros críticos;
6. porcentaje de asistencia de los últimos 30 días;
7. tardanzas de los últimos 30 días;
8. ausencias de los últimos 30 días.

**No se usan deudas ni pagos como predictores académicos.** Finanzas puede seguir mostrándose en la Ficha 360, pero no participa en el puntaje de riesgo para evitar introducir un proxy socioeconómico.

## Prevención de fuga de información

Cada fila de entrenamiento se construye con información disponible hasta un bimestre `t` y el objetivo se obtiene del bimestre `t+1`.

El exportador intenta obtener la fecha de corte en este orden:

1. cierre real de `grade_period_closures.closed_at`;
2. fecha real registrada en `evaluations` (`date_created`, `created_at`, `evaluation_date` o `date`);
3. solo si se solicita explícitamente, una fecha estimada dividiendo el año académico en cuatro periodos.

Para resultados finales de tesis se recomienda **no usar** `--allow-estimated-cutoffs=1`.

## 1. Exportar dataset histórico

Desde la raíz del repositorio, usando PHP de XAMPP en Windows:

```powershell
C:\xampp\php\php.exe tools\export_risk_dataset.php --school=ID_COLEGIO --output=storage\risk_dataset.csv
```

Para limitar a un año académico concreto:

```powershell
C:\xampp\php\php.exe tools\export_risk_dataset.php --school=ID_COLEGIO --year=ID_ANIO --output=storage\risk_dataset.csv
```

Solo para pruebas preliminares, si los años antiguos no tienen cierres ni fechas confiables:

```powershell
C:\xampp\php\php.exe tools\export_risk_dataset.php --school=ID_COLEGIO --allow-estimated-cutoffs=1 --output=storage\risk_dataset.csv
```

El CSV contiene `student_id` para poder separar grupos durante la evaluación, pero **no contiene nombres**. El archivo está ignorado por Git y no debe subirse a un repositorio público.

## 2. Preparar Python

```powershell
py -m venv .venv
.venv\Scripts\Activate.ps1
python -m pip install --upgrade pip
pip install -r ml\requirements.txt
```

## 3. Entrenar y evaluar

```powershell
python ml\train_risk_model.py --input storage\risk_dataset.csv --output edusync\storage\ai_models\risk_model.json
```

El script exige por defecto al menos 40 observaciones y estudiantes suficientes para separar entrenamiento y prueba. Si los datos no contienen ambas clases, el entrenamiento se detiene en lugar de producir métricas engañosas.

### Evaluación

La evaluación usa `GroupShuffleSplit` con `student_id` como grupo. De este modo, un mismo estudiante no aparece simultáneamente en entrenamiento y prueba.

Se reportan:

- accuracy;
- balanced accuracy;
- precision;
- recall;
- F1;
- ROC-AUC;
- matriz de confusión.

También se entrena un **Random Forest** como benchmark. El modelo desplegado en PHP es regresión logística porque:

- permite reproducir exactamente la inferencia en Hostinger sin ejecutar Python;
- cada variable tiene una contribución interpretable;
- facilita explicar al jurado por qué un estudiante fue marcado con riesgo.

No se debe afirmar que un modelo es mejor antes de ejecutar el experimento real y comparar las métricas obtenidas.

## 4. Artefacto del modelo

El entrenamiento genera:

```text
edusync/storage/ai_models/risk_model.json
```

El JSON contiene solamente:

- nombres de variables;
- medias y escalas;
- coeficientes;
- intercepto;
- umbrales de riesgo;
- métricas agregadas del experimento.

No contiene nombres de estudiantes ni credenciales.

El archivo se ignora en Git para evitar publicar accidentalmente artefactos experimentales. Para producción debe subirse manualmente al mismo path del servidor o configurar:

```text
EDUSYNC_RISK_MODEL_PATH=/ruta/privada/risk_model.json
```

## 5. Consultas del chatbot

Una vez instalado el modelo, Administración puede consultar por lenguaje natural:

```text
¿Qué estudiantes tienen mayor riesgo el próximo bimestre?
```

```text
Dame los 10 estudiantes con mayor riesgo futuro de secundaria
```

```text
¿Cuál es el riesgo predictivo de NOMBRE DEL ESTUDIANTE?
```

```text
¿Por qué está en riesgo NOMBRE DEL ESTUDIANTE?
```

```text
¿Qué precisión tiene el modelo predictivo?
```

Las probabilidades y factores se calculan en PHP desde el artefacto entrenado. Groq no calcula ni modifica el puntaje.

## 6. Interpretación

El puntaje se divide inicialmente en:

- **Bajo:** `< 40%`;
- **Medio:** `40% a < 70%`;
- **Alto:** `>= 70%`.

Los umbrales pueden ajustarse después de analizar sensibilidad, especificidad y el costo educativo de falsos positivos/falsos negativos.

La explicación muestra las variables con mayor contribución positiva al riesgo, por ejemplo:

```text
Estudiante X — riesgo Alto (78.3%)
Factores: ausencias últimos 30 días: 4; cursos críticos actuales: 2; tendencia del rendimiento: -1.40
```

Esto es una explicación del **modelo**, no una afirmación causal.

## 7. Limitaciones que deben declararse en la tesis

- Un modelo entrenado en un colegio no debe asumirse válido para otros colegios sin validación externa.
- La ausencia de registros de asistencia puede afectar las variables de asistencia.
- La predicción depende de la calidad y continuidad de las notas históricas.
- La probabilidad no reemplaza el juicio del docente/directivo.
- Los factores explicativos indican contribución al modelo, no causalidad.
- Se recomienda evaluar desempeño por nivel educativo y revisar posibles diferencias entre grupos antes de cualquier uso de alto impacto.

## 8. Arquitectura

```text
Datos históricos EduSync
        ↓
Exportación temporal por bimestre
        ↓
Dataset sin fuga temporal
        ↓
Entrenamiento + holdout por estudiante
        ↓
Regresión logística + benchmark Random Forest
        ↓
risk_model.json
        ↓
Motor PHP de inferencia
        ↓
Explicación por contribuciones
        ↓
Asistente conversacional EduSync
```

Esta separación permite demostrar que el LLM es la interfaz conversacional, mientras que el componente predictivo es un modelo medible, reproducible y explicable.
