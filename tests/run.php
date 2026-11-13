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
$key = ['allowed_origins' => 'cabesto.com'];
check('origine autorisée (sous-domaine)', ApiKeys::checkOrigin($key, ['HTTP_ORIGIN' => 'https://www.cabesto.com', 'HTTP_HOST' => 'search.x']) === 'https://www.cabesto.com');
check('origine refusée', ApiKeys::checkOrigin($key, ['HTTP_ORIGIN' => 'https://cabesto.com.evil.io', 'HTTP_HOST' => 'search.x']) === null);
check('origine refusée sans en-tête', ApiKeys::checkOrigin($key, ['HTTP_HOST' => 'search.x']) === null);
check('même domaine sans Origin (Referer) accepté', ApiKeys::checkOrigin($key, ['HTTP_REFERER' => 'https://search.x/admin/demo', 'HTTP_HOST' => 'search.x']) === '');

// Planification (cron)
use DeepFind\Import\Cron;
check('cron : expression valide / invalide', Cron::isValid('*/15 6-23 * * 1-5') && !Cron::isValid('61 * * * *') && !Cron::isValid('* * *'));
check('cron : correspondance', Cron::matches('*/15 6-23 * * *', new DateTimeImmutable('2026-11-12 23:45')) && !Cron::matches('*/15 6-23 * * *', new DateTimeImmutable('2026-11-12 05:45')));
check('cron : dimanche = 0 ou 7', Cron::matches('0 9 * * 7', new DateTimeImmutable('2026-11-15 09:00')));
$nx = Cron::nextRuns('15 3 * * *', 2, new DateTimeImmutable('2026-11-12 13:20'));
check('cron : prochaines exécutions', count($nx) === 2 && $nx[0]->format('Y-m-d H:i') === '2026-11-13 03:15');

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
    check('API : redirection', ($d['redirect'] ?? '') === 'https://www.example.com/cgv#retours');
    [, $d] = $get("/api/v1/search?key=$key&q=nageoire");
    check('API : synonymes', $d['total'] > 0);
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
    DeepFind\Db::exec("INSERT INTO product_boosts (project_id, lang, sku, boost, starts_at, ends_at, note, created_at) VALUES (1, 'fr', 'seac-x-vision', 10, NULL, '2099-12-31', 'salon', NOW())");
    $nMig = DeepFind\Rules\BoostRules::migrateProductBoosts();
    $mig = DeepFind\Rules\BoostRules::productRule('fr', 'SEAC-X-VISION');
    check('migration : boost produit +10 converti en règle ×2 (dates gardées)', $nMig === 1 && $mig !== null && abs($mig['boost'] - 2.0) < 0.001 && $mig['ends_at'] === '2099-12-31'
        && (int) DeepFind\Db::value('SELECT COUNT(*) FROM product_boosts') === 0);
    DeepFind\Rules\BoostRules::setProductBoost('fr', 'SEAC-X-VISION', 1.0);
    check('boost rapide : coefficient 1 = règle supprimée', DeepFind\Rules\BoostRules::productRule('fr', 'SEAC-X-VISION') === null);
    DeepFind\Rules\BoostRules::setProductBoost('fr', 'SEAC-X-VISION', 3);
    DeepFind\Rules\BoostRules::setProductBoost('fr', 'SEAC-X-VISION', 5);
    check('boost rapide : une seule règle par produit, modifiée', (int) DeepFind\Db::value("SELECT COUNT(*) FROM boost_rules WHERE name = 'Produit SEAC-X-VISION'") === 1
        && abs(DeepFind\Rules\BoostRules::productBoosts('fr')['SEAC-X-VISION'] - 5.0) < 0.001);
    check('période : programmée / terminée / en cours', DeepFind\Rules\BoostRules::period(['starts_at' => '2099-01-01', 'ends_at' => null]) === 'scheduled'
        && DeepFind\Rules\BoostRules::period(['starts_at' => null, 'ends_at' => '2000-01-01']) === 'expired'
        && DeepFind\Rules\BoostRules::period(['starts_at' => '2000-01-01', 'ends_at' => '2099-01-01']) === 'current');
    DeepFind\Db::exec("UPDATE boost_rules SET ends_at = '2000-01-01'");
    DeepFind\CacheVersion::bump();
    check('période : règle terminée non appliquée', DeepFind\Rules\BoostRules::active('fr') === []);
    [, $errDates] = DeepFind\Rules\BoostRules::sanitize(['name' => 'x', 'boost' => 2, 'starts_at' => '2026-05-10', 'ends_at' => '2026-05-01',
        'conditions' => [['field' => 'brand', 'values' => ['C4']]]], 'fr');
    check('période : fin avant début refusée', $errDates !== []);
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
    $testId = DeepFind\Project::create('test', 'Cabesto Test');
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
        DeepFind\Rules\RulesRepository::addRedirect('fr', 'bonjour test', 'https://www.example.com/test');
        DeepFind\Rules\RulesRepository::bumpVersion();
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
        && DeepFind\Db::value("SELECT COUNT(*) FROM redirects WHERE project_id = ? AND query = 'bonjour test'", [$prodId]) == 0);
    file_put_contents(APP_ROOT . '/tests/_tmp/test_project', $testId . ' ' . $testKey);

    [$c, $d] = $get('/api/v1/health');
    check('API : santé', $c === 200 && $d['status'] === 'ok');
}

echo "\n" . $passed . ' réussi(s), ' . count($failed) . " échec(s)\n";
exit($failed === [] ? 0 : 1);
