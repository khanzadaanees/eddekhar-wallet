<?php

namespace App\Http\Requests;

use App\Models\Employee;
use App\Models\Wallet;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWalletRequest extends FormRequest
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
        /** @var Employee $employee */
        $employee = $this->route('employee');

        return [
            'type' => [
                'required',
                'string',
                Rule::in([Wallet::TYPE_SALARY, Wallet::TYPE_SAVINGS]),
                Rule::unique('wallets', 'type')->where(
                    fn ($query) => $query->where('employee_id', $employee->id),
                ),
            ],
            'currency' => ['required', 'string', 'size:3'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            /** @var Employee|null $employee */
            $employee = $this->route('employee');

            if ($employee === null) {
                return;
            }

            if ($employee->status === Employee::STATUS_TERMINATED) {
                $validator->errors()->add(
                    'employee',
                    'Wallets cannot be created for a terminated employee.',
                );
            }
        });
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('currency') && is_string($this->input('currency'))) {
            $this->merge([
                'currency' => strtoupper($this->input('currency')),
            ]);
        }
    }
}
