<?php

declare(strict_types=1);

namespace DeepFind\Security;

/**
 * Nettoyage du HTML des bannières (mode « code ») avant de l'envoyer au site : seules quelques balises de mise en page,
 * images et liens sont gardées ; aucun script, gestionnaire d'événement (on…), iframe, formulaire ni lien javascript:.
 * Le widget refait ce filtrage côté navigateur (défense en profondeur).
 */
final class HtmlSanitizer
{
    public const TAGS = ['a', 'img', 'picture', 'source', 'div', 'span', 'p', 'strong', 'b', 'em', 'i', 'u', 'br', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'small'];
    public const ATTRS = ['href', 'target', 'rel', 'src', 'srcset', 'sizes', 'media', 'alt', 'title', 'width', 'height', 'class', 'style', 'loading'];
    private const URL_ATTRS = ['href', 'src'];

    public static function clean(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="__root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $root = $doc->getElementById('__root');
        if ($root === null) {
            return '';
        }
        self::walk($root);
        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }
        return mb_substr(trim($out), 0, 20000, 'UTF-8');
    }

    private static function walk(\DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMComment || $child instanceof \DOMProcessingInstruction) {
                $node->removeChild($child);
                continue;
            }
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if (!in_array($tag, self::TAGS, true)) {
                // balise interdite : supprimée avec son contenu si dangereuse, sinon remplacée par son contenu
                if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'form', 'svg', 'math', 'template', 'noscript', 'link', 'meta', 'base'], true)) {
                    $node->removeChild($child);
                    continue;
                }
                self::walk($child);
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }
            foreach (iterator_to_array($child->attributes) as $attr) {
                $name = strtolower($attr->name);
                $value = trim($attr->value);
                $ok = in_array($name, self::ATTRS, true);
                if ($ok && in_array($name, self::URL_ATTRS, true)) {
                    $ok = self::safeUrl($value);
                }
                if ($ok && $name === 'srcset') {
                    $ok = !preg_match('/javascript:|data:|vbscript:/i', $value);
                }
                if ($ok && $name === 'style') {
                    $ok = !preg_match('/url\s*\(|expression|javascript:|@import|behavior/i', $value);
                }
                if ($ok && $name === 'target') {
                    $ok = in_array($value, ['_blank', '_self'], true);
                }
                if (!$ok) {
                    $child->removeAttribute($attr->name);
                }
            }
            if ($tag === 'a' && $child->getAttribute('target') === '_blank') {
                $child->setAttribute('rel', 'noopener');
            }
            self::walk($child);
        }
    }

    /** http(s), chemin relatif au site ou ancre ; jamais javascript:, data:, vbscript:. */
    public static function safeUrl(string $u): bool
    {
        $u = trim($u);
        return $u === '' || (bool) preg_match('#^(https?://|/(?!/)|\#)#i', $u);
    }
}
