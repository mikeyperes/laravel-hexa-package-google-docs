<?php

namespace hexa_package_google_docs\Services;

use hexa_core\Models\Setting;
use hexa_core\Services\CredentialService;
use Illuminate\Support\Facades\Cache;

class GoogleDocsWriteService
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const JWT_GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:jwt-bearer';
    private const DRIVE_API_BASE = 'https://www.googleapis.com/drive/v3';
    private const DOCS_API_BASE = 'https://docs.googleapis.com/v1/documents';

    public function __construct(protected CredentialService $credentials) {}

    public function authMode(): string { $v = (string) Setting::getValue('google_docs_auth_mode', config('google-docs.auth_mode', 'public_read')); return in_array($v, ['public_read','oauth_user','service_account'], true) ? $v : 'public_read'; }
    public function ownerEmail(): string { return trim((string) Setting::getValue('google_docs_owner_email', config('google-docs.owner_email', ''))); }
    public function defaultFolderId(): string { return trim((string) Setting::getValue('google_docs_default_folder_id', config('google-docs.default_folder_id', ''))); }

    public function writeContext(): array
    {
        $mode = $this->authMode();
        return [
            'auth_mode' => $mode,
            'connected_email' => trim((string) Setting::getValue('google_docs_connected_email', '')) ?: null,
            'owner_email' => $this->ownerEmail(),
            'default_folder_id' => $this->defaultFolderId(),
            'has_write_access' => $mode === 'oauth_user'
                ? ($this->credentials->exists('google-docs','oauth_client_id') && $this->credentials->exists('google-docs','oauth_client_secret') && $this->credentials->exists('google-docs','oauth_refresh_token'))
                : ($mode === 'service_account' ? ($this->serviceAccountJson() !== '') : false),
        ];
    }

    public function testWriteConnection(): array
    {
        $token = $this->token(); if (!($token['success'] ?? false)) return $token;
        $mode = $token['auth_mode'];
        $url = $mode === 'oauth_user' ? 'https://www.googleapis.com/oauth2/v2/userinfo' : self::DRIVE_API_BASE . '/about?fields=user(emailAddress,displayName)';
        $res = $this->req('GET', $url, $this->auth((string) $token['access_token']));
        if (!($res['success'] ?? false)) return ['success' => false, 'message' => $res['error'] ?? 'Google identity lookup failed.'];
        $email = $mode === 'oauth_user' ? trim((string) ($res['data']['email'] ?? '')) : trim((string) ($res['data']['user']['emailAddress'] ?? ''));
        if ($email !== '') Setting::setValue('google_docs_connected_email', $email, 'packages');
        return ['success' => true, 'message' => 'Google Docs write connection verified' . ($email ? ' as ' . $email : '') . '.', 'connected_email' => $email];
    }

    public function createDocumentFromHtml(string $title, string $html): array { return $this->export(null, $title, $html); }
    public function updateDocumentFromHtml(string $id, string $title, string $html): array { return $this->export($id, $title, $html, true); }

    public function export(?string $id, string $title, string $html, bool $preserve = false): array
    {
        $token = $this->token(); if (!($token['success'] ?? false)) return $token;
        $text = $this->plain($html); if (trim($text) === '') return ['success' => false, 'message' => 'Google Doc export requires non-empty article content.'];
        $id = $id ?: $this->create((trim($title) ?: 'Untitled Document'), (string) $token['access_token']); if (!$id) return ['success' => false, 'message' => 'Failed to create the Google Doc file.'];
        if (trim($title) !== '') $this->rename($id, trim($title), (string) $token['access_token']);
        $write = $this->replace($id, $text, (string) $token['access_token']);
        if (!($write['success'] ?? false)) { if (!$preserve) $this->deleteDocument($id, true); return $write; }
        $meta = $this->meta($id, (string) $token['access_token']); if (!($meta['success'] ?? false)) return $meta;
        $shared = $this->ensureOwnerAccess($id, (string) $token['access_token'], (string) ($meta['file']['owner_email'] ?? ''), (string) ($token['connected_email'] ?? ''));
        return ['success' => true, 'message' => $preserve ? 'Google Doc updated successfully.' : 'Google Doc created successfully.', 'document_id' => $id, 'normalized_url' => 'https://docs.google.com/document/d/' . $id . '/edit', 'web_view_link' => (string) ($meta['file']['web_view_link'] ?? ''), 'owner_email' => (string) ($meta['file']['owner_email'] ?? ''), 'connected_email' => (string) ($token['connected_email'] ?? ''), 'shared_with_requested_owner' => $shared, 'file' => $meta['file']];
    }

    public function deleteDocument(string $value, bool $quiet = false): array
    {
        $id = $this->id($value); if (!$id) return ['success' => false, 'message' => 'Missing Google Doc ID.'];
        $token = $this->token(); if (!($token['success'] ?? false)) return $token;
        $res = $this->req('DELETE', self::DRIVE_API_BASE . '/files/' . urlencode($id), $this->auth((string) $token['access_token']), null, true);
        if (!($res['success'] ?? false) && !$quiet) return ['success' => false, 'message' => $res['error'] ?? 'Failed to delete the Google Doc.'];
        return ['success' => true, 'message' => 'Google Doc deleted successfully.', 'document_id' => $id];
    }

    public function smokeTestWrite(): array
    {
        $title = 'Hexa Google Docs smoke test ' . now()->format('Y-m-d H:i:s');
        $res = $this->createDocumentFromHtml($title, '<h1>' . e($title) . '</h1><p>Temporary smoke-test document.</p>');
        if (!($res['success'] ?? false)) return $res; if (!empty($res['document_id'])) $this->deleteDocument((string) $res['document_id'], true);
        return ['success' => true, 'message' => 'Google Docs smoke test passed.', 'document_id' => (string) ($res['document_id'] ?? '')];
    }

    protected function token(): array
    {
        if ($this->authMode() === 'public_read') return ['success' => false, 'message' => 'Google Docs write access is not configured.'];
        if ($this->authMode() === 'oauth_user') {
            $id = trim((string) $this->credentials->get('google-docs','oauth_client_id')); $secret = trim((string) $this->credentials->get('google-docs','oauth_client_secret')); $refresh = trim((string) $this->credentials->get('google-docs','oauth_refresh_token'));
            if ('' === $id || '' === $secret || '' === $refresh) return ['success' => false, 'message' => 'Google Docs OAuth client ID, client secret, or refresh token is missing.'];
            $key = 'gdocs_oauth_' . md5($id . '|' . $refresh); if ($cached = Cache::get($key)) return ['success' => true, 'auth_mode' => 'oauth_user', 'access_token' => $cached, 'connected_email' => trim((string) Setting::getValue('google_docs_connected_email', '')) ?: null];
            $res = $this->req('POST', self::TOKEN_URL, ['Content-Type: application/x-www-form-urlencoded'], http_build_query(['client_id'=>$id,'client_secret'=>$secret,'refresh_token'=>$refresh,'grant_type'=>'refresh_token']));
            if (!($res['success'] ?? false) || empty($res['data']['access_token'])) return ['success' => false, 'message' => 'Failed to refresh the Google Docs OAuth token: ' . ($res['error'] ?? 'Unknown error')];
            $token = (string) $res['data']['access_token']; Cache::put($key, $token, max(60, ((int) ($res['data']['expires_in'] ?? 3600)) - 120));
            return ['success' => true, 'auth_mode' => 'oauth_user', 'access_token' => $token, 'connected_email' => trim((string) Setting::getValue('google_docs_connected_email', '')) ?: null];
        }
        $json = $this->serviceAccountJson(); if ('' === $json) return ['success' => false, 'message' => 'Google Docs service-account JSON is missing.'];
        $sa = json_decode($json, true); if (!is_array($sa) || empty($sa['client_email']) || empty($sa['private_key'])) return ['success' => false, 'message' => 'Stored Google Docs service-account JSON is invalid.'];
        $key = 'gdocs_sa_' . md5((string) $sa['client_email']); if ($cached = Cache::get($key)) return ['success' => true, 'auth_mode' => 'service_account', 'access_token' => $cached, 'connected_email' => (string) $sa['client_email']];
        $now = time(); $header = $this->b64(json_encode(['alg'=>'RS256','typ'=>'JWT'])); $claims = $this->b64(json_encode(['iss'=>$sa['client_email'],'scope'=>'https://www.googleapis.com/auth/drive https://www.googleapis.com/auth/drive.file https://www.googleapis.com/auth/documents','aud'=>self::TOKEN_URL,'iat'=>$now,'exp'=>$now+3600]));
        $pk = openssl_pkey_get_private($sa['private_key']); if (!$pk) return ['success' => false, 'message' => 'Unable to load the Google Docs service-account private key.']; $sig=''; if (!openssl_sign($header . '.' . $claims, $sig, $pk, OPENSSL_ALGO_SHA256)) return ['success' => false, 'message' => 'Failed to sign the Google Docs service-account JWT assertion.'];
        $res = $this->req('POST', self::TOKEN_URL, ['Content-Type: application/x-www-form-urlencoded'], http_build_query(['grant_type'=>self::JWT_GRANT_TYPE,'assertion'=>$header . '.' . $claims . '.' . $this->b64($sig)]));
        if (!($res['success'] ?? false) || empty($res['data']['access_token'])) return ['success' => false, 'message' => 'Failed to generate the Google Docs service-account token: ' . ($res['error'] ?? 'Unknown error')];
        $token = (string) $res['data']['access_token']; Cache::put($key, $token, max(60, ((int) ($res['data']['expires_in'] ?? 3600)) - 120)); return ['success' => true, 'auth_mode' => 'service_account', 'access_token' => $token, 'connected_email' => (string) $sa['client_email']];
    }

    protected function create(string $title, string $token): ?string
    {
        $body = ['name' => $title, 'mimeType' => 'application/vnd.google-apps.document']; if ('' !== $this->defaultFolderId()) $body['parents'] = [$this->defaultFolderId()];
        $res = $this->req('POST', self::DRIVE_API_BASE . '/files?fields=id', array_merge($this->auth((string) $token), ['Content-Type: application/json']), json_encode($body, JSON_UNESCAPED_SLASHES));
        return ($res['success'] ?? false) ? (string) ($res['data']['id'] ?? '') : null;
    }

    protected function rename(string $id, string $title, string $token): void
    {
        $this->req('PATCH', self::DRIVE_API_BASE . '/files/' . urlencode($id) . '?fields=id', array_merge($this->auth((string) $token), ['Content-Type: application/json']), json_encode(['name' => $title], JSON_UNESCAPED_SLASHES));
    }

    protected function replace(string $id, string $text, string $token): array
    {
        $doc = $this->req('GET', self::DOCS_API_BASE . '/' . urlencode($id), $this->auth($token)); if (!($doc['success'] ?? false)) return ['success' => false, 'message' => $doc['error'] ?? 'Failed to load the Google Doc before update.'];
        $content = (array) data_get($doc, 'data.body.content', []); $last = $content !== [] ? end($content) : []; $end = (int) (is_array($last) ? ($last['endIndex'] ?? 1) : 1);
        $requests = []; if ($end > 1) $requests[] = ['deleteContentRange' => ['range' => ['startIndex' => 1, 'endIndex' => max(1, $end - 1)]]]; $requests[] = ['insertText' => ['location' => ['index' => 1], 'text' => $text]];
        $res = $this->req('POST', self::DOCS_API_BASE . '/' . urlencode($id) . ':batchUpdate', array_merge($this->auth($token), ['Content-Type: application/json']), json_encode(['requests' => $requests], JSON_UNESCAPED_SLASHES));
        return ($res['success'] ?? false) ? ['success' => true] : ['success' => false, 'message' => $res['error'] ?? 'Failed to write content into the Google Doc.'];
    }

    protected function meta(string $id, string $token): array
    {
        $res = $this->req('GET', self::DRIVE_API_BASE . '/files/' . urlencode($id) . '?fields=id,name,webViewLink,owners(emailAddress)', $this->auth($token));
        if (!($res['success'] ?? false)) return ['success' => false, 'message' => $res['error'] ?? 'Failed to load Google Doc metadata.'];
        $f = (array) $res['data']; return ['success' => true, 'file' => ['id' => (string) ($f['id'] ?? ''), 'name' => (string) ($f['name'] ?? ''), 'web_view_link' => (string) ($f['webViewLink'] ?? ''), 'owner_email' => (string) ($f['owners'][0]['emailAddress'] ?? '')]];
    }

    protected function serviceAccountJson(): string
    {
        $json = trim((string) $this->credentials->get('google-docs', 'service_account_json'));
        if ($json !== '') return $json;
        return trim((string) $this->credentials->get('google-drive', 'service_account_json'));
    }

    protected function ensureOwnerAccess(string $id, string $token, string $ownerEmail, string $connectedEmail): bool
    {
        $requested = $this->ownerEmail();
        if ($requested === '') return false;
        if (strcasecmp($requested, $ownerEmail) === 0) return false;
        if ($connectedEmail !== '' && strcasecmp($requested, $connectedEmail) === 0) return false;
        $payload = json_encode(['role' => 'writer', 'type' => 'user', 'emailAddress' => $requested], JSON_UNESCAPED_SLASHES);
        $res = $this->req('POST', self::DRIVE_API_BASE . '/files/' . urlencode($id) . '/permissions?sendNotificationEmail=false&fields=id', array_merge($this->auth($token), ['Content-Type: application/json']), $payload);
        return (bool) ($res['success'] ?? false);
    }

    protected function id(string $value): ?string
    {
        $value = trim($value); if ('' === $value) return null;
        if (preg_match('#/document/(?:u/\d+/)?d/([a-zA-Z0-9_-]+)#', $value, $m)) return $m[1];
        return preg_match('/^[a-zA-Z0-9_-]{20,}$/', $value) ? $value : null;
    }

    protected function plain(string $html): string
    {
        $h = preg_replace('/<br\s*\/?\s*>/i', chr(10), $html) ?? $html;
        $h = preg_replace('/<\/(p|div|li|h1|h2|h3|h4|h5|h6|tr)>/i', chr(10), $h) ?? $h;
        $t = html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, "UTF-8");
        $t = str_replace([chr(13) . chr(10), chr(13)], chr(10), $t);
        $t = preg_replace('/[ 	]+/', " ", $t) ?? $t;
        while (str_contains($t, chr(10) . chr(10) . chr(10))) { $t = str_replace(chr(10) . chr(10) . chr(10), chr(10) . chr(10), $t); }
        return trim($t) . chr(10);
    }


    protected function b64(string $data): string { return rtrim(strtr(base64_encode($data), '+/', '-_'), '='); }
    protected function auth(string $token): array { return ['Authorization: Bearer ' . $token]; }

    protected function req(string $method, string $url, array $headers = [], ?string $body = null, bool $raw = false): array
    {
        $ch = curl_init(); curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_CUSTOMREQUEST => strtoupper($method), CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers)]); if (null !== $body) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $out = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
        if (false === $out) return ['success' => false, 'error' => $err ?: 'cURL request failed.', 'status' => $code];
        if ($code < 200 || $code >= 300) { $msg = 'HTTP ' . $code; $json = json_decode($out, true); if (is_array($json)) $msg = $json['error']['message'] ?? $json['message'] ?? $msg; return ['success' => false, 'error' => $msg, 'status' => $code]; }
        if ($raw) return ['success' => true, 'data' => $out, 'status' => $code];
        $json = json_decode($out, true); if (JSON_ERROR_NONE !== json_last_error()) return ['success' => false, 'error' => 'Invalid JSON response from Google API.', 'status' => $code]; return ['success' => true, 'data' => $json, 'status' => $code];
    }
}
