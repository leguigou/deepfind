# Notifications (Web Push)

Envoyer des messages sur le téléphone ou l'ordinateur des visiteurs qui l'ont accepté (promotions, retours en stock, nouveautés), **sans application** et **sans outil payant** : DeepFind utilise le standard des navigateurs (Web Push).

- *Paramètres › Notifications* : l'invitation à s'abonner (forme, appareils, iPhone, contenu, style) et l'installation.
- *Recommandations › Envoi de notifications* : rédiger, cibler, programmer les messages, et suivre leurs résultats.

> ⚠️ Désactivé par défaut. Avant d'activer : déposer le fichier `sw.js` sur le site (voir plus bas), tester sur la recette, et faire valider par le DPO la mention dans la politique de confidentialité. Ce document n'est pas un avis juridique.

## Comment un visiteur s'abonne

1. **Votre invitation** s'affiche (forme et textes réglables) : « Ne ratez aucune bonne affaire » — *Oui, me prévenir* / *Plus tard*.
2. S'il clique sur **Oui**, le **navigateur** demande son accord (« Autoriser les notifications ? »). Cette fenêtre n'est pas modifiable et ne s'ouvre qu'après un clic (règle des navigateurs). **S'il la bloque, on ne peut plus la reproposer** : c'est pour cela que l'invitation passe d'abord.
3. Accord donné : l'abonnement est enregistré, un message de remerciement s'affiche.

La **croix** en haut à droite ferme l'invitation (comme *Plus tard*) ou la fenêtre de la cloche.

*Plus tard* : invitation reproposée au bout du nombre de jours réglé (mémorisé dans le navigateur, comme les popins). Navigateur qui a déjà bloqué les notifications : aucune invitation.

## Réglages (Paramètres › Notifications)

| Réglage | Choix |
|---|---|
| Forme | bandeau en bas, bandeau en haut, popin au centre, popin dans un coin, **cloche flottante** (le visiteur clique quand il veut ; un abonné peut s'y désabonner), bloc dans la page (sélecteur CSS) |
| Appareils | **téléphones uniquement** (par défaut), téléphones et tablettes, tous, ordinateurs uniquement — d'après la taille de l'écran et l'écran tactile |
| Exclure les iPhone et iPad | activé par défaut : sur iPhone / iPad, les notifications ne marchent que si le site a été **ajouté à l'écran d'accueil** (iOS 16.4 ou plus récent) ; sinon l'invitation ne servirait à rien |
| Quand | délai après l'arrivée (secondes), à partir de la N<sup>e</sup> page vue de la visite, pages sans invitation (débuts d'adresse, ex. `/checkout`) |
| Contenu | titre, texte, boutons, message de remerciement, image (popins) ; variables `{{code}}` acceptées |
| Style | couleurs (fond, texte, bouton et cloche), CSS personnalisé (`.df-push`, `.df-push-bell`, `.df-push-yes`, `.df-push-later`) ; **aperçu ordinateur / mobile** |
| Installation | adresse du `sw.js` sur le site, icône des notifications (carrée, 192 px conseillé) |

## Installation sur le site (une seule fois)

Déposer sur le serveur du site Magento un fichier **`sw.js`** contenant **une seule ligne** (copiable depuis *Paramètres › Notifications*) :

```js
importScripts('https://search.example.com/widget/push-sw.js');
```

- Il doit être servi **par le domaine du site** (règle des navigateurs : le widget ne peut pas le créer lui-même), avec le type JavaScript. À la racine (`/sw.js`, recommandé) ou dans un dossier du thème (ex. `/static/frontend/…/js/sw.js`) : indiquer alors son adresse dans les réglages.
- Le vrai code est chargé depuis DeepFind (`public/widget/push-sw.js`) : ses évolutions arrivent sans retoucher le site (navigateurs : sous 24 h).
- Il ne met rien en cache et n'intercepte aucune requête du site : il affiche les notifications reçues et ouvre le lien au clic.
- Vérification : en navigation privée sur Android (Chrome), accepter l'invitation, puis envoyer un message de test depuis *Envoi de notifications*.

