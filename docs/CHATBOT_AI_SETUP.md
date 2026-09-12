# Configuración de IA para el Asistente EduSync

El Asistente EduSync funciona en dos modos:

- **IA**: usa la API configurada para interpretar lenguaje natural y solicitar únicamente herramientas de lectura autorizadas de EduSync.
- **Modo local**: usa el motor de intenciones interno cuando no existe una API key o cuando la llamada de IA falla.

El frontend nunca recibe la API key. No se debe guardar ninguna clave en Git, JavaScript, HTML, `system_settings` ni archivos públicos del hosting.

## Variables de entorno

Configurar en el entorno privado de PHP/hosting:

```text
OPENAI_API_KEY=sk-...
EDUSYNC_AI_ENABLED=1
EDUSYNC_AI_MODEL=gpt-5.6-luna
```

Opcionalmente:

```text
EDUSYNC_AI_ENDPOINT=https://api.openai.com/v1/responses
```

`EDUSYNC_AI_MODEL` es configurable para poder cambiar el modelo sin modificar el código.

Para desactivar temporalmente la IA sin eliminar la clave:

```text
EDUSYNC_AI_ENABLED=0
```

## Seguridad

La IA no recibe acceso SQL. `chatbot_tools.php` expone un conjunto cerrado de herramientas según el rol autenticado. Cada herramienta reutiliza consultas controladas de EduSync y toma `school_id`, `student_id` o `teacher_id` desde la sesión, no desde argumentos enviados por el usuario.

El asistente es de solo lectura. No modifica estudiantes, docentes, pagos, deudas, notas, asistencias ni facturación.

Las consultas del estudiante quedan limitadas a su propio registro. Las consultas del docente quedan limitadas a sus asignaciones vigentes y al año académico actual. Administración trabaja dentro del colegio autenticado. Auxiliar dispone solo de herramientas operativas autorizadas.

## Archivos de la integración

```text
edusync/chatbot_api.php
edusync/includes/chatbot_engine.php
edusync/includes/chatbot_queries.php
edusync/includes/chatbot_knowledge.php
edusync/includes/chatbot_tools.php
edusync/includes/chatbot_ai.php
edusync/includes/footer.php
edusync/css/chatbot.css
edusync/js/chatbot.js
```

## Flujo

```text
Usuario
  -> chatbot_api.php
  -> chatbot_ai.php
  -> modelo IA
  -> function calling
  -> chatbot_tools.php
  -> consultas controladas de EduSync
  -> resultado autorizado
  -> modelo IA redacta la respuesta
```

Si cualquier llamada al proveedor falla, `chatbot_api.php` registra el error en el log del servidor y continúa usando el motor local.

## Pruebas recomendadas

### Administrador/Director

- `Dame un resumen del colegio.`
- `¿Cuántos estudiantes tienen deuda en secundaria?`
- `¿Cuánto se ha cobrado este mes?`
- `¿Cómo está la asistencia hoy?`
- `¿Cuántos estudiantes están en riesgo en Matemática?`
- `¿Cómo registro un pago?`

### Docente

- `¿Cuáles son mis cursos?`
- `¿Cuántos estudiantes tengo?`
- `¿Cuántos de mis estudiantes están en riesgo en el segundo bimestre?`
- Intentar preguntar por cobranza general: debe negarse.

### Auxiliar

- `¿Cómo está la asistencia hoy?`
- `¿Cuántos estudiantes hay en secundaria?`
- Intentar consultar deudas o notas: debe negarse.

### Estudiante

- `¿Cómo voy este mes?`
- `¿Cuáles son mis deudas?`
- `¿Por qué no puedo ver mis notas?`
- `¿Cuál fue mi último pago?`
- `¿Cómo está mi asistencia este mes?`
- `¿Qué notas tengo en Matemática?`
- Intentar preguntar por otro alumno: no debe revelar información.

### Fuera de alcance

Preguntas de noticias, clima, cultura general o cualquier tema ajeno a EduSync deben ser rechazadas de forma breve y redirigidas al sistema.
