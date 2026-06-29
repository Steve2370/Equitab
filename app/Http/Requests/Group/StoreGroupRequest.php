<?php

namespace App\Http\Requests\Group;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreGroupRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'subscription_id' => ['required', 'exists:subscriptions,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'tier' => ['required', 'in:standard,premium,famille'],
            'max_members' => ['required', 'integer', 'min:2', 'max:10'],
            'price_per_member' => ['required', 'integer', 'min:100'],
            'split_type' => ['required', 'in:equal,custom,usage_based'],
            'visibility' => ['required', 'in:public,private,invite_only'],
            'renewal_date' => ['required', 'date', 'after:today'],
            'auto_renew' => ['boolean'],
            'credential_email' => ['nullable', 'email'],
            'credential_password' => ['nullable', 'string', 'max:255'],
            'credential_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'subscription_id.exists' => 'Cet abonnement n\'existe pas.',
            'renewal_date.after' => 'La date de renouvellement doit être dans le futur.',
            'max_members.min' => 'Un groupe doit avoir au moins 2 membres.',
        ];
    }
}
