<?php

declare(strict_types=1);

/*
 * Tests automatisés (sans PHPUnit). Lancement : bash tests/run.sh
 * Partie 1 : tests unitaires (aucune dépendance).
 * Partie 2 : tests d'intégration (MySQL de test + faux Meilisearch + serveur web PHP), si TEST_BASE_URL est défini.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use DeepFind\Import\Mapper;
use DeepFind\Import\ProductBuilder;
use DeepFind\Import\XmlItemReader;
use DeepFind\Search\Filter;
use DeepFind\Search\SearchService;
use DeepFind\Security\ApiKeys;
use DeepFind\Security\ClientIp;
use DeepFind\Text;

$passed = 0;
$failed = [];
function check(string $name, bool $cond, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  \033[32m✔\033[0m $name\n";
    } else {
        $failed[] = $name;
        echo "  \033[31m✘ $name\033[0m" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

echo "Tests unitaires\n";

// Texte
check('normalize : minuscules, accents, espaces', Text::normalize('  Palmes  Réglables ÉTÉ ') === 'palmes reglables ete');
check('stripHtml', Text::stripHtml('<p>Bonjour<br>le <strong>monde</strong> &amp; co</p><script>x()</script>') === 'Bonjour le monde & co');

// Transformations
check('to_float virgule française', Mapper::toFloat('1 234,56 €') === 1234.56);
check('to_float point + séparateur milliers', Mapper::toFloat('1,234.56') === 1234.56);
check('to_bool « En stock »', Mapper::toBool('En stock') === 1 && Mapper::toBool('Rupture') === 0);
check('stock Google Shopping (in_stock / out_of_stock / preorder)', Mapper::toBool('in_stock') === 1 && Mapper::toBool('out_of_stock') === 0 && Mapper::toBool('preorder') === 1);
check('stock schema.org et quantités', Mapper::toBool('https://schema.org/InStock') === 1 && Mapper::toBool('http://schema.org/OutOfStock') === 0 && Mapper::toBool('12') === 1 && Mapper::toBool('0') === 0);
$gxml = '<rss xmlns:g="http://base.google.com/ns/1.0"><channel><item><g:id>1</g:id><g:availability>out_of_stock</g:availability></item>'
    . '<item><g:id>2</g:id><g:availability>in_stock</g:availability></item></channel></rss>';
$gtmp = tempnam(sys_get_temp_dir(), 'df');
file_put_contents($gtmp, $gxml);
$gm = new Mapper(['item_element' => 'item', 'fields' => ['in_stock' => ['path' => 'g:availability'], 'id' => ['path' => 'g:id']]]);
$gvals = [];
foreach (XmlItemReader::items($gtmp, ['item']) as [, $gel]) {
    $gvals[] = $gm->mapProduct($gel)['fields']['in_stock'] ?? null;
}
unlink($gtmp);
check('flux Google (g:availability) : stock lu correctement', $gvals === [0, 1], json_encode($gvals));
$pw = DeepFind\Search\Relevance::words('pompe paddle', 'fr');
check('bonus début du nom : « Pompe électrique… » devant « Pack Paddle … Pompe »',
    DeepFind\Search\Relevance::namePositionBonus($pw, 'Pompe électrique Haute Pression Pour Sup Gonflable', 'fr')
    > DeepFind\Search\Relevance::namePositionBonus($pw, 'Pack Paddle Gonflable Regent Horizon (sup. Pompe. Pagaie. Leash)', 'fr'));
check('bonus début du nom : pluriel et accents', DeepFind\Search\Relevance::namePositionBonus(['palme'], 'Palmes de plongée', 'fr') === 1.0);
check('pertinence : priorité au champ', DeepFind\Search\Relevance::rankingRules('low', true)[2] === 'attribute');
check('pertinence : importance du score métier', DeepFind\Search\Relevance::rankingRules('low')[6] === 'biz_score:desc' && DeepFind\Search\Relevance::rankingRules('high')[2] === 'biz_score:desc');
check('transform divide:100', Mapper::transform(['to_float', 'divide:100'], '42') === 0.42);
check('transform split', Mapper::transform(['split:|'], 'a| b |c') === ['a', 'b', 'c']);
check('transform replace', Mapper::transform(['replace:-| '], 'a-b') === 'a b');
check('transform default', Mapper::transform(['default:simple'], '') === 'simple');
$threw = false;
try {
    Mapper::transform(['inconnue'], 'x');
} catch (InvalidArgumentException) {
    $threw = true;
}
check('transformation inconnue refusée', $threw);

// Mapping imbriqué
$xml = <<<XML
<product id="7"><sku>ABC-1</sku><name><![CDATA[Palme <b>Test</b>]]></name><brand>  Cressi </brand>
<url>https://ex.com/p</url><price>100</price><final_price>80</final_price>
<categories><category id="3" path="Plongée &gt; Palmes">Palmes</category></categories>
<attributes><attribute code="Couleur" label="Couleur" filterable="1">Noir</attribute></attributes>
<variants>
  <variant><sku>ABC-1-S</sku><price>100</price><final_price>80</final_price><in_stock>0</in_stock><attributes><attribute code="taille" label="Taille" filterable="1">S</attribute></attributes></variant>
  <variant><sku>ABC-1-M</sku><price>110</price><final_price>90</final_price><in_stock>1</in_stock><salable_qty>4</salable_qty><attributes><attribute code="taille" label="Taille" filterable="1">M</attribute></attributes></variant>
</variants></product>
XML;
$map = Mapper::defaultProducts();
$map['fields']['id'] = ['path' => '@id'];
$mapper = new Mapper(json_decode(json_encode($map), true));
$m = $mapper->mapProduct(new SimpleXMLElement($xml));
check('mapping : attribut XML @id', $m['fields']['id'] === '7');
check('mapping : champ absent non renseigné', !array_key_exists('enabled', $m['fields']));
check('mapping : catégorie avec chemin', $m['categories'][0]['path'] === ['Plongée', 'Palmes']);
check('mapping : code attribut normalisé', isset($m['attributes']['couleur']));
check('mapping : 2 variantes', count($m['variants']) === 2);

$b = new ProductBuilder(['stock' => 30, 'margin' => 20, 'popularity' => 30, 'new' => 10, 'boost' => 5, 'popularity_ref' => 100, 'new_days' => 45], 1_800_000_000);
$doc = $b->build($m, $m['variants']);
check('agrégat : document construit', is_array($doc), is_string($doc) ? $doc : '');
check('agrégat : prix « dès » = variante en stock la moins chère', $doc['final_price'] === 90.0 && $doc['price'] === 110.0);
check('agrégat : en stock si un enfant en stock', $doc['in_stock'] === 1 && $doc['salable_qty'] === 4);
check('agrégat : promotion et pourcentage', $doc['on_sale'] === 1 && $doc['discount_percent'] === 18);
check('agrégat : SKU enfants recherchables', $doc['all_skus'] === ['ABC-1', 'ABC-1-S', 'ABC-1-M']);
check('agrégat : attributs des enfants remontés', $doc['attr']['taille'] === ['S', 'M']);
check('agrégat : HTML retiré du nom', $doc['name'] === 'Palme Test' || $doc['name'] === 'Palme <b>Test</b>');
check('agrégat : identifiant Meilisearch valide', preg_match('/^[a-zA-Z0-9_\-]+$/', $doc['id']) === 1);
check('rejet si prix manquant', $b->build(['fields' => ['sku' => 'X', 'name' => 'X', 'url' => 'https://x'], 'categories' => [], 'attributes' => []], []) === 'prix manquant ou nul');
check('rejet si désactivé', $b->build(['fields' => ['sku' => 'X', 'name' => 'X', 'url' => 'https://x', 'enabled' => 0], 'categories' => [], 'attributes' => []], []) === 'désactivé');
$okDoc = $b->build(['fields' => ['sku' => 'AV1', 'name' => 'Palme AV', 'url' => 'https://x', 'price' => 10], 'categories' => [], 'attributes' => []], []);
check('produit du flux : vendable (available = 1)', is_array($okDoc) && $okDoc['available'] === 1);
check('recalcul (scores, delta) : un produit désactivé auto le reste', is_array($okDoc) && $b->computeDerived(['available' => 0] + $okDoc)['available'] === 0);

// Lecture XML en flux
$tmp = tempnam(sys_get_temp_dir(), 'df');
file_put_contents($tmp, '<?xml version="1.0"?><catalog><product><sku>A</sku></product><deleted><sku>B</sku></deleted><product><sku>C</sku></product></catalog>');
$names = [];
foreach (XmlItemReader::items($tmp, ['product', 'deleted']) as [$n, $el]) {
    $names[] = $n . ':' . trim((string) $el->sku);
}
check('XMLReader : éléments extraits dans l\'ordre', $names === ['product:A', 'deleted:B', 'product:C'], implode(',', $names));
file_put_contents($tmp, gzencode('<?xml version="1.0"?><c><product><sku>Z</sku></product></c>'));
$n = 0;
foreach (XmlItemReader::items($tmp, ['product']) as $_) {
    $n++;
}
check('XMLReader : fichier .gz lu', $n === 1);
file_put_contents($tmp, '<?xml version="1.0"?><c><product><sku>Z</sku></product><product><sku>');
$threw = false;
try {
    foreach (XmlItemReader::items($tmp, ['product']) as $_) {
    }
} catch (RuntimeException) {
    $threw = true;
}
check('XMLReader : XML tronqué => import en erreur (pas d\'index à moitié vide)', $threw);
file_put_contents($tmp, '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file:///etc/passwd">]><c><product><sku>&x;</sku></product></c>');
$val = '';
try {
    foreach (XmlItemReader::items($tmp, ['product']) as [, $el]) {
        $val = (string) $el->sku;
    }
} catch (RuntimeException) {
}
check('XMLReader : entités externes non résolues (XXE)', !str_contains($val, 'root:'));
unlink($tmp);

// Filtres et sécurité
check('échappement des filtres', Filter::eq('brand', 'A "B" \\') === 'brand = "A \\"B\\" \\\\"');
check('détection SKU', SearchService::looksLikeSku('C4-PRED-42') && !SearchService::looksLikeSku('palme') && !SearchService::looksLikeSku('palme c4'));
check('IP masquée IPv4', ClientIp::mask('203.0.113.42') === '203.0.113.x');
check('IP masquée IPv6', ClientIp::mask('2001:db8:abcd:12::1') === '2001:db8:abcd::/48');
check('CIDR', ClientIp::inCidr('10.1.2.3', '10.0.0.0/8') && !ClientIp::inCidr('11.1.2.3', '10.0.0.0/8'));
check('IP hachée (pas en clair)', strlen(ClientIp::hash('1.2.3.4')) === 16 && !str_contains(ClientIp::hash('1.2.3.4'), '1.2.3.4'));
$key = ['allowed_origins' => 'example.com'];
check('origine autorisée (sous-domaine)', ApiKeys::checkOrigin($key, ['HTTP_ORIGIN' => 'https://www.example.com', 'HTTP_HOST' => 'search.x']) === 'https://www.example.com');
check('origine refusée', ApiKeys::checkOrigin($key, ['HTTP_ORIGIN' => 'https://example.com.evil.io', 'HTTP_HOST' => 'search.x']) === null);
check('origine refusée sans en-tête', ApiKeys::checkOrigin($key, ['HTTP_HOST' => 'search.x']) === null);
check('même domaine sans Origin (Referer) accepté', ApiKeys::checkOrigin($key, ['HTTP_REFERER' => 'https://search.x/admin/demo', 'HTTP_HOST' => 'search.x']) === '');

// Planification (cron)
use DeepFind\Import\Cron;
check('cron : expression valide / invalide', Cron::isValid('*/15 6-23 * * 1-5') && !Cron::isValid('61 * * * *') && !Cron::isValid('* * *'));
check('cron : correspondance', Cron::matches('*/15 6-23 * * *', new DateTimeImmutable('2026-11-12 23:45')) && !Cron::matches('*/15 6-23 * * *', new DateTimeImmutable('2026-11-12 05:45')));
check('cron : dimanche = 0 ou 7', Cron::matches('0 9 * * 7', new DateTimeImmutable('2026-11-15 09:00')));
$nx = Cron::nextRuns('15 3 * * *', 2, new DateTimeImmutable('2026-11-12 13:20'));
check('cron : prochaines exécutions', count($nx) === 2 && $nx[0]->format('Y-m-d H:i') === '2026-11-13 03:15');

// Mapping visuel : TOUTES les balises du flux (au-delà des 300 premiers produits, balises vides ou absentes quand vides)
$bigFeed = sys_get_temp_dir() . '/df_inspect_' . getmypid() . '.xml';
$x = "<?xml version=\"1.0\"?>\n<products xmlns:g=\"http://base.google.com/ns/1.0\">\n";
for ($i = 1; $i <= 400; $i++) {
    $x .= "<product><sku>S$i</sku><name>Produit $i</name><ean></ean><g:color/>"
        . ($i === 350 ? '<promo_label>Soldes</promo_label><extra><code lang="fr">X1</code></extra>' : '')
        . ($i > 380 ? '<rare_flag>oui</rare_flag>' : '')
        . "</product>\n";
}
file_put_contents($bigFeed, $x . "</products>\n");
$FI = DeepFind\Import\FeedInspector::class;
$bigIns = $FI::inspect($bigFeed, 'product', 300);
$bigOpts = array_column($FI::options($bigIns), null, 'value');
check('mapping visuel : balises au-delà des 300 premiers produits, avec exemple et présence réelle',
    $bigIns['scanned'] === 300 && $bigIns['total'] === 400 && !$bigIns['partial']
    && isset($bigOpts['promo_label'], $bigOpts['rare_flag'], $bigOpts['extra/code'], $bigOpts['extra/code/@lang'])
    && $bigOpts['promo_label']['sample'] === 'Soldes' && $bigOpts['promo_label']['coverage'] === 1 && $bigOpts['rare_flag']['coverage'] === 5
    && $bigOpts['sku']['coverage'] === 100 && !isset($bigOpts['@xmlns:g']), json_encode(array_keys($bigOpts)));
check('mapping visuel : balises toujours vides listées, jamais pré-sélectionnées', isset($bigOpts['ean'], $bigOpts['g:color'])
    && $bigOpts['ean']['empty'] === true && $FI::suggest(['ean'], array_values($bigOpts)) === '' && $FI::suggest(['sku'], array_values($bigOpts)) === 'sku'
    && (require_once __DIR__ . '/../src/Admin/helpers.php') !== false && str_contains(select_paths('x', array_values($bigOpts), ''), 'ean (toujours vide)'));
$t0 = microtime(true);
$again = $FI::inspect($bigFeed, 'product', 300);
check('mapping visuel : analyse mise en cache tant que le fichier ne change pas', $again === $bigIns);
@unlink($bigFeed);

