<?php
require __DIR__ . '/navbar_view.php';

$ui_css_file = dirname(__DIR__) . '/css/custom.css';
$ui_js_file = dirname(__DIR__) . '/js/ui_consistency.js';
$ui_css_version = @filemtime($ui_css_file) ?: time();
$ui_js_version = @filemtime($ui_js_file) ?: time();
?>
<script>
(function(){
    var link = document.querySelector('link[href^="css/custom.css"]');
    if (link) {
        var base = (link.getAttribute('href') || 'css/custom.css').split('?')[0];
        link.setAttribute('href', base + '?v=<?php echo rawurlencode((string)$ui_css_version); ?>');
    }
})();
</script>
<script src="js/ui_consistency.js?v=<?php echo rawurlencode((string)$ui_js_version); ?>"></script>
