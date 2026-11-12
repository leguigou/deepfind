<?php

declare(strict_types=1);

/*
 * Génère des flux d'exemple FICTIFS (produits imbriqués, produits « à plat », delta, blog).
 * Usage : php samples/generate.php [URL_DE_BASE_DES_IMAGES]
 * Les noms de marques servent uniquement à illustrer ; prix, stocks et marges sont inventés.
 */

$imgBase = rtrim($argv[1] ?? 'http://localhost:8080/assets/demo', '/');
$dir = __DIR__;
mt_srand(42);

$brands = ['C4', 'CRESSI', 'SOMMAP', 'BEUCHAT', 'SEAC', 'IMERSION', 'SIGALSUB', 'MARES', 'AQUALUNG', 'AQUATYS'];
$families = [
    ['Palmes de chasse sous-marine', 'palme', 'Chasse sous-marine > Palmes', ['Predator', 'Gara Turbo', 'Libeccio', 'L-30', 'M-30', 'Ghost', 'Carbon 500'], [59.99, 399]],
    ['Palmes de plongée réglables', 'palme', 'Plongée > Palmes > Palmes réglables', ['Alcyon', 'Avanti', 'Go Sport', 'Jet'], [39, 149]],
    ['Palmes de plongée chaussantes', 'palme', 'Plongée > Palmes > Palmes chaussantes', ['Estrel', 'Rondine', 'Clio'], [19.99, 79]],
    ['Masque de plongée', 'masque', 'Plongée > Masques', ['Big Eyes', 'Nano', 'X-Vision', 'Frameless'], [19.9, 99]],
    ['Combinaison néoprène', 'combi', 'Chasse sous-marine > Combinaisons', ['Camu 5 mm', 'Black Ice 7 mm', 'Pro 3 mm'], [149, 549]],
    ['Tuba', 'tuba', 'Plongée > Tubas', ['Dry', 'Flex', 'Classic'], [9.9, 39]],
];
$sizes = ['38/39', '40/41', '42/43', '44/45', '46/47'];
$colors = ['Noir', 'Bleu', 'Camouflage', 'Blanc'];

$products = [];
$id = 1000;
foreach ($families as [$label, $icon, $catPath, $models, [$pmin, $pmax]]) {
    foreach ($models as $model) {
        foreach (array_slice($brands, mt_rand(0, 5), mt_rand(1, 3)) as $brand) {
            $id += 10;
            $price = round($pmin + mt_rand(0, 100) / 100 * ($pmax - $pmin), 0) - 0.01;
            $sale = mt_rand(0, 4) === 0;
            $final = $sale ? round($price * (1 - mt_rand(10, 30) / 100), 2) : $price;
            $sku = strtoupper(preg_replace('/[^A-Z0-9]+/i', '-', $brand . '-' . $model));
            $hasVariants = in_array($icon, ['palme', 'combi'], true);
            $variants = [];
            if ($hasVariants) {
                foreach ($sizes as $i => $sz) {
                    $variants[] = [
                        'id' => $id + $i + 1, 'sku' => $sku . '-' . str_replace('/', '-', $sz), 'ean' => '3' . str_pad((string) ($id * 10 + $i), 12, '0', STR_PAD_LEFT),
                        'size' => $sz, 'price' => $price, 'final' => $final, 'stock' => mt_rand(0, 3) ? mt_rand(1, 20) : 0,
                    ];
                }
            }
            $stock = $hasVariants ? array_sum(array_column($variants, 'stock')) : (mt_rand(0, 5) ? mt_rand(1, 40) : 0);
            $products[] = [
                'id' => $id, 'sku' => $sku, 'type' => $hasVariants ? 'configurable' : 'simple',
                'name' => $label . ' ' . $model . ' ' . ucfirst(strtolower($brand)), 'brand' => $brand, 'icon' => $icon,
                'cat' => $catPath, 'price' => $price, 'final' => $final, 'stock' => $stock, 'margin' => mt_rand(15, 55) / 100,
                'sales' => mt_rand(0, 120), 'created' => date('c', time() - mt_rand(1, 400) * 86400), 'rating' => mt_rand(30, 50) / 10,
                'reviews' => mt_rand(0, 80), 'color' => $colors[mt_rand(0, 3)], 'variants' => $variants,
                'usage' => str_contains($catPath, 'Chasse') ? 'Chasse' : 'Plongée',
            ];
        }
    }
}

