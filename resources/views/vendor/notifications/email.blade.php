@php
    // Presentation only: Laravel still creates and validates the original action URL.
    $isVerification = isset($actionText) && $actionText === __('Verify Email Address');
    $isPasswordReset = isset($actionText) && $actionText === __('Reset Password');
    $buttonText = $isVerification ? 'Confirmer mon courriel' : ($isPasswordReset ? 'Choisir un nouveau mot de passe' : ($actionText ?? ''));
@endphp
<x-mail::message>
@if ($isVerification)
# Confirmez votre courriel.

Il reste une étape pour activer votre compte EquitAb. Confirmez votre adresse courriel pour rejoindre un groupe ou préparer le partage de votre abonnement.
@elseif ($isPasswordReset)
# Un nouveau mot de passe.

Vous avez demandé à réinitialiser le mot de passe de votre compte EquitAb. Utilisez le bouton ci-dessous pour en choisir un nouveau.
@else
# {{ $greeting ?: 'Bonjour,' }}

@foreach ($introLines as $line)
{{ $line }}

@endforeach
@endif

@isset($actionText)
<x-mail::button :url="$actionUrl">
{{ $buttonText }}
</x-mail::button>
@endisset

@if ($isVerification)
Si vous n’avez pas créé de compte EquitAb, vous pouvez ignorer ce courriel.
@elseif ($isPasswordReset)
Ce lien expire dans {{ config('auth.passwords.'.config('auth.defaults.passwords').'.expire') }} minutes.

Vous n’avez pas fait cette demande ? Ignorez ce courriel. Votre mot de passe reste inchangé tant que vous n’en choisissez pas un nouveau.
@else
@foreach ($outroLines as $line)
{{ $line }}

@endforeach
@endif

{{ $salutation ?: 'L’équipe EquitAb' }}

@isset($actionText)
<x-slot:subcopy>
Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur :
<span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>
@endisset
</x-mail::message>
