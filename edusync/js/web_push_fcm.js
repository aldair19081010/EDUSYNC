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
        var info=$('<span class="edwp-info" role="status" aria-live="polite"></span>');
        opt.append(toggle,info);
        menu.append(opt);

        function message(text, error) {
            info.text(text || '').toggleClass('text-danger',!!error);
        }
        function label(enabled) {
            toggle.text(enabled?'Desactivar avisos del dispositivo':'Activar notificaciones');
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
        var foregroundBound=false, foregroundLastKey='', foregroundLastAt=0;
        function safeNotificationUrl(value) {
            try {
                var scope=new URL('./',window.location.href);
                var url=new URL(String(value||'index.php?page=notifications'),scope);
                if(url.origin===scope.origin && url.pathname.indexOf(scope.pathname)===0)
                    return url.href;
            } catch (_) {}
            return new URL('index.php?page=notifications',new URL('./',window.location.href)).href;
        }
        async function foregroundAllowed() {
            if(!currentEnabled() || Notification.permission!=='granted')return false;
            try {
                if('caches' in window){
                    var cache=await caches.open('edusync-push-session-v1');
                    var item=await cache.match(new URL('web-push-session-state',new URL('./',location.href)).href);
                    if(item && (await item.text())==='logged_out')return false;
                }
            } catch (_) {}
            return true;
        }
        function showInAppNotification(title,body,url) {
            if(document.visibilityState==='hidden')return;
            $('.edwp-foreground-alert').remove();
            var box=$('<div>',{
                class:'edwp-foreground-alert alert alert-primary shadow',
                role:'alert',
                'aria-live':'polite'
            }).css({
                position:'fixed',top:'76px',right:'16px',width:'min(380px,calc(100vw - 32px))',
                zIndex:1090,margin:0,borderRadius:'12px',cursor:'pointer'
            });
            var close=$('<button>',{type:'button','aria-label':'Cerrar aviso'})
                .addClass('close').html('&times;').css({marginLeft:'12px'});
            close.on('click',function(event){event.stopPropagation();box.remove();});
            box.append(close);
            box.append($('<div>').addClass('font-weight-bold mb-1').text(title));
            if(body)box.append($('<div>').addClass('small').text(body));
            box.append($('<div>').addClass('small mt-2').text('Pulsa para abrir el aviso'));
            box.on('click',function(){window.location.href=url;});
            $('body').append(box);
            window.setTimeout(function(){box.remove();},9000);
        }
        async function foregroundNotification(payload,alreadyShownByWorker) {
            if(!(await foregroundAllowed()))return;
            var data=payload&&payload.data&&typeof payload.data==='object'?payload.data:{};
            var info=payload&&payload.notification&&typeof payload.notification==='object'?payload.notification:{};
            var title=String(data.title||info.title||'').slice(0,150);
            var body=String(data.body||info.body||'').slice(0,450);
            if(!title)return;
            var tag=String(data.tag||'edusync-web').slice(0,100);
            var url=safeNotificationUrl(data.url||info.click_action);
            var dedupe=tag+'|'+title+'|'+body;
            var now=Date.now();
            if(foregroundLastKey===dedupe && now-foregroundLastAt<5000)return;
            foregroundLastKey=dedupe;foregroundLastAt=now;
            showInAppNotification(title,body,url);
            // El Service Worker ya ha presentado la notificación nativa;
            // el mensaje al cliente sólo proporciona el aviso dentro de EduSync.
            if(alreadyShownByWorker)return;
            try {
                var worker=await navigator.serviceWorker.getRegistration('./');
                if(worker && Notification.permission==='granted'){
                    var icon=new URL('assets/uploads/logo.jpg',new URL('./',window.location.href)).href;
                    await worker.showNotification(title,{
                        body:body,tag:tag,icon:icon,badge:icon,renotify:true,data:{url:url}
                    });
                }
            } catch (_) {
                // La alerta dentro de EduSync sigue visible si el sistema
                // operativo o navegador no permite mostrar banners nativos.
            }
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
            // Con EduSync abierto Firebase puede entregar mensajes por
            // onMessage en lugar del evento push de segundo plano.
            if(!foregroundBound && typeof messaging.onMessage==='function'){
                messaging.onMessage(function(payload){
                    foregroundNotification(payload).catch(function(err){
                        console.warn('[EduSync Web Push] Aviso de primer plano:',err);
                    });
                });
                foregroundBound=true;
            }
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
        async function markCurrentSessionForPush(value) {
            // El logout marca el Service Worker como desconectado para ignorar
            // notificaciones FCM antiguas pendientes de entrega.
            // Sólo una nueva suscripción explícita elimina ese bloqueo.
            try {
                if(!('caches' in window))return;
                var cache=await caches.open('edusync-push-session-v1');
                await cache.put(
                    new URL('web-push-session-state',new URL('./',window.location.href)).href,
                    new Response(value==='logged_out'?'logged_out':'active',{headers:{'Content-Type':'text/plain'}})
                );
            } catch (_) {}
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
                await markCurrentSessionForPush('active');
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
                await markCurrentSessionForPush('logged_out');
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

        if(!compatible) {
            toggle.prop('disabled',true).text('Notificaciones no compatibles');
            var iphone=/iPhone|iPad|iPod/.test(navigator.userAgent);
            message(iphone?'En iPhone, agrega EduSync a la pantalla de inicio y ábrelo desde allí.':
                'Necesitas HTTPS (o localhost) y un navegador compatible.',false);
            return;
        }
        // El service worker personalizado puede recibir FCM incluso sin que
        // el SDK invocase onMessage. Su mensaje activa el toast de primer plano.
        if(navigator.serviceWorker && typeof navigator.serviceWorker.addEventListener==='function'){
            navigator.serviceWorker.addEventListener('message',function(event){
                var payload=event.data||{};
                if(payload.type!=='edusync-web-push-visible')return;
                foregroundNotification({data:payload.data||{}},true).catch(function(){});
            });
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
            // Renovar automáticamente SOLO si la cuenta ya había aceptado
            // notificaciones. El logout pausa los envíos, no revoca el permiso.
            if(currentEnabled() && Notification.permission==='granted') {
                enable(false);
            } else if(Notification.permission==='granted' && 'caches' in window) {
                // Usuarios afectados por versiones que borraban la preferencia
                // tras salir deben activarla una sola vez desde la campana.
                caches.open('edusync-push-session-v1').then(function(cache){
                    return cache.match(new URL('web-push-session-state',new URL('./',location.href)).href);
                }).then(function(response){
                    return response?response.text():'';
                }).then(function(status){
                    if(status==='logged_out' && !currentEnabled())
                        message('Al volver a ingresar, activa las notificaciones una vez desde la campana.',false);
                }).catch(function(){});
            }
        }).fail(function(){message('No se pudo consultar la configuración del servidor.',true);});
    });
})(window.jQuery);
