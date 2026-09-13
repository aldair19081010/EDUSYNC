# Configuración de IA para el Asistente EduSync

El Asistente EduSync puede trabajar con IA local o con un proveedor externo compatible, manteniendo las mismas herramientas seguras de EduSync.

- **IA local**: Ollama, vLLM, llama.cpp u otro servidor compatible.
- **IA en producción**: Groq u OpenAI mediante API HTTPS.
- **Herramientas EduSync**: consultas cerradas, de solo lectura y filtradas por rol.
- **Modo local por reglas**: respaldo automático si el proveedor de IA no responde o no está configurado.

La IA no recibe acceso SQL y no puede modificar datos. `school_id`, `student_id` y `teacher_id` siempre salen de la sesión del servidor.

## Producción con Groq

Groq está soportado como proveedor nativo. La integración usa por defecto:

- API: `https://api.groq.com/openai/v1/chat/completions`
- Modelo: `openai/gpt-oss-20b`
- Estilo: `chat_completions`
- Autenticación: `GROQ_API_KEY`

Configuración mínima:

```text
EDUSYNC_AI_ENABLED=1
EDUSYNC_AI_PROVIDER=groq
GROQ_API_KEY=gsk_xxxxxxxxxxxxxxxxx
```

No es necesario definir endpoint, modelo ni estilo de API para la configuración estándar. Se pueden sobrescribir si se desea:

```text
EDUSYNC_AI_MODEL=openai/gpt-oss-20b
EDUSYNC_AI_API_STYLE=chat_completions
EDUSYNC_AI_ENDPOINT=https://api.groq.com/openai/v1/chat/completions
EDUSYNC_AI_MAX_TOKENS=1500
```

Por compatibilidad también se acepta `EDUSYNC_AI_API_KEY` si no se define `GROQ_API_KEY`, aunque se recomienda usar `GROQ_API_KEY` para mantener la configuración explícita.

Si `EDUSYNC_AI_ENABLED=1` y el proveedor es `groq`, pero no existe ninguna clave, EduSync considera la IA no configurada y utiliza el Modo local por reglas.

La clave debe permanecer únicamente en la configuración privada del servidor. No debe guardarse en GitHub, JavaScript, HTML ni enviarse al navegador.

## Configuración recomendada con Ollama

Para la integración local con herramientas se recomienda usar Chat Completions:

```text
EDUSYNC_AI_ENABLED=1
EDUSYNC_AI_PROVIDER=ollama
EDUSYNC_AI_MODEL=qwen3:4b
EDUSYNC_AI_API_STYLE=chat_completions
EDUSYNC_AI_ENDPOINT=http://127.0.0.1:11434/v1/chat/completions
```

No se necesita `OPENAI_API_KEY` ni `EDUSYNC_AI_API_KEY` para un Ollama local sin autenticación.

## vLLM

Ejemplo:

```text
EDUSYNC_AI_ENABLED=1
EDUSYNC_AI_PROVIDER=vllm
EDUSYNC_AI_MODEL=Qwen/Qwen3-8B
EDUSYNC_AI_API_STYLE=responses
EDUSYNC_AI_ENDPOINT=http://127.0.0.1:8000/v1/responses
```

## llama.cpp

Ejemplo:

```text
EDUSYNC_AI_ENABLED=1
EDUSYNC_AI_PROVIDER=llamacpp
EDUSYNC_AI_MODEL=nombre-del-modelo
EDUSYNC_AI_API_STYLE=chat_completions
EDUSYNC_AI_ENDPOINT=http://127.0.0.1:8080/v1/chat/completions
```

## OpenAI

Ejemplo:

```text
EDUSYNC_AI_ENABLED=1
EDUSYNC_AI_PROVIDER=openai
OPENAI_API_KEY=sk_xxxxxxxxxxxxxxxxx
```

El modelo y endpoint pueden configurarse con `EDUSYNC_AI_MODEL` y `EDUSYNC_AI_ENDPOINT`.

## Seguridad

La IA recibe únicamente las herramientas permitidas para el usuario autenticado. No se permite SQL generado por el modelo ni operaciones de escritura.

- Estudiante: únicamente su información personal autorizada.
- Docente: sus asignaciones y datos académicos vinculados.
- Auxiliar: consultas operativas autorizadas.
- Administrador/Director: indicadores del colegio y herramientas administrativas de lectura.

Aunque se use Groq u otro proveedor externo, el modelo no recibe acceso directo a MySQL. EduSync ejecuta las herramientas autorizadas en PHP y entrega al modelo solo el resultado necesario para redactar la respuesta.

## Consultas financieras avanzadas para administración

Además del resumen global de deuda, administración dispone de un ranking controlado de estudiantes con mayor saldo pendiente. La herramienta acepta una cantidad de 1 a 20 estudiantes y filtros opcionales por nivel y grado.

Ejemplos:

```text
Dame los 10 estudiantes que más deben.
¿Cuáles son los 5 mayores deudores de secundaria?
Dame los 3 alumnos de 4.º con mayor deuda.
¿Quiénes deben más actualmente?
```

La respuesta muestra nombre del estudiante, saldo pendiente, número de obligaciones y ubicación académica. No expone IDs internos ni DNI.

## Pruebas recomendadas

### Administrador/Director

- `Dame un resumen del colegio.`
- `Dime cuántos estudiantes hay en cada sección de secundaria.`
- `¿Cuántos estudiantes tienen deuda en secundaria?`
- `Dame los 10 estudiantes que más deben.`
- `Dame los 5 mayores deudores de secundaria.`
- `¿Cuánto se ha cobrado este mes?`
- `¿Cómo está la asistencia hoy por sección?`
- `¿Cuántos estudiantes están en riesgo en Matemática?`

### Docente

- `¿Cuáles son mis cursos?`
- `¿Cuántos estudiantes tengo?`
- `¿Cuántos de mis estudiantes están en riesgo?`
- Intentar consultar ranking de deudores: debe negarse/no ofrecer la herramienta.

### Auxiliar

- `¿Cómo está la asistencia hoy?`
- `¿Cuántos estudiantes hay en secundaria?`
- Intentar consultar información financiera: debe negarse/no ofrecer la herramienta.

### Estudiante

- `¿Cómo voy este mes?`
- `¿Cuáles son mis deudas?`
- `¿Por qué no puedo ver mis notas?`
- `¿Cuál fue mi último pago?`
- `¿Cómo está mi asistencia este mes?`
- `¿Qué notas tengo en Matemática?`
- Intentar preguntar por otro alumno: no debe revelar información.

## Archivos principales

```text
edusync/chatbot_api.php
edusync/includes/chatbot_engine.php
edusync/includes/chatbot_queries.php
edusync/includes/chatbot_knowledge.php
edusync/includes/chatbot_tools.php
edusync/includes/chatbot_analytics.php
edusync/includes/chatbot_totals.php
edusync/includes/chatbot_router.php
edusync/includes/chatbot_ai.php
edusync/includes/footer.php
edusync/css/chatbot.css
edusync/js/chatbot.js
```

No requiere cambios de base de datos.
