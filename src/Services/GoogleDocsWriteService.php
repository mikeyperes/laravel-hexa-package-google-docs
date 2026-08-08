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
    public function serviceAccountEmail(): string
    {
        $json = $this->serviceAccountJson();
        if ($json === '') return '';
        $serviceAccount = json_decode($json, true);
        return is_array($serviceAccount) ? trim((string) ($serviceAccount['client_email'] ?? '')) : '';
    }
    public function defaultFolderId(): string { return trim((string) Setting::getValue('google_docs_default_folder_id', config('google-docs.default_folder_id', ''))); }

    public function writeContext(): array
    {
        $mode = $this->authMode();
        $hasOauthCredentials = $this->credentials->exists('google-docs', 'oauth_client_id')
            && $this->credentials->exists('google-docs', 'oauth_client_secret')
            && $this->credentials->exists('google-docs', 'oauth_refresh_token');
        $hasServiceAccount = $this->serviceAccountJson() !== '';

        return [
            'auth_mode' => $mode,
            'connected_email' => trim((string) Setting::getValue('google_docs_connected_email', '')) ?: null,
            'service_account_email' => $this->serviceAccountEmail() ?: null,
            'owner_email' => $this->ownerEmail(),
            'default_folder_id' => $this->defaultFolderId(),
            'has_oauth_credentials' => $hasOauthCredentials,
            'has_service_account' => $hasServiceAccount,
            'has_write_access' => $mode === 'oauth_user'
                ? $hasOauthCredentials
                : ($mode === 'service_account' ? $hasServiceAccount : false),
        ];
    }

    public function testWriteConnection(): array
    {
        $token = $this->token(); if (!($token['success'] ?? false)) return $token;
        $res = $this->req('GET', self::DRIVE_API_BASE . '/about?fields=user(emailAddress,displayName)', $this->auth((string) $token['access_token']));
        if (!($res['success'] ?? false)) return ['success' => false, 'message' => $res['error'] ?? 'Google identity lookup failed.'];
        $email = trim((string) ($res['data']['user']['emailAddress'] ?? ''));
        if ($email !== '') Setting::setValue('google_docs_connected_email', $email, 'packages');
        return ['success' => true, 'message' => 'Google Docs write connection verified' . ($email ? ' as ' . $email : '') . '.', 'connected_email' => $email];
    }

    public function createDocumentFromHtml(string $title, string $html): array { return $this->export(null, $title, $html); }
    public function updateDocumentFromHtml(string $id, string $title, string $html): array { return $this->export($id, $title, $html, true); }

    public function export(?string $id, string $title, string $html, bool $preserve = false): array
    {
        $token = $this->token(); if (!($token["success"] ?? false)) return $token;
        if (trim(strip_tags($html)) === "") return ["success" => false, "message" => "Google Doc export requires non-empty article content."];
        $previousId = $id ? $this->id($id) : null;
        $imagePayload = $this->prepareInlineImageMarkers($html);
        $htmlForImport = (string) ($imagePayload["html"] ?? $html);
        $imageMarkers = is_array($imagePayload["images"] ?? null) ? $imagePayload["images"] : [];
        $import = $this->importHtmlDocument((trim($title) ?: "Untitled Document"), $htmlForImport, (string) $token["access_token"]);
        if (!($import["success"] ?? false)) return $import;
        $id = (string) ($import["document_id"] ?? "");
        if ($id === "") return ["success" => false, "message" => "Google Doc import did not return a document ID."];
        $insertedImages = $this->insertMarkedImages($id, $imageMarkers, (string) $token["access_token"]);
        if (!($insertedImages["success"] ?? false)) { if (!$preserve) $this->deleteDocument($id, true); return ["success" => false, "message" => (string) ($insertedImages["message"] ?? "Failed to insert images into the Google Doc."), "inserted_images" => (int) ($insertedImages["inserted_images"] ?? 0)]; }
        $meta = $this->meta($id, (string) $token["access_token"]); if (!($meta["success"] ?? false)) return $meta;
        $shared = $this->ensureOwnerAccess($id, (string) $token["access_token"], (string) ($meta["file"]["owner_email"] ?? ""), (string) ($token["connected_email"] ?? ""));
        $public = $this->ensurePublicEditableAccess($id, (string) $token["access_token"]);
        if (!($public["success"] ?? false)) { if (!$preserve) $this->deleteDocument($id, true); return ["success" => false, "message" => (string) ($public["message"] ?? "Failed to make the Google Doc publicly editable by link.")]; }
        if ($preserve && $previousId && $previousId !== $id) { $this->deleteDocument($previousId, true); }
        return ["success" => true, "message" => $preserve ? "Google Doc updated successfully." : "Google Doc created successfully.", "document_id" => $id, "normalized_url" => "https://docs.google.com/document/d/" . $id . "/edit", "web_view_link" => (string) ($meta["file"]["web_view_link"] ?? ""), "owner_email" => (string) ($meta["file"]["owner_email"] ?? ""), "connected_email" => (string) ($token["connected_email"] ?? ""), "shared_with_requested_owner" => $shared, "public_editable" => true, "public_role" => (string) ($public["role"] ?? "writer"), "public_access" => "anyone_with_link", "file" => $meta["file"], "inserted_images" => (int) ($insertedImages["inserted_images"] ?? 0)];
    }



    public function exportDocumentContent(string $value, string $format = "html"): array
    {
        $id = $this->id($value);
        if (!$id) return ["success" => false, "message" => "Missing Google Doc ID."];
        $format = in_array($format, ["html", "txt"], true) ? $format : "html";
        $mime = $format === "html" ? "text/html" : "text/plain";
        $token = $this->token();
        if (!($token["success"] ?? false)) return $token;

        $url = self::DRIVE_API_BASE . "/files/" . urlencode($id) . "/export?mimeType=" . rawurlencode($mime);
        $res = $this->req("GET", $url, $this->auth((string) $token["access_token"]), null, true);
        if (!($res["success"] ?? false)) {
            return [
                "success" => false,
                "message" => $res["error"] ?? "Failed to export the Google Doc through Google Drive API.",
                "document_id" => $id,
                "format" => $format,
                "mime_type" => $mime,
                "status" => $res["status"] ?? null,
            ];
        }

        $content = (string) ($res["data"] ?? "");
        if (trim($content) === "") {
            return [
                "success" => false,
                "message" => "Google Drive API export returned an empty response.",
                "document_id" => $id,
                "format" => $format,
                "mime_type" => $mime,
            ];
        }

        return [
            "success" => true,
            "message" => "Fetched Google Doc through Google Drive API.",
            "document_id" => $id,
            "normalized_url" => "https://docs.google.com/document/d/" . $id . "/edit",
            "format" => $format,
            "mime_type" => $mime,
            "byte_length" => strlen($content),
            "content" => $content,
            "connected_email" => (string) ($token["connected_email"] ?? ""),
            "auth_mode" => (string) ($token["auth_mode"] ?? ""),
        ];
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

    public function createFolder(string $name, ?string $parentId = null): array
    {
        $folderName = trim($name);
        if ($folderName === '') return ['success' => false, 'message' => 'Folder name is required.'];

        $token = $this->token();
        if (!($token['success'] ?? false)) return $token;

        $body = [
            'name' => $folderName,
            'mimeType' => 'application/vnd.google-apps.folder',
        ];

        $parentId = trim((string) $parentId);
        if ($parentId !== '') $body['parents'] = [$parentId];

        $res = $this->req(
            'POST',
            self::DRIVE_API_BASE . '/files?fields=id,name,webViewLink,parents',
            array_merge($this->auth((string) $token['access_token']), ['Content-Type: application/json']),
            json_encode($body, JSON_UNESCAPED_SLASHES)
        );

        if (!($res['success'] ?? false)) {
            return ['success' => false, 'message' => $res['error'] ?? 'Failed to create the Google Drive folder.'];
        }

        return [
            'success' => true,
            'message' => 'Google Drive folder created successfully.',
            'folder_id' => (string) ($res['data']['id'] ?? ''),
            'name' => (string) ($res['data']['name'] ?? $folderName),
            'web_view_link' => (string) ($res['data']['webViewLink'] ?? ''),
        ];
    }

    protected function token(): array
    {
        if ($this->authMode() === 'public_read') return ['success' => false, 'message' => 'Google Docs write access is not configured. Open Settings > Google Docs, switch Write auth mode to OAuth user write or Service account write, and save real write credentials first.'];
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
        $now = time(); $header = $this->b64(json_encode(['alg'=>'RS256','typ'=>'JWT'])); $claims = $this->b64(json_encode(['iss'=>$sa['client_email'],'scope'=>'https://www.googleapis.com/auth/drive.readonly https://www.googleapis.com/auth/drive.file https://www.googleapis.com/auth/documents.readonly https://www.googleapis.com/auth/documents','aud'=>self::TOKEN_URL,'iat'=>$now,'exp'=>$now+3600]));
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

    protected function importHtmlDocument(string $title, string $html, string $token): array
    {
        $html = $this->embedRemoteImagesForImport($html);
        $boundary = 'hexa-google-docs-' . md5($title . '|' . microtime(true));
        $metadata = ['name' => $title, 'mimeType' => 'application/vnd.google-apps.document'];
        if ('' !== $this->defaultFolderId()) {
            $metadata['parents'] = [$this->defaultFolderId()];
        }

        $body = '--' . $boundary . "
";
        $body .= "Content-Type: application/json; charset=UTF-8

";
        $body .= json_encode($metadata, JSON_UNESCAPED_SLASHES) . "
";
        $body .= '--' . $boundary . "
";
        $body .= "Content-Type: text/html; charset=UTF-8

";
        $body .= $html . "
";
        $body .= '--' . $boundary . "--
";

        $res = $this->req('POST', 'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id', array_merge($this->auth((string) $token), ['Content-Type: multipart/related; boundary=' . $boundary]), $body);
        if (!($res['success'] ?? false) || empty($res['data']['id'])) {
            return ['success' => false, 'message' => $res['error'] ?? 'Failed to create the Google Doc from HTML.'];
        }

        return ['success' => true, 'document_id' => (string) $res['data']['id']];
    }

    /**
     * Google Drive HTML import can drop image tags. Preserve image placement
     * with markers, then replace the markers through the Google Docs API.
     *
     * @return array{html:string,images:array<int,array{marker:string,url:string,alt:string}>}
     */
    protected function prepareInlineImageMarkers(string $html): array
    {
        if ($html === "" || stripos($html, "<img") === false) {
            return ["html" => $html, "images" => []];
        }

        $images = [];
        $index = 0;
        $updated = preg_replace_callback("/<img\\b([^>]*)>/iu", function (array $matches) use (&$images, &$index): string {
            $attributes = (string) ($matches[1] ?? "");
            if (!preg_match("/\\bsrc=([\"\\x27])(.*?)\\1/iu", $attributes, $srcMatch)) {
                return (string) ($matches[0] ?? "");
            }

            $url = html_entity_decode(trim((string) ($srcMatch[2] ?? "")), ENT_QUOTES | ENT_HTML5, "UTF-8");
            if (!preg_match("#^https?://#i", $url)) {
                return (string) ($matches[0] ?? "");
            }

            preg_match("/\\balt=([\"\\x27])(.*?)\\1/iu", $attributes, $altMatch);
            $alt = html_entity_decode(trim((string) ($altMatch[2] ?? "")), ENT_QUOTES | ENT_HTML5, "UTF-8");
            $marker = "HEXA_GOOGLE_DOC_IMAGE_" . $index . "_" . substr(sha1($url . "|" . $index), 0, 10);
            $images[] = ["marker" => $marker, "url" => $url, "alt" => $alt];
            $index++;

            return "<span>" . $marker . "</span>";
        }, $html);

        return ["html" => $updated ?? $html, "images" => $images];
    }

    protected function insertMarkedImages(string $id, array $images, string $token): array
    {
        if ($images === []) {
            return ["success" => true, "inserted_images" => 0];
        }

        $document = $this->req(
            "GET",
            self::DOCS_API_BASE . "/" . urlencode($id) . "?fields=body(content(startIndex,endIndex,paragraph(elements(startIndex,endIndex,textRun(content)))))",
            $this->auth($token)
        );
        if (!($document["success"] ?? false)) {
            return ["success" => false, "message" => $document["error"] ?? "Failed to inspect the imported Google Doc for image markers."];
        }

        $positions = $this->findImageMarkerPositions((array) ($document["data"] ?? []), array_column($images, "marker"));
        $requests = [];
        $inserted = 0;
        $byMarker = [];
        foreach ($images as $image) {
            if (!is_array($image) || empty($image["marker"]) || empty($image["url"])) {
                continue;
            }
            $byMarker[(string) $image["marker"]] = (string) $image["url"];
        }

        usort($positions, static fn (array $a, array $b): int => ((int) ($b["start"] ?? 0)) <=> ((int) ($a["start"] ?? 0)));
        foreach ($positions as $position) {
            $marker = (string) ($position["marker"] ?? "");
            $url = $byMarker[$marker] ?? "";
            $start = (int) ($position["start"] ?? 0);
            $end = (int) ($position["end"] ?? 0);
            if ($marker === "" || $url === "" || $start <= 0 || $end <= $start) {
                continue;
            }

            $requests[] = ["deleteContentRange" => ["range" => ["startIndex" => $start, "endIndex" => $end]]];
            $requests[] = ["insertInlineImage" => ["uri" => $url, "location" => ["index" => $start]]];
            $inserted++;
        }

        if ($inserted === 0) {
            return ["success" => false, "message" => "Google Doc image markers were not found after import.", "inserted_images" => 0];
        }

        $res = $this->req(
            "POST",
            self::DOCS_API_BASE . "/" . urlencode($id) . ":batchUpdate",
            array_merge($this->auth($token), ["Content-Type: application/json"]),
            json_encode(["requests" => $requests], JSON_UNESCAPED_SLASHES)
        );
        if (!($res["success"] ?? false)) {
            return ["success" => false, "message" => $res["error"] ?? "Google Docs image insertion failed.", "inserted_images" => 0];
        }

        return ["success" => true, "inserted_images" => $inserted];
    }

    /**
     * @return array<int,array{marker:string,start:int,end:int}>
     */
    protected function findImageMarkerPositions(array $document, array $markers): array
    {
        $wanted = array_fill_keys(array_map("strval", $markers), true);
        $positions = [];
        $content = (array) data_get($document, "body.content", []);

        foreach ($content as $block) {
            foreach ((array) data_get($block, "paragraph.elements", []) as $element) {
                $text = (string) data_get($element, "textRun.content", "");
                if ($text === "") {
                    continue;
                }
                $elementStart = (int) ($element["startIndex"] ?? 0);
                foreach ($wanted as $marker => $_) {
                    $offset = strpos($text, $marker);
                    if ($offset === false) {
                        continue;
                    }
                    $positions[] = [
                        "marker" => $marker,
                        "start" => $elementStart + $offset,
                        "end" => $elementStart + $offset + strlen($marker),
                    ];
                }
            }
        }

        return $positions;
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

    protected function ensurePublicEditableAccess(string $id, string $token): array
    {
        $permissions = $this->req('GET', self::DRIVE_API_BASE . '/files/' . urlencode($id) . '/permissions?fields=permissions(id,type,role,allowFileDiscovery)', $this->auth($token));
        if (($permissions['success'] ?? false) && $this->hasAnyoneWriterPermission((array) ($permissions['data']['permissions'] ?? []))) {
            return ['success' => true, 'role' => 'writer'];
        }

        $payload = json_encode(['role' => 'writer', 'type' => 'anyone', 'allowFileDiscovery' => false], JSON_UNESCAPED_SLASHES);
        $res = $this->req('POST', self::DRIVE_API_BASE . '/files/' . urlencode($id) . '/permissions?sendNotificationEmail=false&fields=id,role,type', array_merge($this->auth($token), ['Content-Type: application/json']), $payload);
        if ($res['success'] ?? false) {
            return ['success' => true, 'role' => (string) ($res['data']['role'] ?? 'writer')];
        }

        $verify = $this->req('GET', self::DRIVE_API_BASE . '/files/' . urlencode($id) . '/permissions?fields=permissions(id,type,role,allowFileDiscovery)', $this->auth($token));
        if (($verify['success'] ?? false) && $this->hasAnyoneWriterPermission((array) ($verify['data']['permissions'] ?? []))) {
            return ['success' => true, 'role' => 'writer'];
        }

        return ['success' => false, 'message' => $res['error'] ?? 'Failed to make the Google Doc publicly editable by link.'];
    }

    protected function hasAnyoneWriterPermission(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (!is_array($permission)) {
                continue;
            }
            if (($permission['type'] ?? '') === 'anyone' && ($permission['role'] ?? '') === 'writer') {
                return true;
            }
        }
        return false;
    }

    protected function id(string $value): ?string
    {
        $value = trim($value); if ('' === $value) return null;
        if (preg_match('#/document/(?:u/\d+/)?d/([a-zA-Z0-9_-]+)#', $value, $m)) return $m[1];
        return preg_match('/^[a-zA-Z0-9_-]{20,}$/', $value) ? $value : null;
    }


    protected function embedRemoteImagesForImport(string $html): string
    {
        if ($html === "" || stripos($html, "<img") === false) {
            return $html;
        }

        return preg_replace_callback("/<img\\b([^>]*)>/iu", function (array $matches): string {
            $tag = $matches[0] ?? "";
            $attributes = $matches[1] ?? "";
            if ($tag === "" || !preg_match("/\\bsrc=([\"\\x27])(.*?)\\1/iu", $attributes, $srcMatch)) {
                return $tag;
            }

            $src = html_entity_decode(trim((string) ($srcMatch[2] ?? "")), ENT_QUOTES | ENT_HTML5, "UTF-8");
            $dataUri = $this->remoteImageDataUri($src);
            if ($dataUri === null) {
                return $tag;
            }

            $replacement = "src=\"" . htmlspecialchars($dataUri, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") . "\"";
            $updatedAttributes = preg_replace("/\\bsrc=([\"\\x27])(.*?)\\1/iu", $replacement, $attributes, 1) ?? $attributes;

            return "<img" . $updatedAttributes . ">";
        }, $html) ?? $html;
    }

    protected function remoteImageDataUri(string $url): ?string
    {
        if ($url === "" || str_starts_with($url, "data:image/")) {
            return $url !== "" ? $url : null;
        }
        if (!preg_match("#^https?://#i", $url)) {
            return null;
        }

        $image = $this->fetchRemoteImage($url);
        if ($image === null) {
            return null;
        }

        return "data:" . $image["mime"] . ";base64," . base64_encode($image["body"]);
    }

    /**
     * @return array{mime:string,body:string}|null
     */
    protected function fetchRemoteImage(string $url): ?array
    {
        $body = "";
        $maxBytes = 8 * 1024 * 1024;
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => [
                "Accept: image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8",
                "User-Agent: Hexa Google Docs Export/1.0",
            ],
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, $maxBytes): int {
                if (strlen($body) + strlen($chunk) > $maxBytes) {
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);

        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $mime = trim((string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
        $error = curl_error($ch);
        curl_close($ch);

        if ($error !== "" || $code < 200 || $code >= 300 || $body === "") {
            return null;
        }

        $mime = strtolower(trim(explode(";", $mime)[0] ?? ""));
        if ($mime === "" || !str_starts_with($mime, "image/")) {
            $info = @getimagesizefromstring($body);
            $mime = is_array($info) ? strtolower((string) ($info["mime"] ?? "")) : "";
        }

        $allowed = ["image/jpeg", "image/png", "image/gif", "image/webp"];
        if (!in_array($mime, $allowed, true)) {
            return null;
        }

        return ["mime" => $mime, "body" => $body];
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
        $out = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch);
        if (false === $out) return ['success' => false, 'error' => $err ?: 'cURL request failed.', 'status' => $code];
        if ($code < 200 || $code >= 300) { $msg = 'HTTP ' . $code; $json = json_decode($out, true); if (is_array($json)) $msg = $json['error']['message'] ?? $json['message'] ?? $msg; return ['success' => false, 'error' => $msg, 'status' => $code]; }
        if ($raw) return ['success' => true, 'data' => $out, 'status' => $code];
        $json = json_decode($out, true); if (JSON_ERROR_NONE !== json_last_error()) return ['success' => false, 'error' => 'Invalid JSON response from Google API.', 'status' => $code]; return ['success' => true, 'data' => $json, 'status' => $code];
    }
}

