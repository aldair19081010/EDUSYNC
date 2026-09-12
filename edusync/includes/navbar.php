<?php
require __DIR__ . '/navbar_view.php';

$custom_css_file = dirname(__DIR__) . '/css/custom.css';
$ui_css_file = dirname(__DIR__) . '/css/ui_consistency.css';
$ui_refinements_file = dirname(__DIR__) . '/css/ui_refinements.css';
$ui_report_tables_file = dirname(__DIR__) . '/css/ui_report_tables.css';
$ui_grades_individual_file = dirname(__DIR__) . '/css/ui_grades_individual.css';
$ui_modals_file = dirname(__DIR__) . '/css/ui_modals.css';
$ui_feedback_file = dirname(__DIR__) . '/css/ui_feedback.css';
$ui_responsive_file = dirname(__DIR__) . '/css/ui_responsive.css';
$ui_table_actions_file = dirname(__DIR__) . '/css/ui_table_actions.css';
$ui_js_file = dirname(__DIR__) . '/js/ui_consistency.js';
$ui_modals_js_file = dirname(__DIR__) . '/js/ui_modals.js';
$ui_feedback_js_file = dirname(__DIR__) . '/js/ui_feedback.js';
$custom_css_version = @filemtime($custom_css_file) ?: time();
$ui_css_version = @filemtime($ui_css_file) ?: time();
$ui_refinements_version = @filemtime($ui_refinements_file) ?: time();
$ui_report_tables_version = @filemtime($ui_report_tables_file) ?: time();
$ui_grades_individual_version = @filemtime($ui_grades_individual_file) ?: time();
$ui_modals_version = @filemtime($ui_modals_file) ?: time();
$ui_feedback_version = @filemtime($ui_feedback_file) ?: time();
$ui_responsive_version = @filemtime($ui_responsive_file) ?: time();
$ui_table_actions_version = @filemtime($ui_table_actions_file) ?: time();
$ui_js_version = @filemtime($ui_js_file) ?: time();
$ui_modals_js_version = @filemtime($ui_modals_js_file) ?: time();
$ui_feedback_js_version = @filemtime($ui_feedback_js_file) ?: time();
?>
<script>
(function(){
    var custom = document.querySelector('link[href^="css/custom.css"]');
    if (custom) {
        var customBase = (custom.getAttribute('href') || 'css/custom.css').split('?')[0];
        custom.setAttribute('href', customBase + '?v=<?php echo rawurlencode((string)$custom_css_version); ?>');
    }

    if (!document.querySelector('link[data-edusync-ui-consistency]')) {
        var ui = document.createElement('link');
        ui.rel = 'stylesheet';
        ui.href = 'css/ui_consistency.css?v=<?php echo rawurlencode((string)$ui_css_version); ?>';
        ui.setAttribute('data-edusync-ui-consistency', '1');
        document.head.appendChild(ui);
    }

    if (!document.querySelector('link[data-edusync-ui-refinements]')) {
        var refinements = document.createElement('link');
        refinements.rel = 'stylesheet';
        refinements.href = 'css/ui_refinements.css?v=<?php echo rawurlencode((string)$ui_refinements_version); ?>';
        refinements.setAttribute('data-edusync-ui-refinements', '1');
        document.head.appendChild(refinements);
    }

    if (!document.querySelector('link[data-edusync-ui-report-tables]')) {
        var reportTables = document.createElement('link');
        reportTables.rel = 'stylesheet';
        reportTables.href = 'css/ui_report_tables.css?v=<?php echo rawurlencode((string)$ui_report_tables_version); ?>';
        reportTables.setAttribute('data-edusync-ui-report-tables', '1');
        document.head.appendChild(reportTables);
    }

    if (!document.querySelector('link[data-edusync-ui-grades-individual]')) {
        var gradesIndividual = document.createElement('link');
        gradesIndividual.rel = 'stylesheet';
        gradesIndividual.href = 'css/ui_grades_individual.css?v=<?php echo rawurlencode((string)$ui_grades_individual_version); ?>';
        gradesIndividual.setAttribute('data-edusync-ui-grades-individual', '1');
        document.head.appendChild(gradesIndividual);
    }

    if (!document.querySelector('link[data-edusync-ui-modals]')) {
        var modals = document.createElement('link');
        modals.rel = 'stylesheet';
        modals.href = 'css/ui_modals.css?v=<?php echo rawurlencode((string)$ui_modals_version); ?>';
        modals.setAttribute('data-edusync-ui-modals', '1');
        document.head.appendChild(modals);
    }

    if (!document.querySelector('link[data-edusync-ui-feedback]')) {
        var feedback = document.createElement('link');
        feedback.rel = 'stylesheet';
        feedback.href = 'css/ui_feedback.css?v=<?php echo rawurlencode((string)$ui_feedback_version); ?>';
        feedback.setAttribute('data-edusync-ui-feedback', '1');
        document.head.appendChild(feedback);
    }

    if (!document.querySelector('link[data-edusync-ui-responsive]')) {
        var responsive = document.createElement('link');
        responsive.rel = 'stylesheet';
        responsive.href = 'css/ui_responsive.css?v=<?php echo rawurlencode((string)$ui_responsive_version); ?>';
        responsive.setAttribute('data-edusync-ui-responsive', '1');
        document.head.appendChild(responsive);
    }

    if (!document.querySelector('link[data-edusync-ui-table-actions]')) {
        var tableActions = document.createElement('link');
        tableActions.rel = 'stylesheet';
        tableActions.href = 'css/ui_table_actions.css?v=<?php echo rawurlencode((string)$ui_table_actions_version); ?>';
        tableActions.setAttribute('data-edusync-ui-table-actions', '1');
        document.head.appendChild(tableActions);
    }
})();
</script>
<script src="js/ui_consistency.js?v=<?php echo rawurlencode((string)$ui_js_version); ?>"></script>
<script src="js/ui_modals.js?v=<?php echo rawurlencode((string)$ui_modals_js_version); ?>"></script>
<script src="js/ui_feedback.js?v=<?php echo rawurlencode((string)$ui_feedback_js_version); ?>"></script>