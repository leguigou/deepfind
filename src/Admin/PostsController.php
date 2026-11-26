<?php

declare(strict_types=1);

namespace DeepFind\Admin;

use DeepFind\Config;
use DeepFind\Meili\MeiliClient;
use DeepFind\Search\Filter;

/** Catalogues › Articles de blog : articles importés (index du blog), recherche, catégorie, du plus récent au plus ancien. */
final class PostsController
{
    private const PER_PAGE = 50;

    public function index(): void
    {
        $lang = Auth::lang();
        $q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100, 'UTF-8');
        $cat = mb_substr(trim((string) ($_GET['cat'] ?? '')), 0, 190, 'UTF-8');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $rows = [];
        $total = 0;
        $all = 0;
        $cats = [];
        $error = null;
        try {
            $search = ['indexUid' => Config::postIndex($lang), 'q' => $q, 'limit' => self::PER_PAGE, 'offset' => ($page - 1) * self::PER_PAGE,
                'attributesToRetrieve' => ['id', 'title', 'url', 'image', 'excerpt', 'categories', 'tags', 'author', 'published_ts', 'updated_ts'],
                'facets' => ['categories']];
            if ($cat !== '') {
                $search['filter'] = [Filter::in('categories', [$cat])];
            }
            if ($q === '') {
                $search['sort'] = ['published_ts:desc'];
            }
            $res = MeiliClient::search()->multiSearch([$search, ['indexUid' => Config::postIndex($lang), 'q' => '', 'limit' => 0, 'facets' => ['categories']]]);
            $rows = $res[0]['hits'] ?? [];
            $total = (int) ($res[0]['estimatedTotalHits'] ?? $res[0]['totalHits'] ?? count($rows));
            $all = (int) ($res[1]['estimatedTotalHits'] ?? $res[1]['totalHits'] ?? 0);
            $cats = (array) ($res[1]['facetDistribution']['categories'] ?? []);
            ksort($cats, SORT_NATURAL | SORT_FLAG_CASE);
        } catch (\Throwable $e) {
            $error = str_contains($e->getMessage(), 'not found') || str_contains($e->getMessage(), 'index_not_found')
                ? 'Aucun article de blog importé pour cette langue : ajoutez un flux « Blog » dans Flux & mapping, puis lancez un import complet.'
                : 'Moteur indisponible : ' . $e->getMessage();
        }
        View::render('posts', ['active' => 'posts', 'rows' => $rows, 'total' => $total, 'all' => $all, 'cats' => $cats, 'q' => $q, 'cat' => $cat,
            'page' => $page, 'perPage' => self::PER_PAGE, 'error' => $error, 'lang' => $lang], 'Articles de blog');
    }
}
