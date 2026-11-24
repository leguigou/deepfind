# Tester sur le site

## Favori « Tester DeepFind »

![Tester DeepFind sur le site](docs/screenshots/12-tester-sur-le-site.png)

Un favori à ajouter dans Chrome : sur une page du site, un clic affiche le widget avec le layer choisi, **même inactif**. Seul votre navigateur voit le test.

1. Dans la fenêtre « Tester DeepFind sur le site », choisissez le **layer** et la **clé** (le site doit faire partie de ses domaines autorisés).
2. Affichez la barre de favoris de Chrome (`Ctrl` + `Maj` + `B`) et **glissez-y le bouton « Tester DeepFind »** (ou copiez le lien et créez le favori à la main).
3. Ouvrez le site et **cliquez sur le favori**. Une pastille « DeepFind » apparaît en haut à droite, avec « Quitter l'aperçu ».
4. Si DeepFind est déjà installé sur le site, la page se recharge en aperçu et le reste de page en page dans l'onglet ; sinon, recliquez sur le favori après chaque changement de page.

Le favori contient un jeton signé, lié au projet et au layer, valable **7 jours**. Si rien ne s'affiche, le site bloque peut-être les scripts extérieurs (politique de sécurité du site).

En mode test, les popins s'affichent à chaque page sans être comptées ni mémorisées, et les mesures (audience, statistiques) ne sont pas envoyées. L'aperçu reste actif dans l'onglet jusqu'au bouton « Quitter l'aperçu » de la pastille.

## Démo du widget

**Recommandations › Démo du widget** affiche le widget sur une page de démonstration. La liste **« Layer testé »** propose le layer actif (en premier, choisi par défaut), les autres layers et « Aucun layer ». Le bloc principal de la page s'appelle `main#maincontent`, comme sur Magento, pour tester un layer « Intégré ».
