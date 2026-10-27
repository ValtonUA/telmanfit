<?php

namespace App\Support\Library;

use App\Support\Presentation\PublicImageUrl;
use DOMDocument;
use DOMElement;
use DOMNode;
use League\CommonMark\CommonMarkConverter;

/** CommonMark із вимкненим raw HTML; додаткова перевірка output URL. */
final class LibraryMarkdown
{
    public static function render(?string $markdown): ?string
    {
        if ($markdown === null || trim($markdown) === '') {
            return null;
        }

        $converter = new CommonMarkConverter(['html_input' => 'strip', 'allow_unsafe_links' => false]);
        $rendered = (string) $converter->convert($markdown);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            if (! $dom->loadHTML('<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body><div id="library-body">'
                .$rendered.'</div></body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                return null;
            }
            foreach (iterator_to_array($dom->getElementsByTagName('img')) as $image) {
                if (! $image instanceof DOMElement) {
                    continue;
                }
                $safe = PublicImageUrl::safe($image->getAttribute('src'));
                if ($safe === null) {
                    $image->parentNode?->removeChild($image);
                } else {
                    $image->setAttribute('src', $safe);
                    $image->setAttribute('loading', 'lazy');
                    $image->setAttribute('referrerpolicy', 'no-referrer');
                }
            }
            foreach (iterator_to_array($dom->getElementsByTagName('a')) as $link) {
                if (! $link instanceof DOMElement) {
                    continue;
                }
                $href = $link->getAttribute('href');
                if (ExternalContentUrl::safe($href) !== null) {
                    $link->setAttribute('rel', 'noopener noreferrer');
                    $link->setAttribute('target', '_blank');
                } elseif (str_starts_with($href, '/app/') && ! str_contains($href, '\\')
                    && ! preg_match('/[\x00-\x20<>"\x7f]/', $href)) {
                    $link->removeAttribute('target');
                } else {
                    $link->removeAttribute('href');
                }
            }
            $wrapper = $dom->getElementById('library-body');
            if (! $wrapper) {
                return null;
            }
            $html = '';
            foreach ($wrapper->childNodes as $node) {
                if ($node instanceof DOMNode) {
                    $html .= $dom->saveHTML($node);
                }
            }

            return $html;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