function x(string $s): string
{
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

// ---------------------------------------------------------------- Flux imbriqué (recommandé)
$xml = new XMLWriter();
$xml->openUri("$dir/products_fr.xml");
$xml->setIndent(true);
$xml->startDocument('1.0', 'UTF-8');
$xml->startElement('catalog');
foreach (['version' => '1.0', 'type' => 'full', 'lang' => 'fr_FR', 'store' => 'fr', 'currency' => 'EUR', 'generated_at' => date('c')] as $k => $v) {
    $xml->writeAttribute($k, $v);
}
foreach ($products as $p) {
    $xml->startElement('product');
    $xml->writeElement('id', (string) $p['id']);
    $xml->writeElement('sku', $p['sku']);
    $xml->writeElement('type', $p['type']);
    $xml->startElement('name'); $xml->writeCdata($p['name']); $xml->endElement();
    $xml->startElement('brand'); $xml->writeCdata($p['brand']); $xml->endElement();
    $xml->writeElement('url', 'https://www.example.com/' . strtolower($p['sku']) . '.html');
    $xml->writeElement('image', "$imgBase/{$p['icon']}.svg");
    $xml->startElement('categories');
    $xml->startElement('category');
    $xml->writeAttribute('id', (string) (crc32($p['cat']) % 1000));
    $xml->writeAttribute('path', $p['cat']);
    $xml->writeCdata((string) substr(strrchr(' > ' . $p['cat'], '>'), 2));
    $xml->endElement();
    $xml->endElement();
    $xml->startElement('short_description'); $xml->writeCdata($p['name'] . ' : modèle fictif pour la démonstration.'); $xml->endElement();
    $xml->startElement('description'); $xml->writeCdata('<p>Description <strong>HTML</strong> de ' . $p['name'] . '.</p>'); $xml->endElement();
    $xml->writeElement('price', number_format($p['price'], 2, '.', ''));
    $xml->writeElement('final_price', number_format($p['final'], 2, '.', ''));
    $xml->writeElement('in_stock', $p['stock'] > 0 ? '1' : '0');
    $xml->writeElement('salable_qty', (string) $p['stock']);
    $xml->writeElement('margin_rate', (string) $p['margin']);
    $xml->writeElement('sales_30d', (string) $p['sales']);
    $xml->writeElement('created_at', $p['created']);
    $xml->writeElement('rating', (string) $p['rating']);
    $xml->writeElement('reviews_count', (string) $p['reviews']);
    $xml->startElement('search_keywords'); $xml->writeCdata($p['icon'] === 'palme' ? 'fins, nageoires' : ''); $xml->endElement();
    $xml->writeElement('manual_boost', '0');
    $xml->startElement('attributes');
    foreach ([['couleur', 'Couleur', $p['color']], ['usage', 'Usage', $p['usage']]] as [$c, $l, $v]) {
        $xml->startElement('attribute'); $xml->writeAttribute('code', $c); $xml->writeAttribute('label', $l); $xml->writeAttribute('filterable', '1'); $xml->text($v); $xml->endElement();
    }
    $xml->endElement();
    if ($p['variants']) {
        $xml->startElement('variants');
        foreach ($p['variants'] as $v) {
            $xml->startElement('variant');
            $xml->writeElement('id', (string) $v['id']);
            $xml->writeElement('sku', $v['sku']);
            $xml->writeElement('ean', $v['ean']);
            $xml->writeElement('url', 'https://www.example.com/' . strtolower($p['sku']) . '.html#taille=' . rawurlencode($v['size']));
            $xml->writeElement('price', number_format($v['price'], 2, '.', ''));
            $xml->writeElement('final_price', number_format($v['final'], 2, '.', ''));
            $xml->writeElement('in_stock', $v['stock'] > 0 ? '1' : '0');
            $xml->writeElement('salable_qty', (string) $v['stock']);
            $xml->startElement('attributes');
            $xml->startElement('attribute'); $xml->writeAttribute('code', 'taille'); $xml->writeAttribute('label', 'Taille'); $xml->writeAttribute('filterable', '1'); $xml->text($v['size']); $xml->endElement();
            $xml->endElement();
            $xml->endElement();
        }
        $xml->endElement();
    }
    $xml->endElement();
}
$xml->endElement();
$xml->endDocument();
$xml->flush();

// ---------------------------------------------------------------- Flux « à plat » (une ligne par produit, enfants avec parent_id)
$f = fopen("$dir/products_fr_flat.xml", 'wb');
fwrite($f, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<items>\n");
$rows = [];
foreach ($products as $p) {
    $rows[] = sprintf("  <item><entity_id>%d</entity_id><sku>%s</sku><type_id>%s</type_id><parent_id/><name><![CDATA[%s]]></name><manufacturer>%s</manufacturer><url_key>%s</url_key><image>%s</image><category><![CDATA[%s]]></category><price>%s</price><special_price>%s</special_price><is_in_stock>%s</is_in_stock><qty>%d</qty><marge_pct>%d</marge_pct><color>%s</color></item>\n",
        $p['id'], x($p['sku']), $p['type'], $p['name'], x($p['brand']), 'https://www.example.com/' . strtolower($p['sku']) . '.html', "$imgBase/{$p['icon']}.svg",
        $p['cat'], str_replace('.', ',', number_format($p['price'], 2, '.', '')), str_replace('.', ',', number_format($p['final'], 2, '.', '')),
        $p['stock'] > 0 ? 'En stock' : 'Rupture', $p['stock'], (int) round($p['margin'] * 100), x($p['color']));
    foreach ($p['variants'] as $v) {
        $rows[] = sprintf("  <item><entity_id>%d</entity_id><sku>%s</sku><type_id>simple</type_id><parent_id>%d</parent_id><name><![CDATA[%s]]></name><size>%s</size><price>%s</price><special_price>%s</special_price><is_in_stock>%s</is_in_stock><qty>%d</qty></item>\n",
            $v['id'], x($v['sku']), $p['id'], $p['name'] . ' ' . $v['size'], x($v['size']), str_replace('.', ',', number_format($v['price'], 2, '.', '')),
            str_replace('.', ',', number_format($v['final'], 2, '.', '')), $v['stock'] > 0 ? 'En stock' : 'Rupture', $v['stock']);
    }
}
shuffle($rows); // l'ordre parents/enfants n'a pas d'importance
fwrite($f, implode('', $rows) . "</items>\n");
fclose($f);

// ---------------------------------------------------------------- Flux delta
$first = $products[0];
$withVar = null;
foreach ($products as $p) {
    if ($p['variants'] && $p !== $first) {
        $withVar = $p;
        break;
    }
}
$last = end($products);
$delta = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<catalog version="1.0" type="delta" lang="fr_FR" store="fr" generated_at="' . date('c') . "\">\n"
    . "  <product>\n    <sku>" . x($first['sku']) . "</sku>\n    <final_price>" . number_format($first['final'] - 5, 2, '.', '') . "</final_price>\n  </product>\n"
    . "  <product>\n    <sku>" . x($withVar['sku']) . "</sku>\n    <variants>\n      <variant><sku>" . x($withVar['variants'][2]['sku']) . "</sku><in_stock>0</in_stock><salable_qty>0</salable_qty></variant>\n    </variants>\n  </product>\n"
    . "  <product>\n    <sku>INCONNU-999</sku>\n    <in_stock>1</in_stock>\n  </product>\n"
    . "  <deleted>\n    <sku>" . x($last['sku']) . "</sku>\n  </deleted>\n</catalog>\n";
file_put_contents("$dir/products_fr_delta.xml", $delta);

// ---------------------------------------------------------------- Blog
$posts = [
    ['Comment choisir ses palmes de chasse sous-marine ?', 'Longueur, voilure, chausson : nos conseils pour bien choisir.', ['palmes', 'chasse'], [$products[0]['sku']]],
    ['Palmes chaussantes ou réglables : que choisir ?', 'Avantages et inconvénients des deux familles de palmes.', ['palmes', 'plongée'], []],
    ['Entretenir sa combinaison néoprène', 'Rinçage, séchage, stockage : prolongez la vie de votre combinaison.', ['combinaison', 'entretien'], []],
    ['Bien régler son masque de plongée', 'Éviter la buée et les fuites : le guide complet.', ['masque'], []],
    ['Débuter l\'apnée : le matériel indispensable', 'Palmes longues, masque à faible volume, tuba : la liste.', ['apnée', 'palmes', 'débutant'], []],
];
$b = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<blog version="1.0" type="full" lang="fr_FR" store="fr" generated_at="' . date('c') . "\">\n";
foreach ($posts as $i => [$title, $excerpt, $tags, $related]) {
    $b .= "  <post>\n    <id>" . (200 + $i) . "</id>\n    <title><![CDATA[$title]]></title>\n"
        . '    <url>https://www.example.com/blog/article-' . (200 + $i) . ".html</url>\n    <image>$imgBase/blog.svg</image>\n"
        . "    <excerpt><![CDATA[$excerpt]]></excerpt>\n    <content><![CDATA[<p>$excerpt Texte complet de l'article.</p>]]></content>\n"
        . "    <author><![CDATA[Équipe démo]]></author>\n    <published_at>" . date('c', time() - $i * 20 * 86400) . "</published_at>\n"
        . "    <categories><category><![CDATA[Conseils]]></category></categories>\n    <tags>"
        . implode('', array_map(static fn ($t) => "<tag><![CDATA[$t]]></tag>", $tags)) . "</tags>\n    <related_products>"
        . implode('', array_map(static fn ($s) => '<sku>' . x($s) . '</sku>', $related)) . "</related_products>\n  </post>\n";
}
$b .= "</blog>\n";
file_put_contents("$dir/blog_fr.xml", $b);

echo count($products) . " produits générés dans $dir\n";
