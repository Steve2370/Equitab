<x-mail::message>
# Bienvenue sur EquitAb !

<p>Bonjour {{ $userName }},</p>

Votre compte est créé. Il reste une étape : confirmer votre adresse courriel.

Vous pourrez ensuite rejoindre un groupe ou préparer le partage de votre abonnement.

<x-mail::button :url="$actionUrl">
Confirmer mon courriel
</x-mail::button>

Ce lien est valable pendant {{ $expiresInMinutes }} minutes. S’il a expiré, connectez-vous à EquitAb et cliquez sur « Renvoyer le courriel ».

Si vous n’avez pas créé ce compte, ignorez ce courriel. Ne confirmez pas une inscription qui n’est pas la vôtre.

À bientôt,
L’équipe EquitAb

<x-slot:subcopy>
Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur :
<span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>
</x-mail::message>