// Flux type Google Shopping : parent avec la liste de ses SKU enfants (<skus_enfants>), sans ligne propre pour les enfants
$PB = DeepFind\Import\ProductBuilder::class;
check('SKU enfants : liste découpée (virgule, point-virgule, |), majuscules, doublons', $PB::childSkus(['su-1, SU-2;su-3|SU-1', ' ']) === ['SU-1', 'SU-2', 'SU-3']);
$gIns = DeepFind\Import\FeedInspector::inspect(__DIR__ . '/fixtures/google_parent_fr.xml');
$gOpts = DeepFind\Import\FeedInspector::options($gIns);
$gCat = DeepFind\Import\FieldCatalog::groups('products');
$gSug = static fn (string $f) => DeepFind\Import\FeedInspector::suggest(($gCat['required'][$f] ?? $gCat['secondary'][$f])['synonyms'], $gOpts);
check('SKU enfants : mapping visuel (g:id, skus_enfants, vente_web, prix g:)', $gIns['item'] === 'item' && $gSug('sku') === 'g:id' && $gSug('child_skus') === 'skus_enfants'
    && $gSug('enabled') === 'vente_web' && $gSug('price') === 'g:price' && $gSug('final_price') === 'g:sale_price' && $gSug('in_stock') === 'g:availability',
    json_encode(['sku' => $gSug('sku'), 'child' => $gSug('child_skus'), 'enabled' => $gSug('enabled')]));
$gMap = new DeepFind\Import\Mapper(['item_element' => 'item', 'fields' => ['sku' => ['path' => 'g:id'], 'name' => ['path' => 'title'], 'url' => ['path' => 'link'],
    'price' => ['path' => 'g:price'], 'final_price' => ['path' => 'g:sale_price'], 'in_stock' => ['path' => 'g:availability'], 'enabled' => ['path' => 'vente_web'],
    'child_skus' => ['path' => 'skus_enfants', 'multiple' => true]], 'categories' => ['path' => 'category', 'name_path' => '.', 'separator' => '>']], 'products');
$gDocs = [];
foreach (DeepFind\Import\XmlItemReader::items(__DIR__ . '/fixtures/google_parent_fr.xml', ['item']) as [, $el]) {
    $mp = $gMap->mapProduct($el);
    $gDocs[] = (new DeepFind\Import\ProductBuilder(null, time()))->build($mp, []);
}
[$gP, $gS] = $gDocs;
check('SKU enfants : chercher un SKU enfant trouve le parent, prix « dès », promo, stock, catégories', is_array($gP) && $gP['sku'] === 'PCPL-16'
    && in_array('SU-0002637', $gP['all_skus'], true) && count($gP['all_skus']) === 6 && $gP['has_price_range'] === 1
    && $gP['final_price'] === 349.99 && $gP['price'] === 499.9 && $gP['discount_percent'] === 30 && $gP['in_stock'] === 1
    && in_array('Combinaisons de plongée homme', $gP['category_names'], true)
    && is_array($gS) && $gS['all_skus'] === ['PMT-22'] && $gS['has_price_range'] === 0 && $gS['in_stock'] === 0, json_encode([$gP['all_skus'] ?? $gP, $gS['all_skus'] ?? $gS]));
$gRe = (new DeepFind\Import\ProductBuilder(null, time()))->computeDerived($gP);
check('SKU enfants : conservés au recalcul des scores (sans relire le flux)', $gRe['all_skus'] === $gP['all_skus'] && $gRe['has_price_range'] === 1);

// Mapping visuel : détection des champs du flux
use DeepFind\Import\FeedInspector;
use DeepFind\Admin\MappingController;
$ins = FeedInspector::inspect(APP_ROOT . '/samples/products_fr.xml');
check('inspection : élément produit détecté', $ins['item'] === 'product' && $ins['scanned'] > 10);
check('inspection : groupe des variantes détecté', in_array('variants/variant', FeedInspector::repeatedGroups($ins), true));
check('inspection : attributs par code détectés', isset($ins['codes']['attributes/attribute']['code']['couleur']));
$opts = FeedInspector::options($ins, '');
check('inspection : attribut XML proposé', in_array('categories/category/@path', array_column($opts, 'value'), true));
check('inspection : attribut par code proposé', in_array("attributes/attribute[@code='couleur']", array_column($opts, 'value'), true));
$insFlat = FeedInspector::inspect(APP_ROOT . '/samples/products_fr_flat.xml');
$fo = FeedInspector::options($insFlat, '');
check('inspection flux à plat : élément <item>', $insFlat['item'] === 'item');
check('pré-sélection : url_key pour l\'URL, special_price pour le prix final, entity_id pour l\'id',
    FeedInspector::suggest(['url', 'link', 'product_url', 'url_key'], $fo) === 'url_key'
    && FeedInspector::suggest(['final_price', 'special_price'], $fo) === 'special_price'
    && FeedInspector::suggest(['id', 'entity_id'], $fo) === 'entity_id');
check('transformations saisies', MappingController::transforms('to_float, divide:100') === ['to_float', 'divide:100'] && MappingController::transforms('split:,, trim') === ['split:,', 'trim']);
$codeOpt = new Mapper(['fields' => ['brand' => ['path' => "attributes/attribute[@code='couleur']"]]]);
$el = null;
foreach (XmlItemReader::items(APP_ROOT . '/samples/products_fr.xml', ['product'], 1) as [, $el]) {
}
check('chemin « attribut par code » lu par le mapping', ($codeOpt->mapProduct($el)['fields']['brand'] ?? '') !== '');

