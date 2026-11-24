# Statistiques et journal des recherches

- *Analyse › Statistiques* : recherches, recherches sans résultat, termes les plus cherchés, clics et taux de clic, sur la période choisie.
- *Analyse › Journal des recherches* : chaque recherche finale (une par recherche tapée, pas par frappe), avec filtres et export CSV.

## Journaux et RGPD

- **Contenu enregistré** : chaque recherche « finale » (pas chaque lettre tapée : « pa », « pal », « palm » sont marquées comme intermédiaires), avec les filtres, le nombre de résultats, le temps de réponse, le user-agent et les clics.
- **Adresse IP** : jamais stockée en clair. On conserve une **empreinte** (HMAC avec `APP_SECRET`) pour compter et bloquer, et une **IP tronquée** (`203.0.113.x`) pour l'affichage. Aucun cookie.
- **Conservation** : 90 jours par défaut (Paramètres), purge automatique chaque nuit (`logs:purge`).
- **Carrousels** : statistiques en totaux quotidiens (par carrousel et par produit), sans donnée personnelle. L'historique des carrousels personnalisés reste dans le navigateur du visiteur, seulement avec son consentement (réglable), et n'est jamais enregistré par DeepFind (voir section 8).
- **Supervision** (Temps réel & charge) : compteurs par minute sans donnée personnelle (6 jours) ; visiteurs actifs repérés par l'empreinte du jour de la mesure d'audience, sans IP, effacée après 15 minutes.
- **Popins** : statistiques en totaux quotidiens, sans donnée personnelle. Le choix « ne plus afficher pendant N jours » est gardé dans le navigateur du visiteur (`localStorage` / `sessionStorage`), sans identifiant, et n'est jamais envoyé au serveur.
- **Mesure d'audience** (Analyse › Audience) : aucun cookie ni stockage sur l'appareil, aucune IP enregistrée ; empreinte valable une journée (clé secrète du jour détruite à la fin de la journée), puis seuls des totaux quotidiens sont gardés. Désactivable entièrement ou par type de mesure.
- **À faire valider** par votre référent RGPD / DPO : durée de conservation, mention dans la politique de confidentialité du site, et l'absence de consentement pour la mesure d'audience (méthode conçue pour entrer dans l'exemption de la CNIL pour la mesure d'audience, à confirmer) et pour la mémorisation de la fermeture des popins (choix exprimé par le visiteur, à confirmer également). Ce README ne constitue pas un avis juridique.
