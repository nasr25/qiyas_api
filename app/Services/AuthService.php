<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * AuthService handles authentication for both Local and LDAP users.
 */
class AuthService
{
    public function __construct(private readonly LdapService $ldap) {}

    /**
     * Attempts to authenticate a user.
     * First tries local DB, then LDAP if not found locally.
     *
     * @param  string  $username  Username
     * @param  string  $password  Plain text password
     * @return array{token: string, user: User}|null
     */
    public function attempt(string $username, string $password): ?array
    {
        // Try local user first
        $user = User::where('username', $username)->first();

        if ($user && $user->auth_type === 'local') {
            if (! Hash::check($password, $user->password)) {
                return null;
            }
        } elseif ($user && $user->auth_type === 'ldap') {
            $ldapUser = $this->ldap->authenticate($username, $password);
            if (! $ldapUser) {
                return null;
            }
        } else {
            // Unknown user — try LDAP
            $ldapUser = $this->ldap->authenticate($username, $password);
            if (! $ldapUser) {
                return null;
            }

            // LDAP user not yet in local DB — they must be added by admin first
            return null;
        }

        if (! $user->is_active) {
            return null;
        }

        $this->updateLastLogin($user);
        AuditService::logLogin($user->id, $user->auth_type);

        $token = JWTAuth::fromUser($user);

        return ['token' => $token, 'user' => $user];
    }

    /**
     * Issues a JWT for a username WITHOUT a password (dev quick-login only —
     * the caller is responsible for gating this to local/debug environments).
     *
     * @return array{token: string, user: User}|null
     */
    public function issueTokenFor(string $username): ?array
    {
        $user = User::where('username', $username)->where('auth_type', 'local')->first();
        if (! $user || ! $user->is_active) {
            return null;
        }

        $this->updateLastLogin($user);
        AuditService::logLogin($user->id, $user->auth_type, 'auth.quick_login');

        return ['token' => JWTAuth::fromUser($user), 'user' => $user];
    }

    /**
     * Logs out the current user and invalidates the JWT token.
     */
    public function logout(): void
    {
        $user = auth()->user();
        if ($user) {
            AuditService::logLogout($user->id);
            JWTAuth::invalidate(JWTAuth::getToken());
        }
    }

    /**
     * Refreshes the JWT token.
     *
     * @return string New JWT token
     */
    public function refreshToken(): string
    {
        return JWTAuth::refresh(JWTAuth::getToken());
    }

    /**
     * Creates a new local user account.
     *
     * @param  array  $data  User data including username, email, password
     */
    public function createLocalUser(array $data): User
    {
        return User::create([
            'name' => $data['name'],
            'username' => $data['username'],
            'email' => $data['email'] ?? null,
            'password' => $data['password'],
            'auth_type' => 'local',
            'department_id' => $data['department_id'] ?? null,
            'is_active' => true,
            'must_change_password' => $data['must_change_password'] ?? true,
            'locale' => $data['locale'] ?? 'ar',
        ]);
    }

    /**
     * Creates or updates a user record from LDAP attributes.
     *
     * @param  array  $ldapData  Data from Active Directory
     * @param  array  $extra  Additional platform-specific fields
     */
    public function upsertLdapUser(array $ldapData, array $extra = []): User
    {
        return User::updateOrCreate(
            ['username' => $ldapData['username']],
            [
                'name' => $ldapData['display_name'],
                'email' => $ldapData['email'] ?? null,
                'auth_type' => 'ldap',
                'department_id' => $extra['department_id'] ?? null,
                'is_active' => true,
                'locale' => $extra['locale'] ?? 'ar',
            ]
        );
    }

    /** Updates the user's last login timestamp and IP. */
    private function updateLastLogin(User $user): void
    {
        $user->update([
            'last_login_at' => now(),
            'last_login_ip' => request()->ip(),
        ]);
    }
}
