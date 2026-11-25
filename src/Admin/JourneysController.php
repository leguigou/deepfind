<?php

declare(strict_types=1);

namespace DeepFind\Admin;

use DeepFind\Logging\Journeys;

/** Analyse › Parcours : visiteurs qui ont cherché ou cliqué sur un carrousel (empreinte du jour), et leur chronologie. */
final class JourneysController
{
    public function index(): void
    {
        $filter = (string) ($_GET['f'] ?? 'all');
        $filter = isset(Journeys::FILTERS[$filter]) ? $filter : 'all';
        $q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100, 'UTF-8');
        $days = Journeys::days();
        $day = (string) ($_GET['day'] ?? '');
        $day = in_array($day, $days, true) ? $day : '';
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $list = Journeys::list($filter, $q, $day, $page);
        View::render('journeys', [
            'active' => 'journeys', 'enabled' => Journeys::enabled(), 'filter' => $filter, 'q' => $q, 'day' => $day, 'days' => $days,
            'page' => $page, 'rows' => $list['rows'], 'total' => $list['total'],
        ], 'Parcours');
    }

    public function view(): void
    {
        $found = Journeys::find((int) ($_GET['id'] ?? 0));
        if ($found === null) {
            http_response_code(404);
            View::render('error', ['message' => 'Parcours introuvable : il a peut-être été supprimé (conservation ' . Journeys::RETENTION_HOURS . ' h).'], 'Parcours');
            return;
        }
        View::render('journey', ['active' => 'journeys', 'j' => $found['journey'], 'events' => $found['events'], 'back' => (string) ($_GET['back'] ?? '')],
            $found['journey']['label']);
    }
}
