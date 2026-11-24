# Sécurité

## Règles appliquées partout

- **Marge et score interne** jamais exposés par l'API publique (liste blanche `SearchService::PUBLIC_FIELDS`, vérifiée par un test).
- **Import complet** sans risque pour la recherche en ligne (index temporaire, contrôle de baisse, bascule).
- **Anti-robots** sans écriture en base par requête (compteurs en mémoire).
- **Adresses IP** jamais stockées en clair (empreinte HMAC avec `APP_SECRET`, IP tronquée pour l'affichage).
- **Extranet** : jeton CSRF sur chaque envoi de formulaire, sortie échappée, politique de sécurité (CSP) sans JavaScript en ligne ; pages interdites en cadre (sauf l'aperçu direct, dans l'extranet seulement).
- **Widget** : éléments créés avec `textContent` ; adresses limitées à http(s) ; HTML des bannières et popins nettoyé à l'enregistrement (`HtmlSanitizer`) puis refiltré par le widget ; modèles de carte et liens validés.
- **Secrets** (`.env`, clés Meilisearch, jeton d'administration) jamais dans le dépôt, les journaux ou les réponses.
- **Aide (wiki)** : réservée aux personnes connectées ; le Markdown est converti sans accepter de HTML ; une adresse n'ouvre qu'un fichier trouvé dans `docs/wiki/`.

## Accès à l'extranet

Un **jeton unique** (`ADMIN_TOKEN`), sans comptes utilisateurs. Conseillé : limiter `/admin` (aide comprise) au réseau de l'entreprise ou au VPN (exemple dans `deploy/apache-vhost.conf`).
