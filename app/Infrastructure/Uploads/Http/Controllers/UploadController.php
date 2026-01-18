<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Http\Controllers;

use App\Application\Uploads\Actions\ReplaceFile;
use App\Application\Uploads\Actions\UploadFile;
use App\Domain\Uploads\UploadProfileId;
use App\Infrastructure\Auth\Policies\UploadPolicy;
use App\Infrastructure\Http\Controllers\Controller;
use App\Infrastructure\Uploads\Core\Models\Upload;
use App\Infrastructure\Uploads\Core\Registry\UploadProfileRegistry;
use App\Infrastructure\Uploads\Http\Requests\HttpUploadedMedia;
use App\Infrastructure\Uploads\Http\Requests\StoreUploadRequest;
use App\Infrastructure\Uploads\Http\Requests\ReplaceUploadRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class UploadController extends Controller
{
    public function store(
        StoreUploadRequest $request,
        UploadProfileRegistry $profiles,
        UploadFile $uploadFile
    ): JsonResponse {
        $profile = $profiles->get(new UploadProfileId($request->input('profile_id')));
        $this->authorize('create', [Upload::class, (string) $profile->id]);

        $result = $uploadFile(
            $profile,
            $request->user(),
            new HttpUploadedMedia($request->file('file')),
            $request->input('owner_id')
        );

        return response()->json([
            'id' => $result->id,
            'profile_id' => $result->profileId,
            'status' => $result->status,
            'correlation_id' => $result->correlationId,
        ], Response::HTTP_CREATED);
    }

    public function update(
        ReplaceUploadRequest $request,
        string $uploadId,
        UploadProfileRegistry $profiles,
        ReplaceFile $replaceFile
    ): JsonResponse {
        /** @var Upload $upload */
        $upload = Upload::query()->whereKey($uploadId)->firstOrFail();
        $this->authorize('replace', $upload);

        if ((string) $request->input('profile_id') !== (string) $upload->profile_id) {
            return response()->json([
                'message' => __('validation.invalid', ['attribute' => 'profile_id']),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $profile = $profiles->get(new UploadProfileId((string) $upload->profile_id));
        $result = $replaceFile(
            $profile,
            $request->user(),
            new HttpUploadedMedia($request->file('file')),
            $request->input('owner_id')
        );

        return response()->json([
            'id' => $result->new->id,
            'profile_id' => $result->new->profileId,
            'status' => $result->new->status,
            'correlation_id' => $result->new->correlationId,
        ]);
    }

    public function destroy(Request $request, string $uploadId): JsonResponse
    {
        /** @var Upload $upload */
        $upload = Upload::query()->whereKey($uploadId)->firstOrFail();
        $this->authorize('delete', $upload);

        $disk = (string) $upload->disk;
        $path = (string) $upload->path;
        $storage = Storage::disk($disk);

        try {
            if ($path !== '' && $storage->exists($path)) {
                $storage->delete($path);
            }
        } catch (\Throwable $e) {
            Log::warning('uploads.delete.failed', [
                'upload_id' => $uploadId,
                'error' => $e->getMessage(),
            ]);
        } finally {
            $upload->delete();
        }

        return response()->json([], Response::HTTP_NO_CONTENT);
    }
}
