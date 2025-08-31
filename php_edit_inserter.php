<?php
/**
 * php_edit_inserter.php
 * 
 * Usage (CLI):
 *   php php_edit_inserter.php --file=target.php --mode=append --snippet="<?php echo 'Hi'; ?>"
 *   php php_edit_inserter.php --file=target.php --mode=after --pattern="/require.+autoload\.php;/" --snippet="date_default_timezone_set('Asia/Colombo');" --marker=tz_set
 * 
 * Modes: append, prepend, after, before, replace
 */

$options = getopt("", [
    "file:",       // path to file
    "mode:",       // append|prepend|after|before|replace
    "snippet:",    // code snippet (string)
    "snippet-file::", // optional file containing snippet
    "pattern::",   // regex pattern (required for after/before/replace)
    "marker::",    // optional marker id to prevent duplicates
    "force::",     // force insert even if marker exists
    "no-backup::"  // skip backup
]);

function usage() {
    echo "Usage: php php_edit_inserter.php --file=FILE --mode=MODE --snippet=CODE [--pattern=REGEX] [--marker=ID]\n";
    exit(1);
}

if (!isset($options['file'], $options['mode'])) {
    usage();
}

$file = $options['file'];
$mode = strtolower($options['mode']);
$pattern = $options['pattern'] ?? null;
$markerId = $options['marker'] ?? null;
$force = array_key_exists("force", $options);
$noBackup = array_key_exists("no-backup", $options);

if (!file_exists($file)) {
    fwrite(STDERR, "Error: file not found: $file\n");
    exit(1);
}

$snippet = "";
if (isset($options['snippet'])) {
    $snippet = $options['snippet'];
} elseif (isset($options['snippet-file'])) {
    $snippet = file_get_contents($options['snippet-file']);
}
$snippet = trim($snippet);

if ($snippet === "") {
    fwrite(STDERR, "Error: snippet is empty\n");
    exit(1);
}

$markerStart = $markerId ? "/* AUTOEDIT: {$markerId} START */" : "";
$markerEnd   = $markerId ? "/* AUTOEDIT: {$markerId} END */" : "";

$snippetWrapped = $markerId ? $markerStart . "\n" . $snippet . "\n" . $markerEnd : $snippet;

$text = file_get_contents($file);

// Duplicate check
if ($markerId && !$force && strpos($text, $markerStart) !== false) {
    echo "Skipping: marker '{$markerId}' already present. Use --force to insert again.\n";
    exit(0);
}

// Backup
if (!$noBackup) {
    $bak = $file . ".bak." . date("YmdHis");
    copy($file, $bak);
    echo "Backup created: $bak\n";
}

try {
    switch ($mode) {
        case "append":
            if (substr($text, -2) === "?>") {
                // insert before closing tag
                $text = substr($text, 0, -2) . "\n" . $snippetWrapped . "\n?>";
            } else {
                $text .= "\n" . $snippetWrapped . "\n";
            }
            break;

        case "prepend":
            $text = $snippetWrapped . "\n" . $text;
            break;

        case "after":
            if (!$pattern) throw new Exception("--pattern is required for after mode");
            if (!preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE)) {
                throw new Exception("Pattern not found: $pattern");
            }
            $pos = $m[0][1] + strlen($m[0][0]);
            $text = substr($text, 0, $pos) . "\n" . $snippetWrapped . substr($text, $pos);
            break;

        case "before":
            if (!$pattern) throw new Exception("--pattern is required for before mode");
            if (!preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE)) {
                throw new Exception("Pattern not found: $pattern");
            }
            $pos = $m[0][1];
            $text = substr($text, 0, $pos) . $snippetWrapped . "\n" . substr($text, $pos);
            break;

        case "replace":
            if (!$pattern) throw new Exception("--pattern is required for replace mode");
            $count = 0;
            $text = preg_replace($pattern, $snippetWrapped, $text, 1, $count);
            if ($count === 0) throw new Exception("Pattern not found: $pattern");
            break;

        default:
            throw new Exception("Unsupported mode: $mode");
    }

    file_put_contents($file, $text);
    echo "Edit applied successfully.\n";
} catch (Exception $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(2);
}
