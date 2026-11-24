# Statistiques et API des carrousels

## Mesures

| Événement (`POST /api/v1/carousel`) | Envoyé quand | Compté dans |
|---|---|---|
| `e=v` + produits visibles | le carrousel est visible au moins à moitié (une fois par page) | vues du carrousel, affichages des produits |
| `e=p` + produits | d'autres produits deviennent visibles en faisant défiler | affichages des produits |
| `e=c` + produit | clic sur un produit | clics du carrousel et du produit |

Tables : `carousel_stats` (par carrousel et par jour), `carousel_product_stats` (par produit et par jour, purgée avec les journaux, 30 jours minimum). Rien n'est compté en aperçu ni sur la page Démo. Les robots et IP bloquées ne sont pas comptés.

## Demande des carrousels de la page

`GET /api/v1/carousels?key=…&lang=fr&page=/chemin[&sku=…][&h=id1,id2]`

- Le widget ne fait cette demande que si `/api/v1/config` indique `carousels: true`.
- `h` (historique) n'est envoyé qu'avec le consentement ; la réponse est alors `no-store`.
- Réponse : `carousels` (emplacement, titre, affichage, produits publics) et `page_product` (identifiant du produit de la page, pour l'historique).

## Code

`src/Rules/Carousels.php` (règles, sources, apprentissage, statistiques), `src/Admin/CarouselsController.php`, `templates/admin/carousels.php` et `carousel_edit.php`, `public/assets/carousels.js` ; affichage dans `public/widget/deepfind.js` (`renderCarousel`).
