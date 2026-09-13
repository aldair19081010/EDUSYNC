# IA local para el Asistente EduSync

EduSync puede funcionar sin depender de un proveedor externo de IA. El modelo puede ejecutarse en un servidor controlado por la institución y el chatbot mantiene las mismas herramientas, permisos y reglas de seguridad.

Hay tres niveles de funcionamiento:

- **IA local**: EduSync consulta un modelo autoalojado mediante Ollama, vLLM, llama.cpp u otro servidor compatible.
- **IA externa opcional**: puede habilitarse explícitamente un proveedor externo si alguna instalación lo necesita.
- **Modo local por reglas**: si la IA está deshabilitada o el servidor local falla, EduSync usa el motor interno de intenciones y consultas seguras.

La interfaz muestra `IA local` cuando la respuesta fue procesada por un modelo autoalojado y `Modo local` cuando actuó el fallback sin modelo generativo.

## Configuración recomendada: Ollama

Ollama es la opción más sencilla para desarrollo y pruebas. Debe ejecutarse en la misma máquina que PHP o en un servidor privado accesible únicamente por EduSync.

Ejemplo de instalación/configuración del modelo:

```bash
ollama pull qwen3
ollama serve
```

Variables de entorno del proceso PHP:

```text
EDUSYNC_AI_ENABLED=1
EDUSYNC_AI_PROVIDER=ollama
EDUSYNC_AI_MODEL=qwen3
EDUSYNC_AI_API_STYLE=responses
EDUSYNC_AI_ENDPOINT=http://127.0.0.1:11434/v1/responses
```

No se necesita una API key para una instancia local de Ollama protegida por red local. Ollama soporta una API compatible con OpenAI y function calling. Si se utiliza una versión que no exponga `/v1/responses`, puede configurarse:

```text
EDUSYNC_AI_API_STYLE=chat_completions
EDUSYNC_AI_ENDPOINT=http://127.0.0.1:11434/v1/chat/completions
```

## Producción con vLLM

Para una instalación con varios usuarios concurrentes se recomienda un servidor Linux con GPU dedicado y vLLM.

Ejemplo conceptual:

```bash
vllm serve Qwen/Qwen3-8B \
  --enable-auto-tool-choice \
  --tool-call-parser hermes
```

Variables de entorno:

```text
EDUSYNC_AI_ENABLED=1
EDUSYNC_AI_PROVIDER=vllm
EDUSYNC_AI_MODEL=Qwen/Qwen3-8B
EDUSYNC_AI_API_STYLE=responses
EDUSYNC_AI_ENDPOINT=http://127.0.0.1:8000/v1/responses
```

Si vLLM se encuentra en otra máquina, usar una IP privada o nombre interno. No se recomienda exponer directamente el puerto del modelo a Internet.

Si se protege el servidor local con una API key propia:

```text
EDUSYNC_AI_API_KEY=una-clave-interna-larga
```

EduSync añadirá esa clave únicamente desde el servidor PHP.

## llama.cpp u otros servidores

Para motores que implementen principalmente Chat Completions:

```text
EDUSYNC_AI_ENABLED=1
EDUSYNC_AI_PROVIDER=llamacpp
EDUSYNC_AI_MODEL=nombre-del-modelo
EDUSYNC_AI_API_STYLE=chat_completions
EDUSYNC_AI_ENDPOINT=http://127.0.0.1:8080/v1/chat/completions
```

El modelo seleccionado debe soportar bien instrucciones y tool/function calling. La calidad del asistente dependerá del modelo y de su plantilla de herramientas.

## Desactivar la IA generativa

```text
EDUSYNC_AI_ENABLED=0
```

En ese estado EduSync sigue respondiendo con el motor local por reglas.

## Proveedor externo opcional

La integración externa queda disponible de forma opcional, no obligatoria:

```text
EDUSYNC_AI_ENABLED=1
EDUSYNC_AI_PROVIDER=openai
OPENAI_API_KEY=sk-...
EDUSYNC_AI_MODEL=<modelo-configurado>
EDUSYNC_AI_API_STYLE=responses
EDUSYNC_AI_ENDPOINT=https://api.openai.com/v1/responses
```

