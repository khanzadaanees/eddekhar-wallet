<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransferRequest extends FormRequest
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
            'from_wallet_id' => ['required', 'integer', Rule::exists('wallets', 'id')],
            'to_wallet_id' => ['required', 'integer', 'different:from_wallet_id', Rule::exists('wallets', 'id')],
            'amount' => [
                'required',
                'regex:/^\d+(\.\d{1,4})?$/',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (bccomp((string) $value, '0', 4) <= 0) {
                        $fail('The amount must be greater than zero.');
                    }
                },
            ],
            'reference_id' => ['required', 'string', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.regex' => 'The amount must be a positive number with up to 4 decimal places.',
            'to_wallet_id.different' => 'Source and destination wallets must be different.',
        ];
    }
}
