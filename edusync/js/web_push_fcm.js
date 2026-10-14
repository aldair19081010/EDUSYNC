/* EduSync Web Push real mediante Firebase Cloud Messaging.
 * FCM y el Service Worker reciben los avisos incluso sin pestañas abiertas.
 * El usuario activa el permiso con un clic, nunca al cargar la página.
 */
(function($) {
    'use strict';
    if (!$) return;
    $(function(){
        var menu=$('.edusync-notification-menu');
        if (!menu.length) return;
        var compatible=!!(window.isSecureContext && 'Notification' in window
            && 'serviceWorker' in navigator && 'PushManager' in window);
        var config=null, messaging=null, running=false;
        var enabledKey='', browserId='';
        var opt=$('<div class="edwp-controls"></div>');
        var toggle=$('<button type="button" class="edwp-toggle btn btn-sm btn-outline-primary"></button>');
        var test=$('<button type="button" class="edwp-test btn btn-sm btn-light border d-none">Enviar prueba</button>');
        var info=$('<span class="edwp-info" role="status" aria-live="polite"></span>');
        opt.append(toggle,test,info);
        menu.append(opt);

        function message(text, error) {
            info.text(text || '').toggleClass('text-danger',!!error);
        }
        function label(enabled) {
            toggle.text(enabled?'Desactivar avisos del dispositivo':'Activar notificaciones');
            test.toggleClass('d-none',!enabled);
        }
        function currentEnabled() {
            try { return localStorage.getItem(enabledKey)==='1'; }
            catch (_) { return false; }
        }
        function saveEnabled(value) {
            try {
                if (value) localStorage.setItem(enabledKey,'1');
                else localStorage.removeItem(enabledKey);
            } catch (_) {}
            label(value);
        }
        function deviceId() {
            try {
                var id=localStorage.getItem('edusync:webpush:device');
                if (id && /^[A-Za-z0-9._:-]{20,100}$/.test(id)) return id;
                var values=new Uint8Array(24);
                window.crypto.getRandomValues(values);
                id='browser-'+Array.prototype.map.call(values,function(x){
                    return x.toString(16).padStart(2,'0');
                }).join('');
                localStorage.setItem('edusync:webpush:device',id);
                return id;
            } catch (_) { return ''; }
        }
        function script(url) {
            return new Promise(function(resolve,reject) {
                var existing=document.querySelector('script[data-edwp-url="'+url+'"]');
                if(existing){
                    if(existing.dataset.ready==='1')return resolve();
                    existing.addEventListener('load',resolve,{once:true});
                    existing.addEventListener('error',reject,{once:true});
                    return;
                }
                var el=document.createElement('script');
                el.src=url;el.async=true;el.dataset.edwpUrl=url;
                el.onload=function(){el.dataset.ready='1';resolve()};
                el.onerror=function(){reject(new Error('No se pudo cargar Firebase. Comprueba Internet.'))};
                document.head.appendChild(el);
            });
        }
        async function firebaseClient() {
            if (messaging) return messaging;
            await script('https://www.gstatic.com/firebasejs/10.14.1/firebase-app-compat.js');
            await script('https://www.gstatic.com/firebasejs/10.14.1/firebase-messaging-compat.js');
            if (!window.firebase || !firebase.messaging) throw new Error('Firebase no está disponible.');
            if (firebase.messaging.isSupported && !(await firebase.messaging.isSupported()))
                throw new Error('Este navegador no admite Firebase Cloud Messaging.');
            var app;
            try { app=firebase.app('edusync-web'); }
            catch (_) { app=firebase.initializeApp(config.firebase,'edusync-web'); }
            messaging=firebase.messaging(app);
            return messaging;
        }
        async function fcmToken() {
            var sw=await navigator.serviceWorker.register('firebase-messaging-sw.js',{
                scope:'./',updateViaCache:'none'
            });
            // Uso explícito del service worker de EduSync: no afecta otro SW del hosting.
            var client=await firebaseClient();
            var token=await client.getToken({
                vapidKey:config.vapid_key,serviceWorkerRegistration:sw
            });
            if (!token) throw new Error('Firebase no devolvió un token.');
            return token;
        }
        function request(action,data) {
            return new Promise(function(resolve,reject){
                $.ajax({
                    url:'api/web_push.php',method:'POST',dataType:'json',
                    data:$.extend({action:action,browser_id:browserId,
                        csrf_token:config.csrf_token},data||{})
                }).done(function(result){
                    if (result && result.status==='ok')resolve(result);
                    else reject(new Error((result&&result.message)||'No se pudo actualizar el registro.'));
                }).fail(function(xhr){
                    reject(new Error((xhr.responseJSON||{}).message||'Sin conexión con el servidor.'));
                });
            });
        }
        async function enable(prompt) {
            if (running) return;
            running=true;toggle.prop('disabled',true);
            try {
                if (!config || !config.configured || !config.push_ready)
                    throw new Error('Firebase Web Push o la migración todavía no están configurados.');
                if (prompt && Notification.permission==='default') {
                    // Esta llamada sólo ocurre en el manejador del clic del usuario.
                    var permission=await Notification.requestPermission();
                    if(permission!=='granted')throw new Error('No se concedió permiso para los avisos.');
                } else if(Notification.permission!=='granted') {
                    throw new Error('Habilita las notificaciones del navegador para continuar.');
                }
                var token=await fcmToken();
                await request('register',{fcm_token:token});
                saveEnabled(true);
                message('Avisos activados en este dispositivo.',false);
            } catch(err) {
                message(err.message||'No se pudo activar Web Push.',true);
            } finally {
                running=false;toggle.prop('disabled',false);
            }
        }
        async function disable() {
            if(running)return;
            running=true;toggle.prop('disabled',true);
            try {
                await request('unregister');
                // Borrar el token local sólo después de que el servidor confirma la baja.
                try { if(messaging) await messaging.deleteToken(); }
                catch (_) {}
                saveEnabled(false);
                message('Este dispositivo ya no recibirá avisos.',false);
            } catch(err){
                message(err.message||'No se pudo desactivar.',true);
            } finally {running=false;toggle.prop('disabled',false)}
        }

        toggle.on('click',function(event){
            event.stopPropagation();
            if(currentEnabled())disable();
            else enable(true); // Solicita permiso sólo como respuesta directa al clic.
        });
        test.on('click',async function(event){
            event.stopPropagation();
            test.prop('disabled',true);
            try {
                var result=await request('test');
                message(result.message||'Se solicitó el envío de prueba.',false);
            } catch(err){message(err.message,true)}
            finally{test.prop('disabled',false)}
        });

        if(!compatible) {
            toggle.prop('disabled',true).text('Notificaciones no compatibles');
            var iphone=/iPhone|iPad|iPod/.test(navigator.userAgent);
            message(iphone?'En iPhone, agrega EduSync a la pantalla de inicio y ábrelo desde allí.':
                'Necesitas HTTPS (o localhost) y un navegador compatible.',false);
            return;
        }
        browserId=deviceId();
        if(!browserId){
            toggle.prop('disabled',true);message('Este navegador no permite guardar la suscripción.',true);return;
        }
        label(false);
        $.getJSON('api/web_push.php',{action:'config'}).done(function(result){
            if(!result||result.status!=='ok'){message('No se pudo verificar Web Push.',true);return;}
            config=result;
            enabledKey='edusync:webpush:enabled:'+result.identity;
            label(currentEnabled());
            if(!config.configured) {
                toggle.prop('disabled',true);
                message('Falta configurar Firebase web (clave VAPID y datos públicos).',false);
                return;
            }
            if(!config.push_ready) {
                toggle.prop('disabled',true);
                message('Falta ejecutar la migración web push.',false);
                return;
            }
            if(Notification.permission==='denied') {
                toggle.prop('disabled',true);
                message('Permiso bloqueado. Debes cambiarlo en la configuración del navegador.',true);
                return;
            }
            // Renovar la vinculación sin volver a solicitar permisos después del login.
            if(currentEnabled() && Notification.permission==='granted') enable(false);
        }).fail(function(){message('No se pudo consultar la configuración del servidor.',true);});
    });
})(window.jQuery);
