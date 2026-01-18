<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Http\Requests;

use App\Domain\Uploads\UploadProfile;
use App\Domain\Uploads\UploadProfileId;
use App\Infrastructure\Http\Requests\Concerns\SanitizesInputs;
use App\Infrastructure\Models\User;
use App\Infrastructure\Uploads\Core\Registry\UploadProfileRegistry;
use App\Infrastructure\Uploads\Http\Requests\Concerns\UsesDocumentValidation;
use App\Infrastructure\Uploads\Http\Requests\Concerns\UsesImageValidation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

final class ReplaceUploadRequest extends FormRequest
{
    use UsesImageValidation;
    use UsesDocumentValidation;
    use SanitizesInputs;

    private ?UploadProfile $profile = null;

    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    public function rules(): array
    {
        $profileRule = function (string $attribute, mixed $value, callable $fail): void {
            try {
                $this->profile();
            } catch (\Throwable) {
                $fail(__('validation.exists', ['attribute' => $attribute]));
            }
        };

        $profile = $this->safeProfile();

        return array_filter([
            'profile_id' => ['required', 'string', $profileRule],
            'file' => $profile ? $this->fileRulesForProfile($profile) : ['required', 'file'],
            'owner_id' => ['nullable', 'integer'],
        ]);
    }

    /**
     * @return array<int,mixed>
     */
    private function fileRulesForProfile(UploadProfile $profile): array
    {
        return match ($profile->kind) {
            \App\Domain\Uploads\UploadKind::IMAGE => $this->imageRules('file'),
            default => $this->documentRules('file', $profile),
        };
    }

    public function profile(): UploadProfile
    {
        if ($this->profile instanceof UploadProfile) {
            return $this->profile;
        }

        $value = (string) $this->input('profile_id');
        try {
            $this->profile = app(UploadProfileRegistry::class)->get(new UploadProfileId($value));
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['profile_id' => __('validation.exists', ['attribute' => 'profile_id'])]);
        }

        return $this->profile;
    }

    private function safeProfile(): ?UploadProfile
    {
        try {
            return $this->profile();
        } catch (ValidationException) {
            return null;
        }
    }
}
