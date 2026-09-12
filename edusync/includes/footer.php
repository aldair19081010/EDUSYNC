<!-- Universal Modal for Dynamic Content -->
<div class="modal fade" id="uni_modal" data-backdrop="static" data-keyboard="false" tabindex="-1" role="dialog" aria-labelledby="uni_modal_label" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="uni_modal_label">Modal</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body" id="uni_modal_body">
                <!-- Content will be loaded here -->
            </div>
        </div>
    </div>
</div>

<!-- Footer -->
<footer class="sticky-footer bg-white">
    <div class="container my-auto">
        <div class="copyright text-center my-auto">
            <span>Copyright &copy; EduSync <?php echo date('Y'); ?></span>
        </div>
    </div>
</footer>
<!-- End of Footer -->

<button type="button" id="edu-chat-toggle" class="edu-chat-toggle" aria-label="Abrir asistente" title="Asistente EduSync">
  <i class="fas fa-comments"></i>
</button>
<section id="edu-chat-panel" class="edu-chat-panel" aria-label="Asistente EduSync" hidden>
  <header class="edu-chat-header">
    <strong><i class="fas fa-robot mr-2"></i>Asistente EduSync</strong>
    <button type="button" id="edu-chat-close" aria-label="Cerrar">&times;</button>
  </header>
  <div id="edu-chat-messages" class="edu-chat-messages">
    <div class="edu-chat-message edu-chat-bot">Hola. Puedo ayudarte con procesos y consultas básicas del sistema.</div>
    <div class="edu-chat-suggestions" aria-label="Consultas sugeridas">
      <button type="button" class="edu-chat-suggestion">¿Cómo registro un estudiante?</button>
      <button type="button" class="edu-chat-suggestion">¿Cómo subo un Excel?</button>
      <button type="button" class="edu-chat-suggestion">¿Cuántos estudiantes hay?</button>
      <button type="button" class="edu-chat-suggestion">¿Cómo desactivo un docente?</button>
      <button type="button" class="edu-chat-suggestion">¿Cómo registro asistencia?</button>
    </div>
  </div>
  <form id="edu-chat-form" class="edu-chat-form">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
    <input type="text" id="edu-chat-input" name="message" maxlength="500" placeholder="Escribe tu consulta..." autocomplete="off" required>
    <button type="submit" aria-label="Enviar consulta" title="Enviar"><i class="fas fa-paper-plane"></i></button>
  </form>
</section>

<style>
.edu-chat-toggle { position:fixed; right:22px; bottom:22px; z-index:1060; width:52px; height:52px; border:0; border-radius:50%; background:#1f6f8b; color:#fff; box-shadow:0 5px 18px rgba(0,0,0,.2); cursor:pointer; }
.edu-chat-panel { position:fixed; right:22px; bottom:86px; z-index:1060; width:min(360px, calc(100vw - 32px)); height:460px; background:#fff; border:1px solid #dce3e8; border-radius:8px; box-shadow:0 10px 30px rgba(27,47,61,.22); overflow:hidden; }
.edu-chat-header { display:flex; justify-content:space-between; align-items:center; padding:14px 16px; background:#1f6f8b; color:#fff; }
.edu-chat-header button { border:0; background:transparent; color:#fff; font-size:24px; line-height:1; cursor:pointer; }
.edu-chat-messages { height:365px; padding:14px; overflow-y:auto; background:#f4f7f8; }
.edu-chat-message { max-width:88%; margin-bottom:10px; padding:9px 11px; border-radius:8px; font-size:14px; line-height:1.4; white-space:pre-wrap; }
.edu-chat-bot { background:#fff; border:1px solid #dce3e8; color:#263640; }
.edu-chat-user { margin-left:auto; background:#d9edf2; color:#173b46; }
.edu-chat-suggestions { display:flex; flex-wrap:wrap; gap:6px; margin:4px 0 12px; }
.edu-chat-followup { display:flex; flex-wrap:wrap; gap:6px; margin-top:8px; }
.edu-chat-suggestion { border:1px solid #9cc5cf; border-radius:5px; background:#fff; color:#1f6f8b; padding:6px 8px; font-size:12px; cursor:pointer; }
.edu-chat-suggestion:hover { background:#e9f5f7; }
.edu-chat-form { display:flex; gap:8px; padding:10px; border-top:1px solid #dce3e8; background:#fff; }
.edu-chat-form input { flex:1; min-width:0; border:1px solid #cbd6dc; border-radius:5px; padding:8px 10px; }
.edu-chat-form button { width:38px; border:0; border-radius:5px; background:#1f6f8b; color:#fff; cursor:pointer; }
@media (max-width:576px) { .edu-chat-toggle { right:16px; bottom:16px; } .edu-chat-panel { right:16px; bottom:78px; height:430px; } .edu-chat-messages { height:335px; } }
</style>

<script>
(function($) {
  var $panel = $('#edu-chat-panel');
  var $messages = $('#edu-chat-messages');
  function addMessage(text, type) {
    $('<div>').addClass('edu-chat-message edu-chat-' + type).text(text).appendTo($messages);
    $messages.scrollTop($messages[0].scrollHeight);
  }
  function addFollowUps(items) {
    if (!Array.isArray(items) || !items.length) return;
    var $wrap = $('<div>').addClass('edu-chat-followup');
    $.each(items, function(_, item) {
      if (!item) return;
      $('<button>', {
        type: 'button',
        class: 'edu-chat-suggestion',
        text: item
      }).on('click', function() {
        $('#edu-chat-input').val(item).trigger('focus');
      }).appendTo($wrap);
    });
    $wrap.appendTo($messages);
    $messages.scrollTop($messages[0].scrollHeight);
  }
  $('#edu-chat-toggle').on('click', function() { $panel.prop('hidden', false); $('#edu-chat-input').trigger('focus'); });
  $('#edu-chat-close').on('click', function() { $panel.prop('hidden', true); });
  $('.edu-chat-suggestion').on('click', function() { $('#edu-chat-input').val($(this).text()).trigger('focus'); });
  $('#edu-chat-form').on('submit', function(e) {
    e.preventDefault();
    var $form = $(this), $input = $('#edu-chat-input'), text = $.trim($input.val());
    if (!text || $form.data('busy')) return;
    var formData = {
      message: text,
      csrf_token: $form.find('input[name="csrf_token"]').val()
    };
    addMessage(text, 'user');
    $input.val('');
    $form.data('busy', true);
    $.ajax({
      url: 'chatbot_api.php',
      type: 'POST',
      data: formData,
      dataType: 'json'
    }).done(function(resp) {
      addMessage(resp && resp.message ? resp.message : 'No pude procesar la consulta.', 'bot');
      addFollowUps(resp && Array.isArray(resp.follow_up) ? resp.follow_up : []);
    }).fail(function(xhr) {
      var msg = xhr && xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'No se pudo conectar con el asistente.';
      addMessage(msg, 'bot');
    }).always(function() { $form.data('busy', false); });
  });
})(window.jQuery);
</script>

<!-- El comportamiento de confirmación vive en js/ui_feedback.js. -->
<div class="modal fade" id="confirm_modal" tabindex="-1" role="dialog" aria-labelledby="confirm_modal_label" aria-hidden="true">
  <div class="modal-dialog modal-sm modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="confirm_modal_label">Confirmar</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body" id="confirm_modal_body">¿Estás seguro?</div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-danger btn-sm" id="confirm_modal_ok">Confirmar</button>
      </div>
    </div>
  </div>
</div>
