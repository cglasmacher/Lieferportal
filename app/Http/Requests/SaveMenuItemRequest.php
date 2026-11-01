<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveMenuItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['required', 'string', 'max:2000'],
            'category' => ['required', 'string', 'max:80'],
            'price_cents' => ['required', 'integer', 'min:1', 'max:100000'],
            'allergens' => ['present', 'array', 'max:30'],
            'allergens.*' => ['required', 'string', 'max:80', 'distinct'],
            'is_available' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:65000'],
        ];
    }
}
