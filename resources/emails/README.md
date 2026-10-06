# Courriels EquitAb

Les sources sont dans `mjml/`. Les fichiers Blade de `resources/views/emails/`
et le layout `resources/views/vendor/mail/html/layout.blade.php` sont générés :
ne pas les modifier directement.

```sh
npm run emails
npm run emails:check
```

La compilation valide tous les modèles avant de remplacer les vues et retourne
un échec si un modèle est invalide. Le contrôle `emails:check` ne modifie rien
et vérifie que les vues versionnées correspondent exactement aux sources.

`partials/` rassemble la typographie, les couleurs du site, le logo PNG officiel
et le pied de page. Le logo utilise l’URL absolue de l’application ; il reste
identifiable par son texte alternatif si les images sont bloquées. Les boutons
et les informations importantes restent du texte HTML, sans JavaScript ni SVG
distant. Les arrondis et ombres sont décoratifs : leur absence dans un client
de messagerie ne doit pas empêcher la lecture ou l’action.

Les notifications Laravel de confirmation d’adresse et de réinitialisation du
mot de passe utilisent le même layout, complété par le thème Markdown dans
`resources/views/vendor/mail/html/themes/default.css`. Le texte français est dans
`resources/views/vendor/notifications/email.blade.php`, partagé avec la version
texte. Les URL signées, jetons, délais et règles d’envoi de Laravel sont conservés.

Pour vérifier les liens et l’échappement des données sans envoyer de courriel :

```sh
php vendor/bin/phpunit tests/Feature/EmailPresentationTest.php
```

La recette navigateur est un contrôle visuel local. Elle ne remplace pas un
essai de livraison dans Gmail, Outlook et Apple Mail avant une campagne.
