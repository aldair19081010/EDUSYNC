<?php
$tokens = token_get_all(file_get_contents("ajax.php"));
$stack = [];
$last_line = 1;
foreach ($tokens as $t) {
    if (is_array($t)) {
        $last_line = $t[2];
        if ($t[0] == T_CURLY_OPEN || $t[0] == T_DOLLAR_OPEN_CURLY_BRACES) {
            $stack[] = $last_line;
        }
    } elseif ($t == "{") {
        $stack[] = $last_line;
    } elseif ($t == "}") {
        array_pop($stack);
    }
}
echo "Unclosed braces at lines: " . implode(", ", $stack);
?>
