<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * LdapService handles Active Directory queries and authentication.
 * Uses native LDAP extension to avoid heavy AD packages.
 */
class LdapService
{
    private ?string $host;

    private int $port;

    private string $baseDn;

    private ?string $bindUser;

    private ?string $bindPassword;

    private bool $useSsl;

    private bool $useTls;

    public function __construct()
    {
        $this->host = config('ldap.host', env('LDAP_HOST'));
        $this->port = (int) config('ldap.port', env('LDAP_PORT', 389));
        $this->baseDn = config('ldap.base_dn', env('LDAP_BASE_DN', 'dc=company,dc=local'));
        $this->bindUser = config('ldap.username', env('LDAP_USERNAME'));
        $this->bindPassword = config('ldap.password', env('LDAP_PASSWORD'));
        $this->useSsl = (bool) config('ldap.use_ssl', env('LDAP_USE_SSL', false));
        $this->useTls = (bool) config('ldap.use_tls', env('LDAP_USE_TLS', false));
    }

    /**
     * Checks if LDAP is configured and available.
     */
    public function isConfigured(): bool
    {
        return ! empty($this->host) && function_exists('ldap_connect');
    }

    /**
     * Attempts to authenticate a user against Active Directory.
     *
     * @param  string  $username  SAM account name or UPN
     * @param  string  $password  Plain text password
     * @return array|null User attributes on success, null on failure
     */
    public function authenticate(string $username, string $password): ?array
    {
        // Defense in depth against the classic "unauthenticated LDAP bind":
        // many directory servers (including AD, depending on configuration)
        // treat ldap_bind() with a blank/whitespace-only password as an
        // anonymous bind and report success without checking the password
        // at all. The login form already requires a non-empty password
        // (see LoginRequest), but that must not be the only guard — this
        // service is called directly by AuthService and must refuse to
        // even attempt a bind with an empty credential, checked first and
        // unconditionally so it can never be skipped by configuration state.
        if (trim($password) === '') {
            return null;
        }

        if (! $this->isConfigured()) {
            Log::warning('LDAP not configured');

            return null;
        }

        try {
            $protocol = $this->useSsl ? 'ldaps://' : 'ldap://';
            $conn = ldap_connect("{$protocol}{$this->host}", $this->port);

            ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);

            if ($this->useTls) {
                ldap_start_tls($conn);
            }

            // Determine bind DN (support UPN or sAMAccountName)
            $bindDn = str_contains($username, '@')
                ? $username
                : "{$username}@".$this->getDomainFromBaseDn();

            if (! @ldap_bind($conn, $bindDn, $password)) {
                return null;
            }

            // Fetch user attributes
            $user = $this->findUser($conn, $username);
            ldap_unbind($conn);

            return $user;
        } catch (\Exception $e) {
            Log::error('LDAP authentication error: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Searches Active Directory for users matching the given query.
     * Used by Super Admin when adding AD users to the platform.
     *
     * @param  string  $query  Partial username or display name
     * @return array List of matching users
     */
    public function searchUsers(string $query): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        // Active Directory rejects searches over an anonymous bind with an
        // "Operations error". A directory search therefore requires a service
        // (bind) account. If none is configured, skip cleanly instead of
        // emitting warnings.
        if (empty($this->bindUser) || empty($this->bindPassword)) {
            Log::info('LDAP search skipped: no service (bind) account configured (LDAP_USERNAME/LDAP_PASSWORD).');

            return [];
        }

        try {
            $protocol = $this->useSsl ? 'ldaps://' : 'ldap://';
            $conn = @ldap_connect("{$protocol}{$this->host}", $this->port);
            if (! $conn) {
                Log::error('LDAP connection failed for '.$this->host);

                return [];
            }

            ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);

            if ($this->useTls && ! @ldap_start_tls($conn)) {
                Log::error('LDAP start_tls failed');

                return [];
            }

            if (! @ldap_bind($conn, $this->bindUser, $this->bindPassword)) {
                Log::error('LDAP service account bind failed');

                return [];
            }

            $escaped = ldap_escape($query, '', LDAP_ESCAPE_FILTER);
            $filter = "(|(sAMAccountName=*{$escaped}*)(displayName=*{$escaped}*)(mail=*{$escaped}*))";
            $attrs = ['sAMAccountName', 'displayName', 'mail', 'department', 'title'];

            $result = @ldap_search($conn, $this->baseDn, $filter, $attrs, 0, 50);
            if ($result === false) {
                Log::error('LDAP search failed: '.ldap_error($conn));
                ldap_unbind($conn);

                return [];
            }
            $entries = ldap_get_entries($conn, $result);
            ldap_unbind($conn);

            $users = [];
            for ($i = 0; $i < $entries['count']; $i++) {
                $entry = $entries[$i];
                $users[] = [
                    'username' => $entry['samaccountname'][0] ?? '',
                    'display_name' => $entry['displayname'][0] ?? '',
                    'email' => $entry['mail'][0] ?? '',
                    'department' => $entry['department'][0] ?? '',
                    'title' => $entry['title'][0] ?? '',
                ];
            }

            return $users;
        } catch (\Exception $e) {
            Log::error('LDAP search error: '.$e->getMessage());

            return [];
        }
    }

    /**
     * Fetches a single user's attributes from Active Directory.
     *
     * @param  resource  $conn  Active LDAP connection
     * @param  string  $username  SAM account name
     */
    private function findUser($conn, string $username): ?array
    {
        $escaped = ldap_escape($username, '', LDAP_ESCAPE_FILTER);
        $filter = "(sAMAccountName={$escaped})";
        $attrs = ['sAMAccountName', 'displayName', 'mail', 'department'];

        // Bind with service account for attribute lookup
        if ($this->bindUser && $this->bindPassword) {
            @ldap_bind($conn, $this->bindUser, $this->bindPassword);
        }

        $result = @ldap_search($conn, $this->baseDn, $filter, $attrs);
        if ($result === false) {
            Log::error('LDAP user lookup failed: '.ldap_error($conn));

            return null;
        }
        $entries = ldap_get_entries($conn, $result);

        if (! $entries || $entries['count'] === 0) {
            return null;
        }

        $entry = $entries[0];

        return [
            'username' => $entry['samaccountname'][0] ?? $username,
            'display_name' => $entry['displayname'][0] ?? $username,
            'email' => $entry['mail'][0] ?? null,
            'department' => $entry['department'][0] ?? null,
        ];
    }

    /** Derives domain suffix from base DN (e.g., dc=company,dc=local → company.local). */
    private function getDomainFromBaseDn(): string
    {
        preg_match_all('/dc=([^,]+)/i', $this->baseDn, $matches);

        return implode('.', $matches[1]);
    }
}
