<?php

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use App\Http\Requests\Concerns\SanitizesInputs;

class LoginRequest extends FormRequest
{
    use SanitizesInputs;

    private const MAX_ATTEMPTS = 5;
    private const DECAY_MINUTES = 15;
    private const PASSWORD_MAX = 255;
    private const PASSWORD_MIN = 8;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('email')) {
            $value = $this->sanitizeFieldValue((string) $this->input('email'), 'sanitizeEmail', 'login.email');
            $this->merge(['email' => strtolower(trim($value))]);
        }

        if ($this->filled('password')) {
            $this->merge(['password' => trim((string) $this->input('password'))]);
        }

        if (!$this->has('remember')) {
            $this->merge(['remember' => false]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'min:' . self::PASSWORD_MIN, 'max:' . self::PASSWORD_MAX],
            'remember' => ['required', 'boolean'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $throttleKey = $this->throttleKey();
        $decayMinutes = (int) config('security.rate_limiting.login_decay_minutes', self::DECAY_MINUTES);
        RateLimiter::hit($throttleKey, $decayMinutes * 60);

        $data = $this->validated();
        $credentials = [
            'email' => (string) ($data['email'] ?? ''),
            'password' => (string) ($data['password'] ?? ''),
        ];
        $remember = (bool) ($data['remember'] ?? false);

        $success = Auth::attempt($credentials, $remember);

        Log::info('Login attempt recorded', [
            'email_hash' => hash('sha256', $credentials['email']),
            'ip_hash' => hash('sha256', (string) $this->ip()),
            'success' => $success,
            'throttle_key' => $throttleKey,
        ]);

        if (! $success) {
            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($throttleKey);
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        $maxAttempts = (int) config('security.rate_limiting.login_max_attempts', self::MAX_ATTEMPTS);
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), $maxAttempts)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     * 
     * @return string
     *
     */
    public function throttleKey(): string
    {
        $email = strtolower((string) $this->input('email', ''));
        $ip = (string) ($this->ip() ?? '');

        return 'login:' . hash('sha256', $email . '|' . $ip);
    }
}
