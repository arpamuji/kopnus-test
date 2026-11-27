<?php

namespace App\Http\Requests;

use App\Http\Middleware\RecordApiRequest;
use App\Services\AuthService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates login payloads before they reach the service layer.
 */
final class LoginRequest extends FormRequest
{
    /**
     * Login is public; authentication happens against the submitted credentials.
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
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Audit a rejected payload when its email still identifies a registered user.
     */
    protected function failedValidation(Validator $validator): void
    {
        $email = $this->input('email');

        if (is_string($email) && $email !== '') {
            app(AuthService::class)->recordValidationFailure(
                $email,
                (string) $this->attributes->get(RecordApiRequest::TRACE_ID_ATTRIBUTE),
            );
        }

        parent::failedValidation($validator);
    }
}
