/* Invitación visual de EduSync: sólo delega la activación al botón existente.
 * No inicializa Firebase, no registra SW y nunca pide permisos al cargar.
 */
(function($){
    'use strict';
    if (!$) return;

    $(function(){
        if (!window.isSecureContext || !('Notification' in window)
            || !('serviceWorker' in navigator) || !('PushManager' in window)) return;

        var toggle=$('.edusync-notification-menu .edwp-toggle').first();
        if (!toggle.length) return;
        var overlay=null, previousFocus=null;

        function read(storage,key) {
            try { return storage.getItem(key); } catch (_) { return null; }
        }
        function write(storage,key,value) {
            try { storage.setItem(key,value); } catch (_) {}
        }
        function close(defer,key) {
            if (defer) write(localStorage,key,String(Date.now()+30*24*60*60*1000));
            if (!overlay) return;
            overlay.off().remove();
            overlay=null;
            $('body').removeClass('edwp-welcome-open');
            if (previousFocus && document.contains(previousFocus) &&
                typeof previousFocus.focus==='function') previousFocus.focus();
            previousFocus=null;
        }
        function open(identity) {
            var deferKey='edusync:webpush:welcome-later:'+identity;
            var sessionKey='edusync:webpush:welcome-shown:'+identity;
            var enabledKey='edusync:webpush:enabled:'+identity;
            if (read(localStorage,enabledKey)==='1' || Notification.permission==='denied') return;
            if (Number(read(localStorage,deferKey)||0)>Date.now()) return;
            if (read(sessionStorage,sessionKey)==='1') return;
            if ($('.modal.show').length || toggle.prop('disabled')) return;

            write(sessionStorage,sessionKey,'1');
            previousFocus=document.activeElement;

            overlay=$('<div class="edwp-welcome-layer"></div>');
            var panel=$('<section class="edwp-welcome-panel" role="dialog" aria-modal="true" aria-labelledby="edwp-welcome-title" aria-describedby="edwp-welcome-description"></section>');
            panel.append('<div class="edwp-welcome-bell" aria-hidden="true"><i class="far fa-bell"></i></div>');
            panel.append($('<h2 id="edwp-welcome-title" class="edwp-welcome-title"></h2>').text('Activa las notificaciones de EduSync'));
            var info=(Notification.permission==='granted')
                ? 'Ya autorizaste los avisos del navegador. Actívalos para vincular este dispositivo con EduSync.'
                : 'Recibe avisos de notas, asistencia, pagos y comunicados, incluso con EduSync cerrado.';
            panel.append($('<p id="edwp-welcome-description" class="edwp-welcome-copy"></p>').text(info));

            var actions=$('<div class="edwp-welcome-actions"></div>');
            var activate=$('<button type="button" class="btn btn-primary edwp-welcome-activate"></button>').text('Activar notificaciones');
            var later=$('<button type="button" class="btn btn-light border edwp-welcome-later"></button>').text('Ahora no');
            var hint=$('<p class="edwp-welcome-hint"></p>').text('También puedes activarlas después desde la campana.');

            activate.on('click',function(){
                // MUY IMPORTANTE: invocar directamente el botón existente
                // dentro del clic real del usuario. Sin awaits, Promise,
                // suscripciones automáticas ni un segundo flujo Firebase.
                var button=toggle[0];
                if (!button || button.disabled || read(localStorage,enabledKey)==='1') {
                    close(false,deferKey);
                    return;
                }
                button.click();
                close(false,deferKey);
            });
            later.on('click',function(){ close(true,deferKey); });
            overlay.on('keydown.edwpWelcome',function(event){
                if (event.key==='Escape') {
                    event.preventDefault();
                    close(true,deferKey);
                } else if (event.key==='Tab') {
                    var a=activate[0],b=later[0];
                    if (event.shiftKey && document.activeElement===a) {
                        event.preventDefault();b.focus();
                    } else if (!event.shiftKey && document.activeElement===b) {
                        event.preventDefault();a.focus();
                    }
                }
            });
            actions.append(activate,later);
            panel.append(actions,hint);
            overlay.append(panel);
            $('body').append(overlay).addClass('edwp-welcome-open');
            activate.trigger('focus');
        }

        // La respuesta sólo se utiliza para saber si mostrar la invitación.
        // La suscripción/token siguen exclusivamente en web_push_fcm.js.
        $.getJSON('api/web_push.php',{action:'config'}).done(function(result){
            if (!result || result.status!=='ok' || !result.configured || !result.push_ready) return;
            if (typeof result.identity!=='string' || !result.identity) return;
            open(result.identity);
        });
    });
})(window.jQuery);
