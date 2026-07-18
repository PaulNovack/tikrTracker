<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSymbolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'symbol' => ['required', 'string', 'uppercase', 'max:10', 'regex:/^[A-Z0-9\-]+$/'],
            'asset_type' => ['required', 'string', 'in:stock,crypto'],
            'common_name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'sector' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'symbol.required' => 'Symbol is required.',
            'symbol.uppercase' => 'Symbol must be uppercase.',
            'symbol.regex' => 'Symbol can only contain uppercase letters, numbers, and hyphens.',
            'asset_type.required' => 'Asset type is required.',
            'asset_type.in' => 'Asset type must be either stock or crypto.',
            'common_name.required' => 'Common name is required.',
        ];
    }
}
