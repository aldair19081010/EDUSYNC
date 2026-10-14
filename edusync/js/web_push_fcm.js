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
        var enabledKey='', browserId='', dismissedKey='', optedOutKey='', invitedKey='';
        var invite=null, previousFocus=null;
        var cooldownKey='', lastError=null, lastStage='Inicio', lastSw=null;
        var inviteCooldown=30*24*60*60*1000; // «Ahora no»: 30 días.
        var opt=$('<div class="edwp-controls"></div>');
        var toggle=$('<button type="button" class="edwp-toggle btn btn-sm btn-outline-primary"></button>');
        var test=$('<button type="button" class="edwp-test btn btn-sm btn-light border d-none">Enviar prueba</button>');
        var info=$('<span class="edwp-info" role="status" aria-live="polite"></span>');
        var diagnose=$('<button type="button" class="edwp-diagnose btn btn-sm btn-light border d-none">Diagnóstico</button>');
        var report=$('<pre class="edwp-report d-none" role="status"></pre>');
        opt.append(toggle,test,diagnose,info,report);
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
                if (value) {
                    localStorage.setItem(enabledKey,'1');
                    localStorage.removeItem(optedOutKey);
                    localStorage.removeItem(dismissedKey);
                } else localStorage.removeItem(enabledKey);
            } catch (_) {}
            label(value);
        }
        function optedOut() {
            try { return localStorage.getItem(optedOutKey)==='1'; }
            catch (_) { return false; }
        }
        function recentlyDismissed() {
            try {
                var until=Number(localStorage.getItem(dismissedKey)||0);
                return Number.isFinite(until) && until>Date.now();
            } catch (_) { return false; }
        }
        function dismissInvite(defer) {
            if (defer) {
                try { localStorage.setItem(dismissedKey,String(Date.now()+inviteCooldown)); }
                catch (_) {}
            }
            if (!invite) return;
            invite.off().remove();
            invite=null;
            $('body').removeClass('edwp-invite-open');
            if (previousFocus && document.contains(previousFocus) &&
                typeof previousFocus.focus==='function') previousFocus.focus();
            previousFocus=null;
        }
        function showInvite() {
            if (invite || !config || !config.configured || !config.push_ready
                || Notification.permission!=='default' || recentlyDismissed()
                || optedOut() || $('.modal.show').length) return;
            // No repetir la invitación al cambiar de módulo durante la sesión.
            try {
                if (sessionStorage.getItem(invitedKey)==='1') return;
                sessionStorage.setItem(invitedKey,'1');
            } catch (_) {}

            previousFocus=document.activeElement;
            var overlay=$('<div class="edwp-invite-layer"></div>');
            var dialog=$('<section class="edwp-invite-dialog" role="dialog" aria-modal="true" aria-labelledby="edwp-invite-title" aria-describedby="edwp-invite-description"></section>');
            dialog.append('<div class="edwp-invite-icon" aria-hidden="true"><i class="far fa-bell"></i></div>');
            dialog.append($('<h2 id="edwp-invite-title" class="edwp-invite-title"></h2>').text('Mantente informado con EduSync'));
            dialog.append($('<p id="edwp-invite-description" class="edwp-invite-description"></p>').text('Recibe avisos sobre notas, asistencias, pagos y comunicados incluso cuando EduSync esté cerrado.'));
            var feedback=$('<p class="edwp-invite-feedback" role="status" aria-live="polite"></p>');
            var actions=$('<div class="edwp-invite-actions"></div>');
            var modalDiagnosis=$('<button type="button" class="btn btn-link btn-sm edwp-invite-diagnose d-none">Diagnóstico</button>');
            var modalReport=$('<pre class="edwp-invite-report d-none" role="status"></pre>');
            modalDiagnosis.on('click',showDiagnosis);
            var accept=$('<button type="button" class="btn btn-primary edwp-invite-accept"></button>').text('Activar notificaciones');
            var later=$('<button type="button" class="btn btn-light border edwp-invite-later"></button>').text('Ahora no');
            accept.on('click',async function(){
                // El permiso nativo sólo se pide durante esta interacción.
                accept.prop('disabled',true);
                later.prop('disabled',true);
                var success=await enable(true);
                if (success) dismissInvite(false);
                else if (invite) {
                    accept.prop('disabled',false);
                    later.prop('disabled',false);
                }
            });
            later.on('click',function(){dismissInvite(true);});
            overlay.on('keydown.edwpInvite',function(event){
                if (event.key==='Escape') {
                    event.preventDefault();dismissInvite(true);return;
                }
                if (event.key!=='Tab') return;
                var focusable=dialog.find('button:not(:disabled)');
                if (!focusable.length) {event.preventDefault();return;}
                var first=focusable[0],last=focusable[focusable.length-1];
                if (event.shiftKey && document.activeElement===first){
                    event.preventDefault();last.focus();
                } else if (!event.shiftKey && document.activeElement===last){
                    event.preventDefault();first.focus();
                }
            });
            actions.append(accept,later);
            dialog.append(feedback,actions,modalDiagnosis,modalReport);
            overlay.append(dialog);
            invite=overlay;
            $('body').append(overlay).addClass('edwp-invite-open');
            accept.trigger('focus');
        }
        async function showDiagnosis() {
            diagnose.prop('disabled',true);
            try {
                var diagnostic=window.EduSyncWebPushDiagnostic;
                var output=await diagnostic.report(config,lastError,lastStage,lastSw);
                report.text(output).removeClass('d-none');
                if(invite)invite.find('.edwp-invite-report').text(output).removeClass('d-none');
            } catch(err){
                message('No se pudo realizar el diagnóstico del navegador.',true);
            } finally {diagnose.prop('disabled',false);}
        }
        diagnose.on('click',function(e){e.stopPropagation();showDiagnosis();});

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
            if (running) return false;
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
                return true;
            } catch(err) {
                var reason=err.message||'No se pudo activar Web Push.';
                message(reason,true);
                if (invite) invite.find('.edwp-invite-feedback').text(reason);
                return false;
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
                // Si el usuario lo desactivó expresamente, no reactivarlo
                // sólo por tener un permiso anterior del navegador.
                try { localStorage.setItem(optedOutKey,'1'); } catch (_) {}
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
            dismissedKey='edusync:webpush:invite-dismissed:'+result.identity;
            optedOutKey='edusync:webpush:opted-out:'+result.identity;
            invitedKey='edusync:webpush:invite-shown:'+result.identity;
            label(currentEnabled() && Notification.permission==='granted');
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
            // Permiso previamente concedido: vincular/renovar en Firebase sin
            // volver a pedir autorización, salvo desactivación explícita.
            if(Notification.permission==='granted' && !optedOut()) {
                enable(false);
            } else if(Notification.permission==='default' && !optedOut()) {
                // Al entrar en EduSync, la invitación es interna; el permiso
                // del navegador se solicita sólo al pulsar «Activar».
                showInvite();
            }
        }).fail(function(){message('No se pudo consultar la configuración del servidor.',true);});
    });
})(window.jQuery);