## Recette et projet Test

- Le **projet** d'un abonné est celui de la **clé du widget** de la page (`data-key`), pas le fichier `sw.js` : un site de recette avec la clé du projet Test donne des abonnés du projet Test, et un message envoyé depuis un projet ne part qu'à ses abonnés.
- `sw.js` ne fait que charger `push-sw.js`, identique pour tous les projets. Avec un **serveur DeepFind de recette séparé**, copiez la ligne depuis l'extranet de recette : elle pointe alors vers ce serveur.
- Chaque serveur DeepFind a ses propres clés d'envoi (VAPID) : des abonnés recueillis sur la recette ne reçoivent jamais les messages de la production.
- Chaque abonné garde la clé avec laquelle il s'est inscrit ; ses messages la reprennent pour renvoyer « affiché » et « cliqué », même si le projet a plusieurs clés (production, recette).
- Si le thème a déjà un service worker (thème « PWA »), ne le remplacez pas : ajoutez-y la ligne `importScripts(...)`.

## Envoyer un message (Recommandations › Envoi de notifications)

- **Titre** (80 caractères au plus, environ 40 visibles sur un téléphone), **texte** (250), **lien** ouvert au clic, **image** (affichée par Android et Windows). Aperçu à droite.
- **Destinataires** : appareils, et provenance au moment de l'abonnement (moteurs, réseaux sociaux, e-mail… voir [Ciblage selon la provenance](ciblage-par-provenance)). Le nombre d'abonnés visés est affiché.
- **Envoi** : brouillon, maintenant (dans la minute, après confirmation), ou programmé (date et heure de Paris). Un message envoyé ne peut plus être modifié.
- **Envoyer au groupe de test** : envoie tout de suite le titre, le texte, le lien et l'image saisis aux appareils du [groupe de test](#groupe-de-test), autant de fois que nécessaire. Le message n'est **ni enregistré ni envoyé aux abonnés**, et ses statistiques ne bougent pas (pensez à l'enregistrer ensuite). Un message programmé reste programmé.
- **Résultats** : envoyés, échecs, **affichés** et **clics** (signalés par le navigateur de l'abonné), taux de clic.

## Abonnés

*Recommandations › Envoi de notifications › Voir les abonnés* : un abonné = un navigateur qui a accepté les notifications.

| Colonne | Contenu |
|---|---|
| N° | identifiant de l'abonné |
| Appareil | téléphone, tablette ou ordinateur ; navigateur et système (famille seulement : Chrome, Android…) ; provenance à l'abonnement |
| Service d'envoi / adresse | Google, Mozilla, Apple, Microsoft ; adresse d'envoi **masquée** (début `****` fin) |
| Clé de chiffrement | clé publique du navigateur, **masquée** |
| Inscription, statut | date ; **Actif**, **En pause** (ne reçoit plus les messages) ou **N échec(s)** (envoi refusé ; supprimé après 5 de suite) |
| Reçus, affichés, clics | messages acceptés pour cet appareil, puis affichés et cliqués (signalés par l'appareil), taux de clic, dernier clic |

- **Filtres** : appareil, statut, navigateur, provenance, n°.
- **Envoyer un test** : notification « Test DeepFind » envoyée tout de suite à cet abonné seul (même en pause). Pour tester votre appareil : abonnez-vous depuis le site, puis testez le dernier abonné (n° le plus élevé).
- **Mettre en pause / Réactiver** : l'abonné ne reçoit plus les messages, sans perdre son abonnement.
- **Supprimer** : retiré de la liste ; s'il revient sur le site avec les notifications autorisées, il est réenregistré.
- Les compteurs d'un appareil sont signés (`s`, `g` dans le message) : ils ne peuvent pas être gonflés de l'extérieur.

## Groupe de test

Vos appareils (et ceux des collègues qui relisent les messages), pour voir un message sur un vrai téléphone avant de l'envoyer à tout le monde.

1. Abonnez-vous depuis le site (en recette d'abord) avec chaque appareil de test.
2. Dans **Abonnés**, sur la ligne de l'appareil (le dernier abonné, n° le plus élevé, ou utilisez « Envoyer un test » pour le reconnaître) : saisissez un **nom** (ex. « iPhone de Paul »), puis **Ajouter au groupe de test**. Badge **Test** dans la liste, filtre **Groupe › Groupe de test**.
3. Dans la fiche d'un message : **Envoyer au groupe de test**, autant de fois que nécessaire. Depuis la liste des abonnés : **Envoyer un test au groupe** (message « Test DeepFind »).

- Le groupe reçoit les essais **même en pause**. Astuce : mettez vos appareils de test en pause pour qu'ils ne reçoivent que les essais, pas les vrais messages.
- Les essais ne comptent ni dans les résultats du message ni dans les compteurs de l'appareil (ils n'ont pas de n° de message).
- 50 appareils au plus : le groupe de test ne sert pas à envoyer un message à tout le monde sans le programmer.
- **Retirer du groupe de test** efface aussi le nom. Un appareil supprimé ou désabonné sort du groupe.

## Statistiques

En haut des deux écrans : abonnés (téléphones, tablettes, ordinateurs), invitations affichées sur 30 jours, part de « Oui » et de « Plus tard », accord du navigateur (autorisé / bloqué), nouveaux abonnés et désabonnements.

## Limites

- iPhone / iPad : seulement si le site est ajouté à l'écran d'accueil (iOS 16.4+) ; il faut aussi un manifeste web (souvent déjà fourni par le thème).
- Le visiteur peut couper les notifications dans les réglages de son navigateur ou de son téléphone ; l'abonnement est alors supprimé au premier envoi qui échoue.
- Un message n'arrive que si le navigateur est en marche (sur Android, en arrière-plan aussi) ; sinon il est remis dans les 24 heures (durée de validité).

## Détails techniques

- **Standard** : Web Push. Chiffrement du message (RFC 8291, aes128gcm : ECDH P-256, HKDF, AES-128-GCM) et identification du serveur (VAPID, RFC 8292 : jeton ES256) faits par DeepFind avec OpenSSL — aucun service tiers. Conformité vérifiée par un test avec le vecteur officiel de la RFC 8291.
- **Clés VAPID** : créées une fois pour l'installation (réglages globaux `push_vapid_public`, `push_vapid_private` — la clé privée est chiffrée avec `APP_SECRET`). **Ne pas les changer** : les abonnements existants deviendraient inutilisables. `php bin/console push:keys` affiche la clé publique.
- **Tables** : `push_subscriptions` (adresse d'envoi fournie par le navigateur, clés publiques du navigateur, appareil, navigateur et système en famille, provenance, clé du widget utilisée à l'abonnement, pause, groupe de test et nom de l'appareil, compteurs reçus / affichés / cliqués, échecs), `push_campaigns` (messages et compteurs), `push_stats` (invitation, navigateur, abonnements, par jour).
- **Envoi** : `schedule:run` (chaque minute) envoie les messages dont l'heure est passée (`php bin/console push:send` pour le faire à la main), par lots de 500, 50 requêtes en parallèle. Réponse 404 / 410 : abonnement supprimé ; autre échec : supprimé après 5 échecs de suite.
- **API** : `POST /api/v1/push/subscribe`, `/push/unsubscribe`, `/push/event` (`e` = `iv` invitation vue, `ia` oui, `il` plus tard, `ig` autorisé, `id` bloqué ; ou `c` = message et `e` = `s` affiché / `c` cliqué). `/api/v1/config` renvoie `push` (réglages de l'invitation) ou `false`.
- **Code** : `src/Push/WebPush.php` (chiffrement, signature, envoi), `src/Push/Push.php` (réglages, abonnés, messages), `src/Admin/PushController.php`, gabarits `push_settings.php`, `push.php`, `push_edit.php`, scripts `public/assets/push-admin.js`, `public/widget/push-sw.js` ; invitation dans `public/widget/deepfind.js` (`startPush`).
