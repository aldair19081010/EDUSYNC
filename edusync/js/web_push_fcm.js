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
                || Notification.permission!=='default'
                || recentlyDismissed() || optedOut() || $('.modal.show').length) return;
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
            dialog.append($('<p id="edwp-invite-description" class="edwp-invite-description"></p>').text(
                'Recibe avisos sobre notas, asistencias, pagos y comunicados incluso cuando EduSync esté cerrado.'
            ));
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
            // Abrir en una ventana propia: el menú desplegable de la campana
            // recortaba el informe y sólo dejaba ver las primeras líneas.
            var layer=$('<div class="edwp-debug-layer"></div>');
            var panel=$('<section class="edwp-debug-panel" role="dialog" aria-modal="true" aria-label="Diagnóstico de notificaciones"></section>');
            var heading=$('<div class="edwp-debug-heading"></div>').text('Diagnóstico de notificaciones');
            var content=$('<pre class="edwp-debug-content"></pre>').text('Revisando el navegador (máximo 5 segundos)…');
            var controls=$('<div class="edwp-debug-actions"></div>');
            var copy=$('<button type="button" class="btn btn-primary btn-sm"></button>').text('Copiar diagnóstico');
            var probe=$('<button type="button" class="btn btn-outline-primary btn-sm"></button>').text('Probar servicio Push');
            var close=$('<button type="button" class="btn btn-light border btn-sm"></button>').text('Cerrar');
            function dismiss() {layer.remove();}
            close.on('click',dismiss);
            layer.on('keydown',function(e){if(e.key==='Escape'){e.preventDefault();dismiss();}});
            copy.on('click',async function(){
                try {
                    if(!navigator.clipboard || !navigator.clipboard.writeText)
                        throw new Error('Clipboard unsupported');
                    await navigator.clipboard.writeText(content.text());
                    copy.text('Copiado');
                } catch (_) {
                    copy.text('Selecciona el texto para copiar');
                }
            });
            probe.on('click',async function(){
                // Activación explícita del usuario, sin volver a pedir permiso.
                // Si funciona, la suscripción es reutilizable por Firebase.
                probe.prop('disabled',true).text('Probando (hasta 20 s)…');
                content.text(content.text()+'\n\nPrueba Push nativa en curso…');
                try {
                    var result=await window.EduSyncWebPushDiagnostic.probe(config,lastSw);
                    content.text(content.text()+'\n'+result.message);
                    report.text(content.text()).removeClass('d-none');
                    if(invite)invite.find('.edwp-invite-report').text(content.text()).removeClass('d-none');
                    probe.text(result.ok?'Servicio Push responde':'Reintentar prueba Push');
                } catch(e){
                    content.text(content.text()+'\nNo se completó la prueba: '+String(e.name||'Error'));
                    probe.text('Reintentar prueba Push');
                } finally {probe.prop('disabled',false);}
            });
            panel.append(heading,content);
            controls.append(probe,copy,close);
            panel.append(controls);
            layer.append(panel);
            $('body').append(layer);
            close.trigger('focus');
            try {
                var diagnostic=window.EduSyncWebPushDiagnostic;
                var output=await diagnostic.report(config,lastError,lastStage,lastSw);
                content.text(output);
                report.text(output).removeClass('d-none');
                if(invite)invite.find('.edwp-invite-report').text(output).removeClass('d-none');
            } catch(err){
                content.text('No se pudo completar el diagnóstico: '+String(err.name||'Error'));
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
            var limited=window.EduSyncWebPushDiagnostic.limited;
            await limited(script('https://www.gstatic.com/firebasejs/10.14.1/firebase-app-compat.js'),10000,'descarga de Firebase App');
            await limited(script('https://www.gstatic.com/firebasejs/10.14.1/firebase-messaging-compat.js'),10000,'descarga de Firebase Messaging');
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
            lastStage='Validar Firebase y clave VAPID';
            window.EduSyncWebPushDiagnostic.validate(config);
            lastStage='Registrar Service Worker';
            var limited=window.EduSyncWebPushDiagnostic.limited;
            var sw=await limited(navigator.serviceWorker.register('firebase-messaging-sw.js',{
                scope:'./',updateViaCache:'none'
            }),10000,'registro de Service Worker');
            lastSw=sw;
            // Evitar la carrera inicial: Firebase requiere un trabajador activo.
            lastStage='Activar Service Worker';
            if(!sw.active && navigator.serviceWorker.ready){
                var active=await limited(navigator.serviceWorker.ready,6500,'activación de Service Worker');
                if(!active || active.scope!==sw.scope || !active.active)
                    throw new Error('El Service Worker de EduSync no se activó correctamente.');
            }
            lastStage='Inicializar Firebase';
            var client=await limited(firebaseClient(),12000,'inicio de Firebase');
            lastStage='Registrar dispositivo en Push';
            var token=await limited(client.getToken({
                vapidKey:config.vapid_key,serviceWorkerRegistration:sw
            }),20000,'registro en el servicio Push del navegador');
            if (!token) throw new Error('Firebase no devolvió un token.');
            lastStage='Registrar dispositivo en EduSync';
            return token;
        }
        function request(action,data) {
            return new Promise(function(resolve,reject){
                $.ajax({
                    url:'api/web_push.php',method:'POST',dataType:'json',timeout:12000,
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
                lastStage='Registro completado';
                lastError=null;
                try{localStorage.removeItem(cooldownKey)}catch(_){}
                diagnose.addClass('d-none');
                report.addClass('d-none').text('');
                saveEnabled(true);
                message('Avisos activados en este dispositivo.',false);
                return true;
            } catch(err) {
                lastError={name:err.name||'Error',code:err.code||'',message:err.message||''};
                var diagnostic=window.EduSyncWebPushDiagnostic;
                var reason=diagnostic.explain(err);
                message(reason,true);
                diagnose.removeClass('d-none');
                if(invite){
                    invite.find('.edwp-invite-feedback').text(reason);
                    invite.find('.edwp-invite-diagnose').removeClass('d-none');
                }
                // Evitar solicitar una y otra vez el servicio Push tras fallos
                // externos; un clic manual siempre permite reintentar.
                if(diagnostic.isPushServiceError(err)){
                    try{localStorage.setItem(cooldownKey,String(Date.now()+10*60*1000))}
                    catch(_){}
                }
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
            // Devolver la operación permite probar su finalización de forma
            // determinista; no cambia la solicitud de permiso desde el clic.
            if(currentEnabled()) return disable();
            return enable(true);
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
            cooldownKey='edusync:webpush:failed-until:'+result.identity;
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
            // Solo renovar automáticamente si el propio usuario ya activó
            // Web Push y el navegador conserva una suscripción existente.
            // El permiso «granted» por sí solo NO implica consentimiento para
            // registrar un dispositivo nuevo ni autoriza suscripciones automáticas.
            if(Notification.permission==='granted' && currentEnabled() && !optedOut()) {
                (async function() {
                    try {
                        var limited=window.EduSyncWebPushDiagnostic.limited;
                        var registration=await limited(
                            navigator.serviceWorker.getRegistration('./'),2500,
                            'consulta de suscripción anterior');
                        var subscription=registration&&registration.pushManager
                            ? await limited(registration.pushManager.getSubscription(),2500,
                                'comprobación de suscripción anterior') : null;
                        if(!subscription) {
                            // No forzar PushManager.subscribe() al iniciar sesión.
                            label(false);
                            message('El permiso ya está concedido, pero falta la suscripción Push. Actívala desde esta campana.',false);
                            return;
                        }
                        var after=0;
                        try {after=Number(localStorage.getItem(cooldownKey)||0);} catch(_){}
                        if(!after || after<=Date.now()) enable(false);
                        else message('Si deseas reactivar los avisos, usa la campana.',false);
                    } catch(err) {
                        message('No se comprobó la suscripción previa. Puedes activar desde la campana.',false);
                    }
                })();
            } else if(!optedOut() && Notification.permission==='default') {
                // La invitación es sólo para el primer permiso del navegador.
                // Una vez concedido, la activación normal queda en la campana.
                showInvite();
            } else if(!optedOut() && Notification.permission==='granted' && !currentEnabled()) {
                message('El navegador ya permitió avisos. Actívalos desde esta campana para vincular el dispositivo.',false);
            }
        }).fail(function(){message('No se pudo consultar la configuración del servidor.',true);});
    });
})(window.jQuery);
