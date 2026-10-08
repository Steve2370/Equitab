<?php

namespace App\Features\Group\Services;

use App\Models\Subscription;
use App\Support\BillingCurrencies;
use App\Support\Currency;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GroupDraftData
{
    public const FIELDS = [
        'subscription_id', 'name', 'description', 'tier', 'max_members',
        'total_price', 'currency', 'split_type', 'visibility', 'renewal_date', 'auto_renew',
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
            'currency' => ['sometimes', 'required', Rule::in(Currency::SUPPORTED)],
            'split_type' => [$presence, Rule::in(['equal'])],
            'visibility' => [$presence, Rule::in(GroupVisibility::ACCEPTED)],
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

        $validated = GroupVisibility::normalizeData($validator->validate());
        $validated['currency'] = $this->currency($validated);
        BillingCurrencies::assertEnabled($validated['currency']);

        return $validated;
    }

    /** @param array<string, mixed> $data
     * @param  array<string, mixed>  $previous
     * @return array<string, mixed>
     */
    public function forSave(array $data, array $previous): array
    {
        if (! array_key_exists('currency', $data)) {
            // An omitted field must not erase a snapshot or give an old amount
            // the currency of a newly selected service.
            $currency = $previous['currency']
                ?? Subscription::find($previous['subscription_id'] ?? null)?->currency;
            if ($currency === null && isset($data['subscription_id'])) {
                if (isset($previous['total_price'], $data['total_price'])) {
                    throw ValidationException::withMessages([
                        'data.currency' => 'Précisez la devise de ce montant avant de choisir un service.',
                    ]);
                }
                $currency = Subscription::find($data['subscription_id'])?->currency;
            }
            if ($currency !== null) {
                $data['currency'] = Currency::normalize($currency);
            }
        }

        return GroupVisibility::normalizeData($data);
    }

    /** Legacy drafts resolve their original service currency until it is persisted. */
    public function currency(array $data): string
    {
        return Currency::normalize($data['currency'] ?? Subscription::findOrFail($data['subscription_id'])->currency);
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
            'currency' => $this->currency($data),
        ];
    }
}
