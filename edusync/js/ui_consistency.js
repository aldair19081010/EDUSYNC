(function ($) {
    'use strict';

    if (!$) return;

    function markStudents() {
        var $root = $('.main-content-area').first();
        if (!$root.length || !$('#student-table').length) return;

        var $header = $root.children('.d-sm-flex.align-items-center.justify-content-between.mb-4').first();
        if ($header.length) {
            $header.addClass('ed-page-header');
            var $left = $header.children('.d-flex.align-items-center').first();
            $left.addClass('ed-page-heading');
            $left.find('.title-icon').first().addClass('ed-page-icon');
            $left.find('h1').first().addClass('ed-page-title');
            $left.find('.small.text-muted').first().addClass('ed-page-subtitle');
            $header.children('.d-flex.align-items-center').last().addClass('ed-page-actions');
        }

        $('.students-card').addClass('ed-content-card');
        $('.students-card > .card-header').addClass('ed-content-card-header');
        $('.students-card > .card-body').addClass('ed-content-card-body');
        $('.filter-bar-students').addClass('ed-filter-card');
        $('.student-table').addClass('ed-table');
        $('.empty-state').addClass('ed-empty-state');
    }

    function markTeachers() {
        if (!$('#teachers-table').length) return;

        var $header = $('.page-title-wrapper .page-title').first();
        $header.addClass('ed-page-header');
        $header.find('.title-left').first().addClass('ed-page-heading');
        $header.find('.title-icon').first().addClass('ed-page-icon');
        $header.find('.page-title-text').first().addClass('ed-page-title');
        $header.find('.page-title-sub').first().addClass('ed-page-subtitle');
        $header.find('.title-actions').first().addClass('ed-page-actions');

        $('.teacher-summary-card').addClass('ed-stat-card');
        $('.teacher-summary-value').addClass('ed-stat-value');
        $('.teacher-summary-label').addClass('ed-stat-label');
        $('.content-card').addClass('ed-content-card');
        $('.content-card > .card-header').addClass('ed-content-card-header');
        $('.content-card > .card-body').addClass('ed-content-card-body');
        $('#teacher-status-filter').closest('.form-inline').addClass('ed-filter-card');
        $('#teachers-table').addClass('ed-table');
    }

    function markUsers() {
        if (!$('#usersTable').length) return;

        $('.user-hero').addClass('ed-page-header');
        $('.user-hero h1').first().addClass('ed-page-title');
        $('.user-hero p').first().addClass('ed-page-subtitle');

        $('.user-stat').addClass('ed-stat-card');
        $('.user-stat .label').addClass('ed-stat-label');
        $('.user-stat .number').addClass('ed-stat-value');
        $('.user-stat .meta').addClass('ed-stat-meta');
        $('.users-table-card').addClass('ed-content-card');
        $('.users-table-card > .card-header').addClass('ed-content-card-header');
        $('.users-table-card > .card-body').addClass('ed-content-card-body');
        $('#usersTable').addClass('ed-table');
    }

    function normalizeCommon() {
        $('.ed-content-card > .card-header').addClass('bg-white');
        $('.ed-table thead').addClass('thead-light');
        $('.ed-table').addClass('table-hover');
        $('body').attr('data-edusync-ui', 'standard-v1');
    }

    function boot() {
        markStudents();
        markTeachers();
        markUsers();
        normalizeCommon();
    }

    $(boot);
})(window.jQuery);