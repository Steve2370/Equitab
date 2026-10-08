# Vérification des destination charges en mode test

`DestinationChargeProof` est un vérificateur pur destiné à la recette. Il ne
charge pas Laravel, ne lit aucun secret, n'appelle pas Stripe et ne modifie
aucun objet. Il ne constitue ni un rapprochement de production ni une validation
de l'éligibilité commerciale d'un compte.

Fournir les objets Stripe TEST suivants, convertis en tableaux :

- `charge` et `charge_balance` : charge de la plateforme et sa balance transaction ;
- `transfer` et `transfer_balance` : transfert lié à cette charge et son débit ;
- `destination_payment` et `destination_balance` : paiement et balance transaction
  lus dans le contexte du compte connecté correspondant ;
- `application_fee` et `application_fee_balance` : commission et crédit associé.

Appeler `verify($objects, $amountInCents, $currency, $expectedConnectedAccount)`.
Le montant, la devise et la destination attendus doivent venir du contrat local
de recette, pas être copiés depuis l'objet distant à contrôler. Toute contradiction
lève une exception. Un succès retourne séparément les valeurs de présentation,
de règlement plateforme, de transfert, de règlement propriétaire et de commission.

## Hypothèses explicites

- Flux de destination charge existant d'EquitAb, sans `on_behalf_of`.
- Commission existante de 5 %, avec son arrondi actuel ; aucun changement de tarif.
- CAD ou EUR, unités mineures entières ; propriétaire réglé dans la devise facturée.
- Le transfert brut est rapproché du règlement brut de la plateforme et non du
  montant présenté au client. Les conversions sont contrôlées avec les taux des
  balance transactions Stripe, jamais avec un taux courant externe.
- Une commission peut référencer le paiement connecté : dans ce cas, son
  `originating_transaction` doit retrouver la charge initiale de la plateforme.
- `net_profit` reste nul : des frais en CAD ne se soustraient pas à une commission
  EUR sans un rapprochement comptable complémentaire explicite.

Ce contrôle ne prouve pas à lui seul la réception d'un webhook, l'ouverture des
droits, le renouvellement, un défi 3DS, un remboursement, un versement bancaire
ou la réussite de tous les pays pris en charge. Vérifier ces étapes séparément.
L'exemple historique EUR vers Belgique ne valide pas les 21 pays européens.

## Tests hors réseau

```bash
php vendor/bin/phpunit tests/Unit/DestinationChargeProofTest.php --fail-on-all-issues --disallow-test-output
```

Les données de ces tests sont synthétiques et aucune clé Stripe n'est nécessaire.
Pour une recette distante, utiliser une base isolée, les seules clés TEST et un
relais de webhooks séparé du site public. Une modification de webhook exige son
autorisation et la restauration vérifiée de son état initial.
