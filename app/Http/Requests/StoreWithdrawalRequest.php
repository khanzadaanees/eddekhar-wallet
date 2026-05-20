<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreWithdrawalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => [
                'required',
                'regex:/^\d+(\.\d{1,4})?$/',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (bccomp((string) $value, '0', 4) <= 0) {
                        $fail('The amount must be greater than zero.');
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.regex' => 'The amount must be a positive number with up to 4 decimal places.',
        ];
    }
}
