<?php

declare(strict_types=1);

namespace App\Http\View;

use DOMDocument;
use DOMXPath;

/** Resolve validation summary links to real control IDs in the rendered section. */
final class FormErrors
{
    public static function anchors(string $content): array
    {
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument;
        $dom->loadHTML('<?xml encoding="UTF-8"><div>'.$content.'</div>');
        $anchors = [];
        foreach ((new DOMXPath($dom))->query('//*[@name and @id]') as $control) {
            $name = str_replace(['[', ']'], ['.', ''], $control->getAttribute('name'));
            $anchors[$name] ??= $control->getAttribute('id');
        }
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $anchors;
    }
}
