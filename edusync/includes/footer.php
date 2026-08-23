<script>console.log('FOOTER: start');</script>
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

<!-- Universal Modal Function -->
<script>
// Definir explícitamente en el objeto global por si hay scoping
window.uni_modal = function(title, url, size = "modal-lg") {
    var modal = $('#uni_modal');
    var modalDialog = modal.find('.modal-dialog');
    
    // Actualizar el tamaño del modal
    modalDialog.removeClass('modal-sm modal-lg modal-xl mid-large');
    modalDialog.addClass(size || 'modal-lg');
    
    // Actualizar título
    $('#uni_modal_label').html(title);
    
    // Mostrar modal INMEDIATAMENTE (vacío)
    $('#uni_modal_body').html('');
    modal.modal({ backdrop: 'static', keyboard: false, show: true });
    
    // Append current EDUSYNCSESSID as sid for XHR session resume
    try {
      var m = document.cookie.match('(^|; )EDUSYNCSESSID=([^;]+)');
      if (m && m[2]) {
        var sid = decodeURIComponent(m[2]);
        url = url + (url.indexOf('?') === -1 ? '?' : '&') + 'sid=' + encodeURIComponent(sid);
      }
    } catch(e) { console.warn('uni_modal: could not append sid', e); }
    
    // Cargar contenido en segundo plano
    $.ajax({
        url: url,
        type: 'GET',
        cache: false,
        success: function(response) {
            $('#uni_modal_body').html(response);
        },
        error: function(xhr) {
            if (xhr && xhr.status == 401) {
                var body = xhr.responseText || '<div class="alert alert-danger">Sesión expirada. Por favor <a href="login.php">inicie sesión</a>.</div>';
                $('#uni_modal_body').html(body);
            } else {
                $('#uni_modal_body').html('<div class="alert alert-danger">Error al cargar el contenido: ' + xhr.status + ' ' + xhr.statusText + '</div>');
            }
        }
    });
  }
  console.log('FOOTER: uni_modal definida:', typeof window.uni_modal);
</script>

<style>
/* Loader overlay */
#page-loader {
  position: fixed;
  inset: 0 0 0 0;
  background: rgba(0,0,0,0.35);
  z-index: 2000000;
  display: flex;
  align-items: center;
  justify-content: center;
}
#page-loader .loader-inner {
  background: #fff;
  padding: 18px 22px;
  border-radius: 8px;
  box-shadow: 0 6px 20px rgba(0,0,0,0.2);
  display: flex;
  align-items: center;
}
#page-loader .spinner-border {
  width: 1.5rem;
  height: 1.5rem;
  margin-right: 10px;
}

/* Simple toast position */
.toast-alert-fixed {
  position: fixed;
  top: 20px;
  right: 20px;
  z-index: 2000001;
  min-width: 220px;
  box-shadow: 0 6px 18px rgba(0,0,0,0.12);
}
</style>

<script>
// Provide small global helpers if missing so pages don't break when template's utilities aren't loaded.
if (typeof start_load !== 'function') {
  function start_load() {
    try {
      if ($('#page-loader').length) return;
      var $loader = $('<div id="page-loader" role="status" aria-live="polite" aria-label="Cargando..."></div>');
      var inner = $('<div class="loader-inner"><div class="spinner-border text-primary" role="status"><span class="sr-only">Cargando...</span></div><div class="ml-2">Cargando...</div></div>');
      $loader.append(inner);
      $('body').append($loader);
    } catch (err) {
      // silent fail
      console.warn('start_load fallback error:', err);
    }
  }
}
if (typeof end_load !== 'function') {
  function end_load() {
    try {
      $('#page-loader').fadeOut(180, function(){ $(this).remove(); });
    } catch (err) {
      console.warn('end_load fallback error:', err);
    }
  }
}
if (typeof alert_toast !== 'function') {
  function alert_toast(message, type) {
    type = type || 'info';
    try {
      var $t = $('<div class="alert alert-' + type + ' toast-alert-fixed" role="alert">' + message + '</div>');
      $('body').append($t);
      setTimeout(function(){ $t.fadeOut(300, function(){ $t.remove(); }); }, 3500);
    } catch (err) {
      // fallback to console
      console.log('Toast:', type, message);
    }
  }
}
</script>

<!-- Confirm Modal -->
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

<script>
// Confirmation helper: _conf(message, functionName, paramsArray)
function _conf(message, func, params){
  try{
    $('#confirm_modal_body').html(message);
    $('#confirm_modal_ok').off('click');
    $('#confirm_modal_ok').on('click', function(e){
      $('#confirm_modal').modal('hide');
      // Try to call a global JS function
      try{
        if(typeof window[func] === 'function'){
          window[func].apply(window, params || []);
          return;
        }
      }catch(err){ console.warn('Error calling function', err); }
      // Fallback: if function name corresponds to an ajax.php action, call it
      if (typeof func === 'string') {
        // POST to ajax.php?action=func with params[0] etc. (if provided)
        var postData = {};
        if (Array.isArray(params) && params.length > 0) {
          // Assume first param is id when used like delete_student
          postData.id = params[0];
        }
        $.post('ajax.php?action=' + func, postData, function(resp){
          try { var j = (typeof resp === 'string') ? JSON.parse(resp) : resp; } catch(e){ var j = resp; }
          if (j && (j.status == 1 || j.status == 'success')) {
            alert_toast(j.message || 'Acción ejecutada.', 'success');
            try { $('#student-table').DataTable().ajax.reload(null, false); } catch(_){}
          } else {
            alert_toast(j.message || 'Ocurrió un error.', 'danger');
          }
        }, 'json').fail(function(){ alert_toast('Error en el servidor.', 'danger'); });
      }
    });
    $('#confirm_modal').modal({backdrop: 'static', keyboard: false});
    $('#confirm_modal').modal('show');
  }catch(e){
    // fallback to native confirm
    if (confirm(message)){
      try{ if (typeof window[func] === 'function') window[func].apply(window, params || []); } catch(err){ console.warn(err); }
    }
  }
}
</script>
