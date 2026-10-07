<?php

namespace App\Features\Group\Requests;

use App\Features\Group\Services\GroupVisibility;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGroupRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('group'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'price_per_member' => ['sometimes', 'integer', 'min:100'],
            'visibility' => ['sometimes', Rule::in(GroupVisibility::ACCEPTED)],
            'status' => ['sometimes', 'in:open,full,closed,suspended'],
            'auto_renew' => ['sometimes', 'boolean'],
        ];
    }
}
