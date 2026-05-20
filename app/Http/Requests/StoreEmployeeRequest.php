<?php

namespace App\Http\Requests;

use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends FormRequest
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
            'external_id' => ['required', 'string', 'max:255', 'unique:employees,external_id'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'company_id' => ['required', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', Rule::in([
                Employee::STATUS_ACTIVE,
                Employee::STATUS_INACTIVE,
                Employee::STATUS_TERMINATED,
            ])],
            'currency' => ['sometimes', 'string', 'size:3'],
        ];
    }
}
