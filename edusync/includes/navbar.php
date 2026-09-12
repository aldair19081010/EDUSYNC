<?php
require __DIR__ . '/navbar_view.php';

$custom_css_file = dirname(__DIR__) . '/css/custom.css';
$ui_css_file = dirname(__DIR__) . '/css/ui_consistency.css';
$ui_refinements_file = dirname(__DIR__) . '/css/ui_refinements.css';
$ui_js_file = dirname(__DIR__) . '/js/ui_consistency.js';
$custom_css_version = @filemtime($custom_css_file) ?: time();
$ui_css_version = @filemtime($ui_css_file) ?: time();
$ui_refinements_version = @filemtime($ui_refinements_file) ?: time();
$ui_js_version = @filemtime($ui_js_file) ?: time();
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
})();
</script>
<script src="js/ui_consistency.js?v=<?php echo rawurlencode((string)$ui_js_version); ?>"></script>