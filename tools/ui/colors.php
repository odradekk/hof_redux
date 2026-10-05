<?php

use App\Http\View\UiColors;

/** Regenerate the 216 web-safe user color classes without a frontend build. */
require dirname(__DIR__, 2).'/vendor/autoload.php';
$colors = array_map('trim', file(dirname(__DIR__, 2).'/legacy/class/Color.dat', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
if ($colors !== UiColors::all()) {
    throw new RuntimeException('Legacy color palette does not match the supported 216 colors.');
}
$css = ":root {\n  --c-user-light: #ffffff;\n  --c-user-dark: #000000;\n";
foreach ($colors as $color) {
    $backing = UiColors::backing($color);
    $css .= "  --uc-{$color}: #{$color};\n  --uc-bg-{$color}: var(--c-user-{$backing});\n";
}
$css .= "}\n";
foreach ($colors as $color) {
    $css .= ".uc-{$color} { color: var(--uc-{$color}); background-color: var(--uc-bg-{$color}); }\n.swatch-{$color} { background: var(--uc-{$color}); }\n";
}
file_put_contents(dirname(__DIR__, 2).'/public/css/colors.css', $css);
