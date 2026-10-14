/* EduSync Web Push: diagnóstico local del navegador.
 * Nunca incluye tokens FCM, claves VAPID o información escolar en los informes.
 */
(function(global) {
    'use strict';
    function validate(publicConfig) {
        if (!publicConfig || !publicConfig.firebase) {
            throw new Error('Falta la configuración pública de Firebase Web.');
        }
        var app=publicConfig.firebase;
        var sender=String(app.messagingSenderId||'');
        var appId=String(app.appId||'');
        if (!/^[0-9]{5,20}$/.test(sender) || appId.indexOf('1:'+sender+':web:')!==0) {
            throw new Error('appId y messagingSenderId no coinciden. Revisa la configuración de Firebase Web en XAMPP.');
        }
        var vapid=String(publicConfig.vapid_key||'').trim();
        if (!/^[a-zA-Z0-9_-]+$/.test(vapid)) {
            throw new Error('La clave VAPID contiene caracteres no válidos.');
        }
        var raw;
        try {
            raw=global.atob(vapid.replace(/-/g,'+').replace(/_/g,'/')+
                '='.repeat((4-vapid.length%4)%4));
        } catch (_) {
            throw new Error('La clave VAPID pública no tiene un formato base64 válido.');
        }
        if (raw.length!==65 || raw.charCodeAt(0)!==4) {
            throw new Error('La clave VAPID pública no es una clave P-256 válida. Cópiala de Firebase → Cloud Messaging → Certificados push web.');
        }
        return true;
    }
    function isPushServiceError(error) {
        var content=String(error&&error.name||'')+' '+String(error&&error.message||'');
        return /push service error|registration failed|token-subscribe-failed/i.test(content);
    }
    function explain(error) {
        var detail=String(error&&error.message||'').slice(0,180);
        if (isPushServiceError(error)) {
            return 'El navegador no logró conectarse a su servicio Push. No es un fallo de MySQL. Prueba con Chrome o Edge normal, sin incógnito; revisa conexión, VPN y bloqueadores. Pulsa «Diagnóstico».';
        }
        if (/applicationServerKey|vapid|InvalidAccessError|DataError/i.test(detail)) {
            return 'La clave VAPID fue rechazada. Comprueba que corresponde a Firebase Cloud Messaging del mismo proyecto.';
        }
        if (/service.worker|serviceworker|no active/i.test(detail)) {
            return 'El Service Worker no está activo. Recarga EduSync e intenta nuevamente.';
        }
        if (/network|fetch|failed to fetch/i.test(detail)) {
            return 'No hay comunicación correcta con Firebase. Revisa la conexión y las restricciones de red.';
        }
        return detail || 'No se pudo completar el registro. Consulta el diagnóstico.';
    }
    async function report(config, error, stage, reg) {
        var lines=[
            'EduSync Web Push · Diagnóstico local',
            'Origen seguro: '+(global.isSecureContext?'Sí':'No'),
            'Permiso notificaciones: '+(global.Notification&&global.Notification.permission||'No disponible'),
            'Firebase Web: '+(config&&config.configured?'Configurado':'Incompleto'),
            'Base de datos: '+(config&&config.push_ready?'Lista':'Pendiente'),
            'Etapa fallida: '+String(stage||'Sin información')
        ];
        try { validate(config);lines.push('VAPID/appId: formato válido'); }
        catch(e){lines.push('Configuración pública: '+String(e.message).slice(0,150));}
        try {
            var sw=reg||await global.navigator.serviceWorker.getRegistration('./');
            lines.push('Service Worker: '+(sw?(sw.active?'Activo':'Registrado, no activo'):'No registrado'));
            if(sw && sw.pushManager) {
                var subscription=await sw.pushManager.getSubscription();
                lines.push('Suscripción Push: '+(subscription?'Existe':'No existe'));
            }
        } catch(e) {lines.push('Inspección del Service Worker: '+String(e.name||'Error'));}
        if(error){
            lines.push('Error navegador: '+String(error.name||'Error').slice(0,60));
            lines.push('Código Firebase: '+String(error.code||'No informado').slice(0,90));
            lines.push('Detalle: '+String(error.message||'').slice(0,180));
        }
        lines.push('Sin tokens, claves ni datos escolares.');
        return lines.join('\n');
    }
    global.EduSyncWebPushDiagnostic={validate:validate,explain:explain,
        isPushServiceError:isPushServiceError,report:report};
})(window);
