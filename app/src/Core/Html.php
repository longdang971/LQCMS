<?php
declare(strict_types=1);

namespace LQ\Core;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Lọc HTML theo danh sách trắng cho người dùng không có quyền unfiltered_html (vd. Tác giả).
 * Quản trị viên / Biên tập viên được lưu HTML nguyên bản giống WordPress.
 */
final class Html
{
    private const TAGS = [
        'p', 'br', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'strong', 'b', 'em', 'i', 'u', 's', 'del', 'sub', 'sup',
        'blockquote', 'pre', 'code', 'ul', 'ol', 'li', 'a', 'img', 'figure', 'figcaption', 'table', 'thead', 'tbody',
        'tfoot', 'tr', 'th', 'td', 'caption', 'span', 'div', 'mark', 'small',
    ];

    private const ATTRS = ['href', 'title', 'target', 'rel', 'src', 'alt', 'width', 'height', 'class', 'style', 'colspan', 'rowspan'];

    public static function sanitize(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $doc = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="__lq">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $root = $doc->getElementById('__lq') ?? $doc->documentElement;
        self::clean($root);

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }
        return $out;
    }

    private static function clean(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select', 'link', 'meta'], true)) {
                    $node->removeChild($child);
                    continue;
                }
                if (!in_array($tag, self::TAGS, true)) {
                    // Giữ nội dung bên trong, bỏ thẻ bọc.
                    self::clean($child);
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    continue;
                }
                foreach (iterator_to_array($child->attributes) as $attr) {
                    $name = strtolower($attr->name);
                    $value = trim($attr->value);
                    $bad = !in_array($name, self::ATTRS, true)
                        || (in_array($name, ['href', 'src'], true) && !preg_match('#^(https?:|mailto:|tel:|/|\#|data:image/(png|jpe?g|gif|webp);)#i', $value))
                        || ($name === 'style' && preg_match('/expression|url\s*\(|javascript:/i', $value));
                    if ($bad) {
                        $child->removeAttribute($attr->name);
                    }
                }
                if ($tag === 'a' && $child->getAttribute('target') === '_blank') {
                    $child->setAttribute('rel', 'noopener noreferrer');
                }
                self::clean($child);
            } elseif ($child->nodeType === XML_COMMENT_NODE) {
                $node->removeChild($child);
            }
        }
    }
}