Una instalación que quiera cero dependencia de proveedores externos simplemente no configura `OPENAI_API_KEY` y utiliza `EDUSYNC_AI_PROVIDER=ollama`, `vllm` o `llamacpp`.

## Seguridad

La IA nunca recibe acceso SQL. `chatbot_tools.php` expone un conjunto cerrado de herramientas según el rol autenticado. Cada herramienta reutiliza consultas controladas de EduSync y toma `school_id`, `student_id` o `teacher_id` desde la sesión, nunca desde argumentos elegidos por el modelo o escritos por el usuario.

El asistente es de solo lectura. No modifica estudiantes, docentes, pagos, deudas, notas, asistencias ni facturación.

Las consultas del estudiante quedan limitadas a su propio registro. Las del docente quedan limitadas a sus asignaciones vigentes y al año académico actual. Administración trabaja exclusivamente dentro del colegio autenticado. Auxiliar dispone solo de las herramientas operativas autorizadas.

El servidor del modelo debe mantenerse en `127.0.0.1` o en una red privada. Si se publica en una red compartida, debe protegerse mediante firewall, reverse proxy y autenticación interna.

## Privacidad

Con `EDUSYNC_AI_PROVIDER=ollama`, `vllm`, `llamacpp` o un servidor `custom` interno, las solicitudes de IA se envían únicamente al endpoint configurado por la institución. EduSync no necesita enviar las conversaciones a un proveedor externo.

Aun así, el modelo recibe únicamente la conversación necesaria y los resultados de herramientas autorizadas. No recibe la base de datos completa, credenciales, contraseñas ni acceso directo a MySQL.

## Arquitectura

```text
Usuario
  -> chatbot_api.php
  -> chatbot_ai.php
  -> servidor IA local
  -> function calling
  -> chatbot_tools.php
  -> consultas controladas EduSync
  -> MySQL
  -> resultados autorizados
  -> servidor IA local redacta respuesta
```

Si el servidor IA no responde:

```text
Usuario
  -> chatbot_api.php
  -> motor local de EduSync
  -> herramientas/consultas seguras
```

El chatbot continúa funcionando.

## Archivos

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

## Variables disponibles

```text
EDUSYNC_AI_ENABLED=1|0
EDUSYNC_AI_PROVIDER=local|ollama|vllm|llamacpp|custom|openai|off
EDUSYNC_AI_MODEL=<modelo>
EDUSYNC_AI_API_STYLE=responses|chat_completions
EDUSYNC_AI_ENDPOINT=<endpoint completo>
EDUSYNC_AI_API_KEY=<clave interna opcional>
OPENAI_API_KEY=<solo si provider=openai>
```

Valores por defecto cuando la IA se habilita sin especificar todo:

```text
provider: local
modelo: qwen3
api style: responses
endpoint: http://127.0.0.1:11434/v1/responses
```

## Pruebas recomendadas

Administrador/Director:

```text
Dame un resumen del colegio.
¿Cuántos estudiantes tienen deuda en secundaria?
¿Cuánto se ha cobrado este mes?
¿Cómo está la asistencia hoy?
¿Cuántos estudiantes están en riesgo en Matemática?
¿Cómo registro un pago?
```

Docente:

```text
Dame mi resumen docente.
¿Cuáles son mis cursos?
¿Cuántos estudiantes tengo?
¿Cuántos de mis estudiantes están en riesgo en el segundo bimestre?
```

Debe negarse a entregar cobranza general u otros datos financieros restringidos.

Auxiliar:

```text
¿Cómo está la asistencia hoy?
¿Cuántos estudiantes hay en secundaria?
```

Debe negarse a entregar deudas, cobranza o notas restringidas.

Estudiante:

```text
¿Cómo voy?
¿Cuáles son mis deudas?
¿Por qué no puedo ver mis notas?
¿Cuál fue mi último pago?
¿Cómo está mi asistencia este mes?
¿Qué notas tengo en Matemática?
```

Intentar consultar información de otro estudiante no debe revelar datos.

Las preguntas ajenas a EduSync deben rechazarse brevemente y redirigirse al sistema.
