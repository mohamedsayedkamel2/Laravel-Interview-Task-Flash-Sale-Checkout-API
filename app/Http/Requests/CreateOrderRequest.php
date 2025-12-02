<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'hold_id' => 'required|string|min:1|max:255'
        ];
    }

    public function messages(): array
    {
        return [
            'hold_id.required' => 'Hold ID is required',
            'hold_id.string' => 'Hold ID must be a string',
            'hold_id.min' => 'Hold ID must be at least 1 character',
            'hold_id.max' => 'Hold ID must not exceed 255 characters',
        ];
    }
}