<?php

namespace App\Features\Group\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PublishGroupDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'certify' => ['required', 'accepted'],
            'credential_email' => ['nullable', 'email', 'max:255'],
            'credential_password' => ['nullable', 'string', 'max:255'],
            'credential_notes' => ['nullable', 'string', 'max:1000'],
            'data' => ['prohibited'],
            'owner_id' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'certify.accepted' => 'Confirmez que vous êtes autorisé à partager cet abonnement.',
            'certify.required' => 'Votre confirmation est nécessaire pour publier.',
            'credential_email.email' => 'Indiquez une adresse courriel valide pour le service.',
            'max' => 'La limite autorisée est :max caractères.',
            'prohibited' => 'Les renseignements du groupe doivent être enregistrés dans le brouillon avant publication.',
        ];
    }
}
