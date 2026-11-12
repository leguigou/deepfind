<?php

declare(strict_types=1);

namespace DeepFind\Import;

use SimpleXMLElement;
use XMLReader;

/**
 * Lecture en flux (XMLReader) : mémoire constante même pour un fichier de plusieurs centaines de Mo.
 * Chaque élément recherché est converti en SimpleXMLElement isolé.
 */
final class XmlItemReader
{
    /**
     * @param list<string> $names noms locaux des éléments à extraire (ex. ['product', 'deleted'])
     * @return \Generator<int, array{0:string, 1:SimpleXMLElement}>
     */
    public static function items(string $file, array $names, ?int $max = null): \Generator
    {
        $uri = self::isGzip($file) ? 'compress.zlib://' . $file : $file;
        $prev = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $r = new XMLReader();
        // LIBXML_NONET : aucun accès réseau ; pas de LIBXML_NOENT (protection XXE).
        if (!$r->open($uri, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
            libxml_use_internal_errors($prev);
            throw new \RuntimeException('Impossible d\'ouvrir le flux XML : ' . basename($file));
        }
        $count = 0;
        try {
            $ok = $r->read();
            while ($ok) {
                if ($r->nodeType === XMLReader::ELEMENT && in_array($r->localName, $names, true)) {
                    $name = $r->localName;
                    $node = @$r->expand();
                    if ($node === false) {
                        throw new \RuntimeException(self::lastError('Élément XML illisible'));
                    }
                    $dom = new \DOMDocument('1.0', 'UTF-8');
                    $imported = $dom->importNode($node, true);
                    $dom->appendChild($imported);
                    $sx = simplexml_import_dom($imported);
                    if ($sx !== null) {
                        yield $count => [$name, $sx];
                        $count++;
                        if ($max !== null && $count >= $max) {
                            return;
                        }
                    }
                    $ok = $r->next();
                    continue;
                }
                $ok = $r->read();
            }
            foreach (libxml_get_errors() as $err) {
                if ($err->level >= LIBXML_ERR_ERROR) {
                    throw new \RuntimeException(sprintf('XML invalide ligne %d : %s', $err->line, trim($err->message)));
                }
            }
        } finally {
            $r->close();
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }

    public static function isGzip(string $file): bool
    {
        $h = @fopen($file, 'rb');
        if ($h === false) {
            return false;
        }
        $magic = fread($h, 2);
        fclose($h);
        return $magic === "\x1f\x8b";
    }

    private static function lastError(string $default): string
    {
        $e = libxml_get_last_error();
        return $e ? sprintf('XML invalide ligne %d : %s', $e->line, trim($e->message)) : $default;
    }
}
