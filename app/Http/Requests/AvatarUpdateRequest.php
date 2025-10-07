<?php

namespace App\Http\Requests;

use App\Rules\SecureImageValidation;
use Illuminate\Foundation\Http\FormRequest;

class AvatarUpdateRequest extends FormRequest
{
    private const MAX_FILE_SIZE_KB = 2048;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $authenticated = $this->user();
        $routeUser = $this->route('user');

        if ($authenticated === null || $routeUser === null) {
            return false;
        }

        $routeUserId = $routeUser instanceof \Illuminate\Contracts\Auth\Authenticatable
            ? (int) $routeUser->getAuthIdentifier()
            : (int) data_get($routeUser, 'id', $routeUser);

        return (int) $authenticated->getAuthIdentifier() === $routeUserId;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'avatar' => [
                'required',
                'file',
                'max:' . self::MAX_FILE_SIZE_KB,
                new SecureImageValidation(null, self::MAX_FILE_SIZE_KB * 1024),
            ],
        ];
    }

    /**
     * Custom validation error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'avatar.required' => __('validation.custom.avatar.required'),
            'avatar.max' => __('validation.custom.avatar.max'),
            'avatar.uploaded' => __('validation.custom.avatar.uploaded'),
        ];
    }

    /**
     * Attribute names for validation errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'avatar' => __('validation.attributes.avatar'),
        ];
    }
}
