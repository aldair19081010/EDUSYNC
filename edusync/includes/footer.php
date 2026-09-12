<?php
if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['csrf_token'])) {
    try {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } catch (Throwable $e) {
        $_SESSION['csrf_token'] = hash('sha256', session_id() . microtime(true));
    }
}
$chatbotCss = dirname(__DIR__) . '/css/chatbot.css';
$chatbotJs = dirname(__DIR__) . '/js/chatbot.js';
$chatbotCssVersion = @filemtime($chatbotCss) ?: time();
$chatbotJsVersion = @filemtime($chatbotJs) ?: time();
?>
<link rel="stylesheet" href="css/chatbot.css?v=<?php echo rawurlencode((string)$chatbotCssVersion); ?>">

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
            <div class="modal-body" id="uni_modal_body"></div>
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

<button type="button" id="edu-chat-toggle" class="edu-chat-toggle" aria-label="Abrir Asistente EduSync" title="Asistente EduSync">
    <i class="fas fa-robot"></i>
</button>

<section id="edu-chat-panel" class="edu-chat-panel" aria-label="Asistente EduSync" hidden>
    <header class="edu-chat-header">
        <div class="edu-chat-header-main">
            <div class="edu-chat-header-icon"><i class="fas fa-robot"></i></div>
            <div>
                <div class="edu-chat-title">Asistente EduSync</div>
                <small id="edu-chat-role" class="edu-chat-role">Conectado al sistema</small>
            </div>
        </div>
        <div class="edu-chat-header-actions">
            <button type="button" id="edu-chat-reset" class="edu-chat-header-btn" aria-label="Nueva conversación" title="Nueva conversación">
                <i class="fas fa-redo-alt"></i>
            </button>
            <button type="button" id="edu-chat-close" class="edu-chat-header-btn" aria-label="Cerrar asistente" title="Cerrar">
                <i class="fas fa-times"></i>
            </button>
        </div>
    </header>

    <div id="edu-chat-messages" class="edu-chat-body" aria-live="polite">
        <div class="edu-chat-empty">
            <i class="fas fa-circle-notch fa-spin"></i>
            <div>Cargando asistente...</div>
        </div>
    </div>

    <form id="edu-chat-form" class="edu-chat-form" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
        <textarea id="edu-chat-input" class="edu-chat-input" name="message" maxlength="500" rows="1" placeholder="Pregunta sobre EduSync..." required></textarea>
        <button type="submit" id="edu-chat-send" class="edu-chat-send" aria-label="Enviar consulta" title="Enviar">
            <i class="fas fa-paper-plane"></i>
        </button>
    </form>
    <div class="edu-chat-footnote">Solo muestra información permitida para tu perfil.</div>
</section>

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

<script src="js/chatbot.js?v=<?php echo rawurlencode((string)$chatbotJsVersion); ?>"></script>
