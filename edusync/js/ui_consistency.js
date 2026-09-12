(function ($) {
    'use strict';

    if (!$) return;

    var PAGE = (function () {
        try {
            return new URLSearchParams(window.location.search).get('page') || 'home';
        } catch (e) {
            return 'home';
        }
    })();

    var UI_VERSION = 'standard-v2';

    function firstExisting(items) {
        for (var i = 0; i < items.length; i++) {
            var $el = $(items[i]).first();
            if ($el.length) return $el;
        }
        return $();
    }

    function markHeader($header) {
        if (!$header || !$header.length || $header.closest('.modal').length) return;
        $header.addClass('ed-page-header');

        var $heading = firstExisting([
            $header.find('.title-left'),
            $header.find('.py-title'),
            $header.find('.am-title'),
            $header.find('.am-heading').parent(),
            $header.find('.cp-title'),
            $header.find('.cp-heading').parent(),
            $header.find('.df-title'),
            $header.find('.dc-title'),
            $header.find('.d-flex.align-items-center').first()
        ]);

        if (!$heading.length) {
            var $title = $header.find('h1,h2,h3,h4,h5,.page-title-text').first();
            if ($title.length) $heading = $title.parent();
        }
        if ($heading.length) $heading.addClass('ed-page-heading');

        $header.find('.title-icon,.py-icon,.am-icon,.cp-icon,.df-icon,.dc-icon,.ay-hero-icon').first().addClass('ed-page-icon');
        $header.find('h1,h2,h3,h4,h5,.page-title-text').first().addClass('ed-page-title');
        $header.find('.page-title-sub,.small.text-muted,p,.at-sub,.ar-sub').filter(':first').addClass('ed-page-subtitle');

        var $actions = $header.find('.title-actions,.dr-actions,.ed-actions').first();
        if (!$actions.length) {
            var $directButtons = $header.children().filter(function () {
                return $(this).find('.btn').length > 0;
            }).last();
            if ($directButtons.length) $actions = $directButtons;
        }
        if ($actions.length) $actions.addClass('ed-page-actions');
    }

    function markKnownHeaders() {
        var selectors = [
            '.user-hero', '.gr-header', '.mc-header', '.gr-report-hero', '.pr-head', '.ar-header',
            '.ir-hero', '.dc-head', '.py-head', '.am-head', '.cp-head', '.df-head', '.dr-head',
            '.at-head', '.ar-head', '.ay-hero', '.page-title-wrapper > .page-title'
        ];
        $(selectors.join(',')).each(function () { markHeader($(this)); });

        if (PAGE === 'students') {
            markHeader($('.main-content-area').first().children('.d-sm-flex.align-items-center.justify-content-between').first());
        }

        if (PAGE === 'comprobantes') {
            markHeader($('#content .container-fluid > .d-sm-flex.align-items-center.justify-content-between').first());
        }

        if (!$('.ed-page-header').length) {
            var $candidate = $('#content .container-fluid').find('> .d-sm-flex:has(h1), > .d-flex:has(h1)').first();
            if ($candidate.length) markHeader($candidate);
        }
    }

    function markStats() {
        var statSelectors = [
            '.user-stat', '.teacher-summary-card', '.py-stat', '.am-stat', '.cp-stat', '.df-stat',
            '.dc-stat', '.gr-stat', '.ar-kpi', '.pr-stat', '.dr-stat', '.mc-summary', '.ay-stat',
            '.stat-card'
        ];
        $(statSelectors.join(',')).not('.modal *').addClass('ed-stat-card');

        $('.user-stat .label,.teacher-summary-label,.ed-stat-card small:first-child').addClass('ed-stat-label');
        $('.user-stat .number,.teacher-summary-value,.ed-stat-card strong:first,.ed-stat-card .h5:first').addClass('ed-stat-value');
        $('.user-stat .meta').addClass('ed-stat-meta');
    }

    function explicitFilterSelectors() {
        return [
            '.filter-bar-students',
            '#teacher-status-filter',
            '.users-toolbar',
            '.py-filters',
            '.am-toolbar',
            '.cp-toolbar',
            '.df-filters',
            '.dc-filter',
            '.gr-filter',
            '.mc-filter',
            '.pr-filters',
            '.dr-filters',
            '.at-toolbar',
            '.gr-filter-card'
        ];
    }

    function markFilters() {
        explicitFilterSelectors().forEach(function (selector) {
            $(selector).each(function () {
                var $el = $(this);
                if ($el.closest('.modal').length) return;
                if ($el.is('#teacher-status-filter')) $el = $el.closest('.form-inline');
                if ($el.hasClass('users-toolbar')) $el = $el.closest('.card-header');
                $el.addClass('ed-filter-card');
            });
        });

        $('#content .card').not('.modal .card').each(function () {
            var $card = $(this);
            var title = $.trim($card.children('.card-header').first().text()).toLowerCase();
            if (/filtro|búsqueda|busqueda|buscar|criterio/.test(title)) {
                $card.addClass('ed-filter-shell');
                $card.children('.card-body').first().addClass('ed-filter-card');
            }
        });

        $('#content form').not('.modal form').each(function () {
            var $form = $(this);
            var controlCount = $form.find('select,input[type="date"],input[type="search"],input[type="text"]').length;
            var submitCount = $form.find('.btn,button').length;
            if (controlCount >= 3 && submitCount >= 1 && !$form.closest('.ed-content-card').find('table').length) {
                $form.addClass('ed-filter-form');
            }
        });
    }

    function isSpecialTable($table) {
        if ($table.closest('.modal').length) return true;
        if ($table.is('[data-ed-table-skip],.gradebook-table,.spreadsheet-table,.excel-table,.book-table')) return true;
        if ($table.closest('.gradebook,.spreadsheet,.excel-grid,.grade-sheet,.gr-book').length) return true;
        if ($table.find('[contenteditable="true"]').length) return true;
        if ($table.find('input,select,textarea').length > 12) return true;
        return false;
    }

    function markTables() {
        $('#content table.table').each(function () {
            var $table = $(this);
            if (isSpecialTable($table)) {
                $table.addClass('ed-table-special');
                return;
            }
            $table.addClass('ed-table table-hover');
            if (!$table.find('thead').hasClass('thead-dark')) $table.find('thead').addClass('thead-light');
        });

        $('#content .dataTables_wrapper').addClass('ed-datatable');
    }

    function markContentCards() {
        var explicitPanels = [
            '.students-card', '.content-card', '.users-table-card', '.py-panel', '.am-panel', '.cp-panel',
            '.df-panel', '.pr-panel', '.dr-panel', '.gr-results-card', '.mc-card', '.catalog-card', '.ficha-option'
        ];
        $(explicitPanels.join(',')).not('.modal *').addClass('ed-content-card');

        $('#content .card').not('.modal .card,.ed-filter-shell').each(function () {
            var $card = $(this);
            if ($card.find('table').length || $card.find('.list-group').length || $card.find('form').length) {
                $card.addClass('ed-content-card');
            } else {
                $card.addClass('ed-card');
            }
        });

        $('.ed-content-card').each(function () {
            var $card = $(this);
            $card.children('.card-header').first().addClass('ed-content-card-header');
            $card.children('.card-body').first().addClass('ed-content-card-body');
        });
    }

    function markToolbars() {
        $('#content .alert.alert-light.border').each(function () {
            var $el = $(this);
            if ($el.find('.btn').length || /seleccionad/i.test($el.text())) $el.addClass('ed-bulk-bar');
        });

        $('#content .btn-toolbar,#content .form-inline').not('.modal *').each(function () {
            var $el = $(this);
            if ($el.find('.btn').length && !$el.hasClass('ed-filter-card')) $el.addClass('ed-toolbar');
        });
    }

    function markButtons() {
        $('#content .btn-sm').not('.modal .btn-sm').each(function () {
            var $btn = $(this);
            var text = $.trim($btn.clone().children().remove().end().text());
            if (!text && $btn.find('i,svg').length) $btn.addClass('ed-icon-btn');
        });

        $('.ed-filter-card .btn,.ed-filter-form .btn').addClass('ed-filter-action');
        $('.ed-page-actions .btn').addClass('ed-page-action');
    }

    function markEmptyStates() {
        $('#content .am-empty,#content .cp-empty,#content .gr-empty,#content .mc-empty,#content .hd-empty,#content .edusync-empty,#content .empty-state').addClass('ed-empty-state');
        $('#content td.dataTables_empty').closest('tr').addClass('ed-empty-row');
    }

    function normalizeSelect2() {
        $('#content .select2-container').addClass('ed-select2');
    }

    function normalizeClassicModules() {
        if (PAGE === 'config_facturacion') {
            $('#content .container-fluid > .card').first().addClass('ed-content-card ed-config-card');
        }

        if (PAGE === 'facturacion_deudas') {
            $('#content .card').first().addClass('ed-content-card');
            $('#content .card-body > .row.mb-3').first().addClass('ed-filter-card ed-filter-inline');
        }

        if (PAGE === 'comprobantes') {
            $('#content .card').eq(0).addClass('ed-filter-shell');
            $('#content .card').eq(0).children('.card-body').addClass('ed-filter-card');
            $('#content .card').eq(1).addClass('ed-content-card');
        }

        if (PAGE.indexOf('student_') === 0 || PAGE.indexOf('my_') === 0) {
            $('#content .student-profile-bar').addClass('ed-student-context');
            $('#content .stat-card').addClass('ed-stat-card');
        }
    }

    function normalizeCommon() {
        $('body').attr('data-edusync-ui', UI_VERSION).attr('data-edusync-page', PAGE);
        $('#content').addClass('ed-app-content');

        $('.ed-content-card > .card-header').addClass('bg-white');
        $('.ed-table').addClass('table-hover');
        $('.ed-table thead').not('.thead-dark').addClass('thead-light');
        $('#content .table-responsive').addClass('ed-table-responsive');
        $('#content .dropdown-menu').addClass('ed-dropdown');
        $('#content .pagination').addClass('ed-pagination');
        $('#content .badge').addClass('ed-badge');
    }

    function refreshDynamicUi() {
        markTables();
        normalizeSelect2();
        markButtons();
        markEmptyStates();
        normalizeCommon();
    }

    function boot() {
        markKnownHeaders();
        markStats();
        markFilters();
        markContentCards();
        markTables();
        markToolbars();
        markButtons();
        markEmptyStates();
        normalizeSelect2();
        normalizeClassicModules();
        normalizeCommon();

        window.setTimeout(refreshDynamicUi, 500);
        $(document).on('draw.dt shown.bs.tab', refreshDynamicUi);
    }

    $(boot);
})(window.jQuery);