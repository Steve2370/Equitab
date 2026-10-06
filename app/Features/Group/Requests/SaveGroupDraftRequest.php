<?php

namespace App\Features\Group\Requests;

use App\Features\Group\Services\GroupDraftData;
use Illuminate\Foundation\Http\FormRequest;

class SaveGroupDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(GroupDraftData $data): array
    {
        $rules = [
            'id' => $this->isMethod('post') ? ['required', 'uuid'] : ['prohibited'],
            'version' => $this->isMethod('post') ? ['prohibited'] : ['required', 'integer', 'min:1'],
            'data' => ['present', 'array:'.implode(',', GroupDraftData::FIELDS)],
            'owner_id' => ['prohibited'],
            'status' => ['prohibited'],
            'published_group_id' => ['prohibited'],
        ];

        foreach ($data->rules() as $field => $fieldRules) {
            $rules['data.'.$field] = $fieldRules;
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            ...app(GroupDraftData::class)->messages(),
            'data.array' => 'Le brouillon contient des champs non autorisés. Les accès au service ne peuvent pas y être enregistrés.',
            'id.uuid' => 'L’identifiant du brouillon est invalide. Rechargez la page.',
            'prohibited' => 'Ce renseignement ne peut pas être modifié ici.',
        ];
    }
}
