<?php

namespace hexa_package_google_docs\Services;

use Google\Client;
use Google\Service\Oauth2;
use hexa_core\Models\Setting;
use hexa_core\Services\CredentialService;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;
use RuntimeException;

class GoogleDocsOAuthService
{
    private const SESSION_KEY = 'google_docs.oauth_states';

    private const STATE_TTL_SECONDS = 600;

    private const SCOPES = [
        'openid',
        'email',
        'profile',
        'https://www.googleapis.com/auth/documents',
        'https://www.googleapis.com/auth/drive',
    ];

    public function __construct(
        protected CredentialService $credentials,
        protected GoogleDocsWriteService $write,
    ) {
    }

    public function authorizationUrl(Session $session, string $accountId, ?int $userId): string
    {
        $writer = $this->write->forAccount($accountId);
        $client = $this->clientFor($writer);
        $state = Str::random(64);
        $verifier = $this->base64Url(random_bytes(64));
        $states = $this->freshStates((array) $session->get(self::SESSION_KEY, []));
        $expectedEmail = $this->expectedEmail($writer);

        $states[$state] = [
            'account_id' => $writer->accountId(),
            'expected_email' => $expectedEmail,
            'user_id' => $userId,
            'verifier' => $verifier,
            'created_at' => time(),
        ];
        $session->put(self::SESSION_KEY, $states);

        $client->setState($state);
        if ($expectedEmail !== '') {
            $client->setLoginHint($expectedEmail);
        }

        return $client->createAuthUrl(null, [
            'code_challenge' => $this->base64Url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
        ]);
    }

    /** @return array{account_id:string, connected_email:string, success:bool, message:string, test:array<string, mixed>} */
    public function complete(Session $session, string $state, string $code, ?int $userId): array
    {
        $attempt = $this->consumeState($session, $state);
        if ((int) ($attempt['user_id'] ?? 0) !== (int) $userId) {
            throw new RuntimeException('This Google authorization was started by a different signed-in user. Start again.');
        }

        $writer = $this->write->forAccount((string) $attempt['account_id']);
        $client = $this->clientFor($writer);
        $token = $client->fetchAccessTokenWithAuthCode($code, (string) $attempt['verifier']);
        if (!is_array($token) || empty($token['access_token'])) {
            $message = is_array($token)
                ? (string) ($token['error_description'] ?? $token['error'] ?? 'Unknown token error')
                : 'Unknown token error';
            throw new RuntimeException('Google OAuth token exchange failed: '.$message);
        }

        $refreshToken = trim((string) ($token['refresh_token'] ?? ''));
        if ($refreshToken === '') {
            throw new RuntimeException('Google did not issue a new offline refresh token. Remove the app from your Google Account connections, then click Refresh token and approve access again.');
        }

        $client->setAccessToken($token);
        $userInfo = (new Oauth2($client))->userinfo->get();
        $email = trim((string) $userInfo->getEmail());
        if ($email === '') {
            throw new RuntimeException('Google authorized access but did not return the signed-in email address. Start again and approve the email permission.');
        }

        $expectedEmail = trim((string) ($attempt['expected_email'] ?? ''));
        if ($expectedEmail !== '' && strcasecmp($expectedEmail, $email) !== 0) {
            throw new RuntimeException('Google authorized '.$email.', but this profile is '.$expectedEmail.'. Click Refresh token again and choose the correct Google account.');
        }

        $this->credentials->store($writer->credentialSlug(), 'oauth_refresh_token', $refreshToken);
        Setting::setValue($writer->accountSettingKey('auth_mode'), 'oauth_user', 'packages');
        Setting::setValue($writer->accountSettingKey('connected_email'), $email, 'packages');

        $test = $writer->testWriteConnection();
        $success = (bool) ($test['success'] ?? false);
        $testMessage = trim((string) ($test['message'] ?? 'Google did not return a connection result.'));

        return [
            'account_id' => $writer->accountId(),
            'connected_email' => $email,
            'success' => $success,
            'message' => $success
                ? 'Refresh token saved and Google Docs connection verified as '.$email.'.'
                : 'Refresh token saved for '.$email.', but the automatic connection test failed: '.$testMessage,
            'test' => $test,
        ];
    }

    public function accountIdForState(Session $session, string $state): ?string
    {
        $states = $this->freshStates((array) $session->get(self::SESSION_KEY, []));
        $session->put(self::SESSION_KEY, $states);
        $accountId = $states[$state]['account_id'] ?? null;

        return is_string($accountId) && $accountId !== '' ? $accountId : null;
    }

    public function cancel(Session $session, string $state): ?string
    {
        $states = $this->freshStates((array) $session->get(self::SESSION_KEY, []));
        $accountId = $states[$state]['account_id'] ?? null;
        unset($states[$state]);
        $session->put(self::SESSION_KEY, $states);

        return is_string($accountId) && $accountId !== '' ? $accountId : null;
    }

    protected function clientFor(GoogleDocsWriteService $writer): Client
    {
        $clientId = trim((string) $this->credentials->get($writer->credentialSlug(), 'oauth_client_id'));
        $clientSecret = trim((string) $this->credentials->get($writer->credentialSlug(), 'oauth_client_secret'));
        if ($clientId === '' || $clientSecret === '') {
            throw new RuntimeException('Save this account\'s Google OAuth client ID and client secret before refreshing its token.');
        }

        $client = new Client();
        $client->setApplicationName('Hexa Google Docs');
        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);
        $client->setRedirectUri(route('settings.google-docs.oauth.callback'));
        $client->setScopes(self::SCOPES);
        $client->setAccessType('offline');
        $client->setPrompt('consent select_account');
        $client->setIncludeGrantedScopes(true);

        return $client;
    }

    protected function expectedEmail(GoogleDocsWriteService $writer): string
    {
        if ($writer->accountId() === 'legacy') {
            return trim((string) Setting::getValue($writer->accountSettingKey('connected_email'), ''));
        }

        return trim((string) Setting::getValue($writer->accountSettingKey('label'), ''));
    }

    /** @return array<string, mixed> */
    protected function consumeState(Session $session, string $state): array
    {
        $states = $this->freshStates((array) $session->get(self::SESSION_KEY, []));
        $attempt = $states[$state] ?? null;
        unset($states[$state]);
        $session->put(self::SESSION_KEY, $states);

        if (!is_array($attempt) || empty($attempt['account_id']) || empty($attempt['verifier'])) {
            throw new RuntimeException('The Google OAuth request is invalid or expired. Click Refresh token and try again.');
        }

        return $attempt;
    }

    /** @param array<string, array<string, mixed>> $states */
    protected function freshStates(array $states): array
    {
        $cutoff = time() - self::STATE_TTL_SECONDS;

        return array_filter(
            $states,
            static fn ($entry): bool => is_array($entry) && (int) ($entry['created_at'] ?? 0) >= $cutoff,
        );
    }

    protected function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