// ---------------------------------------------------------------- Intégration
$base = getenv('TEST_BASE_URL');
if ($base) {
    echo "\nTests d'intégration ($base)\n";
    $key = trim((string) getenv('TEST_KEY'));
    $get = static function (string $path, array $headers = []) use ($base): array {
        $ch = curl_init($base . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => false, CURLOPT_NOPROXY => '*',
            CURLOPT_HTTPHEADER => array_values(array_merge(['origin' => 'Origin: http://localhost:8080', 'user-agent' => 'User-Agent: Mozilla/5.0 Tests'],
                array_combine(array_map(static fn ($h) => strtolower(strstr($h, ':', true)), $headers), $headers) ?: []))]);
        $body = (string) curl_exec($ch);
        return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), json_decode($body, true) ?? $body];
    };
    [$c, $d] = $get("/api/v1/search?key=$key&q=palme&limit=5");
    check('API : 200 et résultats', $c === 200 && $d['total'] > 0, (string) $c);
    check('API : règle « épingler » (C4-PREDATOR en 1re position)', ($d['products'][0]['sku'] ?? '') === 'C4-PREDATOR');
    check('API : marge jamais exposée', !str_contains(json_encode($d), 'margin'));
    check('API : marques à gauche', ($d['facets'][0]['code'] ?? '') === 'brand');
    check('API : articles de blog', count($d['posts']) > 0);
    [$c2, $d2] = $get("/api/v1/search?key=$key&q=palme&limit=5&offset=5&posts=0");
    $ids1 = array_column($d['products'], 'id');
    $ids2 = array_column($d2['products'], 'id');
    check('API : page 2 sans doublon (défilement)', $c2 === 200 && array_intersect($ids1, $ids2) === [] && count($ids2) === 5);
    [, $d] = $get("/api/v1/search?key=$key&q=plame&limit=3");
    check('API : faute de frappe tolérée (plame)', $d['total'] > 0);
    [, $d] = $get("/api/v1/search?key=$key&q=c4-predator-40-41");
    check('API : recherche par SKU enfant => parent + variante', ($d['products'][0]['sku'] ?? '') === 'C4-PREDATOR' && ($d['products'][0]['matched_variant']['sku'] ?? '') === 'C4-PREDATOR-40-41');
    [, $d] = $get("/api/v1/search?key=$key&q=palme&brand%5B%5D=SEAC&limit=48");
    check('API : filtre marque', $d['total'] > 0 && count(array_unique(array_column($d['products'], 'brand'))) === 1);
    $brandFacet = $d['facets'][0]['values'] ?? [];
    check('API : compteurs marques disjonctifs (autres marques toujours listées)', count($brandFacet) > 1);
    [, $d] = $get("/api/v1/search?key=$key&q=palme&in_stock=1&limit=48");
    check('API : filtre en stock', !in_array(false, array_column($d['products'], 'in_stock'), true));
    [, $d] = $get("/api/v1/search?key=$key&q=palme&on_sale=1&limit=48");
    check('API : filtre promotion', $d['total'] > 0 && !in_array(false, array_column($d['products'], 'on_sale'), true));
    [, $d] = $get("/api/v1/search?key=$key&q=palme&sort=price_asc&in_stock=1&limit=48");
    $prices = array_column($d['products'], 'final_price');
    $sorted = $prices;
    sort($sorted);
    check('API : tri prix croissant', $prices === $sorted);
    [, $d] = $get("/api/v1/search?key=$key&q=retour");
    check('API : redirection (ancien format converti, automatique)', ($d['redirect'] ?? '') === 'https://www.example.com/cgv#retours' && ($d['redirect_auto'] ?? false) === true
        && ($d['redirect_new_tab'] ?? true) === false);
    $RD = DeepFind\Rules\Redirections::class;
    [$rdl, $rdErr] = $RD::sanitize(['name' => 'Livraison', 'active' => true, 'auto' => false, 'new_tab' => true, 'url' => 'https://www.example.com/livraison',
        'terms' => [['term' => 'livraison', 'match' => 'broad']]]);
    $rdId = $RD::save('fr', $rdl, null);
    [, $d] = $get("/api/v1/search?key=$key&q=" . rawurlencode('délai de livraison') . "&limit=3");
    check('redirection : terme large, lien proposé (non automatique)', $rdErr === [] && ($d['redirect'] ?? '') === 'https://www.example.com/livraison' && ($d['redirect_auto'] ?? true) === false);
    check('redirection : ouverture dans un nouvel onglet enregistrée et transmise', ($d['redirect_new_tab'] ?? false) === true && $RD::find($rdId, 'fr')['new_tab'] === true);
    $rdl['starts_at'] = '2099-01-01';
    $rdl['ends_at'] = '2099-12-31';
    $RD::save('fr', $rdl, $rdId);
    [, $d] = $get("/api/v1/search?key=$key&q=livraison&limit=3");
    check('redirection : période à venir → pas encore active', ($d['redirect'] ?? null) === null);
    [, $rdBad] = $RD::sanitize(['name' => 'x', 'url' => 'javascript:alert(1)', 'terms' => []]);
    check('redirection : URL non http(s) et sans terme refusée', count($rdBad) === 2);
    $RD::delete($rdId, 'fr');
    [, $d] = $get("/api/v1/search?key=$key&q=nageoire");
    check('API : synonymes', $d['total'] > 0);
    // Modification d'un synonyme (fenêtre « Modifier » de la page Synonymes & alias)
    DeepFind\Rules\RulesRepository::addSynonym('fr', 'equivalent', 'ecoflau', 'ecoflo');
    $synId = (int) DeepFind\Db::value('SELECT id FROM synonyms WHERE term = ? AND project_id = ?', ['ecoflau', DeepFind\Project::id()]);
    $synOk = DeepFind\Rules\RulesRepository::updateSynonym($synId, 'fr', 'alias', ' ecoflaw ', 'ecoflow|eco flow');
    $synRow = DeepFind\Db::one('SELECT type, term, synonyms FROM synonyms WHERE id = ?', [$synId]);
    check('synonymes : modification (type, terme, synonymes)', $synOk && $synRow === ['type' => 'alias', 'term' => 'ecoflaw', 'synonyms' => 'ecoflow|eco flow']);
    check('synonymes : modification refusée hors langue ou identifiant inconnu', !DeepFind\Rules\RulesRepository::updateSynonym($synId, 'en', 'alias', 'x', 'y')
        && !DeepFind\Rules\RulesRepository::updateSynonym(999999, 'fr', 'alias', 'x', 'y'));
    DeepFind\Db::exec('DELETE FROM synonyms WHERE id = ?', [$synId]);
    check('synonymes : découpage d\'une ligne de mots (virgules, |, doublons, espaces)',
        DeepFind\Rules\RulesRepository::splitWords(' ecoflow, EcoFlau |ecoflau,, eco  flo ') === ['ecoflow', 'EcoFlau', 'eco flo']);
    [$c] = $get("/api/v1/search?key=pk_00000000000000000000000000000000&q=palme");
    check('API : clé invalide => 401', $c === 401);
    [$c] = $get("/api/v1/search?key=$key&q=palme", ['Origin: https://evil.example']);
    check('API : domaine non autorisé => 403', $c === 403);
    [$c] = $get("/api/v1/search?key=$key&q=palme", ['User-Agent: python-requests/2.31']);
    check('API : user-agent de robot => 403', $c === 403);
    // Cache : une règle ajoutée (autre processus que le serveur web) est visible immédiatement
    [, $before] = $get("/api/v1/search?key=$key&q=masque&limit=3");
    [, $again] = $get("/api/v1/search?key=$key&q=masque&limit=3");
    check('cache : 2e réponse servie depuis le cache', ($again['cached'] ?? false) === true);
    $first = $before['products'][0]['sku'] ?? '';
    DeepFind\Rules\RulesRepository::addRule('fr', 'masque', $first, 'hide', null, '', '');
    DeepFind\Rules\RulesRepository::bumpVersion();
    [, $after] = $get("/api/v1/search?key=$key&q=masque&limit=3");
    check('cache : invalidé immédiatement après une modification', $first !== '' && ($after['cached'] ?? true) === false && !in_array($first, array_column($after['products'], 'sku'), true));
    DeepFind\Db::exec("DELETE FROM query_rules WHERE query = 'masque' AND action = 'hide'");
    DeepFind\Rules\RulesRepository::bumpVersion();

    // Aperçu interne : champs trouvés, fiche détaillée, produit désactivé partout (règle « masquer » sur « * »)
    $svc = new DeepFind\Search\SearchService(DeepFind\Meili\MeiliClient::search());
    $int = $svc->search(DeepFind\Search\SearchRequest::fromArray(['q' => 'palme', 'lang' => 'fr', 'limit' => 10], true), true);
    $p0 = $int['products'][0] ?? [];
    check('aperçu : champs où la recherche est trouvée', in_array('name', array_column($p0['matched'] ?? [], 'field'), true), json_encode($p0['matched'] ?? null));
    check('aperçu : fiche détaillée (attributs, catégories)', isset($p0['details']['attr'], $p0['details']['category_paths']));
    $off = $int['products'][1]['sku'] ?? '';
    DeepFind\Rules\RulesRepository::addRule('fr', '*', $off, 'hide', null, '', '');
    DeepFind\Rules\RulesRepository::bumpVersion();
    [, $pub] = $get("/api/v1/search?key=$key&q=palme&limit=48");
    check('désactivation : produit absent de la recherche publique', $off !== '' && !in_array($off, array_column($pub['products'], 'sku'), true));
    check('API : ni fiche détaillée ni champs trouvés exposés', !str_contains(json_encode($pub), '"details"') && !str_contains(json_encode($pub), '"matched"'));
    $int = $svc->search(DeepFind\Search\SearchRequest::fromArray(['q' => 'palme', 'lang' => 'fr', 'limit' => 48], true), true);
    check('désactivation : listé dans « produits désactivés » de l\'aperçu', in_array($off, array_column($int['disabled'] ?? [], 'sku'), true)
        && !in_array($off, array_column($int['products'], 'sku'), true));
    $intAll = $svc->search(DeepFind\Search\SearchRequest::fromArray(['q' => 'palme', 'lang' => 'fr', 'limit' => 48], true), true, true);
    $mark = array_column($intAll['products'], 'disabled', 'sku');
    check('aperçu « Désactivés » : produit gardé à sa place et signalé', ($mark[$off] ?? null) === true && count(array_filter($mark)) === 1);
    [, $pub2] = $get("/api/v1/search?key=$key&q=palme&limit=48&show_disabled=1");
    check('API : un paramètre show_disabled ne fait pas réapparaître le produit', !in_array($off, array_column($pub2['products'], 'sku'), true) && !str_contains(json_encode($pub2), '"disabled"'));
    DeepFind\Db::exec("DELETE FROM query_rules WHERE query = '*' AND action = 'hide'");
    DeepFind\Rules\RulesRepository::bumpVersion();
    [, $pub] = $get("/api/v1/search?key=$key&q=palme&limit=48");
    check('réactivation : produit de retour dans la recherche', in_array($off, array_column($pub['products'], 'sku'), true));

    // Résultats personnalisés : produits en premier (ordre choisi), uniquement les sélectionnés, exclusions, période, suivi
    $CR = DeepFind\Rules\CustomResults::class;
    // le jeu d'exemple a déjà un résultat « masque » (converti depuis samples/boosts.csv) : mis de côté pendant ces tests
    $savedMasque = array_values(array_filter($CR::all('fr'), static fn ($c) => in_array('masque', array_column($c['terms'], 'term'), true)));
    foreach ($savedMasque as $c) {
        $CR::delete($c['id'], 'fr');
    }
    [, $nat] = $get("/api/v1/search?key=$key&q=masque&limit=48&posts=0");
    $natSkus = array_column($nat['products'], 'sku');
    $last = end($natSkus);
    $beforeLast = $natSkus[count($natSkus) - 2] ?? '';
    [$crFirst] = $CR::sanitize(['name' => 'Test en premier', 'active' => true, 'mode' => 'first', 'terms' => [['term' => 'masque', 'match' => 'exact']],
        'include_items' => [$last, $beforeLast]], 'fr');
    $idFirst = $CR::save('fr', $crFirst, null);
    [, $d] = $get("/api/v1/search?key=$key&q=masque&limit=48&posts=0&sid=sidcr000000000001");
    check('résultat perso : produits choisis en premier, dans l\'ordre', array_slice(array_column($d['products'], 'sku'), 0, 2) === [$last, $beforeLast]
        && count($d['products']) === count($natSkus), json_encode(array_slice(array_column($d['products'], 'sku'), 0, 3)));
    check('résultat perso : identifiant de suivi sur les produits mis en avant, rien d\'interne exposé', ($d['products'][0]['cr'] ?? 0) === $idFirst
        && !isset($d['products'][2]['cr']) && !array_key_exists('_cr', $d));
    [, $d] = $get("/api/v1/search?key=$key&q=masque&limit=48&posts=0&sort=price_asc");
    check('résultat perso : ordre imposé ignoré si le visiteur trie par prix', ($d['products'][0]['sku'] ?? '') !== $last || $natSkus[0] === $last);
    // suivi : 1 impression (recherche finale journalisée) et 1 clic sur un produit mis en avant
    $ch = curl_init($base . '/api/v1/click');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOPROXY => '*', CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Origin: http://localhost:8080', 'User-Agent: Mozilla/5.0 Tests'],
        CURLOPT_POSTFIELDS => http_build_query(['key' => $key, 'lang' => 'fr', 'q' => 'masque', 'doc_type' => 'product', 'doc_id' => 'x', 'position' => 1, 'sid' => 'sidcr000000000001', 'cr' => $idFirst])]);
    curl_exec($ch);
    usleep(300000);
    $st = $CR::stats('fr', [$idFirst], 30)[$idFirst];
    // 2 recherches où le résultat s'appliquait (dont le tri par prix), 1 clic sur un produit mis en avant
    check('résultat perso : impressions, clics et CTR', $st['impressions'] === 2 && $st['clicks'] === 1 && $st['ctr'] === 50.0, json_encode($st));

    // mode « uniquement » avec une règle (marque) + exclusion par règle ; terme large
    $brand = $nat['products'][0]['brand'] ?? '';
    [$crOnly, $errOnly] = $CR::sanitize(['name' => 'Test uniquement', 'active' => true, 'mode' => 'only', 'terms' => [['term' => 'masque', 'match' => 'broad']],
        'include_rules' => [['match' => 'all', 'conditions' => [['field' => 'brand', 'op' => 'in', 'values' => [$brand]]]]]], 'fr');
    $CR::delete($idFirst, 'fr');
    $idOnly = $CR::save('fr', $crOnly, null);
    [, $d] = $get("/api/v1/search?key=$key&q=" . rawurlencode('masque plongée') . "&limit=48&posts=0");
    $brands = array_values(array_unique(array_column($d['products'], 'brand')));
    check('résultat perso « uniquement » (terme large, règle marque) : seulement la marque choisie', $errOnly === [] && $brands === [$brand] && $d['total'] >= 1, json_encode($brands));
    $crOnly['exclude_rules'] = [['match_all' => true, 'conditions' => [['field' => 'final_price', 'op' => 'gte', 'value' => 0]]]];
    $CR::save('fr', $crOnly, $idOnly);
    [, $d] = $get("/api/v1/search?key=$key&q=masque&limit=48&posts=0");
    check('résultat perso : exclusion par règle (prix ≥ 0 → plus rien)', $d['total'] === 0 && $d['products'] === []);
    // période terminée : sans effet
    $crOnly['starts_at'] = '2000-01-01';
    $crOnly['ends_at'] = '2000-12-31';
    $CR::save('fr', $crOnly, $idOnly);
    [, $d] = $get("/api/v1/search?key=$key&q=masque&limit=48&posts=0");
    check('résultat perso : période terminée sans effet', array_column($d['products'], 'sku') === $natSkus);
    [, $errs] = $CR::sanitize(['name' => 'x', 'mode' => 'only', 'terms' => [['term' => 'a', 'match' => 'exact']]], 'fr');
    check('résultat perso : « uniquement » sans produit refusé', $errs !== []);
    // épingler depuis l'aperçu : position dans la liste, retrait
    $CR::delete($idOnly, 'fr');
    $pid = $CR::pin('fr', 'masque', $last, 1);
    [, $d] = $get("/api/v1/search?key=$key&q=masque&limit=48&posts=0");
    $pins = $CR::pinsFor('fr', 'masque');
    check('épingler (aperçu) : produit en 1re position', ($d['products'][0]['sku'] ?? '') === $last && ($pins[mb_strtoupper($last)]['position'] ?? 0) === 1);
    $CR::unpin('fr', $pid, $last);
    check('retirer l\'épinglage : résultat vide supprimé', $CR::find($pid, 'fr') === null);
    foreach ($savedMasque as $c) {
        $CR::save('fr', $c, null);
    }

    // Bannières : sélection par termes, bannière par défaut, période, HTML nettoyé, suivi affichages / clics / CTR
    $BN = DeepFind\Rules\Banners::class;
    [$bDef] = $BN::sanitize(['name' => 'Défaut', 'active' => true, 'is_default' => true, 'mode' => 'visual', 'image_desktop' => 'https://cdn.example/def.jpg'], 'fr');
    $idDef = $BN::save('fr', $bDef, null);
    [$bPal, $errPal] = $BN::sanitize(['name' => 'Palmes', 'active' => true, 'mode' => 'visual', 'terms' => [['term' => 'palme', 'match' => 'broad']],
        'image_desktop' => 'https://cdn.example/d.jpg', 'image_mobile' => 'https://cdn.example/m.jpg', 'link_url' => 'https://www.example.com/palmes', 'new_window' => true], 'fr');
    $idPal = $BN::save('fr', $bPal, null);
    [, $d] = $get("/api/v1/search?key=$key&q=" . rawurlencode('palmes de plongée') . "&limit=5&sid=sidbanner00000001");
    check('bannière : choisie par terme large, image mobile et lien envoyés', $errPal === [] && ($d['banner']['id'] ?? 0) === $idPal
        && ($d['banner']['mobile'] ?? '') === 'https://cdn.example/m.jpg' && ($d['banner']['new_window'] ?? false) === true && !isset($d['banner']['name']));
    [, $d] = $get("/api/v1/search?key=$key&q=masque&limit=5");
    check('bannière : par défaut quand aucune ne correspond', ($d['banner']['id'] ?? 0) === $idDef);
    [, $d] = $get("/api/v1/search?key=$key&q=palme&limit=5&offset=5");
    check('bannière : pas sur les pages suivantes', ($d['banner'] ?? null) === null);
    $ch = curl_init($base . '/api/v1/click');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOPROXY => '*', CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Origin: http://localhost:8080', 'User-Agent: Mozilla/5.0 Tests'],
        CURLOPT_POSTFIELDS => http_build_query(['key' => $key, 'lang' => 'fr', 'q' => 'palmes de plongée', 'doc_type' => 'banner', 'doc_id' => $idPal, 'position' => 0, 'sid' => 'sidbanner00000001'])]);
    curl_exec($ch);
    usleep(300000);
    $bs = $BN::stats('fr', [$idPal], 30)[$idPal];
    check('bannière : affichages, clics, CTR', $bs['impressions'] === 1 && $bs['clicks'] === 1 && $bs['ctr'] === 100.0, json_encode($bs));
    [$bCode] = $BN::sanitize(['name' => 'Code', 'active' => true, 'mode' => 'code', 'terms' => [['term' => 'tuba', 'match' => 'exact']],
        'html' => '<a href="javascript:alert(1)" onclick="x()">A</a><script>alert(1)</script><img src="https://cdn.example/t.jpg" onerror="y()" alt="T">'], 'fr');
    check('bannière HTML : scripts, événements et javascript: retirés', $bCode !== null && !preg_match('/script|onclick|onerror|javascript/i', $bCode['html'])
        && str_contains($bCode['html'], 'https://cdn.example/t.jpg'));
    $bPal['starts_at'] = '2000-01-01';
    $bPal['ends_at'] = '2000-01-31';
    $BN::save('fr', $bPal, $idPal);
    [, $d] = $get("/api/v1/search?key=$key&q=palme&limit=5");
    check('bannière : période terminée → bannière par défaut', ($d['banner']['id'] ?? 0) === $idDef);
    [, $errB] = $BN::sanitize(['name' => 'x', 'mode' => 'visual', 'terms' => [], 'image_desktop' => 'javascript:alert(1)'], 'fr');
    check('bannière : sans terme ni défaut, ou image non http(s), refusée', count($errB) === 2, json_encode($errB));
    DeepFind\Db::exec('DELETE FROM banners');
    DeepFind\Db::exec("DELETE FROM search_logs WHERE sid = 'sidbanner00000001'");
    DeepFind\Db::exec("DELETE FROM click_logs WHERE doc_type = 'banner'");
    DeepFind\CacheVersion::bump();
    DeepFind\Db::exec("DELETE FROM search_logs WHERE sid = 'sidcr000000000001'");
    DeepFind\Db::exec('DELETE FROM click_logs WHERE custom_result_id IS NOT NULL');
    DeepFind\CacheVersion::bump();

    // Règles de boost : termes exacts / larges, conditions toutes / au moins une, effet sur l'ordre
    $BR = DeepFind\Rules\BoostRules::class;
    $qw = DeepFind\Search\Relevance::words('palmes de plongée', 'fr');
    check('boost : terme large « palme » couvre « palmes de plongée »', $BR::termsMatch([['term' => 'palme', 'match' => 'broad']], 'palmes de plongee', $qw, 'fr'));
    check('boost : terme exact « palme » ne couvre pas « palmes de plongée »', !$BR::termsMatch([['term' => 'palme', 'match' => 'exact']], 'palmes de plongee', $qw, 'fr'));
    check('boost : sans terme = toutes les recherches', $BR::termsMatch([], 'masque', ['masque'], 'fr'));
    $doc = ['brand' => 'Cressi', 'category_names' => ['Palmes', 'Plongée'], 'final_price' => 80, 'margin_rate' => 0.4, 'in_stock' => 1, 'attr' => ['couleur' => ['Jaune']]];
    $rule = ['match_all' => true, 'conditions' => [['field' => 'category_names', 'values' => ['plongée']], ['field' => 'final_price', 'op' => 'gte', 'value' => 50]]];
    check('boost : toutes les conditions remplies', $BR::matches($rule, $doc));
    $rule['conditions'][] = ['field' => 'attr.couleur', 'values' => ['Noir']];
    check('boost : une condition manquante => non', !$BR::matches($rule, $doc));
    check('boost : « au moins une » condition => oui', $BR::matches(['match_all' => false] + $rule, $doc));
    check('boost : marge en %', $BR::matches(['match_all' => true, 'conditions' => [['field' => 'margin_rate', 'op' => 'gte', 'value' => 35]]], $doc)
        && $BR::matches(['match_all' => true, 'conditions' => [['field' => 'in_stock', 'values' => ['1']]]], $doc));
    $tc = static fn (string $field, string $match, string $text) => ['match_all' => true, 'conditions' => [['field' => $field, 'match' => $match, 'text' => $text]]];
    check('boost : texte contient / commence par / finit par (sans majuscules)', $BR::matches($tc('brand', 'contains', 'ESS'), $doc)
        && $BR::matches($tc('brand', 'starts', 'cre'), $doc) && $BR::matches($tc('brand', 'ends', 'SSI'), $doc) && !$BR::matches($tc('brand', 'starts', 'ssi'), $doc));
    check('boost : texte sur un champ à plusieurs valeurs et un attribut', $BR::matches($tc('category_names', 'starts', 'plong'), $doc)
        && $BR::matches($tc('attr.couleur', 'ends', 'une'), $doc) && $BR::matches($tc('category_names', 'contains', 'PLONGEE'), $doc)
        && $BR::matches($tc('brand', 'starts', 'Crèss'), $doc) && !$BR::matches($tc('attr.taille', 'contains', 'x'), $doc));
    check('boost : texte ne contient pas', $BR::matches($tc('category_names', 'not_contains', 'chaussure'), $doc)
        && !$BR::matches($tc('category_names', 'not_contains', 'palm'), $doc) && $BR::matches($tc('attr.taille', 'not_contains', 'x'), $doc));
    [$okText] = $BR::sanitize(['name' => 'x', 'boost' => '1.5', 'conditions' => [['field' => 'brand', 'match' => 'ends', 'text' => ' ssi '], ['field' => 'in_stock', 'match' => 'contains', 'text' => '1']]], 'fr');
    check('boost : condition texte enregistrée, refusée sur un champ oui/non', $okText !== null && $okText['conditions'] === [['field' => 'brand', 'match' => 'ends', 'texts' => ['ssi']]]);
    [$bad, $errs] = $BR::sanitize(['name' => 'x', 'boost' => '1.5', 'conditions' => [['field' => 'brand', 'match' => 'contains', 'text' => '  ']]], 'fr');
    check('boost : condition texte vide refusée', $bad === null && $errs !== []);
    check('boost : résumé d\'une condition texte', str_contains($BR::describe($tc('brand', 'not_contains', 'Cressi'), 'fr'), 'Marque ne contient pas « Cressi »'));
    // Nom du produit : comparaison de texte uniquement (ex. batteries d'une marque sans ses accessoires)
    $batt = ['name' => 'Batterie nomade EcoFlow River 2', 'brand' => 'ECOFLOW'];
    $cable = ['name' => 'Câble de recharge voiture EcoFlow', 'brand' => 'ECOFLOW'];
    $nameRule = ['match_all' => true, 'conditions' => [['field' => 'brand', 'values' => ['EcoFlow']], ['field' => 'name', 'match' => 'contains', 'text' => 'batterie']]];
    check('boost : nom du produit contient (marque + nom)', $BR::matches($nameRule, $batt) && !$BR::matches($nameRule, $cable)
        && $BR::matches($tc('name', 'contains', 'cable'), $cable) && $BR::matches($tc('name', 'starts', 'batterie'), $batt) && $BR::matches($tc('name', 'not_contains', 'câble'), $batt));
    [$okName] = $BR::sanitize(['name' => 'x', 'boost' => '3', 'conditions' => [['field' => 'name', 'match' => 'contains', 'text' => ' batterie ']]], 'fr');
    [$badName, $errName] = $BR::sanitize(['name' => 'x', 'boost' => '3', 'conditions' => [['field' => 'name', 'values' => ['Batterie']]]], 'fr');
    check('boost : nom du produit enregistré en texte, refusé en liste de valeurs', $okName !== null && $okName['conditions'] === [['field' => 'name', 'match' => 'contains', 'texts' => ['batterie']]]
        && $badName === null && $errName !== [] && in_array('name', $BR::DOC_FIELDS, true)
        && str_contains($BR::describe($tc('name', 'contains', 'batterie'), 'fr'), 'Nom du produit contient « batterie »'));
    // Plusieurs textes dans une condition : OU (« ne contient pas » = aucun des textes)
    $tm = static fn (string $match, array $texts) => ['match_all' => true, 'conditions' => [['field' => 'name', 'match' => $match, 'texts' => $texts]]];
    $station = ['name' => 'Station électrique EcoFlow Delta 2'];
    check('boost : plusieurs textes en OU', $BR::matches($tm('contains', ['batterie', 'station']), $station) && $BR::matches($tm('contains', ['batterie', 'station']), $batt)
        && !$BR::matches($tm('contains', ['batterie', 'station']), $cable) && $BR::matches($tm('starts', ['câble', 'station']), $station));
    check('boost : ne contient pas plusieurs textes = aucun', $BR::matches($tm('not_contains', ['câble', 'housse']), $batt) && !$BR::matches($tm('not_contains', ['housse', 'cable']), $cable));
    [$okMulti] = $BR::sanitize(['name' => 'x', 'boost' => '3', 'conditions' => [['field' => 'name', 'match' => 'contains', 'texts' => [' batterie ', 'Station', 'BATTERIE', '']]]], 'fr');
    check('boost : textes multiples nettoyés (doublons, vides) et résumé', $okMulti !== null && $okMulti['conditions'][0]['texts'] === ['batterie', 'Station']
        && str_contains($BR::describe($tm('contains', ['batterie', 'station']), 'fr'), 'contient « batterie » ou « station »')
        && str_contains($BR::describe($tm('not_contains', ['câble', 'housse']), 'fr'), 'ne contient pas « câble » ni « housse »'));
    [$bad, $errs] = $BR::sanitize(['name' => 'x', 'boost' => '1.5', 'conditions' => []], 'fr');
    check('boost : règle sans condition refusée', $bad === null && $errs !== []);

    [, $base] = $get("/api/v1/search?key=$key&q=palme&limit=48&posts=0");
    $brands = array_column($base['products'], 'brand');
    $target = end($brands); // marque du dernier produit : la remonter
    [$rule] = $BR::sanitize(['name' => 'Test remontée', 'active' => true, 'boost' => '10', 'terms_enabled' => true,
        'terms' => [['term' => 'palme', 'match' => 'broad']], 'match' => 'all', 'conditions' => [['field' => 'brand', 'values' => [$target]]]], 'fr');
    $BR::save('fr', $rule, null);
    [, $boosted] = $get("/api/v1/search?key=$key&q=palme&limit=48&posts=0");
    // 1re position = épinglage (règle de résultats), la 2e doit être un produit de la marque boostée
    check('boost : marque remontée en tête (après l\'épinglé)', ($boosted['products'][1]['brand'] ?? '') === $target, json_encode(array_slice(array_column($boosted['products'], 'brand'), 0, 4)));
    check('boost : marge et attributs toujours absents de l\'API', !str_contains(json_encode($boosted), 'margin') && !str_contains(json_encode($boosted), '"attr"'));
    $int = $svc->search(DeepFind\Search\SearchRequest::fromArray(['q' => 'palme', 'lang' => 'fr', 'limit' => 48], true), true);
    check('boost : règle affichée dans l\'aperçu interne', ($int['products'][1]['boost_rules'][0]['name'] ?? '') === 'Test remontée');
    DeepFind\Db::exec('UPDATE boost_rules SET active = 0');
    DeepFind\CacheVersion::bump();
    [, $inactive] = $get("/api/v1/search?key=$key&q=palme&limit=48&posts=0");
    check('boost : règle inactive sans effet', array_column($inactive['products'], 'sku') === array_column($base['products'], 'sku'));
    DeepFind\Db::exec('DELETE FROM boost_rules');
    DeepFind\CacheVersion::bump();

    // Correspondance des mots : « barbe à papa » (aucun produit) => approchants signalés, ou rien en mode strict
    [, $bp] = $get("/api/v1/search?key=$key&q=" . rawurlencode('barbe à papa') . "&sid=sidbarbe00001");
    check('recherche sans produit : résultats marqués « approchants » (ou aucun)', ($bp['approximate'] ?? false) === true || $bp['total'] === 0, json_encode(['t' => $bp['total'], 'a' => $bp['approximate'] ?? null]));
    [, $ok] = $get("/api/v1/search?key=$key&q=palme");
    check('recherche normale : pas « approchant »', ($ok['approximate'] ?? true) === false && $ok['total'] > 0);
    DeepFind\Settings::set('matching_mode', 'strict');
    [, $bp2] = $get("/api/v1/search?key=$key&q=" . rawurlencode('barbe à papa'));
    check('mode strict : aucun résultat', $bp2['total'] === 0 && ($bp2['approximate'] ?? true) === false);
    DeepFind\Settings::set('matching_mode', 'exact_then_approx');
    usleep(300000);
    $logged = DeepFind\Db::one("SELECT results, approx FROM search_logs WHERE query_norm = 'barbe a papa' ORDER BY id LIMIT 1");
    check('journal : recherche sans produit exact enregistrée avec 0 résultat', $logged !== null && (int) $logged['results'] === 0);

    // Anciens « boosts produits » convertis en règles de boost ; boost rapide d'un produit ; période des règles
    $meiliT = DeepFind\Meili\MeiliClient::admin();
    DeepFind\Db::exec("INSERT INTO product_boosts (project_id, lang, sku, boost, starts_at, ends_at, note, created_at) VALUES
        (1, 'fr', 'seac-x-vision', 10, NULL, '2099-12-31', 'salon', NOW()), (1, 'fr', 'C4-GHOST', 5, NULL, '2000-01-01', '', NOW()),
        (1, 'fr', 'C4-PREDATOR', -10, '2099-01-01', NULL, '', NOW())");
    $nMig = DeepFind\Rules\BoostRules::migrateProductBoosts();
    $mig = DeepFind\Rules\BoostRules::productRule('fr', 'SEAC-X-VISION');
    $fut = DeepFind\Rules\BoostRules::productRule('fr', 'C4-PREDATOR');
    check('migration : boost produit +10 converti en règle ×2, date de fin rappelée dans le nom', $mig !== null && abs($mig['boost'] - 2.0) < 0.001 && str_contains($mig['name'], '31/12/2099') && $mig['active']);
    check('migration : boost terminé non repris, boost à venir en règle inactive ×0,5', $nMig === 2 && DeepFind\Rules\BoostRules::productRule('fr', 'C4-GHOST') === null
        && $fut !== null && !$fut['active'] && abs($fut['boost'] - 0.5) < 0.001 && (int) DeepFind\Db::value('SELECT COUNT(*) FROM product_boosts') === 0);
    DeepFind\Db::exec('DELETE FROM boost_rules');
    DeepFind\Rules\BoostRules::setProductBoost('fr', 'SEAC-X-VISION', 1.0);
    check('boost rapide : coefficient 1 = règle supprimée', DeepFind\Rules\BoostRules::productRule('fr', 'SEAC-X-VISION') === null);
    DeepFind\Rules\BoostRules::setProductBoost('fr', 'SEAC-X-VISION', 3);
    DeepFind\Rules\BoostRules::setProductBoost('fr', 'SEAC-X-VISION', 5);
    check('boost rapide : une seule règle par produit, modifiée', (int) DeepFind\Db::value("SELECT COUNT(*) FROM boost_rules WHERE name = 'Produit SEAC-X-VISION'") === 1
        && abs(DeepFind\Rules\BoostRules::productBoosts('fr')['SEAC-X-VISION'] - 5.0) < 0.001);
    DeepFind\Db::exec('DELETE FROM boost_rules');
    DeepFind\CacheVersion::bump();

    // Recalcul des scores (sans flux) : les clics font monter le produit
    $target = $meiliT->fetchDocuments('deepfind_products_fr', 'sku = "C4-GHOST"', 1)[0] ?? null;
    for ($i = 0; $i < 40; $i++) {
        DeepFind\Db::insert('click_logs', ['created_at' => date('Y-m-d H:i:s'), 'lang' => 'fr', 'query_norm' => 'palme', 'doc_type' => 'product', 'doc_id' => $target['id'], 'position' => 3, 'ip_hash' => 'testtesttesttest']);
    }
    $srcFr = DeepFind\Db::one("SELECT * FROM sources WHERE code = 'produits_fr'");
    $runS = (new DeepFind\Import\Importer($meiliT))->run($srcFr, 'scores', 'cli');
    $after = $meiliT->fetchDocuments('deepfind_products_fr', 'sku = "C4-GHOST"', 1)[0] ?? null;
    check('recalcul des scores : terminé sans relire le flux', $runS['status'] === 'success' && (int) $runS['items_read'] === 50);
    check('recalcul des scores : clics pris en compte', (int) ($after['clicks_30d'] ?? 0) === 40 && $after['biz_score'] > $target['biz_score']);
    DeepFind\Db::exec("DELETE FROM click_logs WHERE ip_hash = 'testtesttesttest'");

    // Flux complet : produit absent = désactivé auto (gardé dans l'index, jamais proposé), réactivé s'il revient ;
    // un produit désactivé à la main le reste quoi qu'il arrive. Écart avec l'import précédent dans le rapport.
    $feed = (string) file_get_contents(APP_ROOT . '/samples/products_fr.xml');
    $gone = 'C4-PREDATOR';
    $minus = preg_replace('#<product>(?:(?!</product>).)*<sku>' . $gone . '</sku>.*?</product>\s*#s', '', $feed, 1);
    @mkdir(APP_ROOT . '/var/feeds-local', 0775, true);
    file_put_contents(APP_ROOT . '/var/feeds-local/test_moins_un.xml', $minus);
    $srcFr = DeepFind\Db::one("SELECT * FROM sources WHERE code = 'produits_fr'");
    $manual = 'SEAC-X-VISION';
    DeepFind\Rules\RulesRepository::addRule('fr', '*', $manual, 'hide', null, '', '');
    DeepFind\Db::exec("UPDATE sources SET url = 'var/feeds-local/test_moins_un.xml' WHERE id = ?", [$srcFr['id']]);
    $r1 = (new DeepFind\Import\Importer($meiliT))->run(DeepFind\Db::one('SELECT * FROM sources WHERE id = ?', [$srcFr['id']]), 'full', 'cli');
    $rep1 = json_decode((string) $r1['report'], true) ?: [];
    check('flux complet : produit absent compté « désactivé auto » dans l\'écart', $r1['status'] === 'success' && ($rep1['auto_disabled'] ?? -1) === 1 && ($rep1['reactivated'] ?? -1) === 0, json_encode($rep1));
    $kept = $meiliT->fetchDocuments('deepfind_products_fr', 'sku = "' . $gone . '"', 1)[0] ?? null;
    check('flux complet : produit absent gardé dans l\'index, non vendable', $kept !== null && (int) $kept['available'] === 0 && (int) ($kept['absent_since'] ?? 0) > 0);
    DeepFind\CacheVersion::bump();
    [, $pubGone] = $get("/api/v1/search?key=$key&q=predator&limit=48");
    check('flux complet : produit absent retiré de la recherche publique', !in_array($gone, array_column($pubGone['products'] ?? [], 'sku'), true));
    $intGone = $svc->search(DeepFind\Search\SearchRequest::fromArray(['q' => 'predator', 'lang' => 'fr', 'limit' => 48], true), true, true);
    $g = array_values(array_filter($intGone['products'], static fn ($x) => $x['sku'] === $gone))[0] ?? [];
    check('aperçu : « désactivé auto » distinct de « désactivé manu »', !empty($g['disabled_auto']) && empty($g['disabled_manual']) && !empty($g['disabled']));
    // retour dans le flux : réactivé ; le produit désactivé à la main reste désactivé
    DeepFind\Db::exec("UPDATE sources SET url = ? WHERE id = ?", [$srcFr['url'], $srcFr['id']]);
    $r2 = (new DeepFind\Import\Importer($meiliT))->run(DeepFind\Db::one('SELECT * FROM sources WHERE id = ?', [$srcFr['id']]), 'full', 'cli');
    $rep2 = json_decode((string) $r2['report'], true) ?: [];
    DeepFind\CacheVersion::bump();
    [, $pubBack] = $get("/api/v1/search?key=$key&q=predator&limit=48");
    [, $pubMan] = $get("/api/v1/search?key=$key&q=" . rawurlencode('seac x-vision') . "&limit=48");
    check('flux complet : produit revenu réactivé automatiquement (écart +1)', ($rep2['reactivated'] ?? 0) === 1 && ($rep2['auto_disabled'] ?? -1) === 0
        && in_array($gone, array_column($pubBack['products'] ?? [], 'sku'), true), json_encode($rep2));
    check('flux complet : produit désactivé à la main non réactivé par le flux', !in_array($manual, array_column($pubMan['products'] ?? [], 'sku'), true));
    DeepFind\Db::exec("DELETE FROM query_rules WHERE query = '*' AND sku = ?", [$manual]);
    DeepFind\Rules\RulesRepository::bumpVersion();
    @unlink(APP_ROOT . '/var/feeds-local/test_moins_un.xml');

    // Conditions des règles : recherche dans toutes les valeurs d'un champ (au-delà des 100 renvoyées par une recherche)
    $fs = array_column($meiliT->facetSearch('deepfind_products_fr', 'brand', 'cre'), 'count', 'value');
    check('valeurs des conditions : recherche dans toutes les marques', isset($fs['CRESSI']) && $fs['CRESSI'] > 0 && !isset($fs['SEAC']), json_encode($fs));

    // Progression : comptage et étapes enregistrées
    $run = DeepFind\Db::one("SELECT * FROM import_runs WHERE type = 'full' AND status = 'success' ORDER BY id LIMIT 1");
    check('progression : total compté et étape finale', (int) $run['items_total'] === (int) $run['items_read'] && $run['phase'] === 'done');
    $pg = DeepFind\Import\Progress::of(['id' => 1, 'status' => 'running', 'phase' => 'read', 'items_total' => 200, 'items_read' => 100, 'items_indexed' => 90, 'items_rejected' => 0,
        'bytes_done' => 0, 'bytes_total' => 0, 'tasks_done' => 0, 'tasks_total' => 0, 'started_at' => date('Y-m-d H:i:s', time() - 30), 'finished_at' => null, 'message' => '']);
    check('progression : pourcentage et temps restant', $pg['percent'] === 43 && $pg['eta'] !== null && $pg['detail'] === '100 / 200 éléments');
    check('comptage rapide des éléments', DeepFind\Import\XmlItemReader::count(APP_ROOT . '/samples/products_fr_flat.xml', ['item']) === 231);

    // Planificateur : une seule mise en file par minute, même si lancé deux fois
    DeepFind\Db::exec("UPDATE import_runs SET status = 'success' WHERE status IN ('queued','running')");
    DeepFind\Db::exec("UPDATE sources SET cron_full = '* * * * *', scheduled_full_at = NULL WHERE code = 'produits_fr'");
    $q1 = DeepFind\Import\Scheduler::tick();
    $q2 = DeepFind\Import\Scheduler::tick();
    check('planificateur : import mis en file', count($q1) === 1 && str_contains($q1[0], 'produits_fr full'));
    check('planificateur : pas de doublon dans la même minute', $q2 === []);
    DeepFind\Db::exec("UPDATE sources SET cron_full = NULL WHERE code = 'produits_fr'");
    DeepFind\Db::exec("DELETE FROM import_runs WHERE status = 'queued'");
    // ================================================================ Projets : isolation complète, copie, sauvegarde
    $prodId = DeepFind\Project::defaultId();
    $testId = DeepFind\Project::create('test', 'Projet Test');
    check('projet : préfixe d\'index propre', DeepFind\Project::find($testId)['index_prefix'] === 'deepfind_test_');
    DeepFind\ConfigTransfer::apply($testId, DeepFind\ConfigTransfer::export($prodId), array_keys(DeepFind\ConfigTransfer::SECTIONS), true);
    $tSources = DeepFind\Db::all('SELECT * FROM sources WHERE project_id = ?', [$testId]);
    check('projet : configuration copiée (flux créés inactifs)', count($tSources) >= 2 && (int) $tSources[0]['active'] === 0);
    $tProd = DeepFind\Db::one("SELECT * FROM sources WHERE project_id = ? AND code = 'produits_fr'", [$testId]);
    $runT = (new DeepFind\Import\Importer($meiliT))->run($tProd, 'full', 'cli');
    DeepFind\Project::use($prodId);
    check('projet : import dans son propre index', $runT['status'] === 'success' && $meiliT->getIndex('deepfind_test_products_fr') !== null);
    $prodCount = (int) ($meiliT->stats('deepfind_products_fr')['numberOfDocuments'] ?? 0);
    $testKey = DeepFind\Project::with($testId, static fn () => DeepFind\Security\ApiKeys::create('Test', 'localhost', 'fr'));
    // Une redirection et un réglage uniquement dans Test
    DeepFind\Project::with($testId, static function (): void {
        [$red] = DeepFind\Rules\Redirections::sanitize(['name' => 'Bonjour', 'active' => true, 'auto' => true, 'url' => 'https://www.example.com/test',
            'terms' => [['term' => 'bonjour test', 'match' => 'exact']]]);
        DeepFind\Rules\Redirections::save('fr', $red, null);
        DeepFind\Settings::set('matching_mode', 'strict');
    });
    [, $rt] = $get("/api/v1/search?key=$testKey&q=" . rawurlencode('bonjour test'));
    [, $rp] = $get("/api/v1/search?key=$key&q=" . rawurlencode('bonjour test'));
    check('projet : règle du projet Test sans effet sur Prod', ($rt['redirect'] ?? '') === 'https://www.example.com/test' && ($rp['redirect'] ?? null) === null);
    [, $bt] = $get("/api/v1/search?key=$testKey&q=" . rawurlencode('barbe à papa'));
    [, $bpP] = $get("/api/v1/search?key=$key&q=" . rawurlencode('barbe à papa'));
    check('projet : paramètre du projet Test sans effet sur Prod', ($bt['approximate'] ?? true) === false && $bt['total'] === 0 && DeepFind\Settings::get('matching_mode') === 'exact_then_approx');
    usleep(300000);
    check('projet : journaux séparés', (int) DeepFind\Db::value("SELECT COUNT(*) FROM search_logs WHERE project_id = ? AND query_norm = 'bonjour test'", [$testId]) >= 1
        && (int) DeepFind\Db::value("SELECT COUNT(*) FROM search_logs WHERE project_id = ? AND query_norm = 'bonjour test'", [$prodId]) >= 1);
    check('projet : index de Prod intact', (int) ($meiliT->stats('deepfind_products_fr')['numberOfDocuments'] ?? 0) === $prodCount);

    // Copie Test → Prod du réglage, avec sauvegarde puis restauration
    $snap = DeepFind\ConfigTransfer::snapshot($prodId, 'test');
    DeepFind\ConfigTransfer::apply($prodId, DeepFind\ConfigTransfer::export($testId), ['relevance', 'redirects'], false);
    DeepFind\Project::use($testId);
    DeepFind\Project::use($prodId);
    check('copie : réglage de Test appliqué à Prod', DeepFind\Settings::get('matching_mode') === 'strict');
    [, $rp2] = $get("/api/v1/search?key=$key&q=" . rawurlencode('bonjour test'));
    check('copie : redirection copiée, visible immédiatement côté Prod', ($rp2['redirect'] ?? '') === 'https://www.example.com/test');
    $resume = DeepFind\ConfigTransfer::summary(DeepFind\ConfigTransfer::export($testId), $prodId, ['relevance']);
    check('copie : résumé sans changement une fois copié', ($resume['relevance']['detail'] ?? ['x']) === []);
    DeepFind\ConfigTransfer::restore($snap, $prodId);
    DeepFind\Project::use($testId);
    DeepFind\Project::use($prodId);
    check('sauvegarde : restauration', DeepFind\Settings::get('matching_mode') === 'exact_then_approx'
        && DeepFind\Db::value("SELECT COUNT(*) FROM redirections WHERE project_id = ? AND terms LIKE '%bonjour test%'", [$prodId]) == 0);
    file_put_contents(APP_ROOT . '/tests/_tmp/test_project', $testId . ' ' . $testKey);

    // Projet désactivé : l'API le signale au widget, qui ne prend pas la main ; recherche refusée
    [$c, $cf] = $get("/api/v1/config?key=$key&lang=fr");
    check('config widget : projet actif', $c === 200 && ($cf['enabled'] ?? null) === true && array_key_exists('enter_submit', $cf));
    check('config widget : autocomplétion d\'origine bloquée par défaut', ($cf['block_native'] ?? null) === true);
    DeepFind\Settings::set('widget_block_native', '0');
    [$c, $cf] = $get("/api/v1/config?key=$key&lang=fr");
    check('config widget : réglage « bloquer l\'autocomplétion » désactivable', ($cf['block_native'] ?? null) === false);
    DeepFind\Settings::set('widget_block_native', '1');
    [$c, $cf] = $get("/api/v1/config?key=$key&lang=fr");
    DeepFind\Project::setActive($prodId, false);
    [$c, $cf] = $get("/api/v1/config?key=$key&lang=fr");
    [$cs] = $get("/api/v1/search?key=$key&q=palme");
    check('projet désactivé : widget prévenu, recherche refusée', $c === 200 && ($cf['enabled'] ?? null) === false && $cs === 403);
    [$c, $cf] = $get("/api/v1/config?key=$testKey&lang=fr");
    check('projet désactivé : sans effet sur les autres projets', ($cf['enabled'] ?? null) === true);
    DeepFind\Project::setActive($prodId, true);
    [$c, $cf] = $get("/api/v1/config?key=$key&lang=fr");
    [$cs] = $get("/api/v1/search?key=$key&q=palme");
    check('projet réactivé', ($cf['enabled'] ?? null) === true && $cs === 200);

    // Intégration : layers (un seul actif), filtres et tris renvoyés au widget
    $WC = DeepFind\Widget\WidgetConfig::class;
    DeepFind\Project::use($prodId);
    [$c, $cf] = $get("/api/v1/config?key=$key&lang=fr");
    check('intégration : sans layer, affichage par défaut + filtres et tris par défaut', (array_key_exists('layer', $cf) && $cf['layer'] === null)
        && ($cf['sorts']['relevance'] ?? '') === 'Pertinence' && array_column($cf['sorts']['items'] ?? [], 'key') === ['price:asc', 'price:desc', 'created:desc']
        && in_array('price', array_column($cf['filters'] ?? [], 'code'), true), json_encode([$c, $cf['layer'] ?? 'x', $cf['sorts'] ?? null, array_column($cf['filters'] ?? [], 'code')]));
    $l1 = $WC::createLayer('Boutique', 'fullscreen');
    $l2 = $WC::createLayer('Essai', 'embedded');
    check('intégration : premier layer actif d\'office, le suivant non', $WC::activeLayer()['id'] === $l1 && !$WC::layer($l2)['active']);
    $WC::saveLayer($l2, 'Essai intégré', 'embedded', ['container' => 'main#maincontent<script>', 'input' => '#search', 'url_state' => '1', 'gap' => 500]);
    $WC::activateLayer($l2, true);
    $al = $WC::activeLayer();
    $WC::saveLayer($l1, 'Boutique', 'below', ['min_chars' => 0, 'logo' => 'javascript:alert(1)', 'color_primary' => '#1069F9', 'color_text' => 'red',
        'css' => '.df-title { color: red; }', 'card_template' => '<div onclick="x()">{{ name }}<script>alert(1)</script><img src="{{image}}"></div>',
        'css_mobile' => '.df-name { font-size: 12px; }', 'card_template_mobile' => '<p>{{price}}<iframe src="x"></iframe></p>', 'max_width' => 100]);
    check('intégration : largeur maximale bornée (320 px minimum, vide = par défaut)', $WC::layer($l1)['settings']['max_width'] === 320
        && $WC::cleanSettings(['max_width' => ''])['max_width'] === 0 && $WC::cleanSettings(['max_width' => '980'])['max_width'] === 980
        && $WC::cleanSettings([])['max_width_unit'] === 'pct' && $WC::cleanSettings(['max_width' => '85', 'max_width_unit' => 'pct']) ['max_width'] === 85
        && $WC::cleanSettings(['max_width' => '900', 'max_width_unit' => 'pct'])['max_width'] === 100);
    // carte par défaut : même modèle côté extranet et widget, jamais stocké s'il n'est pas modifié
    preg_match("/var DEFAULT_CARD = (.*?);\n/s", (string) file_get_contents(APP_ROOT . '/public/widget/deepfind.js'), $jm);
    preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $jm[1] ?? '', $jp);
    check('carte par défaut : modèle identique dans le widget', implode('', array_map('stripcslashes', $jp[1])) === $WC::DEFAULT_CARD_TEMPLATE);
    $dc = $WC::cleanSettings(['card_template' => $WC::DEFAULT_CARD_TEMPLATE, 'card_template_mobile' => $WC::DEFAULT_CARD_TEMPLATE]);
    check('carte par défaut : nettoyage sans perte, non stockée si inchangée', $WC::sameTemplate($WC::cleanCardTemplate($WC::DEFAULT_CARD_TEMPLATE), $WC::DEFAULT_CARD_TEMPLATE)
        && $dc['card_template'] === '' && $dc['card_template_mobile'] === '');
    $pc = $WC::cleanSettings([]);
    $pc2 = $WC::cleanSettings(['cols_desktop' => 12, 'cols_mobile' => 0, 'per_page_desktop' => 100, 'per_page_mobile' => 2]);
    check('intégration : nombre de produits (défauts et bornes)', [$pc['cols_desktop'], $pc['cols_mobile'], $pc['per_page_desktop'], $pc['per_page_mobile']] === [0, 2, 20, 20]
        && [$pc2['cols_desktop'], $pc2['cols_mobile'], $pc2['per_page_desktop'], $pc2['per_page_mobile']] === [8, 1, 48, 4]);
    $ls = $WC::layer($l1);
    check('intégration : mise en page modifiable, identité et personnalisation nettoyées', $ls['type'] === 'below' && $ls['settings']['min_chars'] === 1
        && $ls['settings']['logo'] === '' && $ls['settings']['color_primary'] === '#1069f9' && $ls['settings']['color_text'] === ''
        && $ls['settings']['css'] === '.df-title { color: red; }' && $ls['settings']['card_template'] === '<div>{{name}}<img src="{{image}}"></div>'
        && $ls['settings']['css_mobile'] === '.df-name { font-size: 12px; }' && $ls['settings']['card_template_mobile'] === '<p>{{price}}</p>');
    check('intégration : activation exclusive, réglages nettoyés', $al['id'] === $l2 && !$WC::layer($l1)['active'] && $al['settings']['container'] === 'main#maincontentscript'
        && $al['settings']['url_state'] === true && $al['settings']['gap'] === 80);
    // Favori « Tester DeepFind » : jeton d'aperçu d'un layer inactif, refusé s'il est modifié
    $tok = $WC::previewToken($l1);
    [, $pv] = $get("/api/v1/config?key=$key&lang=fr&preview=" . rawurlencode($tok));
    [, $pvBad] = $get("/api/v1/config?key=$key&lang=fr&preview=" . rawurlencode(substr($tok, 0, -1) . (str_ends_with($tok, 'a') ? 'b' : 'a')));
    check('aperçu : layer inactif servi avec un jeton valide, refusé sinon', ($pv['preview']['layer'] ?? '') === 'Boutique' && ($pv['layer']['type'] ?? '') === 'below'
        && ($pvBad['preview']['error'] ?? '') === 'expired' && ($pvBad['layer']['type'] ?? '') === 'embedded');
    $WC::saveFilters('fr', [['code' => 'price', 'label' => 'Budget', 'visible' => true], ['code' => 'brand', 'label' => '', 'visible' => false], ['code' => 'inconnu', 'visible' => true]]);
    $WC::saveSorts('fr', 'Les plus pertinents', [['field' => 'name', 'dir' => 'asc', 'label' => 'Nom A-Z'], ['field' => 'price', 'dir' => 'desc', 'label' => ''],
        ['field' => 'margin_rate', 'dir' => 'desc', 'label' => 'interdit'], ['field' => 'name', 'dir' => 'asc', 'label' => 'doublon']]);
    [$c, $cf] = $get("/api/v1/config?key=$key&lang=fr");
    $codes = array_column($cf['filters'] ?? [], 'code');
    check('intégration : config widget (layer actif, ordre des filtres, tris sans tri interne)', ($cf['layer']['type'] ?? '') === 'embedded'
        && ($cf['layer']['container'] ?? '') === 'main#maincontentscript' && array_slice($codes, 0, 2) === ['price', 'brand'] && !in_array('inconnu', $codes, true)
        && ($cf['filters'][0]['label'] ?? '') === 'Budget' && ($cf['filters'][1]['visible'] ?? true) === false
        && ($cf['sorts']['relevance'] ?? '') === 'Les plus pertinents' && ($cf['sorts']['items'] ?? []) === [['key' => 'name:asc', 'label' => 'Nom A-Z'], ['key' => 'price:desc', 'label' => 'Prix décroissant']]
        && !str_contains(json_encode($cf), 'margin'));
    check('intégration : seuls les filtres ajoutés restent, les autres sont proposés à l\'ajout', count($codes) === 2
        && isset($WC::addableFilters('fr')['category']) && !isset($WC::addableFilters('fr')['price']));
    $WC::saveFilters('fr', [['code' => 'price', 'label' => 'Budget', 'visible' => true], ['code' => 'brand', 'visible' => false], ['code' => 'category', 'visible' => true, 'limit' => 3, 'open' => false]]);
    check('intégration : bloc replié par défaut enregistré (déplié sinon)', $WC::filters('fr')[2]['open'] === false && $WC::filters('fr')[0]['open'] === true);
    [, $d] = $get("/api/v1/search?key=$key&q=palme&limit=3");
    $cat = array_values(array_filter($d['facets'] ?? [], static fn ($f) => $f['code'] === 'category'))[0] ?? null;
    check('intégration : nombre de valeurs affichées transmis au widget', ($WC::filters('fr')[2]['limit'] ?? 0) === 3 && $cat !== null);
    check('intégration : filtre masqué (marques) absent des résultats', !in_array('brand', array_column($d['facets'] ?? [], 'code'), true));
    // Ordre des valeurs : par nombre de produits ou alphabétique ; la coupe garde toujours les valeurs les plus fréquentes
    $SS = DeepFind\Search\SearchService::class;
    $vals = [];
    foreach (range(1, 40) as $i) {
        $vals[] = ['value' => 'Marque ' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'count' => $i, 'selected' => $i === 2];
    }
    check('filtres : marques triées A à Z par défaut, les catégories par nombre', $WC::defaultOrder('brand') === 'alpha' && $WC::defaultOrder('category') === 'count');
    $WC::saveFilters('fr', [['code' => 'brand', 'visible' => true, 'limit' => 5, 'order' => 'alpha']]);
    $fa = $SS::arrangeFacets([['code' => 'brand', 'label' => 'Marques', 'values' => $vals]], 'fr')[0]['values'];
    $names = array_column($fa, 'value');
    check('filtres : alphabétique parmi les plus fréquentes (fin d\'alphabet gardée), valeur choisie conservée', $names === array_merge(['Marque 02'], array_map(static fn ($i) => 'Marque ' . $i, range(11, 40)))
        && count($names) === 31, json_encode($names));
    $WC::saveFilters('fr', [['code' => 'brand', 'visible' => true, 'limit' => 5, 'order' => 'count']]);
    $fc = array_column($SS::arrangeFacets([['code' => 'brand', 'label' => 'Marques', 'values' => $vals]], 'fr')[0]['values'], 'value');
    check('filtres : ordre par nombre de produits (du plus grand au plus petit)', ($fc[0] ?? '') === 'Marque 40' && ($fc[29] ?? '') === 'Marque 11' && end($fc) === 'Marque 02');
    $WC::activateLayer($l2, false);
    $WC::deleteLayer($l1);
    $WC::deleteLayer($l2);
    DeepFind\Settings::set('widget_filters_fr', '');
    DeepFind\Settings::set('widget_sorts_fr', '');
    [$c, $cf] = $get("/api/v1/config?key=$key&lang=fr");
    check('intégration : layer désactivé = affichage par défaut', (array_key_exists('layer', $cf) && $cf['layer'] === null) && $WC::layers() === [], json_encode([$cf['layer'] ?? 'x', count($WC::layers())]));
    $SR = DeepFind\Search\SearchRequest::class;
    check('tris : champ:ordre accepté, marge refusée, ancienne clé conservée', $SR::fromArray(['q' => 'x', 'sort' => 'brand:desc'])->sort === 'brand:desc'
        && $SR::fromArray(['q' => 'x', 'sort' => 'margin_rate:desc'])->sort === 'relevance' && $SR::fromArray(['q' => 'x', 'sort' => 'price_asc'])->sort === 'price_asc');
    [$c, $dSort] = $get("/api/v1/search?key=$key&q=tuba&sort=" . rawurlencode('price:desc')); // autre mot : limite « même recherche » de l'anti-robots
    check('tris : nouveau format accepté par l\'API', $c === 200, json_encode([$c, $dSort]));

    // ------------------------------------------------ Mesure d'audience (anonyme, statistiques quotidiennes)
    $AU = DeepFind\Logging\Audience::class;
    check('audience : type d\'appareil (user-agent, indication du widget prioritaire)',
        $AU::device('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148') === 'mobile'
        && $AU::device('Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X)') === 'tablet'
        && $AU::device('Mozilla/5.0 (Linux; Android 14; SM-X710) AppleWebKit/537.36 Safari/537.36') === 'tablet'
        && $AU::device('Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Mobile Safari/537.36') === 'mobile'
        && $AU::device('Mozilla/5.0 (Windows NT 10.0; Win64; x64)') === 'desktop'
        && $AU::device('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)', 't') === 'tablet');
    check('audience : pages choisies (chemins, adresses complètes, doublons, racine ignorée)',
        $AU::paths("/fr/plongee\nhttps://www.example.com/fr/apnee.html?x=1\n  fr/plongee \n/\n\n") === ['/fr/plongee', '/fr/apnee.html']);
    $kvA = DeepFind\Store\Store::get();
    check('audience : « pal », « palm », « palme » = une seule recherche',
        $AU::isNewSearch($kvA, 'sidaud0000000001', 'pal', '') && !$AU::isNewSearch($kvA, 'sidaud0000000001', 'palm', '')
        && !$AU::isNewSearch($kvA, 'sidaud0000000001', 'palme', '') && $AU::isNewSearch($kvA, 'sidaud0000000001', 'masque', '')
        && $AU::isNewSearch($kvA, 'sidaud0000000001', 'masque', 'brand=C4'));
    check('audience : empreinte différente chaque jour (aucun suivi d\'un jour à l\'autre)',
        $AU::visitorHash('203.0.113.7', 'UA', '2026-01-01') !== $AU::visitorHash('203.0.113.7', 'UA', '2026-01-02')
        && $AU::visitorHash('203.0.113.7', 'UA', '2026-01-01') === $AU::visitorHash('203.0.113.7', 'UA', '2026-01-01')
        && strlen($AU::visitorHash('203.0.113.7', 'UA', '2026-01-01')) === 32);

    DeepFind\Db::exec('DELETE FROM audience_visitors');
    DeepFind\Db::exec('DELETE FROM audience_daily');
    $post = static function (string $path, array $fields, string $ua = 'Mozilla/5.0 Tests'): int {
        $ch = curl_init(getenv('TEST_BASE_URL') . $path); // ($base est réutilisé plus haut par d'autres tests)
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOPROXY => '*', CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Origin: http://localhost:8080', 'User-Agent: ' . $ua], CURLOPT_POSTFIELDS => http_build_query($fields)]);
        curl_exec($ch);
        return (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    };
    [, $cfA] = $get("/api/v1/config?key=$key&lang=fr");
    check('audience : réglage envoyé au widget (toutes les pages)', ($cfA['audience']['pages'] ?? '') === 'all', json_encode($cfA['audience'] ?? null));
    $phone = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Mobile Safari/537.36';
    $codes = [$post('/api/v1/visit', ['key' => $key, 'lang' => 'fr', 'd' => 'd']), $post('/api/v1/visit', ['key' => $key, 'lang' => 'fr', 'd' => 'd']),
        $post('/api/v1/visit', ['key' => $key, 'lang' => 'fr', 'd' => 'm'], $phone)];
    $get("/api/v1/search?key=$key&q=tub&sid=sidaud0000000002&d=d");
    $get("/api/v1/search?key=$key&q=tuba&sid=sidaud0000000002&d=d");       // frappe : même recherche
    $get("/api/v1/search?key=$key&q=bouee&sid=sidaud0000000002&d=d");      // nouvelle recherche
    $get("/api/v1/search?key=$key&q=tuba&sid=sidaud0000000002&d=d&offset=20"); // défilement : pas une recherche
    $get("/api/v1/search?key=$key&q=lampe&sid=sidaud0000000003&na=1");      // page Démo / aperçu : non compté
    $post('/api/v1/click', ['key' => $key, 'lang' => 'fr', 'q' => 'bouee', 'doc_type' => 'product', 'doc_id' => 'x', 'position' => 1, 'sid' => 'sidaud0000000002', 'd' => 'd']);
    usleep(400000);
    $sumA = $AU::summary(date('Y-m-d'), date('Y-m-d'));
    $tA = $sumA['total'];
    check('audience : pages vues, visiteurs, appareils', $codes === [204, 204, 204] && $tA['visitors'] === 2 && $tA['pageviews'] === 3
        && $sumA['devices']['mobile']['visitors'] === 1 && $sumA['devices']['desktop']['visitors'] === 1, json_encode([$codes, $tA]));
    check('audience : recherches et clics par visiteur', $tA['searchers'] === 1 && $tA['searches'] === 2 && $tA['clickers'] === 1 && $tA['clicks'] === 1
        && $tA['searchers_2_3'] === 1, json_encode($tA));
    check('audience : aucune IP ni user-agent stocké', !str_contains(json_encode(DeepFind\Db::all('SELECT * FROM audience_visitors')), '127.0.0.1')
        && !str_contains(json_encode(DeepFind\Db::all('SELECT * FROM audience_visitors')), 'Mozilla'));

    // interrupteurs par type : pages vues coupées → plus de signal accepté, ni annoncé au widget
    DeepFind\Settings::set('audience_pageviews', '0');
    $post('/api/v1/visit', ['key' => $key, 'lang' => 'fr', 'd' => 'd'], 'Mozilla/5.0 Autre navigateur');
    [, $cfB] = $get("/api/v1/config?key=$key&lang=fr");
    usleep(300000);
    check('audience : pages vues désactivées', ($cfB['audience'] ?? null) === false && $AU::summary(date('Y-m-d'), date('Y-m-d'))['total']['visitors'] === 2);
    DeepFind\Settings::set('audience_pageviews', '1');
    DeepFind\Settings::set('audience_pages', 'paths');
    DeepFind\Settings::set('audience_paths', "/fr/plongee");
    [, $cfC] = $get("/api/v1/config?key=$key&lang=fr");
    check('audience : pages choisies envoyées au widget', ($cfC['audience']['pages'] ?? '') === 'paths' && ($cfC['audience']['paths'] ?? []) === ['/fr/plongee'], json_encode($cfC['audience'] ?? null));
    DeepFind\Settings::set('audience_pages', 'all');
    DeepFind\Settings::set('audience_paths', '');

    // agrégation : la veille passe en totaux quotidiens ; empreintes et sel de la veille supprimés
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $AU::record('pageview', '198.51.100.1', 'Mozilla/5.0 (iPhone) Mobile', '', $yesterday);
    $AU::record('search', '198.51.100.1', 'Mozilla/5.0 (iPhone) Mobile', '', $yesterday);
    $AU::record('pageview', '198.51.100.2', 'Mozilla/5.0 (Windows NT 10.0)', '', $yesterday);
    $before = $AU::summary($yesterday, $yesterday)['total'];
    $nAgg = $AU::aggregate();
    $after = $AU::summary($yesterday, date('Y-m-d'));
    check('audience : agrégation quotidienne puis suppression des empreintes et du sel', $nAgg === 1 && $before['visitors'] === 2
        && $after['days'][$yesterday]['visitors'] === 2 && $after['days'][$yesterday]['searchers'] === 1 && $after['devices']['mobile']['visitors'] === 2
        && (int) DeepFind\Db::value('SELECT COUNT(*) FROM audience_visitors WHERE day < CURDATE()') === 0
        && (int) DeepFind\Db::value('SELECT COUNT(*) FROM audience_salts WHERE day < CURDATE()') === 0
        && (int) DeepFind\Db::value('SELECT COUNT(*) FROM audience_visitors WHERE day = CURDATE()') === 2, json_encode([$nAgg, $before, $after['total']]));
    check('audience : nouvelle agrégation sans jour passé = rien à faire', $AU::aggregate() === 0);
    $AC = DeepFind\Admin\AudienceController::class;
    $todayA = date('Y-m-d');
    $pA = $AC::period(['from' => '2026-03-10', 'to' => '2026-03-01']);
    $pB = $AC::period(['from' => '2000-01-01', 'to' => '2999-01-01']);
    $pC = $AC::period(['from' => '2026-02-30', 'to' => '2026-03-01', 'days' => '90']);
    check('audience : période personnalisée (ordre, pas après aujourd\'hui, 2 ans au plus, date invalide → bouton)',
        $pA['custom'] && $pA['from'] === '2026-03-01' && $pA['to'] === min('2026-03-10', $todayA) && $pA['query'] === ['from' => $pA['from'], 'to' => $pA['to']]
        && $pB['to'] === $todayA && (strtotime($pB['to']) - strtotime($pB['from'])) / 86400 <= $AC::MAX_CUSTOM_DAYS
        && !$pC['custom'] && $pC['days'] === 90 && $pC['to'] === $todayA, json_encode([$pA, $pB, $pC]));

    // ------------------------------------------------ Flux protégé par mot de passe (.htaccess) et test de connexion
    $SE = DeepFind\Security\Secrets::class;
    $encA = $SE::encrypt('s3cret');
    check('flux : mot de passe chiffré (illisible sans APP_SECRET, valeur abîmée refusée)', $SE::decrypt($encA) === 's3cret' && $encA !== $SE::encrypt('s3cret')
        && !str_contains($encA, 's3cret') && $SE::decrypt($encA, 'autre-secret') === null && $SE::decrypt('v1:' . base64_encode('abîmé')) === null && $SE::decrypt(null) === null);
    $feedSrv = proc_open(['php', '-S', '127.0.0.1:7797', __DIR__ . '/fake-feed.php'], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $feedPipes);
    usleep(400000);
    $FF = DeepFind\Import\FeedFetcher::class;
    $fb = 'http://127.0.0.1:7797';
    $rOpen = $FF::check([], "$fb/open/feed.xml");
    check('flux : test de connexion, fichier libre (HTTP 200, XML, taille)', $rOpen['ok'] && $rOpen['status'] === 200 && !$rOpen['protected']
        && in_array('Contenu : XML.', $rOpen['details'], true) && (bool) preg_grep('/^Taille : /', $rOpen['details']), json_encode($rOpen));
    $rNoPw = $FF::check([], "$fb/protected/feed.xml");
    check('flux : protégé sans identifiants → 401 expliqué', !$rNoPw['ok'] && $rNoPw['status'] === 401 && $rNoPw['protected'] && str_contains($rNoPw['message'], 'renseignez'), json_encode($rNoPw));
    $rBad = $FF::check(['auth_user' => 'flux', 'auth_password_plain' => 'faux'], "$fb/protected/feed.xml");
    check('flux : mauvais mot de passe → refusé', !$rBad['ok'] && $rBad['status'] === 401 && str_contains($rBad['message'], 'refusé'), json_encode($rBad));
    $rGood = $FF::check(['auth_user' => 'flux', 'auth_password' => $encA], "$fb/protected/feed.xml");
    check('flux : bons identifiants (mot de passe chiffré en base) → 200, protection acceptée', $rGood['ok'] && $rGood['status'] === 200
        && str_contains($rGood['message'], 'protection par mot de passe acceptée') && (bool) preg_grep('/^Taille : /', $rGood['details']), json_encode($rGood));
    $rUseless = $FF::check(['auth_user' => 'flux', 'auth_password_plain' => 'x'], "$fb/open/feed.xml");
    check('flux : identifiants sur un flux non protégé signalés', $rUseless['ok'] && str_contains($rUseless['message'], 'pas demandé de mot de passe'), json_encode($rUseless));
    $rHtml = $FF::check([], "$fb/open/page.html");
    $rGz = $FF::check([], "$fb/open/feed.xml.gz");
    $r404 = $FF::check([], "$fb/absent.xml");
    $rRedir = $FF::check([], "$fb/redirect");
    $rDown = $FF::check([], 'http://127.0.0.1:7796/feed.xml');
    $rLocal = $FF::check([], 'samples/products_fr.xml');
    check('flux : page HTML, .gz, 404, redirection, serveur injoignable, fichier local',
        !$rHtml['ok'] && (bool) preg_grep('/page HTML/', $rHtml['details']) && $rGz['ok'] && (bool) preg_grep('/compressé/', $rGz['details'])
        && $r404['status'] === 404 && str_contains($r404['message'], 'introuvable') && $rRedir['ok'] && (bool) preg_grep('/Redirigé vers/', $rRedir['details'])
        && !$rDown['ok'] && $rDown['status'] === 0 && str_contains($rDown['message'], 'injoignable') && $rLocal['ok'],
        json_encode([$rHtml['ok'], $rGz['details'], $r404['message'], $rRedir['details'], $rDown['message'], $rLocal['message']]));
    $dl = $FF::fetch(['code' => 'test_auth', 'url' => "$fb/protected/feed.xml", 'auth_user' => 'flux', 'auth_password' => $encA], 'full');
    check('flux : import d\'un flux protégé (téléchargement avec identifiants)', is_file($dl) && str_contains((string) file_get_contents($dl), '<products>'));
    try {
        $FF::fetch(['code' => 'test_auth', 'url' => "$fb/protected/feed.xml"], 'full');
        check('flux : import sans identifiants refusé et expliqué', false);
    } catch (RuntimeException $e) {
        check('flux : import sans identifiants refusé et expliqué', str_contains($e->getMessage(), 'HTTP 401') && str_contains($e->getMessage(), 'mot de passe'), $e->getMessage());
    }
    @unlink($dl);
    proc_terminate($feedSrv);

    // ------------------------------------------------ Règles selon la page (Recommandations › Règles)
    $PR = DeepFind\Rules\PageRules::class;
    check('règles de page : adresse normalisée (domaine, paramètres, majuscules, encodage)',
        $PR::normalizePath('https://www.example.com/FR/Nautisme/Kayak.html?x=1#y') === '/fr/nautisme/kayak.html'
        && $PR::normalizePath('fr/plong%C3%A9e') === '/fr/plongée');
    check('règles de page : comparaisons d\'adresse', $PR::urlMatches(['match' => 'contains', 'text' => '/nautisme/'], '/fr/nautisme/kayak.html')
        && $PR::urlMatches(['match' => 'starts', 'text' => '/fr/NAUT'], '/fr/nautisme') && $PR::urlMatches(['match' => 'ends', 'text' => '.html'], '/a/b.html')
        && $PR::urlMatches(['match' => 'equals', 'text' => '/fr/nautisme/'], '/fr/nautisme') && !$PR::urlMatches(['match' => 'equals', 'text' => '/fr/nautisme'], '/fr/nautisme/kayak')
        && !$PR::urlMatches(['match' => 'contains', 'text' => ''], '/x'));
    [$prBad, $prErr] = $PR::sanitize(['name' => '', 'urls' => [['match' => 'contains', 'text' => '  ']], 'conditions' => []], 'fr');
    check('règles de page : nom, adresse et condition obligatoires', $prBad === null && count($prErr) === 3, json_encode($prErr));
    [$prOk] = $PR::sanitize(['name' => 'C4', 'active' => true, 'action' => 'prefilter', 'label' => 'Marque C4', 'match' => 'all',
        'urls' => [['match' => 'contains', 'text' => '/c4/'], ['match' => 'contains', 'text' => 'https://www.example.com/fr/marque-c4.html']],
        'conditions' => [['field' => 'brand', 'op' => 'in', 'values' => ['C4']]]], 'fr');
    check('règles de page : adresse complète réduite au chemin', $prOk !== null && $prOk['urls'][1]['text'] === '/fr/marque-c4.html', json_encode($prOk));
    DeepFind\Db::exec('DELETE FROM page_rules');
    DeepFind\Settings::set('same_query_per_minute', '500'); // même recherche répétée : pas de refus anti-robots ici
    $idC4 = $PR::save('fr', $prOk, null);
    $idAll = $PR::save('fr', ['name' => 'Tout sauf C4', 'active' => true, 'action' => 'exclude', 'label' => '', 'match_all' => true,
        'urls' => [['match' => 'starts', 'text' => '/']], 'conditions' => [['field' => 'brand', 'op' => 'in', 'values' => ['C4']]]], null);
    check('règles de page : la 1re règle de la liste qui correspond s\'applique', ($PR::match('fr', '/fr/c4/palmes.html')['id'] ?? 0) === $idC4
        && ($PR::match('fr', '/fr/autre.html')['id'] ?? 0) === $idAll && $PR::match('fr', '') === null);
    [, $dAll] = $get("/api/v1/search?key=$key&q=palme&limit=48&posts=0");
    [, $dPre] = $get("/api/v1/search?key=$key&q=palme&limit=48&posts=0&page=" . rawurlencode('/fr/c4/palmes.html'));
    $brandsPre = array_values(array_unique(array_column($dPre['products'] ?? [], 'brand')));
    check('règles de page : filtre pré-coché (seule la marque C4, bandeau envoyé au widget)', $brandsPre === ['C4'] && $dPre['total'] < $dAll['total']
        && ($dPre['page_filter']['label'] ?? '') === 'Marque C4' && ($dPre['page_filter']['off'] ?? true) === false
        && !array_key_exists('page_filter', $dAll) || $dAll['page_filter'] === null, json_encode([$brandsPre, $dPre['total'] ?? null, $dAll['total'] ?? null, $dPre['page_filter'] ?? null]));
    $brandFacet = array_values(array_filter($dPre['facets'] ?? [], static fn ($f) => $f['code'] === 'brand'))[0]['values'] ?? [];
    check('règles de page : filtres du widget limités aux produits de la règle', array_column($brandFacet, 'value') === ['C4'], json_encode(array_column($dPre['facets'] ?? [], 'code')) . json_encode(array_column($dAll['facets'] ?? [], 'code')));
    [, $dOff] = $get("/api/v1/search?key=$key&q=palme&limit=48&posts=0&pr=0&page=" . rawurlencode('/fr/c4/palmes.html'));
    check('règles de page : filtre pré-coché retiré par le visiteur = tout le catalogue', $dOff['total'] === $dAll['total'] && ($dOff['page_filter']['off'] ?? false) === true);
    [, $dEx] = $get("/api/v1/search?key=$key&q=palme&limit=48&posts=0&page=" . rawurlencode('/fr/autre.html'));
    check('règles de page : exclusion (aucun produit C4)', !in_array('C4', array_column($dEx['products'] ?? [], 'brand'), true) && $dEx['total'] > 0
        && $dEx['total'] === $dAll['total'] - $dPre['total'] && ($dEx['page_filter'] ?? null) === null, json_encode([$dEx['total'] ?? null]));
    $PR::reorder('fr', [$idAll, $idC4]);
    $PR::setActive($idAll, 'fr', false);
    $onlyRule = $PR::find($idC4, 'fr');
    $onlyRule['action'] = 'only';
    $PR::save('fr', $onlyRule, $idC4);
    [, $dOnly] = $get("/api/v1/search?key=$key&q=palme&limit=48&posts=0&pr=0&page=" . rawurlencode('/fr/c4/palmes.html'));
    check('règles de page : « uniquement » ne peut pas être retiré ; règle inactive ignorée ; ordre modifiable',
        array_values(array_unique(array_column($dOnly['products'] ?? [], 'brand'))) === ['C4'] && ($dOnly['page_filter'] ?? null) === null
        && $PR::match('fr', '/fr/autre.html') === null && array_column($PR::all('fr'), 'id') === [$idAll, $idC4]);
    // « contient » / « ne contient pas » : valeurs du catalogue qui contiennent le texte (même au milieu d'un mot, sans accents)
    [$prC] = $PR::sanitize(['name' => 'Cressi', 'active' => true, 'action' => 'only', 'match' => 'all', 'urls' => [['match' => 'contains', 'text' => '/contient/']],
        'conditions' => [['field' => 'brand', 'op' => 'contains', 'values' => ['RÉSS']]]], 'fr');
    [$crC] = $CR::sanitizeRules([['match' => 'all', 'conditions' => [['field' => 'brand', 'op' => 'contains', 'values' => ['ress']], ['field' => 'in_stock', 'op' => 'contains', 'values' => ['1']]]]], 'fr', true);
    check('règles de page : « contient » enregistré (champ oui/non : « est »), refusé hors règles de page',
        $prC !== null && $prC['conditions'] === [['field' => 'brand', 'op' => 'contains', 'values' => ['RÉSS']]]
        && $crC[0]['conditions'][1]['op'] === 'in' && $CR::sanitizeRules([['match' => 'all', 'conditions' => [['field' => 'brand', 'op' => 'contains', 'values' => ['x']]]]], 'fr')[0][0]['conditions'][0]['op'] === 'in');
    $idCont = $PR::save('fr', $prC, null);
    $PR::reorder('fr', [$idCont]);
    [, $dCont] = $get("/api/v1/search?key=$key&q=palme&limit=48&posts=0&page=" . rawurlencode('/fr/contient/x.html'));
    $brandsCont = array_values(array_unique(array_column($dCont['products'] ?? [], 'brand')));
    $notRule = $PR::find($idCont, 'fr');
    $notRule['conditions'][0]['op'] = 'not_contains';
    $PR::save('fr', $notRule, $idCont);
    [, $dNot] = $get("/api/v1/search?key=$key&q=palme&limit=48&posts=0&page=" . rawurlencode('/fr/contient/x.html'));
    $noneRule = $PR::find($idCont, 'fr');
    $noneRule['conditions'][0] = ['field' => 'brand', 'op' => 'contains', 'values' => ['zzzz']];
    $PR::save('fr', $noneRule, $idCont);
    [, $dNone] = $get("/api/v1/search?key=$key&q=palme&limit=48&posts=0&page=" . rawurlencode('/fr/contient/x.html'));
    check('règles de page : contient / ne contient pas / aucun texte trouvé', $brandsCont === ['CRESSI'] && ($dNot['total'] ?? 0) > 0
        && !in_array('CRESSI', array_column($dNot['products'] ?? [], 'brand'), true) && ($dNone['total'] ?? -1) === 0,
        json_encode([$brandsCont, $dNot['total'] ?? null, $dNone['total'] ?? null]));
    DeepFind\Db::exec('DELETE FROM page_rules');
    DeepFind\Settings::set('same_query_per_minute', '15');
    DeepFind\CacheVersion::bump();

    // ------------------------------------------------ Popins (Recommandations › Popins)
    $PP = DeepFind\Rules\Popins::class;
    DeepFind\Db::exec('DELETE FROM popins');
    DeepFind\Db::exec('DELETE FROM popin_stats');
    [$ppBad, $ppErr] = $PP::sanitize(['name' => ' ', 'pages' => 'custom', 'urls' => [['match' => 'contains', 'text' => ' ']], 'mode' => 'visual', 'image_desktop' => '']);
    check('popins : nom, pages et image obligatoires', $ppBad === null && count($ppErr) === 3, json_encode($ppErr));
    [$ppA] = $PP::sanitize(['name' => 'Soldes', 'active' => true, 'pages' => 'custom', 'home' => true,
        'urls' => [['match' => 'contains', 'text' => 'https://www.example.com/fr/nautisme/?x=1'], ['match' => 'bizarre', 'text' => '/kayak']],
        'exclude_urls' => [['match' => 'contains', 'text' => '/checkout'], ['match' => 'contains', 'text' => '']],
        'device' => 'tablette', 'delay_seconds' => '9999', 'repeat_mode' => 'page', 'remember_days' => '-3', 'placement' => 'bottom_left', 'max_width' => '50',
        'mode' => 'visual', 'image_desktop' => 'https://cdn.example.com/a.jpg', 'link_url' => 'https://www.example.com/soldes', 'new_window' => true]);
    check('popins : valeurs nettoyées (adresse réduite au chemin, bornes, choix inconnus)', $ppA !== null && $ppA['urls'] === [['match' => 'contains', 'text' => '/fr/nautisme/'], ['match' => 'contains', 'text' => '/kayak']]
        && $ppA['exclude_urls'] === [['match' => 'contains', 'text' => '/checkout']] && $ppA['device'] === 'all' && $ppA['delay_seconds'] === 600
        && $ppA['remember_days'] === 0 && $ppA['max_width'] === 280 && $ppA['min_height'] === 0 && $ppA['placement'] === 'bottom_left' && $ppA['repeat_mode'] === 'page' && $ppA['home'] === true, json_encode($ppA));
    [$ppCode] = $PP::sanitize(['name' => 'HTML', 'active' => true, 'pages' => 'all', 'home' => true, 'urls' => [['match' => 'contains', 'text' => '/x']], 'mode' => 'code',
        'html' => '<a href="https://www.example.com" onclick="x()">Offre</a><script>alert(1)</script>']);
    check('popins : hauteur minimale bornée (0 à 1200 px)', $PP::sanitize(['name' => 'H', 'mode' => 'code', 'html' => '<p>x</p>', 'min_height' => '5000'])[0]['min_height'] === 1200
        && $PP::sanitize(['name' => 'H', 'mode' => 'code', 'html' => '<p>x</p>', 'min_height' => '250'])[0]['min_height'] === 250);
    check('popins : HTML nettoyé ; « toutes les pages » ignore accueil et adresses', $ppCode !== null && !str_contains($ppCode['html'], 'script') && !str_contains($ppCode['html'], 'onclick')
        && str_contains($ppCode['html'], 'Offre') && $ppCode['home'] === false && $ppCode['urls'] === [], json_encode($ppCode));
    $wyIn = '<h2 style="text-align: center;">Titre</h2><p><span style="color: rgb(228, 0, 58);">rouge</span> <b>gras</b></p>'
        . '<p style="text-align: center;"><a href="https://www.example.com" target="_blank" style="display: inline-block; padding: 12px 22px; background: #e4003a;">Bouton</a></p>'
        . '<p style="background: url(javascript:x)">x</p>';
    $wyOut = DeepFind\Security\HtmlSanitizer::clean($wyIn);
    check('éditeur visuel : mise en forme gardée (alignement, couleur, bouton), style dangereux retiré',
        str_contains($wyOut, '<h2 style="text-align: center;">') && str_contains($wyOut, 'color: rgb(228, 0, 58)') && str_contains($wyOut, 'rel="noopener"')
        && str_contains($wyOut, 'background: #e4003a') && !str_contains($wyOut, 'url('), $wyOut);
    $idPA = $PP::save('fr', $ppA, null);
    $idPC = $PP::save('fr', $ppCode, null);
    [$ppOld] = $PP::sanitize(['name' => 'Passée', 'active' => true, 'duration' => 'period', 'starts_at' => '2020-01-01', 'ends_at' => '2020-01-31',
        'mode' => 'visual', 'image_desktop' => 'https://cdn.example.com/b.jpg']);
    $idPO = $PP::save('fr', $ppOld, null);
    [$ppOff] = $PP::sanitize(['name' => 'Inactive', 'active' => false, 'mode' => 'visual', 'image_desktop' => 'https://cdn.example.com/c.jpg']);
    $idPI = $PP::save('fr', $ppOff, null);
    DeepFind\Db::insert('popins', ['project_id' => 999, 'lang' => 'fr', 'name' => 'Autre projet', 'urls' => '[]', 'exclude_urls' => '[]', 'html' => '',
        'image_desktop' => 'https://cdn.example.com/d.jpg', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
    DeepFind\CacheVersion::bump();
    [, $cfP] = $get("/api/v1/config?key=$key&lang=fr");
    $wp = $cfP['popins'] ?? [];
    check('popins : envoyées au widget (en cours, actives, du projet, dans l\'ordre), sans champ interne',
        array_column($wp, 'id') === [$idPA, $idPC] && ($wp[0]['exclude'][0]['text'] ?? '') === '/checkout' && ($wp[0]['link'] ?? '') === 'https://www.example.com/soldes'
        && ($wp[0]['mobile'] ?? '') === 'https://cdn.example.com/a.jpg' && !isset($wp[0]['name']) && !isset($wp[0]['starts_at']) && ($wp[1]['mode'] ?? '') === 'code'
        && !str_contains(json_encode($wp), 'Soldes'), json_encode($wp));
    $ppEvents = [[$idPA, 'v'], [$idPA, 'v'], [$idPA, 'c'], [$idPA, 'x'], [$idPC, 'v'], [$idPO, 'v'], [$idPI, 'v'], [424242, 'v'], [$idPA, 'z']];
    $ppCodes = [];
    foreach ($ppEvents as [$pid0, $ev0]) {
        $ppCodes[] = $post('/api/v1/popin', ['key' => $key, 'lang' => 'fr', 'id' => $pid0, 'e' => $ev0]);
    }
    $ppCodes[] = $post('/api/v1/popin', ['key' => $key, 'lang' => 'fr', 'id' => $idPA, 'e' => 'v', 'na' => '1']); // aperçu : non compté
    $ppCodes[] = $post('/api/v1/popin', ['key' => $key, 'lang' => 'fr', 'id' => $idPA, 'e' => 'v'], ''); // navigateur vide : robot
    usleep(400000);
    $ppSt = $PP::stats([$idPA, $idPC, $idPO, $idPI], 30);
    check('popins : affichages, clics, fermetures et CTR comptés (aperçu, robots, popins hors ligne et inconnues ignorés)',
        array_unique($ppCodes) === [204] && $ppSt[$idPA] === ['views' => 2, 'clicks' => 1, 'closes' => 1, 'ctr' => 50.0] && $ppSt[$idPC]['views'] === 1
        && $ppSt[$idPO]['views'] === 0 && $ppSt[$idPI]['views'] === 0 && (int) DeepFind\Db::value('SELECT COUNT(*) FROM popin_stats') === 2,
        json_encode([$ppCodes, $ppSt]));
    $PP::reorder('fr', [$idPC]);
    $revBefore = $PP::find($idPA, 'fr')['rev'];
    $PP::save('fr', ['reset_memory' => true] + $ppA, $idPA);
    $PP::save('fr', $ppA, $idPA);
    check('popins : ordre modifiable ; « reproposer aux visiteurs » change la version mémorisée',
        array_column($PP::forWidget('fr'), 'id') === [$idPC, $idPA] && $PP::find($idPA, 'fr')['rev'] === $revBefore + 1);
    $PP::delete($idPA, 'fr');
    check('popins : suppression (statistiques comprises)', $PP::find($idPA, 'fr') === null
        && (int) DeepFind\Db::value('SELECT COUNT(*) FROM popin_stats WHERE popin_id = ?', [$idPA]) === 0);
    DeepFind\Db::exec('DELETE FROM popins');
    DeepFind\Db::exec('DELETE FROM popin_stats');
    DeepFind\CacheVersion::bump();

    // ------------------------------------------------ Carrousels de produits (Recommandations › Carrousels)
    $CA = DeepFind\Rules\Carousels::class;
    foreach (['carousels', 'carousel_stats', 'carousel_product_stats'] as $t0) {
        DeepFind\Db::exec("DELETE FROM `$t0`");
    }
    DeepFind\Settings::set('carousel_personal', 'consent');
    [$caBad, $caErr] = $CA::sanitize(['name' => '', 'selector' => '', 'source' => 'manual', 'items' => []], 'fr');
    check('carrousels : nom, emplacement et produits (sélection manuelle) obligatoires', $caBad === null && count($caErr) === 3, json_encode($caErr));
    check('carrousels : sélecteur CSS contrôlé, CSS personnalisé nettoyé',
        $CA::selector('#home .bloc > div') === '#home .bloc > div' && $CA::selector('div{x}') === null && $CA::selector('a</style>') === null
        && $CA::css("@import url(x.css);\n.df-carousel{color:red}</style><script>") === ".df-carousel{color:red}script");
    $caDocs = DeepFind\Meili\MeiliClient::admin()->fetchDocuments(DeepFind\Config::productIndex('fr'), 'in_stock = 1', 200, 0,
        ['id', 'sku', 'brand', 'url', 'url_path', 'category_names', 'category_paths', 'biz_score', 'in_stock']);
    $caRef = array_values(array_filter($caDocs, static fn ($d) => in_array('Palmes réglables', $d['category_names'] ?? [], true)))[0] ?? null;
    check('carrousels : chemin de la page produit indexé (url_path)', $caRef !== null && ($caRef['url_path'] ?? '') === DeepFind\Rules\PageRules::normalizePath($caRef['url']),
        json_encode($caRef));
    $caMk = static fn (array $x) => array_merge(['active' => true, 'selector' => '#reco', 'insert_mode' => 'after', 'title' => 'Nos palmes', 'max_items' => 4, 'in_stock_only' => true, 'sort_by' => 'biz'], $x);
    [$caAuto] = $CA::sanitize($caMk(['name' => 'Auto C4', 'source' => 'auto', 'pages' => 'custom', 'home' => true,
        'urls' => [['match' => 'contains', 'text' => '/plongee/']], 'exclude_urls' => [['match' => 'contains', 'text' => '/checkout']],
        'conditions' => [['field' => 'brand', 'op' => 'in', 'values' => ['C4']]]]), 'fr');
    $idCaAuto = $CA::save('fr', $caAuto, null);
    $caA = $CA::products('fr', $CA::find($idCaAuto, 'fr'));
    $bizA = array_map(static fn ($p) => array_values(array_filter($caDocs, static fn ($d) => $d['id'] === $p['id']))[0]['biz_score'] ?? -1, $caA);
    $sortedBiz = $bizA;
    rsort($sortedBiz);
    check('carrousels : automatique (conditions, en stock, nombre, tri score métier, sans marge)', count($caA) > 0 && count($caA) <= 4
        && array_unique(array_column($caA, 'brand')) === ['C4'] && !in_array(false, array_column($caA, 'in_stock'), true) && $bizA === $sortedBiz
        && !str_contains(json_encode($caA), 'margin') && !str_contains(json_encode($caA), 'biz_score'), json_encode([$caA, $bizA]));
    check('carrousels : pages (accueil, adresse, exclusion)', $CA::pageMatches($CA::find($idCaAuto, 'fr'), '/fr/') && $CA::pageMatches($CA::find($idCaAuto, 'fr'), '/fr/plongee/x.html')
        && !$CA::pageMatches($CA::find($idCaAuto, 'fr'), '/fr/chasse.html') && !$CA::pageMatches($CA::find($idCaAuto, 'fr'), '/plongee/checkout/'));
    $caSkus = array_slice(array_column(array_values(array_filter($caDocs, static fn ($d) => ($d['brand'] ?? '') !== 'C4')), 'sku'), 0, 2);
    [$caMan] = $CA::sanitize($caMk(['name' => 'Manuel', 'source' => 'manual', 'items' => [$caSkus[1], $caSkus[0], 'INCONNU-1']]), 'fr');
    $caM = $CA::products('fr', $caMan + ['id' => 0], null, null, false);
    [$caPin] = $CA::sanitize($caMk(['name' => 'Épinglés + auto', 'source' => 'auto', 'items' => [$caSkus[0]],
        'conditions' => [['field' => 'brand', 'op' => 'in', 'values' => ['C4']]]]), 'fr');
    $caP = $CA::products('fr', $caPin + ['id' => 0], null, null, false);
    check('carrousels : sélection manuelle dans l\'ordre (produit inconnu ignoré) ; épinglés en tête puis automatique',
        array_column($caM, 'sku') === [$caSkus[1], $caSkus[0]] && ($caP[0]['sku'] ?? '') === $caSkus[0] && count($caP) === 4
        && array_unique(array_column(array_slice($caP, 1), 'brand')) === ['C4'], json_encode([array_column($caM, 'sku'), array_column($caP, 'sku')]));
    [$caSim] = $CA::sanitize($caMk(['name' => 'Similaires', 'source' => 'similar', 'similar_by' => 'category', 'max_items' => 8]), 'fr');
    $idCaSim = $CA::save('fr', $caSim, null);
    $fp = $CA::forPage('fr', ['page' => $caRef['url_path'], 'history' => null]);
    $simC = array_values(array_filter($fp['carousels'], static fn ($c) => $c['id'] === $idCaSim))[0] ?? null;
    $simCats = array_map(static fn ($p) => array_values(array_filter($caDocs, static fn ($d) => $d['id'] === $p['id']))[0]['category_names'] ?? [], $simC['products'] ?? []);
    check('carrousels : similaires (même catégorie précise, sans le produit de la page)', $fp['page_product'] === $caRef['id'] && $simC !== null
        && count($simC['products']) > 0 && !in_array($caRef['id'], array_column($simC['products'], 'id'), true)
        && array_filter($simCats, static fn ($cats) => !in_array('Palmes réglables', $cats, true)) === [] && ($simC['selector'] ?? '') === '#reco'
        && !isset($simC['name']), json_encode([$fp['page_product'], $simC]));
    $fpHome = $CA::forPage('fr', ['page' => '/', 'history' => null]);
    $simHome = array_values(array_filter($fpHome['carousels'], static fn ($c) => $c['id'] === $idCaSim))[0] ?? null;
    check('carrousels : similaires sans produit dans la page → sélection automatique', $fpHome['page_product'] === null && $simHome !== null && count($simHome['products']) === 8);
    [$caPer] = $CA::sanitize($caMk(['name' => 'Vus récemment', 'source' => 'personal', 'personal_mode' => 'recent', 'max_items' => 6]), 'fr');
    $idCaPer = $CA::save('fr', $caPer, null);
    $hist = array_slice(array_column($caDocs, 'id'), 3, 3);
    $perC = static fn ($fp) => array_values(array_filter($fp['carousels'], static fn ($c) => $c['id'] === $idCaPer))[0]['products'] ?? [];
    $withHist = $perC($CA::forPage('fr', ['page' => '/', 'history' => [...$hist, 'bad id!']]));
    DeepFind\Settings::set('carousel_personal', 'off');
    $off = $perC($CA::forPage('fr', ['page' => '/', 'history' => $hist]));
    DeepFind\Settings::set('carousel_personal', 'consent');
    [$caRec] = $CA::sanitize($caMk(['name' => 'Pour vous', 'source' => 'personal', 'personal_mode' => 'recommended', 'max_items' => 6]), 'fr');
    $recP = $CA::products('fr', $caRec + ['id' => 0], null, [$caRef['id']], false);
    check('carrousels : personnalisé (vus récemment dans l\'ordre ; « jamais » = sélection automatique ; vous aimerez aussi sans les produits vus)',
        array_column($withHist, 'id') === $hist && array_column($off, 'id') !== $hist && count($off) === 6
        && count($recP) > 0 && !in_array($caRef['id'], array_column($recP, 'id'), true), json_encode([array_column($withHist, 'id'), $hist, array_column($recP, 'id')]));
    // API : configuration, carrousels de la page, événements, apprentissage
    DeepFind\CacheVersion::bump();
    [, $cfC] = $get("/api/v1/config?key=$key&lang=fr");
    [$cC, $dC] = $get("/api/v1/carousels?key=$key&lang=fr&page=" . rawurlencode('/fr/plongee/palmes.html'));
    $apiAuto = array_values(array_filter($dC['carousels'] ?? [], static fn ($c) => $c['id'] === $idCaAuto))[0] ?? null;
    check('carrousels : API (configuration, carrousels de la page, produits publics uniquement)', ($cfC['carousels'] ?? null) === true && ($cfC['personal'] ?? '') === 'consent'
        && $cC === 200 && $apiAuto !== null && count($apiAuto['products']) > 0 && !str_contains(json_encode($dC), 'margin') && array_key_exists('page_product', $dC) && $dC['page_product'] === null,
        json_encode([$cfC['carousels'] ?? null, $cC, $dC]));
    $learnIds = array_column($CA::products('fr', $CA::find($idCaAuto, 'fr')), 'id');
    $lastId = end($learnIds);
    $evCodes = [];
    for ($i = 0; $i < 3; $i++) {
        $evCodes[] = $post('/api/v1/carousel', ['key' => $key, 'lang' => 'fr', 'id' => $idCaAuto, 'e' => 'v', 'p' => $learnIds]);
    }
    for ($i = 0; $i < 3; $i++) {
        $evCodes[] = $post('/api/v1/carousel', ['key' => $key, 'lang' => 'fr', 'id' => $idCaAuto, 'e' => 'c', 'p' => [$lastId]]);
    }
    $evCodes[] = $post('/api/v1/carousel', ['key' => $key, 'lang' => 'fr', 'id' => 99999, 'e' => 'v', 'p' => $learnIds]);
    $evCodes[] = $post('/api/v1/carousel', ['key' => $key, 'lang' => 'fr', 'id' => $idCaAuto, 'e' => 'v', 'p' => $learnIds, 'na' => '1']);
    usleep(400000);
    $caSt = $CA::stats([$idCaAuto], 30)[$idCaAuto];
    $ps = $CA::productStats($idCaAuto, [$lastId, $learnIds[0]], 30);
    $learnRow = $CA::find($idCaAuto, 'fr');
    $learnRow['learning'] = true;
    $learned = array_column($CA::products('fr', $learnRow, null, null, false), 'id');
    check('carrousels : vues et clics comptés (par carrousel et par produit), carrousel inconnu et aperçu ignorés',
        array_unique($evCodes) === [204] && $caSt === ['views' => 3, 'clicks' => 3, 'ctr' => 100.0] && ($ps[$lastId] ?? null) === ['views' => 3, 'clicks' => 3]
        && ($ps[$learnIds[0]]['clicks'] ?? -1) === 0, json_encode([$evCodes, $caSt, $ps]));
    check('carrousels : apprentissage (le produit le plus cliqué remonte en tête)', count($learnIds) > 1 && ($learned[0] ?? '') === $lastId, json_encode([$learnIds, $learned]));
    $CA::delete($idCaAuto, 'fr');
    check('carrousels : suppression (statistiques comprises)', $CA::find($idCaAuto, 'fr') === null
        && (int) DeepFind\Db::value('SELECT COUNT(*) FROM carousel_product_stats WHERE carousel_id = ?', [$idCaAuto]) === 0);
    foreach (['carousels', 'carousel_stats', 'carousel_product_stats'] as $t0) {
        DeepFind\Db::exec("DELETE FROM `$t0`");
    }
    DeepFind\CacheVersion::bump();

    // Photo au survol d'un produit (Paramètres › Widget sur le site)
    [, $cfH1] = $get("/api/v1/config?key=$key&lang=fr");
    DeepFind\Settings::set('widget_hover_image', '0');
    [, $cfH0] = $get("/api/v1/config?key=$key&lang=fr");
    DeepFind\Settings::set('widget_hover_image', '1');
    check('widget : photo au survol activée par défaut, désactivable', ($cfH1['hover_image'] ?? null) === true && ($cfH0['hover_image'] ?? null) === false);

    [$c, $d] = $get('/api/v1/health');
    check('API : santé', $c === 200 && $d['status'] === 'ok');
}

echo "\n" . $passed . ' réussi(s), ' . count($failed) . " échec(s)\n";
exit($failed === [] ? 0 : 1);
