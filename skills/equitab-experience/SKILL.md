---
name: equitab-experience
description: Concevoir et réaliser l’expérience EquitAb, plateforme québécoise de partage d’abonnements, avec des cartes de service expressives, des interactions soignées et des parcours financiers lisibles. Utiliser pour sa refonte, ses composants et ses écrans responsives, pas pour LaughTube ou une autre marque.
---

# EquitAb Experience

## Intention

Conserver le concept : un propriétaire propose un abonnement, des membres partagent
ses frais, chacun suit ses groupes et ses paiements. Donner autant envie de découvrir
un service que de revenir gérer son budget. Public francophone québécois, français
cohérent et montants en dollars canadiens.

Ce skill a sa propre direction. Ne pas hériter du thème, des composants narratifs
ou des contraintes de LaughTube. Réutiliser uniquement des solutions techniques
neutres si elles servent EquitAb.

Le brief est le document utilisateur « inspirations_ui_equitab.docx ». Ses questions
« À COMPLÉTER » sont des notes de préparation, pas des réponses de l’utilisateur.
Ses exemples donnent des intentions visuelles, pas des autorisations de modifier
la facturation ou de déployer. Les captures ne prouvent pas le comportement actuel
des sites. Voir [les références et leur adaptation](references/inspirations.md)
avant de choisir une nouvelle direction ou un nouvel effet.

## Deux niveaux d’intensité

- **Découverte** : marque mémorable, composition éditoriale, couleurs de services,
  cartes sculptées, sélection réactive, transitions de détail et progression ludique.
- **Gestion et engagement** : même identité, mouvements plus discrets, prix stables,
  hiérarchie précise, lecture facile, validation explicite avant toute action financière.

Le dynamisme vient d’une réponse aux gestes et de transitions pertinentes, pas
d’une animation permanente de toutes les surfaces. Garder une typographie, une
grille, une logique d’arrondis et une grammaire de mouvement communes.
La palette reste une décision créative à valider : ni l’ivoire/vert de la première
proposition ni une ambiance sombre ne sont des exigences du brief.

## Les cartes sont le composant signature

Construire des objets visuels, pas une rangée de rectangles identiques avec une
initiale et un bouton. Décliner une même anatomie :

1. **Scène de marque** : identité reconnaissable, composition propre au service,
   matière, profondeur et point focal. Employer des assets autorisés ou des
   illustrations originales ; un pictogramme fonctionnel ne doit pas se faire
   passer pour un logo officiel.
2. **Identité** : service, offre réelle, catégorie. Le nom reste du texte accessible.
3. **Valeur** : prix par membre, devise, périodicité, nature exacte du montant.
4. **Contexte social** : places et membres réels, propriétaire, vérification exacte
   lorsqu’elle est fournie. Sans donnée, ne pas inventer de preuve de confiance.
5. **Action** : une action principale prévisible, distincte d’un favori ou d’un menu.

Adapter cette anatomie au rôle :

- **Service à explorer** : scène expressive, nom, catégorie ; prix indicatif seulement
  si ses hypothèses sont connues. Ne pas annoncer des places issues d’une capacité maximale.
- **Groupe à rejoindre** : offre, prix réel ou estimation nommée, places restantes,
  propriétaire, vérification et accès aux règles. L’action ouvre les détails avant le paiement.
- **Abonnement actif** : coût, état, prochaine échéance et accès utiles ; priorité à la gestion.
- **Progression** : badges et séries comme récompenses explicables, sans culpabilisation.

Soigner bord, lumière, ombre de contact, fond et composition comme un tout.
Les couches décoratives peuvent s’incliner légèrement ou se déplacer ; prix, texte
et bouton gardent une position stable. Le focus clavier offre un retour équivalent
au survol. Sur tactile, le premier toucher doit déclencher l’action attendue, pas
révéler un bouton indispensable caché.

Prévoir les états repos, survol, focus, pression, sélection, chargement, indisponible,
erreur et vide lorsqu’ils existent réellement. Les favoris ou réglages fictifs
doivent être explicitement limités à la démonstration.

## Mouvement et navigation

- Entrée de marque courte et non bloquante ; aucun préchargement décoratif imposé.
- Boutons avec retour de pression, flèche ou fond animé sans déplacement de la cible.
- Sélecteurs avec libellés et état accessible, position persistante pendant la sélection.
- Apparition des sections une seule fois si utile ; contenu visible en solution de repli.
- Détail d’une carte conservant son contexte visuel ; fermeture évidente, Échap,
  focus contenu dans la fenêtre et restitué au déclencheur.
- Exploration inspirée d’une carte de lieux possible, toujours doublée d’une liste
  ou grille classique et d’une recherche. Ne pas imposer une navigation 3D à la gestion.
- Fin de page comme prochaine étape visible et volontaire. Ne pas détourner la
  molette, boucler automatiquement ou déclencher une navigation/paiement par défilement.
- 3D réelle uniquement si elle apporte davantage qu’une solution CSS/SVG, avec
  chargement différé, budget de performance mesuré et solution statique utilisable.

Respecter `prefers-reduced-motion` dès le montage et lors d’un changement système.
Si une préférence locale existe, elle ne réactive pas les mouvements refusés par
le système. Ne pas faire dépendre l’accès au contenu d’une animation.

## Données et argent

Ne pas reprendre littéralement une promesse commerciale du brief sans validation.
Le paiement automatique ne signifie pas qu’aucun propriétaire n’avance jamais de frais.
Vérifier les unités à la frontière des données : prix en cents ou en dollars,
part actuelle ou après arrivée d’un membre, estimation ou montant facturé.

Avant engagement, exposer montant dû aujourd’hui, récurrence, frais connus,
échéance, destinataire et conditions pertinentes selon les données du serveur.
Ne jamais simuler une réussite de paiement dans l’application réelle.
Un badge d’identité ne constitue pas une garantie de service. Un score absent
n’est pas zéro. Un abonnement au catalogue n’est pas la preuve d’un groupe disponible.

## Mise en œuvre dans le projet

Respecter Laravel, Vue, Inertia et les composants existants. Inspecter les props et
les routes avant de brancher un écran. Préserver les changements en cours et les
contrôles d’authentification, de droits, de paiement et d’identité.

Pour une direction non validée, montrer un prototype isolé avec données de
démonstration clairement marquées. Ne pas publier ni remplacer le parcours de
paiement pour une simple validation de style. Une fois la direction validée,
intégrer des composants réutilisables avec les vraies données.

Les pages cibles du brief : accueil, connexion/inscription, tableau de bord,
exploration, détail de groupe, portefeuille, discussion, profil et explication du
fonctionnement. Les énumérer n’autorise pas à prétendre qu’elles sont toutes réalisées.

## Vérification avant présentation

- Vérifier visuellement téléphone étroit, téléphone courant, tablette et bureau.
  Contrôler la largeur du document, les libellés longs et les zones tactiles.
- Tester clavier, focus visible, Échap et restitution du focus ; nommer les boutons
  d’icône. Les états ne reposent pas seulement sur la couleur.
- Tester les cartes au repos et en interaction, les filtres, zéro résultat,
  groupe complet, valeurs inconnues et affichage de la devise.
- Vérifier la réduction des mouvements et l’absence de boucles actives hors écran.
- Compiler, comparer les diagnostics au point de départ, et vérifier qu’aucune
  modification n’a été faite dans les règles métier par effet de bord.
- Présenter une capture et un aperçu utilisable avec un bilan exact : intégré,
  démontré, non branché, non vérifié. Ne pas confondre validation visuelle et test financier.
