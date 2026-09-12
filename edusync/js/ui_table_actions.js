/*
 * EduSync Table Actions v1
 * Ajustes DOM para tablas que ya renderizan sus acciones en PHP.
 * Conserva selectores, data-attributes y handlers existentes.
 */
(function ($) {
    'use strict';

    function normalizeUsersTable() {
        var $table = $('#usersTable');
        if (!$table.length) return;

        $table.find('tbody tr').each(function () {
            var $row = $(this);
            var $cell = $row.find('td').last();
            if (!$cell.length || $cell.find('.ed-row-actions').length) return;

            var $dropdown = $cell.find('.dropdown').first();
            var $menu = $dropdown.find('.dropdown-menu').first();
            var $edit = $menu.find('.edit-user').first();
            if (!$dropdown.length || !$menu.length || !$edit.length) return;

            $edit.detach()
                .removeClass('dropdown-item')
                .addClass('btn btn-outline-primary btn-sm ed-action-primary')
                .html('<i class="fas fa-edit"></i><span class="ed-action-label">Editar</span>');

            var $toggle = $dropdown.children('button').first();
            $toggle
                .removeClass('btn-light border dropdown-toggle')
                .addClass('btn-sm ed-action-more')
                .attr('title', 'Más acciones')
                .attr('aria-label', 'Más acciones')
                .html('<i class="fas fa-ellipsis-v"></i>');

            $menu.addClass('ed-action-menu');
            $menu.find('.delete-permanent').addClass('ed-action-danger');
            $menu.find('.toggle-status').each(function () {
                var $item = $(this);
                if (String($item.data('status')) === 'Activo') {
                    $item.addClass('ed-action-danger').removeClass('ed-action-success');
                } else {
                    $item.addClass('ed-action-success').removeClass('ed-action-danger');
                }
            });

            var $actions = $('<div class="ed-row-actions"></div>');
            $actions.append($edit).append($dropdown.detach());
            $cell.empty().addClass('ed-actions-cell').append($actions);
        });
    }

    function normalizeConceptsTable() {
        var $table = $('#cp-table');
        if (!$table.length) return;

        $table.find('tbody tr').each(function () {
            var $cell = $(this).find('td').last();
            if (!$cell.length || $cell.find('.ed-row-actions').length) return;

            var $group = $cell.find('.btn-group').first();
            var $edit = $group.find('.cp-edit').first();
            var $toggle = $group.find('.dropdown-toggle').first();
            var $menu = $group.find('.dropdown-menu').first();
            if (!$group.length || !$edit.length || !$toggle.length || !$menu.length) return;

            $edit.detach()
                .removeClass('btn-outline-primary')
                .addClass('btn-outline-primary ed-action-primary')
                .attr('title', 'Editar concepto')
                .html('<i class="fa fa-edit"></i><span class="ed-action-label">Editar</span>');

            $toggle.detach()
                .removeClass('btn-outline-secondary dropdown-toggle')
                .addClass('ed-action-more')
                .attr('title', 'Más acciones')
                .attr('aria-label', 'Más acciones')
                .html('<i class="fa fa-ellipsis-v"></i>');

            $menu.detach().addClass('ed-action-menu');
            $menu.find('.cp-delete').removeClass('text-danger').addClass('ed-action-danger');

            var $dropdown = $('<div class="dropdown"></div>').append($toggle).append($menu);
            var $actions = $('<div class="ed-row-actions"></div>').append($edit).append($dropdown);
            $cell.empty().addClass('ed-actions-cell').append($actions);
        });
    }

    function observeDynamicTables() {
        var target = document.getElementById('cp-results');
        if (!target || typeof MutationObserver === 'undefined') return;
        var observer = new MutationObserver(function () {
            normalizeConceptsTable();
        });
        observer.observe(target, { childList: true, subtree: true });
    }

    $(function () {
        normalizeUsersTable();
        normalizeConceptsTable();
        observeDynamicTables();
    });
})(window.jQuery);
