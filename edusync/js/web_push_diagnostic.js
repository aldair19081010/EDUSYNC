/* EduSync Web Push: diagnóstico local del navegador.
 * Nunca incluye tokens FCM, claves VAPID o información escolar en los informes.
 */
(function(global) {
    'use strict';
    function limited(promise, milliseconds, label) {
        return new Promise(function(resolve,reject) {
            var timer=setTimeout(function(){
                var error=new Error('Tiempo de espera agotado: '+label);
                error.code='edusync-push-timeout';
                reject(error);
            },milliseconds);
            Promise.resolve(promise).then(function(value){
                clearTimeout(timer);resolve(value);
            },function(error){
                clearTimeout(timer);reject(error);
            });
        });
    }
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
        return !!(error&&error.code==='edusync-push-timeout') ||
            /push service error|registration failed|token-subscribe-failed/i.test(content);
    }
    function explain(error) {
        var detail=String(error&&error.message||'').slice(0,180);
        if (error && error.code==='edusync-push-timeout') {
            return 'El navegador no respondió durante '+String(error.message||'el registro Push').slice(0,115)+'. EduSync detuvo la espera; consulta el diagnóstico. No necesitas volver a autorizar notificaciones.';
        }
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
    function decodeVapid(value) {
        var vapid=String(value||'').trim();
        var binary=global.atob(vapid.replace(/-/g,'+').replace(/_/g,'/')+
            '='.repeat((4-vapid.length%4)%4));
        var bytes=new Uint8Array(binary.length);
        for(var i=0;i<binary.length;i++)bytes[i]=binary.charCodeAt(i);
        return bytes;
    }
    // Prueba voluntaria y directa del navegador, sin solicitar un token FCM.
    // No se registra ni se muestra la URL privada de suscripción Push.
    async function probe(config, knownRegistration) {
        var results=['Prueba directa del servicio Push del navegador'];
        var stage='Revisar requisitos';
        try {
            if (!global.isSecureContext) throw new Error('EduSync requiere un origen seguro.');
            if (!global.Notification || global.Notification.permission!=='granted')
                throw new Error('El permiso de notificaciones no está concedido.');
            validate(config);
            if (!global.navigator || !global.navigator.serviceWorker)
                throw new Error('El navegador no dispone de Service Workers.');
            stage='Localizar Service Worker';
            var sw=knownRegistration && knownRegistration.active
                ? knownRegistration
                : await limited(global.navigator.serviceWorker.getRegistration('./'),3500,stage);
            if (!sw || !sw.active || !sw.pushManager)
                throw new Error('El Service Worker de EduSync no está activo o no admite Push.');
            results.push('Service Worker: Activo');
            stage='Solicitar suscripción nativa Push';
            // Si ya tenemos el registro activo, invocar subscribe() sin awaits
            // intermedios preserva la activación del clic del usuario.
            // subscribe() recupera una suscripción compatible existente.
            var operation=sw.pushManager.subscribe({
                userVisibleOnly:true,
                applicationServerKey:decodeVapid(config.vapid_key)
            });
            var sub=await limited(operation,15000,stage);
            if (!sub) throw new Error('El navegador no devolvió una suscripción Push.');
            results.push('API Push del navegador: Suscripción creada correctamente');
            results.push('Siguiente prueba: pulsa Activar notificaciones para registrar Firebase.');
            return {ok:true,stage:stage,message:results.join('\n')};
        } catch(error) {
            results.push('Etapa fallida: '+stage);
            results.push('API Push del navegador: Falló');
            results.push('Error: '+String(error&&error.name||'Error').slice(0,60));
            results.push('Detalle: '+String(error&&error.message||'').slice(0,170));
            if (isPushServiceError(error) || (error && error.name==='AbortError')) {
                results.push('Conclusión: la API Push nativa falló antes de Firebase; revisa conectividad, políticas y servicios del navegador.');
            } else {
                results.push('Conclusión: comprueba la etapa indicada antes de cambiar Firebase.');
            }
            return {ok:false,stage:stage,message:results.join('\n')};
        } finally {
            results.push('No se muestran tokens ni datos escolares.');
        }
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
            var sw=reg||await limited(global.navigator.serviceWorker.getRegistration('./'),2200,'consulta del Service Worker');
            lines.push('Service Worker: '+(sw?(sw.active?'Activo':'Registrado, no activo'):'No registrado'));
            if(sw && sw.pushManager) {
                try {
                    var subscription=await limited(sw.pushManager.getSubscription(),2200,'lectura de suscripción Push');
                    lines.push('Suscripción Push: '+(subscription?'Existe':'No existe'));
                } catch(e) {
                    lines.push('Suscripción Push: '+(e.code==='edusync-push-timeout'?'La consulta no respondió (más de 2 segundos)':'Error '+String(e.name||'desconocido')));
                }
            } else {
                lines.push('Suscripción Push: No disponible para consulta');
            }
        } catch(e) {
            lines.push('Inspección de Service Worker: '+(e.code==='edusync-push-timeout'?'No respondió en 2 segundos':String(e.name||'Error')));
        }
        if(error){
            lines.push('Error navegador: '+String(error.name||'Error').slice(0,60));
            lines.push('Código Firebase: '+String(error.code||'No informado').slice(0,90));
            lines.push('Detalle: '+String(error.message||'').slice(0,180));
        }
        if (global.navigator && typeof global.navigator.onLine==='boolean') {
            lines.push('Navegador conectado según sistema: '+(global.navigator.onLine?'Sí':'No'));
        }
        lines.push('Sin tokens, claves ni datos escolares.');
        return lines.join('\n');
    }
    global.EduSyncWebPushDiagnostic={validate:validate,explain:explain,
        isPushServiceError:isPushServiceError,report:report,limited:limited,probe:probe};
})(window);
