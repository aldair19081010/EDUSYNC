# Firebase Web Push en Hostinger (EduSync)

**Rama:** `firebase-web-hostinger-config-v1`. **Único cambio PHP:** `edusync/api/web_push.php`.

## Objetivo

El endpoint `api/web_push.php?action=config` lee en este orden:
1. `EDUSYNC_FIREBASE_WEB_CONFIG_JSON` y `EDUSYNC_FIREBASE_VAPID_KEY`, si están configuradas.
2. Ruta del archivo definida en `EDUSYNC_FIREBASE_WEB_CONFIG_FILE`, opcional.
3. `firebase/edusync-web-public.json`, en la carpeta **hermana** del `public_html` del dominio (Hostinger).
4. Ruta del archivo XAMPP existente en `C:\xampp\firebase\edusync-web-public.json`, para Windows.

Sólo acepta el archivo si es legible y no está dentro del directorio público del sitio. No se suben claves privadas al repositorio.

## Estructura ilustrativa Hostinger

```
/home/u12345678/domains/tudominio.com/
  firebase/
    edusync-web-public.json
  public_html/
    edusync/
      api/
        web_push.php
```

Si Hostinger usa `/home/u12345678/public_html`, la carpeta privada quedaría como `/home/u12345678/firebase`. La ruta real varía según el alojamiento.

## Contenido del archivo

```json
{
  "firebase": {
    "apiKey": "COPIA_DE_FIREBASE_WEB",
    "authDomain": "edusync-69dae.firebaseapp.com",
    "projectId": "edusync-69dae",
    "messagingSenderId": "COPIA_DE_FIREBASE_WEB",
    "appId": "COPIA_DE_FIREBASE_WEB"
  },
  "vapid_key": "COPIA_DE_CLAVE_PUBLICA_WEB_PUSH"
}
```

Los campos tienen que ser **los reales del mismo proyecto Firebase** que utiliza Android. Este archivo NO contiene `type=service_account`, `private_key` ni `client_email`. La credencial privada de servicio que usa Android sigue configurada por separado, y debe rotarse si fue expuesta.

## Publicación y prueba

1. Actualiza `edusync/api/web_push.php` desde la rama validada; el archivo de GitHub `main` seguirá siendo el anterior hasta aprobar merge.
2. Ubica `edusync-web-public.json` fuera de `public_html`, revisa permisos de lectura para PHP y valida JSON.
3. Accede a EduSync desde HTTPS y en el navegador autenticado abre las herramientas de desarrollador → Red: la solicitud `api/web_push.php?action=config` debe devolver `status:ok`, `configured:true`, `push_ready:true`.
4. Si `configured:false`: comprobar ruta y formato de JSON, campos `apiKey`, `authDomain`, `projectId`, `messagingSenderId`, `appId`, clave VAPID con longitud superior a 30, y permisos del archivo.
5. Activar los avisos en la campana. Comprobar la recepción con una nueva notificación real de asistencia o cobranza, con la sesión de estudiante suscrita.
6. Verificar `web_push_subscriptions` y `web_push_delivery` sin mostrar nunca los tokens FCM ni contraseñas.

**No cambiar** `google-services.json` ni el código de la app Android.
