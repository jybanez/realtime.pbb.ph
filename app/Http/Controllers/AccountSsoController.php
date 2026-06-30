<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Account\AccountClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class AccountSsoController extends Controller
{
    public function redirect(Request $request, AccountClient $account): RedirectResponse
    {
        abort_unless((bool) ($account->ssoSettings()['enabled'] ?? false), 404);

        $request->session()->put('pbb_account.return_to', $this->safeReturnPath($request->query('return', '/admin')));

        return redirect()->away($account->authorizationUrl($request));
    }

    public function callback(Request $request, AccountClient $account): RedirectResponse
    {
        abort_unless((bool) ($account->ssoSettings()['enabled'] ?? false), 404);

        try {
            $identity = $account->handleCallback($request, $request->query());
            $user = $this->resolveLocalUser($identity);
            $this->assertLocalAccessAllowed($user);

            Auth::guard('web')->login($user, true);
            $request->session()->regenerate();

            return redirect($this->safeReturnPath($request->session()->pull('pbb_account.return_to', '/admin')))
                ->with('account_login_success', true);
        } catch (HttpExceptionInterface $exception) {
            return redirect('/')->with('account_login_error', $exception->getMessage() ?: 'Account sign in was rejected.');
        } catch (Throwable $exception) {
            report($exception);

            return redirect('/')->with('account_login_error', $exception->getMessage() ?: 'Unable to complete Account sign in.');
        }
    }

    public function logout(Request $request, AccountClient $account): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if (! (bool) ($account->ssoSettings()['enabled'] ?? false)) {
            return redirect('/');
        }

        return redirect()->away($account->logoutUrl());
    }

    /**
     * @param array<string, mixed> $identity
     */
    private function resolveLocalUser(array $identity): User
    {
        $pbbUserId = trim((string) ($identity['pbb_user_id'] ?? ''));
        $email = mb_strtolower(trim((string) ($identity['email'] ?? '')));
        $name = trim((string) ($identity['name'] ?? '')) ?: ($email ?: 'PBB Realtime User');

        abort_if($pbbUserId === '', 422, 'Account identity is missing pbb_user_id.');

        $user = User::query()->where('pbb_user_id', $pbbUserId)->first();

        if (!$user && $email !== '') {
            $user = User::query()->where('email', $email)->first();
            abort_if($user && $user->pbb_user_id && $user->pbb_user_id !== $pbbUserId, 409, 'This email is already linked to another Account identity.');
        }

        if (!$user) {
            $user = new User([
                'password' => Hash::make(Str::random(64)),
                'user_type' => 'regular',
                'is_operator' => true,
                'status' => 'active',
            ]);
        }

        $user->pbb_user_id = $pbbUserId;
        $user->name = $name;
        $user->status = $user->status ?: 'active';
        if ($email !== '') {
            $user->email = $email;
        }
        $user->save();

        return $user;
    }

    private function assertLocalAccessAllowed(User $user): void
    {
        abort_unless($user->isActive(), 403, 'This account is disabled in PBB Realtime.');
        abort_unless($user->canAccessAdminSurface(), 403, 'This account is not authorized for the PBB Realtime admin surface.');
    }

    private function safeReturnPath(mixed $value): string
    {
        $path = trim((string) $value);

        if ($path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return '/admin';
        }

        return $path;
    }
}
