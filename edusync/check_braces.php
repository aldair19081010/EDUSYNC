
$file = "ajax.php";
$tokens = token_get_all(file_get_contents($file));
$stack = [];
$lastLine = 1;
foreach ($tokens as $t) {
    if (is_array($t)) {
        $lastLine = $t[2];
        if ($t[0] === T_CURLY_OPEN || $t[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
            $stack[] = $lastLine;
        }
    } elseif (is_string($t)) {
        if ($t === "{") $stack[] = $lastLine;
        if ($t === "}") array_pop($stack);
    }
}
print_r($stack);

