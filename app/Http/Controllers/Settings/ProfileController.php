<?php

namespace App\Http\Controllers\Settings;

use App\Helpers\SecurityHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();

        $this->authorize('view', $user);

        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            'avatarRoutes' => [
                'upload' => route('settings.avatar.update'),
                'delete' => route('settings.avatar.destroy'),
            ],
        ]);
    }

    /**
     * @param ProfileUpdateRequest $request
     * @return RedirectResponse
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();

        $this->authorize('update', $user);

        try {
            return DB::transaction(function () use ($user, $request) {
                $user->fill($request->validated());

                if ($user->isDirty('email')) {
                    $user->email_verified_at = null;
                }

                $user->save();

                return to_route('profile.edit')->with(['success' => __('auth.update.success')]);
            });
        } catch (\Throwable $e) {
            Log::error('Profile update failed', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);

            return back()->withErrors([
                'profile' => __('auth.update.failed'),
            ]);
        }
    }
    

    /**
     * Delete the user's profile.
     * @param Request $request
     * @return RedirectResponse
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        $this->authorize('delete', $user);

        Auth::logout();
        try {
            // Transacción: borrado + acciones relacionadas
            DB::transaction(function () use ($user) {
                // Si usas soft deletes, esto será reversible; si no, será permanente.
                $user->delete();
            });

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            Log::warning('Cuenta eliminada', SecurityHelper::sanitizeForLogging([
                'user_id' => $user->id,
                'email_domain' => $this->getEmailDomain($user->email),
                'ip_hash' => SecurityHelper::hashIp(request()->ip()),
                'metric' => 'profile.destroy.success',
            ]));

            return redirect('/')->with(['success' => __('auth.deleted.success')]);
            
        } catch (\Throwable $e) {
            Log::warning('Profile destroy failed', SecurityHelper::sanitizeForLogging([
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'metric' => 'profile.destroy.failed',
            ]));

            return back()->withErrors([
                'profile' => __('auth.deleted.failed'),
            ]);
        }
    }

    /**
     * Get the domain of the email for logging (without exposing the email)
     * @param string $email
     * @return string
     */
    private function getEmailDomain(string $email): string
    {
        $parts = explode('@', $email);
        return $parts[1] ?? 'unknown';
    }
}
