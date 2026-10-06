<?php

namespace App\Features\Group\Services;

use App\Models\Subscription;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class GroupDraftData
{
    public const FIELDS = [
        'subscription_id', 'name', 'description', 'tier', 'max_members',
        'total_price', 'split_type', 'visibility', 'renewal_date', 'auto_renew',
    ];

    /** @return array<string, array<mixed>> */
    public function rules(bool $publishing = false): array
    {
        $presence = $publishing ? 'required' : 'nullable';

        return [
            'subscription_id' => [$presence, 'integer', Rule::exists('subscriptions', 'id')->where('is_active', true)],
            'name' => [$presence, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'tier' => [$presence, Rule::in(['standard', 'premium', 'famille'])],
            'max_members' => [$presence, 'integer', 'min:2', 'max:10'],
            'total_price' => [$presence, 'integer', 'min:'.($publishing ? 100 : 0), 'max:99999999'],
            'split_type' => [$presence, Rule::in(['equal'])],
            'visibility' => [$presence, Rule::in(['public', 'private', 'invite_only'])],
            'renewal_date' => $publishing ? ['required', 'date_format:Y-m-d', 'after:today'] : ['nullable', 'date_format:Y-m-d'],
            'auto_renew' => [$presence, 'boolean'],
        ];
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function forPublication(array $data): array
    {
        $validator = Validator::make($data, $this->rules(true), [
            ...$this->messages(),
            'renewal_date.after' => 'Choisissez une date de renouvellement dans le futur.',
            'subscription_id.exists' => 'Ce service n’est plus disponible. Choisissez un service actif.',
        ]);
        $validator->after(function ($validator) use ($data) {
            $subscription = Subscription::find($data['subscription_id'] ?? null);
            if ($subscription && (int) ($data['max_members'] ?? 0) > $subscription->max_members) {
                $validator->errors()->add('max_members', 'Le nombre de membres dépasse la capacité de ce service.');
            }
        });

        return $validator->validate();
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'required' => 'Ce renseignement est obligatoire.',
            'integer' => 'Utilisez un nombre entier.',
            'string' => 'Utilisez du texte pour ce renseignement.',
            'min' => 'La valeur minimale autorisée est :min.',
            'max' => 'La limite autorisée est :max.',
            'in' => 'Choisissez une des options proposées.',
            'boolean' => 'Choisissez oui ou non.',
            'date_format' => 'Indiquez une date valide.',
            'exists' => 'Ce service n’est plus disponible. Choisissez un service actif.',
        ];
    }

    /** @param array<string, mixed> $data
     * @return array{full_group_share: int, total_price: int, max_members: int, currency: string}|null
     */
    public function preview(array $data): ?array
    {
        $price = $data['total_price'] ?? null;
        $members = $data['max_members'] ?? null;
        $subscription = Subscription::find($data['subscription_id'] ?? null);
        if (! $subscription || ! is_numeric($price) || ! is_numeric($members) || $price < 100 || $members < 2) {
            return null;
        }

        return [
            'full_group_share' => (int) round($price / $members),
            'total_price' => (int) $price,
            'max_members' => (int) $members,
            'currency' => $subscription->currency,
        ];
    }
}
