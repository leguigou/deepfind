<?php

/**
 * Faux serveur de flux pour les tests (php -S 127.0.0.1:7797 tests/fake-feed.php) :
 *   /open/feed.xml        flux XML libre
 *   /protected/feed.xml   flux protégé (Basic, comme .htaccess / .htpasswd) : utilisateur « flux », mot de passe « s3cret »
 *   /open/page.html       page HTML (pas un flux)
 *   /open/feed.xml.gz     flux compressé
 *   /redirect             redirection vers /open/feed.xml
 *   autre                 404
 */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n<products>\n" . str_repeat("  <product><sku>X</sku><name>Produit de test</name></product>\n", 200) . "</products>\n";
switch ($path) {
    case '/open/feed.xml':
        // comme Apache sur un fichier statique : demande partielle (Range) → 206 + Content-Range
        header('Content-Type: application/xml');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', 1767225600) . ' GMT');
        if (preg_match('/^bytes=(\d+)-(\d+)$/', $_SERVER['HTTP_RANGE'] ?? '', $m)) {
            $part = substr($xml, (int) $m[1], (int) $m[2] - (int) $m[1] + 1);
            http_response_code(206);
            header('Content-Range: bytes ' . $m[1] . '-' . ((int) $m[1] + strlen($part) - 1) . '/' . strlen($xml));
            header('Content-Length: ' . strlen($part));
            echo $part;
            break;
        }
        header('Content-Length: ' . strlen($xml));
        echo $xml;
        break;
    case '/protected/feed.xml':
        if (($_SERVER['PHP_AUTH_USER'] ?? '') !== 'flux' || ($_SERVER['PHP_AUTH_PW'] ?? '') !== 's3cret') {
            header('WWW-Authenticate: Basic realm="Flux"');
            http_response_code(401);
            echo '<html><body>401 Authorization Required</body></html>';
            break;
        }
        // sans prise en charge des demandes partielles : fichier entier, taille dans Content-Length
        header('Content-Type: application/xml');
        header('Content-Length: ' . strlen($xml));
        echo $xml;
        break;
    case '/open/page.html':
        header('Content-Type: text/html');
        echo "<!DOCTYPE html>\n<html><body>Connexion</body></html>";
        break;
    case '/open/feed.xml.gz':
        header('Content-Type: application/gzip');
        echo gzencode($xml);
        break;
    case '/redirect':
        header('Location: /open/feed.xml', true, 302);
        break;
    default:
        http_response_code(404);
        echo 'Not found';
}
