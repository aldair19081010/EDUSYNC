/*
 * EduSync Modal System
 * Detecta contenido cargado dinámicamente en #uni_modal y aplica únicamente
 * clases visuales. No modifica datos, AJAX ni reglas de negocio.
 */
(function ($) {
    'use strict';

    if (!$) return;

    var modalKinds = [
        'ed-modal-student',
        'ed-modal-teacher',
        'ed-modal-user',
        'ed-modal-payment',
        'ed-modal-fee',
        'ed-modal-concept',
        'ed-modal-discount'
    ];

    function detectKind($modal) {
        if ($modal.find('#manage-student').length) return 'ed-modal-student';
        if ($modal.find('#manage-teacher').length) return 'ed-modal-teacher';
        if ($modal.find('#manage-user').length) return 'ed-modal-user';
        if ($modal.find('#payment-form').length) return 'ed-modal-payment';
        if ($modal.find('#manage-fees').length) return 'ed-modal-fee';
        if ($modal.find('#concept-form').length) return 'ed-modal-concept';
        if ($modal.find('#discount-form').length) return 'ed-modal-discount';
        return '';
    }

    function tagSections($modal) {
        $modal.find('.form-section, .mc-section, .dm-section, .pm-section')
            .addClass('ed-modal-section');

        $modal.find('.form-section-title, .mc-section-title, .dm-title, .pm-section > h6')
            .addClass('ed-modal-section-title');

        $modal.find('.tutor-box, .pm-row, .dm-summary, .dm-final-box')
            .addClass('ed-modal-subsection');

        $modal.find('.dm-rule')
            .addClass('ed-modal-info-box');
    }

    function tagActions($modal) {
        var $forms = $modal.find('form');

        $forms.each(function () {
            var $form = $(this);

            $form.children('.text-right:last-child, .d-flex.justify-content-end:last-child, .btn-group-form:last-child, .dm-footer:last-child')
                .addClass('ed-modal-actions');

            $form.find('> .card > .card-footer:last-child')
                .addClass('ed-modal-actions');
        });

        /* manage_user.php incluye modal-footer dentro del contenido AJAX. */
        $modal.find('.modal-body .modal-footer')
            .addClass('ed-modal-inline-footer');
    }

    function normalizeForms($modal) {
        $modal.find('form').addClass('ed-modal-form');

        /* Neutraliza el .modal-body anidado de manage_user.php sin mover nodos. */
        $modal.find('.modal-body .modal-body')
            .addClass('ed-modal-nested-body');

        tagSections($modal);
        tagActions($modal);
    }

    function normalizeModal(modal) {
        var $modal = $(modal);
        if (!$modal.length) return;

        $modal.addClass('ed-modal-standard');
        $modal.find('.modal-content').addClass('ed-modal-content');

        modalKinds.forEach(function (kind) {
            $modal.removeClass(kind);
        });

        var kind = detectKind($modal);
        if (kind) $modal.addClass(kind);

        normalizeForms($modal);
    }

    function normalizeUniversalModal() {
        var $modal = $('#uni_modal');
        if ($modal.length) normalizeModal($modal);
    }

    $(function () {
        $('.modal').each(function () {
            normalizeModal(this);
        });
        normalizeUniversalModal();
    });

    $(document).on('shown.bs.modal', '.modal', function () {
        normalizeModal(this);
    });

    /* uni_modal carga el formulario después de abrirse. */
    $(document).ajaxComplete(function () {
        window.setTimeout(normalizeUniversalModal, 0);
    });

    /* También cubre contenido inyectado por .html() que no provenga de AJAX. */
    if (window.MutationObserver) {
        var observer = new MutationObserver(function (mutations) {
            var shouldRefresh = false;
            mutations.forEach(function (mutation) {
                if (mutation.addedNodes && mutation.addedNodes.length) shouldRefresh = true;
            });
            if (shouldRefresh) normalizeUniversalModal();
        });

        $(function () {
            var body = document.getElementById('uni_modal_body');
            if (body) {
                observer.observe(body, { childList: true, subtree: true });
            }
        });
    }
})(window.jQuery);
