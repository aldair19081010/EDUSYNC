/*
 * EduSync Feedback System
 * Unifica toasts, confirmaciones, loaders y estados ocupados de botones.
 * Mantiene compatibilidad con alert_toast(), start_load(), end_load() y _conf().
 */
(function (window, document, $) {
    'use strict';

    var loaderDepth = 0;
    var autoBusyForms = [];

    function normalizeType(type) {
        var value = String(type || 'info').toLowerCase();
        if (value === 'error') value = 'danger';
        if (['success', 'warning', 'danger', 'info'].indexOf(value) === -1) value = 'info';
        return value;
    }

    function typeMeta(type) {
        type = normalizeType(type);
        var map = {
            success: { title: 'Listo', icon: 'fa-check' },
            warning: { title: 'Atención', icon: 'fa-exclamation-triangle' },
            danger: { title: 'No se pudo completar', icon: 'fa-times' },
            info: { title: 'Información', icon: 'fa-info' }
        };
        return map[type];
    }

    function ensureToastStack() {
        var stack = document.getElementById('ed-feedback-stack');
        if (!stack) {
            stack = document.createElement('div');
            stack.id = 'ed-feedback-stack';
            stack.setAttribute('aria-live', 'polite');
            stack.setAttribute('aria-atomic', 'false');
            document.body.appendChild(stack);
        }
        return stack;
    }

    function removeToast(toast) {
        if (!toast || !toast.parentNode) return;
        toast.classList.add('is-hiding');
        window.setTimeout(function () {
            if (toast.parentNode) toast.parentNode.removeChild(toast);
        }, 190);
    }

    function toast(message, type, options) {
        options = options || {};
        type = normalizeType(type);
        var meta = typeMeta(type);
        var stack = ensureToastStack();
        var item = document.createElement('div');
        var icon = document.createElement('span');
        var iconGlyph = document.createElement('i');
        var copy = document.createElement('div');
        var title = document.createElement('strong');
        var text = document.createElement('p');
        var close = document.createElement('button');
        var duration = Number(options.duration || (type === 'danger' ? 5200 : 3800));

        item.className = 'ed-feedback-toast ed-feedback-toast-' + type;
        item.setAttribute('role', type === 'danger' ? 'alert' : 'status');

        icon.className = 'ed-feedback-toast-icon';
        iconGlyph.className = 'fas ' + meta.icon;
        icon.appendChild(iconGlyph);

        copy.className = 'ed-feedback-toast-copy';
        title.className = 'ed-feedback-toast-title';
        title.textContent = options.title || meta.title;
        text.className = 'ed-feedback-toast-message';
        text.textContent = message == null ? '' : String(message);
        copy.appendChild(title);
        copy.appendChild(text);

        close.type = 'button';
        close.className = 'ed-feedback-toast-close';
        close.setAttribute('aria-label', 'Cerrar notificación');
        close.innerHTML = '&times;';
        close.addEventListener('click', function () { removeToast(item); });

        item.appendChild(icon);
        item.appendChild(copy);
        item.appendChild(close);
        stack.appendChild(item);

        while (stack.children.length > 5) {
            stack.removeChild(stack.firstElementChild);
        }

        if (duration > 0) {
            window.setTimeout(function () { removeToast(item); }, duration);
        }

        return item;
    }

    function loader(message) {
        loaderDepth += 1;
        var existing = document.getElementById('page-loader');
        if (existing) {
            var label = existing.querySelector('[data-ed-loader-label]');
            if (label && message) label.textContent = message;
            return existing;
        }

        var overlay = document.createElement('div');
        var inner = document.createElement('div');
        var spinner = document.createElement('div');
        var spinnerSr = document.createElement('span');
        var labelNode = document.createElement('div');

        overlay.id = 'page-loader';
        overlay.setAttribute('role', 'status');
        overlay.setAttribute('aria-live', 'polite');
        overlay.setAttribute('aria-label', message || 'Procesando...');

        inner.className = 'loader-inner';
        spinner.className = 'spinner-border text-primary';
        spinnerSr.className = 'sr-only';
        spinnerSr.textContent = 'Procesando...';
        spinner.appendChild(spinnerSr);

        labelNode.setAttribute('data-ed-loader-label', '1');
        labelNode.textContent = message || 'Procesando...';

        inner.appendChild(spinner);
        inner.appendChild(labelNode);
        overlay.appendChild(inner);
        document.body.appendChild(overlay);
        return overlay;
    }

    function stopLoader(force) {
        if (force) loaderDepth = 0;
        else loaderDepth = Math.max(0, loaderDepth - 1);
        if (loaderDepth > 0) return;

        var existing = document.getElementById('page-loader');
        if (!existing) return;

        if ($ && $.fn && $.fn.fadeOut) {
            $(existing).stop(true, true).fadeOut(140, function () {
                if (existing.parentNode) existing.parentNode.removeChild(existing);
            });
        } else if (existing.parentNode) {
            existing.parentNode.removeChild(existing);
        }
    }

    function busyLabel(button) {
        var text = (button && button.textContent ? button.textContent : '').trim().toLowerCase();
        if (text.indexOf('registr') !== -1) return 'Registrando...';
        if (text.indexOf('guard') !== -1) return 'Guardando...';
        if (text.indexOf('aplic') !== -1) return 'Aplicando...';
        if (text.indexOf('gener') !== -1) return 'Generando...';
        if (text.indexOf('actual') !== -1) return 'Actualizando...';
        if (text.indexOf('elimin') !== -1 || text.indexOf('anul') !== -1) return 'Procesando...';
        return 'Procesando...';
    }

    function setButtonLoading(button, active, label) {
        if (!button) return;
        var el = button.jquery ? button[0] : button;
        if (!el) return;

        if (active) {
            if (el.getAttribute('data-ed-busy') === '1') return;
            el.setAttribute('data-ed-busy', '1');
            el.setAttribute('data-ed-original-html', el.innerHTML);
            el.classList.add('ed-btn-busy');
            el.disabled = true;
            el.innerHTML = '<span class="spinner-border ed-btn-spinner" role="status" aria-hidden="true"></span>' +
                '<span>' + (label || busyLabel(el)) + '</span>';
        } else {
            var original = el.getAttribute('data-ed-original-html');
            if (original !== null) el.innerHTML = original;
            el.removeAttribute('data-ed-original-html');
            el.removeAttribute('data-ed-busy');
            el.classList.remove('ed-btn-busy');
            el.disabled = false;
        }
    }

    function releaseAutoBusy() {
        autoBusyForms.forEach(function (entry) {
            if (entry && entry.button) setButtonLoading(entry.button, false);
            if (entry && entry.timer) window.clearTimeout(entry.timer);
        });
        autoBusyForms = [];
    }

    function autoBusySubmit(event) {
        var form = event.target;
        if (!form || form.nodeName !== 'FORM') return;
        var inModal = !!form.closest('.modal');
        if (!inModal && !form.hasAttribute('data-edusync-feedback')) return;
        if (form.hasAttribute('data-edusync-no-auto-busy')) return;

        var submitter = event.submitter || document.activeElement;
        if (!submitter || !form.contains(submitter) || !/^(BUTTON|INPUT)$/.test(submitter.nodeName)) {
            submitter = form.querySelector('button[type="submit"], input[type="submit"], button:not([type])');
        }
        if (!submitter || submitter.disabled) return;

        setButtonLoading(submitter, true);
        var entry = { form: form, button: submitter, ajaxSeen: false, timer: null };
        entry.timer = window.setTimeout(function () {
            if (!entry.ajaxSeen) {
                setButtonLoading(entry.button, false);
                autoBusyForms = autoBusyForms.filter(function (item) { return item !== entry; });
            }
        }, 450);
        autoBusyForms.push(entry);
    }

    function confirmation(message, options) {
        options = options || {};
        var type = normalizeType(options.type || 'danger');
        var title = options.title || (type === 'danger' ? 'Confirmar acción' : 'Confirmar');
        var confirmText = options.confirmText || 'Confirmar';
        var cancelText = options.cancelText || 'Cancelar';

        return new Promise(function (resolve) {
            if (!$ || !$.fn || !$.fn.modal || !document.getElementById('confirm_modal')) {
                resolve(window.confirm(String(message || '¿Estás seguro?')));
                return;
            }

            var $modal = $('#confirm_modal');
            var $body = $('#confirm_modal_body');
            var $ok = $('#confirm_modal_ok');
            var $cancel = $modal.find('.modal-footer [data-dismiss="modal"]').first();
            var settled = false;
            var iconClass = type === 'warning' ? 'ed-confirm-warning' : (type === 'info' ? 'ed-confirm-info' : '');
            var iconGlyph = type === 'warning' ? 'fa-exclamation-triangle' : (type === 'info' ? 'fa-info' : 'fa-exclamation');
            var content = $('<div class="ed-confirm-layout"></div>');
            var icon = $('<span class="ed-confirm-icon"></span>').addClass(iconClass).append($('<i></i>').addClass('fas ' + iconGlyph));
            var copy = $('<div class="ed-confirm-copy"></div>');

            if (options.html) copy.html(message || '¿Estás seguro?');
            else copy.text(message || '¿Estás seguro?');

            content.append(icon, copy);
            $('#confirm_modal_label').text(title);
            $body.empty().append(content);
            $ok.text(confirmText)
                .removeClass('btn-danger btn-warning btn-primary btn-info btn-success')
                .addClass(type === 'warning' ? 'btn-warning' : (type === 'info' ? 'btn-primary' : 'btn-danger'));
            $cancel.text(cancelText);

            function finish(value) {
                if (settled) return;
                settled = true;
                $ok.off('.edfeedback');
                $modal.off('.edfeedback');
                resolve(value);
            }

            $ok.off('.edfeedback').on('click.edfeedback', function () {
                finish(true);
                $modal.modal('hide');
            });

            $modal.off('hidden.bs.modal.edfeedback').on('hidden.bs.modal.edfeedback', function () {
                finish(false);
            });

            $modal.modal({ backdrop: 'static', keyboard: false, show: true });
        });
    }

    function inline(container, message, type) {
        var target = container && container.jquery ? container[0] : container;
        if (typeof target === 'string') target = document.querySelector(target);
        if (!target) return null;
        type = normalizeType(type);
        var box = document.createElement('div');
        box.className = 'ed-inline-feedback ed-inline-feedback-' + type;
        box.setAttribute('role', type === 'danger' ? 'alert' : 'status');
        box.textContent = message == null ? '' : String(message);
        target.innerHTML = '';
        target.appendChild(box);
        return box;
    }

    function invokeLegacyAction(func, params) {
        params = Array.isArray(params) ? params : [];
        try {
            if (typeof window[func] === 'function') {
                window[func].apply(window, params);
                return;
            }
        } catch (err) {
            console.warn('EduSync confirm action error:', err);
            toast('No se pudo ejecutar la acción.', 'danger');
            return;
        }

        if (!$ || typeof func !== 'string') return;
        var postData = {};
        if (params.length > 0) postData.id = params[0];
        loader('Procesando...');
        $.post('ajax.php?action=' + encodeURIComponent(func), postData, null, 'json')
            .done(function (resp) {
                var data = resp;
                if (typeof data === 'string') {
                    try { data = JSON.parse(data); } catch (_) {}
                }
                if (data && (data.status == 1 || data.status === 'success')) {
                    toast(data.message || 'Acción realizada correctamente.', 'success');
                    try { $('#student-table').DataTable().ajax.reload(null, false); } catch (_) {}
                } else {
                    toast((data && data.message) || 'No se pudo completar la acción.', 'danger');
                }
            })
            .fail(function () {
                toast('No se pudo conectar con el servidor.', 'danger');
            })
            .always(function () {
                stopLoader(true);
            });
    }

    function installCompatibility() {
        window.alert_toast = function (message, type) {
            return toast(message, type);
        };

        window.start_load = function (message) {
            return loader(message || 'Procesando...');
        };

        window.end_load = function () {
            return stopLoader(false);
        };

        window._conf = function (message, func, params) {
            confirmation(message, {
                type: 'danger',
                title: 'Confirmar acción',
                confirmText: 'Confirmar',
                html: true
            }).then(function (accepted) {
                if (accepted) invokeLegacyAction(func, params);
            });
        };
    }

    window.EduSyncFeedback = {
        toast: toast,
        confirm: confirmation,
        loading: loader,
        stopLoading: stopLoader,
        setButtonLoading: setButtonLoading,
        inline: inline
    };

    installCompatibility();
    document.addEventListener('submit', autoBusySubmit, true);

    if ($) {
        $(document).ajaxSend(function () {
            autoBusyForms.forEach(function (entry) { entry.ajaxSeen = true; });
        });

        $(document).ajaxStop(function () {
            releaseAutoBusy();
        });

        $(document).on('hidden.bs.modal', '.modal', function () {
            releaseAutoBusy();
            stopLoader(true);
        });
    }
})(window, document, window.jQuery);
