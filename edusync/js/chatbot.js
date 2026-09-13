(function($){
    'use strict';

    var $panel = $('#edu-chat-panel');
    var $body = $('#edu-chat-messages');
    var $form = $('#edu-chat-form');
    var $input = $('#edu-chat-input');
    var $send = $('#edu-chat-send');
    var $role = $('#edu-chat-role');
    var bootstrapped = false;
    var busy = false;
    var roleName = 'Asistente';

    if (!$panel.length || !$body.length || !$form.length) return;

    function scrollBottom(){
        if ($body.length) $body.scrollTop($body[0].scrollHeight);
    }

    function setMode(mode){
        var suffix = ' · Modo local';
        if (mode === 'ai_local') suffix = ' · IA local';
        else if (mode === 'ai') suffix = ' · IA';
        $role.text(roleName + suffix);
    }

    function addMessage(text, role, extra){
        var $row = $('<div>').addClass('edu-chat-message-row ' + (role === 'user' ? 'user' : 'bot'));
        var $msg = $('<div>').addClass('edu-chat-message ' + (role === 'user' ? 'user' : 'bot')).text(text || '');
        $row.append($msg).appendTo($body);
        if (extra) {
            renderCards(extra.cards || []);
            renderActions(extra.actions || []);
        }
        scrollBottom();
    }

    function renderCards(cards){
        if (!Array.isArray(cards) || !cards.length) return;
        var $grid = $('<div>').addClass('edu-chat-card-grid');
        cards.forEach(function(card){
            var $card = $('<div>').addClass('edu-chat-card').attr('data-tone', card && card.tone ? card.tone : 'primary');
            $('<div>').addClass('edu-chat-card-label').text(card && card.label ? card.label : '').appendTo($card);
            $('<div>').addClass('edu-chat-card-value').text(card && card.value != null ? String(card.value) : '').appendTo($card);
            $grid.append($card);
        });
        $body.append($grid);
    }

    function renderActions(actions){
        if (!Array.isArray(actions) || !actions.length) return;
        var $wrap = $('<div>').addClass('edu-chat-actions');
        actions.forEach(function(action){
            if (!action || !action.url || !/^index\.php\?page=/.test(action.url)) return;
            var $a = $('<a>').addClass('edu-chat-action').attr('href', action.url);
            $('<i>').addClass('fas ' + (action.icon || 'fa-arrow-right')).appendTo($a);
            $('<span>').text(action.label || 'Abrir').appendTo($a);
            $wrap.append($a);
        });
        if ($wrap.children().length) $body.append($wrap);
    }

    function renderSuggestions(items){
        if (!Array.isArray(items) || !items.length) return;
        var $wrap = $('<div>').addClass('edu-chat-suggestions');
        items.forEach(function(item){
            if (!item) return;
            $('<button>', {type:'button', class:'edu-chat-suggestion', text:item})
                .on('click', function(){ sendMessage(item); })
                .appendTo($wrap);
        });
        $body.append($wrap);
        scrollBottom();
    }

    function showTyping(){
        removeTyping();
        var $typing = $('<div id="edu-chat-typing">').addClass('edu-chat-typing');
        $typing.append('<span></span><span></span><span></span>');
        $body.append($typing);
        scrollBottom();
    }

    function removeTyping(){ $('#edu-chat-typing').remove(); }

    function setBusy(value){
        busy = !!value;
        $send.prop('disabled', busy);
        $input.prop('disabled', busy);
    }

    function bootstrapChat(force){
        if (bootstrapped && !force) return;
        $body.html('<div class="edu-chat-empty"><i class="fas fa-circle-notch fa-spin"></i><div>Cargando asistente...</div></div>');
        $.ajax({url:'chatbot_api.php', type:'GET', data:{action:'bootstrap'}, dataType:'json', cache:false})
            .done(function(resp){
                $body.empty();
                if (!resp || Number(resp.status) !== 1) {
                    addMessage(resp && resp.message ? resp.message : 'No pude iniciar el asistente.', 'assistant');
                    return;
                }
                roleName = resp.role || 'Asistente';
                setMode(resp.assistant_mode || 'local');
                var history = Array.isArray(resp.history) ? resp.history : [];
                if (history.length) {
                    history.forEach(function(item){
                        addMessage(item.text || '', item.role === 'user' ? 'user' : 'assistant', item.role === 'assistant' ? {cards:item.cards || [], actions:item.actions || []} : null);
                    });
                } else {
                    addMessage(resp.greeting || 'Hola. Soy el Asistente EduSync.', 'assistant');
                }
                renderSuggestions(resp.suggestions || []);
                bootstrapped = true;
            })
            .fail(function(xhr){
                $body.empty();
                addMessage(xhr && xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'No se pudo iniciar el asistente.', 'assistant');
            });
    }

    function sendMessage(text){
        text = $.trim(text || '');
        if (!text || busy) return;
        if (text.length > 500) text = text.substring(0, 500);

        addMessage(text, 'user');
        $input.val('').css('height','auto');
        setBusy(true);
        showTyping();

        $.ajax({
            url:'chatbot_api.php',
            type:'POST',
            dataType:'json',
            data:{
                action:'message',
                message:text,
                csrf_token:$form.find('input[name="csrf_token"]').val()
            }
        }).done(function(resp){
            removeTyping();
            if (!resp || Number(resp.status) !== 1) {
                addMessage(resp && resp.message ? resp.message : 'No pude procesar la consulta.', 'assistant');
                return;
            }
            setMode(resp.assistant_mode || 'local');
            addMessage(resp.message || 'Consulta procesada.', 'assistant', {cards:resp.cards || [], actions:resp.actions || []});
            renderSuggestions(resp.follow_up || []);
        }).fail(function(xhr){
            removeTyping();
            var msg = xhr && xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'No se pudo conectar con el asistente.';
            addMessage(msg, 'assistant');
        }).always(function(){
            setBusy(false);
            $input.trigger('focus');
        });
    }

    function resetChat(){
        if (busy) return;
        setBusy(true);
        $.ajax({
            url:'chatbot_api.php',
            type:'POST',
            dataType:'json',
            data:{action:'reset', csrf_token:$form.find('input[name="csrf_token"]').val()}
        }).done(function(resp){
            bootstrapped = false;
            $body.empty();
            if (resp && Number(resp.status) === 1) {
                setMode(resp.assistant_mode || 'local');
                bootstrapChat(true);
            } else addMessage(resp && resp.message ? resp.message : 'No se pudo reiniciar la conversación.', 'assistant');
        }).fail(function(){
            addMessage('No se pudo reiniciar la conversación.', 'assistant');
        }).always(function(){ setBusy(false); });
    }

    $('#edu-chat-toggle').on('click', function(){
        $panel.prop('hidden', false);
        bootstrapChat(false);
        setTimeout(function(){ $input.trigger('focus'); }, 50);
    });

    $('#edu-chat-close').on('click', function(){ $panel.prop('hidden', true); });
    $('#edu-chat-reset').on('click', resetChat);

    $form.on('submit', function(e){
        e.preventDefault();
        sendMessage($input.val());
    });

    $input.on('keydown', function(e){
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            $form.trigger('submit');
        }
    }).on('input', function(){
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 96) + 'px';
    });

})(window.jQuery);
