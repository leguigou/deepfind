# Ciblage selon la provenance

Les **popins** et les **carrousels** peuvent ne s'afficher que pour les visiteurs arrivés par certains canaux : par exemple une popin « -10 % » seulement pour les abonnés de la newsletter, un carrousel « Nos meilleures ventes » seulement pour les visiteurs venus des réseaux sociaux.

## Réglage

Dans la fiche, section *1. Pages concernées*, bloc **Provenance du visiteur** :

- **Canaux** (aucune case cochée = tout le monde) :

| Canal | Reconnu par |
|---|---|
| Accès direct | ni paramètre de campagne, ni site d'origine (adresse tapée, favori, application) |
| Moteurs de recherche | site d'origine Google, Bing, Qwant, DuckDuckGo, Ecosia… ; ou `utm_source=google` sans `utm_medium` |
| Publicité | identifiant de clic publicitaire dans l'adresse (`gclid`, `fbclid`, `msclkid`, `ttclid`…) ou `utm_medium` = `cpc`, `ppc`, `paid`, `display`… |
| Réseaux sociaux | Facebook, Instagram, TikTok, Pinterest, YouTube, LinkedIn, X… (site d'origine ou `utm_source`), ou `utm_medium=social` |
| E-mail et newsletter | `utm_medium` = `email`, `newsletter`… ; `utm_source` Brevo, Mailchimp, Klaviyo ; webmail d'origine |
| Affiliation et partenaires | `utm_medium` = `affiliate`, `partenaire`… |
| Autres sites | lien depuis un autre site |
| Autre campagne UTM | `utm_source` présent mais canal non reconnu |

- **Conditions UTM** (facultatives, toutes doivent être remplies) : `utm_source`, `utm_medium` ou `utm_campaign` *contient*, *est* ou *ne contient pas* un texte (majuscules ignorées). Ex. `utm_campaign` contient `soldes`.

## Fonctionnement

- Le widget lit la provenance sur la **page d'arrivée** du visiteur (paramètres de l'adresse, site d'origine). Rien n'est stocké sur l'appareil.
- Le serveur la garde **en mémoire pour la journée**, sous l'empreinte du jour du visiteur (comme l'[audience](../analyse/audience)) : les pages suivantes de la visite gardent le même ciblage. Elle n'est jamais écrite en base.
- Un visiteur qui revient le lendemain sans paramètre de campagne est considéré comme « accès direct ».
- Les pages exclues et les appareils restent appliqués en plus du ciblage.

## Détails techniques

- Code : `src/Rules/Segments.php` (canaux, règles), colonnes `segments` (JSON `{channels, utm}`) des tables `popins` et `carousels`.
- Le widget envoie la provenance (`src_s`, `src_m`, `src_c`, `src_r`, `src_ad`) avec `/api/v1/config`, la page vue et `/api/v1/carousels`. `/api/v1/config` renvoie `segments` (un ciblage est utilisé) et `segment` (canal et UTM du visiteur) ; le widget choisit les popins, le serveur filtre les carrousels.
