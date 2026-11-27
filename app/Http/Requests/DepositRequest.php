<?php

namespace App\Http\Requests;

use App\Http\Middleware\RecordApiRequest;
use App\Models\User;
use App\Services\DepositService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates deposit payloads before they reach the service layer.
 */
final class DepositRequest extends FormRequest
{
    /**
     * Authentication is enforced by the route middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'amount' => [
                'required',
                'integer',
                'min:'.DepositService::MIN_AMOUNT,
                'max:'.DepositService::MAX_AMOUNT,
            ],
        ];
    }

    /**
     * Audit a rejected payload against the authenticated actor.
     */
    protected function failedValidation(Validator $validator): void
    {
        $user = $this->user();

        if ($user instanceof User) {
            app(DepositService::class)->recordValidationFailure(
                $user,
                (string) $this->attributes->get(RecordApiRequest::TRACE_ID_ATTRIBUTE),
            );
        }

        parent::failedValidation($validator);
    }
}
