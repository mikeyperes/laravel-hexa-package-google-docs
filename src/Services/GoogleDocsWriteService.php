<?php

namespace hexa_package_google_docs\Services;

use hexa_core\Models\Setting;
use hexa_core\Security\Http\OutboundHttpException;
use hexa_core\Security\Http\OutboundHttpResponse;
use hexa_core\Security\Http\SafeOutboundHttpClient;
use hexa_core\Services\CredentialService;
use Illuminate\Support\Facades\Cache;

class GoogleDocsWriteService
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const JWT_GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:jwt-bearer';
    private const DRIVE_API_BASE = 'https://www.googleapis.com/drive/v3';
    private const DOCS_API_BASE = 'https://docs.googleapis.com/v1/documents';
    private const GOOGLE_ORIGINS = [
        'https://docs.googleapis.com',
        'https://oauth2.googleapis.com',
        'https://www.googleapis.com',
    ];
    private const API_TIMEOUT_SECONDS = 20;
    private const API_RESPONSE_BYTES = 8 * 1024 * 1024;
    private const RAW_RESPONSE_BYTES = 12 * 1024 * 1024;
    private const HTML_IMPORT_BYTES = 16 * 1024 * 1024;
    private const UPLOAD_CHUNK_BYTES = 1024 * 1024;
    private const UPLOAD_DEADLINE_SECONDS = 60;
    private const IMAGE_COUNT = 12;
    private const IMAGE_BYTES = 4 * 1024 * 1024;
    private const IMAGE_AGGREGATE_BYTES = 16 * 1024 * 1024;
    private const IMAGE_PIXELS = 25_000_000;
    private const IMAGE_DEADLINE_SECONDS = 30;
    private const IMAGE_REDIRECTS = 3;
    private const IMAGE_MIME_TYPES = ['image/gif', 'image/jpeg', 'image/png', 'image/webp'];

    protected GoogleDocumentFormattingService $documentFormatting;

    public function __construct(
        protected CredentialService $credentials,
        ?GoogleDocumentFormattingService $documentFormatting = null,
        protected ?SafeOutboundHttpClient $http = null,
    ) {
        $this->documentFormatting = $documentFormatting ?? new GoogleDocumentFormattingService();
    }

    // Explicit selections live on a clone, never on the container singleton.
    protected ?string $selectedAccountId = null;

    public function accountId(): string
    {
        return $this->selectedAccountId ?? (string) Setting::getValue('google_docs_default_account', 'legacy');
    }

    public function forAccount(string $accountId): static
    {
        if ($accountId !== 'legacy' && (!preg_match('/^[a-f0-9-]{36}$/D', $accountId)
            || Setting::getValue('google_docs_account_'.$accountId.'_label') === null)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['account_id' => 'Select an existing Google Docs account.']);
        }
        $writer = clone $this;
        $writer->selectedAccountId = $accountId;
        return $writer;
    }

    public function credentialSlug(): string
    {
        return $this->accountId() === 'legacy' ? 'google-docs' : 'google-docs-'.$this->accountId();
    }

    public function accountSettingKey(string $field): string
    {
        return $this->accountId() === 'legacy' ? 'google_docs_'.$field : 'google_docs_account_'.$this->accountId().'_'.$field;
    }

    protected function accountSetting(string $field, mixed $legacyDefault = ''): mixed
    {
        return Setting::getValue($this->accountSettingKey($field), $this->accountId() === 'legacy' ? $legacyDefault : '');
    }

    /** Only non-secret labels and IDs are returned to the settings page. */
    public function accounts(): array
    {
        $legacy = $this->forAccount('legacy');
        $accounts = [['id' => 'legacy', 'label' => $legacy->accountSetting('connected_email') ?: 'Existing account']];
        foreach (Setting::query()->where('group', 'packages')->where('key', 'like', 'google_docs_account_%_label')->get(['key', 'value']) as $setting) {
            if (preg_match('/^google_docs_account_([a-f0-9-]{36})_label$/D', $setting->key, $match)) {
                $accounts[] = ['id' => $match[1], 'label' => (string) $setting->value];
            }
        }
        return $accounts;
    }

    public function authMode(): string { $v = (string) $this->accountSetting('auth_mode', config('google-docs.auth_mode', 'public_read')); return in_array($v, ['public_read','oauth_user','service_account'], true) ? $v : 'public_read'; }
    public function ownerEmail(): string { return trim((string) $this->accountSetting('owner_email', config('google-docs.owner_email', ''))); }
    public function serviceAccountEmail(): string
    {
        $json = $this->serviceAccountJson();
        if ($json === '') return '';
        $serviceAccount = json_decode($json, true);
        return is_array($serviceAccount) ? trim((string) ($serviceAccount['client_email'] ?? '')) : '';
    }
    public function defaultFolderId(): string { return trim((string) $this->accountSetting('default_folder_id', config('google-docs.default_folder_id', ''))); }

    public function writeContext(): array
    {
        $mode = $this->authMode();
        $hasOauthCredentials = $this->credentials->exists($this->credentialSlug(), 'oauth_client_id')
            && $this->credentials->exists($this->credentialSlug(), 'oauth_client_secret')
            && $this->credentials->exists($this->credentialSlug(), 'oauth_refresh_token');
        $hasServiceAccount = $this->serviceAccountJson() !== '';

        return [
            'account_id' => $this->accountId(),
            'auth_mode' => $mode,
            'connected_email' => trim((string) $this->accountSetting('connected_email')) ?: null,
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
        if ($email === '') return ['success' => false, 'message' => 'Google did not return a connected account email.'];
        $expected = $this->accountId() === 'legacy' ? '' : (string) $this->accountSetting('label');
        if ($this->authMode() === 'oauth_user' && $expected !== '' && strcasecmp($expected, $email) !== 0) {
            return ['success' => false, 'message' => 'These credentials belong to '.$email.', but the selected account is '.$expected.'. Authorize the selected account and save its credentials.'];
        }
        if ($email !== '') Setting::setValue($this->accountSettingKey('connected_email'), $email, 'packages');
        return ['success' => true, 'message' => 'Google Docs write connection verified' . ($email ? ' as ' . $email : '') . '.', 'connected_email' => $email];
    }

    /** @return array<string, mixed> */
    public function testFolderAccess(string $folderId): array
    {
        $folderId = trim($folderId);
        if ($folderId === '') {
            return ['success' => false, 'message' => 'A Google Drive folder ID is required.'];
        }

        $token = $this->token();
        if (!($token['success'] ?? false)) {
            return $token;
        }

        $authMode = (string) ($token['auth_mode'] ?? $this->authMode());
        $connectedEmail = trim((string) ($token['connected_email'] ?? ''));
        $url = self::DRIVE_API_BASE . '/files/' . rawurlencode($folderId)
            . '?fields=id,name,mimeType,webViewLink,capabilities(canAddChildren,canEdit)&supportsAllDrives=true';
        $response = $this->req('GET', $url, $this->auth((string) $token['access_token']));

        if (!($response['success'] ?? false)) {
            $status = (int) ($response['status'] ?? 0);
            $error = trim((string) ($response['error'] ?? 'Google Drive denied access.'));
            $normalizedError = mb_strtolower($error);
            $scopeRequired = $status === 403 && (
                str_contains($normalizedError, 'insufficient authentication scope')
                || str_contains($normalizedError, 'access_token_scope_insufficient')
            );
            $folderUnavailable = $status === 404
                || str_contains($normalizedError, 'not found');

            $reason = 'folder_access_denied';
            $message = 'The active Google Docs writer'.($connectedEmail !== '' ? ' ('.$connectedEmail.')' : '')
                .' cannot open this folder: '.$error;

            if ($scopeRequired) {
                $reason = 'drive_scope_required';
                $message = 'The Google Docs credentials are valid, but the refresh token does not include the full Google Drive scope required for an existing folder. Generate a new token with https://www.googleapis.com/auth/documents and https://www.googleapis.com/auth/drive, then test again.';
            } elseif ($authMode === 'oauth_user' && $folderUnavailable) {
                $reason = 'folder_not_available_to_app';
                $message = 'The Google Docs credentials are valid, but this folder is not available to the app. Create the shared uploads folder from the Service Order Portal settings and use the generated folder link.';
            }

            return [
                'success' => false,
                'message' => $message,
                'auth_mode' => $authMode,
                'connected_email' => $connectedEmail !== '' ? $connectedEmail : null,
                'credentials_valid' => $status !== 401,
                'reason' => $reason,
            ];
        }

        $folder = (array) ($response['data'] ?? []);
        $isFolder = (string) ($folder['mimeType'] ?? '') === 'application/vnd.google-apps.folder';
        $canAddChildren = (bool) data_get($folder, 'capabilities.canAddChildren', false);
        $canEdit = (bool) data_get($folder, 'capabilities.canEdit', false);

        if (!$isFolder || !$canAddChildren || !$canEdit) {
            return [
                'success' => false,
                'message' => !$isFolder
                    ? 'The supplied Google Drive URL does not point to a folder.'
                    : 'The active Google Docs writer'.($connectedEmail !== '' ? ' ('.$connectedEmail.')' : '').' can open this folder but cannot add files. Grant that account Editor access and test again.',
                'auth_mode' => $authMode,
                'connected_email' => $connectedEmail !== '' ? $connectedEmail : null,
                'can_add_children' => $canAddChildren,
                'can_edit' => $canEdit,
            ];
        }

        return [
            'success' => true,
            'message' => 'Folder access verified for the active Google Docs writer'.($connectedEmail !== '' ? ' ('.$connectedEmail.')' : '').'.',
            'folder_id' => (string) ($folder['id'] ?? $folderId),
            'folder_name' => trim((string) ($folder['name'] ?? '')),
            'folder_url' => trim((string) ($folder['webViewLink'] ?? '')) ?: 'https://drive.google.com/drive/folders/'.rawurlencode($folderId),
            'auth_mode' => $authMode,
            'connected_email' => $connectedEmail !== '' ? $connectedEmail : null,
            'can_add_children' => true,
            'can_edit' => true,
        ];
    }

    public function createDocumentFromHtml(string $title, string $html, ?string $folderId = null, ?string $accountId = null): array
    {
        $writer = $this->forAccount($accountId ?? $this->accountId());
        return $writer->export(null, $title, $html, false, $folderId);
    }
    public function updateDocumentFromHtml(string $id, string $title, string $html): array { return $this->export($id, $title, $html, true); }

    public function export(?string $id, string $title, string $html, bool $preserve = false, ?string $folderId = null): array
    {
        $token = $this->token(); if (!($token["success"] ?? false)) return $token;
        if (trim(strip_tags($html)) === "") return ["success" => false, "message" => "Google Doc export requires non-empty article content."];
        $previousId = $id ? $this->id($id) : null;
        $imagePayload = $this->prepareInlineImageMarkers($html);
        if (!($imagePayload['success'] ?? false)) {
            return [
                'success' => false,
                'message' => (string) ($imagePayload['message'] ?? 'A remote image could not be safely prepared for Google Docs.'),
            ];
        }
        $htmlForImport = (string) ($imagePayload["html"] ?? $html);
        $imageMarkers = is_array($imagePayload["images"] ?? null) ? $imagePayload["images"] : [];
        $import = $this->importHtmlDocument((trim($title) ?: "Untitled Document"), $htmlForImport, (string) $token["access_token"], $folderId);
        if (!($import["success"] ?? false)) return $import;
        $id = (string) ($import["document_id"] ?? "");
        if ($id === "") return ["success" => false, "message" => "Google Doc import did not return a document ID."];
        $insertedImages = $this->insertMarkedImages($id, $imageMarkers, (string) $token["access_token"]);
        if (!($insertedImages["success"] ?? false)) { $this->deleteDocument($id, true); return ["success" => false, "message" => (string) ($insertedImages["message"] ?? "Failed to insert images into the Google Doc."), "inserted_images" => (int) ($insertedImages["inserted_images"] ?? 0)]; }
        $formatting = $this->normalizeImportedDocumentFormatting($id, (string) $token["access_token"]);
        if (!($formatting["success"] ?? false)) { $this->deleteDocument($id, true); return ["success" => false, "message" => (string) ($formatting["message"] ?? "Failed to normalize Google Doc formatting."), "formatting_verified" => false, "formatting" => $formatting]; }
        $meta = $this->meta($id, (string) $token["access_token"]); if (!($meta["success"] ?? false)) return $meta;
        $folder = $this->resolveDocumentFolder((array) ($meta["file"] ?? []), (string) $token["access_token"]);
        $shared = $this->ensureOwnerAccess($id, (string) $token["access_token"], (string) ($meta["file"]["owner_email"] ?? ""), (string) ($token["connected_email"] ?? ""));
        $public = $this->ensurePublicEditableAccess($id, (string) $token["access_token"]);
        if (!($public["success"] ?? false)) { if (!$preserve) $this->deleteDocument($id, true); return ["success" => false, "message" => (string) ($public["message"] ?? "Failed to make the Google Doc publicly editable by link.")]; }
        if ($preserve && $previousId && $previousId !== $id) { $this->deleteDocument($previousId, true); }
        return ["success" => true, "account_id" => $this->accountId(), "message" => $preserve ? "Google Doc updated successfully." : "Google Doc created successfully.", "document_id" => $id, "normalized_url" => "https://docs.google.com/document/d/" . $id . "/edit", "web_view_link" => (string) ($meta["file"]["web_view_link"] ?? ""), "owner_email" => (string) ($meta["file"]["owner_email"] ?? ""), "connected_email" => (string) ($token["connected_email"] ?? ""), "shared_with_requested_owner" => $shared, "public_editable" => true, "public_role" => (string) ($public["role"] ?? "writer"), "public_access" => "anyone_with_link", "master_folder_id" => (string) ($folder["id"] ?? ""), "master_folder_name" => (string) ($folder["name"] ?? ""), "master_folder_url" => (string) ($folder["web_view_link"] ?? ""), "formatting_verified" => true, "formatting" => $formatting, "file" => $meta["file"], "inserted_images" => (int) ($insertedImages["inserted_images"] ?? 0)];
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


    /**
     * Read native document structure with the selected account's Docs scope.
     * Drive export access is not a prerequisite for native Docs operations.
     */
    public function getNativeDocument(string $value): array
    {
        $id = $this->id($value);
        if (!$id) return ['success' => false, 'message' => 'Missing Google Doc ID.'];
        $token = $this->token();
        if (!($token['success'] ?? false)) return $token;

        $res = $this->req(
            'GET',
            self::DOCS_API_BASE . '/' . urlencode($id) . '?includeTabsContent=true',
            $this->auth((string) $token['access_token'])
        );
        if (!($res['success'] ?? false)) {
            return [
                'success' => false,
                'message' => $res['error'] ?? 'Failed to read the native Google Doc.',
                'document_id' => $id,
                'status' => $res['status'] ?? null,
            ];
        }
        if (($res['data']['documentId'] ?? null) !== $id) {
            return ['success' => false, 'message' => 'Google returned an unexpected document ID.', 'document_id' => $id];
        }

        return [
            'success' => true,
            'document_id' => $id,
            'normalized_url' => 'https://docs.google.com/document/d/' . $id . '/edit',
            'account_id' => $this->accountId(),
            'connected_email' => (string) ($token['connected_email'] ?? ''),
            'document' => $res['data'],
        ];
    }

    /**
     * Apply native Docs requests in place. No replacement, sharing, or Drive
     * metadata operation is performed. Use the revision from getNativeDocument
     * to reject concurrent changes rather than applying stale content indexes.
     */
    public function batchUpdateNativeDocument(string $value, array $requests, string $requiredRevisionId): array
    {
        $id = $this->id($value);
        if (!$id) return ['success' => false, 'message' => 'Missing Google Doc ID.'];
        if (trim($requiredRevisionId) === '') {
            return ['success' => false, 'message' => 'A current native document revision ID is required.'];
        }
        if ($requests === [] || !array_is_list($requests)) {
            return ['success' => false, 'message' => 'Provide a non-empty list of native Google Docs requests.'];
        }
        foreach ($requests as $request) {
            if (!is_array($request) || count($request) !== 1 || !is_string(array_key_first($request))
                || !is_array(reset($request))) {
                return ['success' => false, 'message' => 'Each native request must contain exactly one operation object.'];
            }
        }
        try {
            $body = json_encode([
                'requests' => $requests,
                'writeControl' => ['requiredRevisionId' => $requiredRevisionId],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            return ['success' => false, 'message' => 'Native requests could not be encoded as JSON.'];
        }

        $token = $this->token();
        if (!($token['success'] ?? false)) return $token;
        $res = $this->req(
            'POST',
            self::DOCS_API_BASE . '/' . urlencode($id) . ':batchUpdate',
            array_merge($this->auth((string) $token['access_token']), ['Content-Type: application/json']),
            $body
        );
        if (!($res['success'] ?? false)) {
            return [
                'success' => false,
                'message' => $res['error'] ?? 'Native Google Doc update failed.',
                'document_id' => $id,
                'status' => $res['status'] ?? null,
            ];
        }
        if (($res['data']['documentId'] ?? null) !== $id) {
            return ['success' => false, 'message' => 'Google returned an unexpected update document ID.', 'document_id' => $id];
        }

        return [
            'success' => true,
            'message' => 'Native Google Doc updated in place.',
            'document_id' => $id,
            'normalized_url' => 'https://docs.google.com/document/d/' . $id . '/edit',
            'account_id' => $this->accountId(),
            'connected_email' => (string) ($token['connected_email'] ?? ''),
            'replies' => $res['data']['replies'] ?? [],
            'write_control' => $res['data']['writeControl'] ?? [],
        ];
    }
    /**
     * Insert local image bytes through this writer's selected Google account.
     * Drive's private image-to-Doc import supplies a short-lived image URI.
     * Only that image is inserted; the temporary import is deleted afterwards.
     */
    public function insertNativeImageFromFile(
        string $value,
        string $localPath,
        array $location,
        string $requiredRevisionId,
        ?array $replaceRange = null,
        ?float $heightPt = null,
    ): array {
        $id = $this->id($value);
        $index = $location['index'] ?? null;
        $tabId = $location['tabId'] ?? null;
        if (!$id || trim($requiredRevisionId) === '' || !is_int($index) || $index < 1
            || !is_string($tabId) || trim($tabId) === '') {
            return ['success' => false, 'message' => 'An exact document, tab, index and current revision are required.'];
        }
        if ($replaceRange !== null && (($replaceRange['startIndex'] ?? null) !== $index
            || ($replaceRange['tabId'] ?? null) !== $tabId
            || !is_int($replaceRange['endIndex'] ?? null) || $replaceRange['endIndex'] <= $index)) {
            return ['success' => false, 'message' => 'The replacement range must start at the image location in the same tab.'];
        }
        if ($heightPt !== null && (!is_finite($heightPt) || $heightPt <= 0)) {
            return ['success' => false, 'message' => 'Image height must be a positive finite point value.'];
        }
        if (!is_file($localPath) || !is_readable($localPath)
            || filesize($localPath) < 1 || filesize($localPath) > self::IMAGE_BYTES) {
            return ['success' => false, 'message' => 'A readable image within the existing size limit is required.'];
        }
        $bytes = file_get_contents($localPath, false, null, 0, self::IMAGE_BYTES + 1);
        $info = is_string($bytes) ? @getimagesizefromstring($bytes) : false;
        $mime = is_array($info) ? ($info['mime'] ?? '') : '';
        if (!is_string($bytes) || strlen($bytes) > self::IMAGE_BYTES
            || !in_array($mime, ['image/png', 'image/jpeg', 'image/gif'], true)
            || ($info[0] ?? 0) < 1 || ($info[1] ?? 0) < 1
            || $info[0] > intdiv(self::IMAGE_PIXELS, $info[1])) {
            return ['success' => false, 'message' => 'Use a PNG, JPEG or GIF within the existing image pixel and byte limits.'];
        }

        $token = $this->token();
        if (!($token['success'] ?? false)) return $token;
        $import = $this->importMediaDocument('Temporary image import', $bytes, $mime, (string) $token['access_token']);
        if (!($import['success'] ?? false)) return $import;
        $temporaryId = (string) $import['document_id'];
        $result = ['success' => false, 'image_inserted' => false, 'message' => 'The imported image was not available.'];

        try {
            $privacy = $this->req(
                'GET',
                self::DRIVE_API_BASE . '/files/' . urlencode($temporaryId) . '?fields=id,shared',
                $this->auth((string) $token['access_token'])
            );
            if (!($privacy['success'] ?? false) || ($privacy['data']['shared'] ?? null) !== false) {
                $result['message'] = 'Private image import sharing could not be verified.';
            } else {
                $source = $this->getNativeDocument($temporaryId);
                $uris = [];
                $sourceDocument = (array) ($source['document'] ?? []);
                array_walk_recursive($sourceDocument, static function ($entry, $key) use (&$uris): void {
                    if ($key === 'contentUri' && is_string($entry)) $uris[] = $entry;
                });
                $uris = array_values(array_unique($uris));
                $parts = count($uris) === 1 ? parse_url($uris[0]) : false;
                $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
                if (!($source['success'] ?? false) || ($parts['scheme'] ?? '') !== 'https'
                    || !str_ends_with($host, '.googleusercontent.com')
                    || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
                    $result['message'] = 'Google did not return one usable image from the private import.';
                } else {
                    $requests = [];
                    if ($replaceRange !== null) $requests[] = ['deleteContentRange' => ['range' => $replaceRange]];
                    $image = ['location' => $location, 'uri' => $uris[0]];
                    if ($heightPt !== null) $image['objectSize'] = ['height' => ['magnitude' => $heightPt, 'unit' => 'PT']];
                    $requests[] = ['insertInlineImage' => $image];
                    $result = $this->batchUpdateNativeDocument($id, $requests, $requiredRevisionId);
                    $result['image_inserted'] = (bool) ($result['success'] ?? false);
                }
            }
        } catch (\Throwable) {
            $result = ['success' => false, 'image_inserted' => false, 'message' => 'Native image insertion could not be completed; read the destination before retrying.'];
        } finally {
            $cleanup = $this->deleteDocument($temporaryId);
        }

        $result['temporary_document_deleted'] = (bool) ($cleanup['success'] ?? false);
        if (!$result['temporary_document_deleted']) {
            $result['success'] = false;
            $result['temporary_document_id'] = $temporaryId;
            $result['message'] = ($result['image_inserted'] ?? false)
                ? 'Image inserted, but its temporary import could not be deleted. Do not insert it again.'
                : 'Image insertion failed and its temporary import could not be deleted.';
        }
        return $result;
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
            $id = trim((string) $this->credentials->get($this->credentialSlug(),'oauth_client_id')); $secret = trim((string) $this->credentials->get($this->credentialSlug(),'oauth_client_secret')); $refresh = trim((string) $this->credentials->get($this->credentialSlug(),'oauth_refresh_token'));
            if ('' === $id || '' === $secret || '' === $refresh) return ['success' => false, 'message' => 'Google Docs OAuth client ID, client secret, or refresh token is missing.'];
            $key = 'gdocs_oauth_' . hash('sha256', $this->credentialSlug() . '|' . $id . '|' . $secret . '|' . $refresh); if ($cached = Cache::get($key)) return ['success' => true, 'auth_mode' => 'oauth_user', 'access_token' => $cached, 'connected_email' => trim((string) $this->accountSetting('connected_email')) ?: null];
            $res = $this->req('POST', self::TOKEN_URL, ['Content-Type: application/x-www-form-urlencoded'], http_build_query(['client_id'=>$id,'client_secret'=>$secret,'refresh_token'=>$refresh,'grant_type'=>'refresh_token']));
            if (!($res['success'] ?? false) || empty($res['data']['access_token'])) return ['success' => false, 'message' => 'Failed to refresh the Google Docs OAuth token: ' . ($res['error'] ?? 'Unknown error')];
            $token = (string) $res['data']['access_token']; Cache::put($key, $token, max(60, ((int) ($res['data']['expires_in'] ?? 3600)) - 120));
            return ['success' => true, 'auth_mode' => 'oauth_user', 'access_token' => $token, 'connected_email' => trim((string) $this->accountSetting('connected_email')) ?: null];
        }
        $json = $this->serviceAccountJson(); if ('' === $json) return ['success' => false, 'message' => 'Google Docs service-account JSON is missing.'];
        $sa = json_decode($json, true); if (!is_array($sa) || empty($sa['client_email']) || empty($sa['private_key'])) return ['success' => false, 'message' => 'Stored Google Docs service-account JSON is invalid.'];
        $key = 'gdocs_sa_' . hash('sha256', $this->credentialSlug() . '|' . $json); if ($cached = Cache::get($key)) return ['success' => true, 'auth_mode' => 'service_account', 'access_token' => $cached, 'connected_email' => (string) $sa['client_email']];
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

    protected function importHtmlDocument(string $title, string $html, string $token, ?string $folderId = null): array
    {
        return $this->importMediaDocument($title, $html, 'text/html; charset=UTF-8', $token, trim((string) $folderId) ?: $this->defaultFolderId());
    }

    protected function importMediaDocument(string $title, string $content, string $mimeType, string $token, ?string $folderId = null): array
    {
        $contentBytes = strlen($content);
        if ($contentBytes === 0 || $contentBytes > self::HTML_IMPORT_BYTES) {
            return ['success' => false, 'message' => 'Google Doc HTML import exceeded the safe size limit.'];
        }

        $metadata = ['name' => $title, 'mimeType' => 'application/vnd.google-apps.document'];
        $folderId = trim((string) $folderId);
        if ($folderId !== '') {
            $metadata['parents'] = [$folderId];
        }

        try {
            $metadataBody = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['success' => false, 'message' => 'Google Doc import metadata could not be encoded.'];
        }

        $deadline = $this->monotonicTime() + self::UPLOAD_DEADLINE_SECONDS;
        $init = $this->googleRequest(
            'POST',
            'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&fields=id',
            array_merge($this->auth($token), [
                'Content-Type: application/json; charset=UTF-8',
                'X-Upload-Content-Type: '.$mimeType,
                'X-Upload-Content-Length: '.$contentBytes,
            ]),
            $metadataBody,
            $this->deadlineTimeout($deadline),
            64 * 1024,
        );
        if (!($init['success'] ?? false)) {
            return ['success' => false, 'message' => $init['error'] ?? 'Failed to start the Google Doc HTML import.'];
        }
        if ($this->monotonicTime() >= $deadline) {
            return ['success' => false, 'message' => 'Google Doc HTML upload exceeded its total deadline.'];
        }

        /** @var OutboundHttpResponse $initResponse */
        $initResponse = $init['response'];
        if (!$initResponse->successful()) {
            return ['success' => false, 'message' => $this->googleHttpError($initResponse)];
        }
        $locations = $initResponse->headerValues('location');
        $sessionUrl = count($locations) === 1 ? trim($locations[0]) : '';
        if ($sessionUrl === '' || !$this->isApprovedGoogleUrl($sessionUrl)) {
            return ['success' => false, 'message' => 'Google Drive returned an invalid resumable upload destination.'];
        }

        $offset = 0;
        while ($offset < $contentBytes) {
            $length = min(self::UPLOAD_CHUNK_BYTES, $contentBytes - $offset);
            $end = $offset + $length - 1;
            $upload = $this->googleRequest(
                'PUT',
                $sessionUrl,
                array_merge($this->auth($token), [
                    'Content-Type: '.$mimeType,
                    'Content-Range: bytes '.$offset.'-'.$end.'/'.$contentBytes,
                ]),
                substr($content, $offset, $length),
                $this->deadlineTimeout($deadline),
                1024 * 1024,
            );
            if (!($upload['success'] ?? false)) {
                return ['success' => false, 'message' => $upload['error'] ?? 'Google Doc HTML upload failed.'];
            }
            if ($this->monotonicTime() >= $deadline) {
                return ['success' => false, 'message' => 'Google Doc HTML upload exceeded its total deadline.'];
            }

            /** @var OutboundHttpResponse $uploadResponse */
            $uploadResponse = $upload['response'];
            $offset = $end + 1;

            if ($offset < $contentBytes) {
                if ($uploadResponse->status !== 308 || !$this->acceptedUploadRange($uploadResponse, $end)) {
                    return ['success' => false, 'message' => 'Google Drive returned an invalid resumable upload checkpoint.'];
                }
                continue;
            }

            if (!$uploadResponse->successful()) {
                return ['success' => false, 'message' => $this->googleHttpError($uploadResponse)];
            }

            try {
                $data = json_decode($uploadResponse->body, true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return ['success' => false, 'message' => 'Google Drive returned an invalid HTML import response.'];
            }

            $documentId = is_array($data) ? trim((string) ($data['id'] ?? '')) : '';
            if ($documentId === '') {
                return ['success' => false, 'message' => 'Google Doc import did not return a document ID.'];
            }

            return ['success' => true, 'document_id' => $documentId];
        }

        return ['success' => false, 'message' => 'Google Doc HTML upload did not complete.'];
    }

    /**
     * Google Drive HTML import can drop image tags. Preserve image placement
     * with markers, then replace the markers through the Google Docs API.
     *
     * @return array{success:bool,html?:string,images?:array<int,array{marker:string,url:string,alt:string}>,message?:string}
     */
    protected function prepareInlineImageMarkers(string $html): array
    {
        if ($html === "" || stripos($html, "<img") === false) {
            return ["success" => true, "html" => $html, "images" => []];
        }

        if ($this->remoteImageCount($html) > self::IMAGE_COUNT) {
            return [
                'success' => false,
                'message' => 'Google Doc export supports at most '.self::IMAGE_COUNT.' remote images.',
            ];
        }

        $images = [];
        $index = 0;
        $aggregateBytes = 0;
        $deadline = $this->monotonicTime() + self::IMAGE_DEADLINE_SECONDS;
        $failure = null;
        $updated = preg_replace_callback("/<img\\b([^>]*)>/iu", function (array $matches) use (&$images, &$index, &$aggregateBytes, $deadline, &$failure): string {
            if ($failure !== null) {
                return '';
            }

            $attributes = (string) ($matches[1] ?? "");
            $source = $this->imageSourceFromAttributes($attributes);
            if ($source === null) {
                return '';
            }

            $url = html_entity_decode(trim($source), ENT_QUOTES | ENT_HTML5, "UTF-8");
            if (str_starts_with(strtolower($url), 'data:image/')) {
                return (string) ($matches[0] ?? '');
            }
            if (!preg_match("#^https?://#i", $url)) {
                return '';
            }

            $image = $this->fetchRemoteImage($url, $deadline);
            if (!($image['success'] ?? false)) {
                if ($image['omit'] ?? false) {
                    return '';
                }
                $failure = (string) ($image['message'] ?? 'A remote image could not be safely validated.');
                return '';
            }
            $aggregateBytes += (int) ($image['bytes'] ?? 0);
            if ($aggregateBytes > self::IMAGE_AGGREGATE_BYTES) {
                $failure = 'Remote images exceeded the safe aggregate size limit.';
                return '';
            }

            $validatedUrl = (string) ($image['url'] ?? '');
            preg_match("/\\balt=([\"\\x27])(.*?)\\1/iu", $attributes, $altMatch);
            $alt = html_entity_decode(trim((string) ($altMatch[2] ?? "")), ENT_QUOTES | ENT_HTML5, "UTF-8");
            $marker = "HEXA_GOOGLE_DOC_IMAGE_" . $index . "_" . substr(sha1($validatedUrl . "|" . $index), 0, 10);
            $images[] = ["marker" => $marker, "url" => $validatedUrl, "alt" => $alt];
            $index++;

            return "<span>" . $marker . "</span>";
        }, $html);

        if ($failure !== null || $updated === null) {
            return [
                'success' => false,
                'message' => $failure ?? 'Remote images could not be safely prepared for Google Docs.',
            ];
        }

        return ["success" => true, "html" => $updated, "images" => $images];
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

    protected function normalizeImportedDocumentFormatting(string $id, string $token): array
    {
        $document = $this->req(
            "GET",
            self::DOCS_API_BASE . "/" . urlencode($id),
            $this->auth($token)
        );
        if (!($document["success"] ?? false)) {
            return ["success" => false, "message" => $document["error"] ?? "Failed to inspect imported Google Doc formatting."];
        }

        $requests = $this->buildDocumentFormattingRequests((array) ($document["data"] ?? []));
        if ($requests === []) {
            return ["success" => false, "message" => "Imported Google Doc did not contain formatable article paragraphs."];
        }

        $update = $this->req(
            "POST",
            self::DOCS_API_BASE . "/" . urlencode($id) . ":batchUpdate",
            array_merge($this->auth($token), ["Content-Type: application/json"]),
            json_encode(["requests" => $requests], JSON_UNESCAPED_SLASHES)
        );
        if (!($update["success"] ?? false)) {
            return ["success" => false, "message" => $update["error"] ?? "Google Docs native formatting update failed."];
        }

        $verifiedDocument = $this->req(
            "GET",
            self::DOCS_API_BASE . "/" . urlencode($id),
            $this->auth($token)
        );
        if (!($verifiedDocument["success"] ?? false)) {
            return ["success" => false, "message" => $verifiedDocument["error"] ?? "Failed to verify Google Doc formatting after update."];
        }

        $verification = $this->verifyDocumentFormatting((array) ($verifiedDocument["data"] ?? []));
        $verification["request_count"] = count($requests);

        return $verification;
    }

    /**
     * Build explicit Google Docs API requests so HTML-import defaults cannot
     * silently inflate headings or collapse paragraph spacing.
     *
     * @return array<int,array<string,mixed>>
     */
    protected function buildDocumentFormattingRequests(array $document): array
    {
        return $this->documentFormatting->buildRequests($document);
    }

    protected function verifyDocumentFormatting(array $document): array
    {
        return $this->documentFormatting->verify($document);
    }

    protected function meta(string $id, string $token): array
    {
        $res = $this->req('GET', self::DRIVE_API_BASE . '/files/' . urlencode($id) . '?fields=id,name,webViewLink,parents,owners(emailAddress)', $this->auth($token));
        if (!($res['success'] ?? false)) return ['success' => false, 'message' => $res['error'] ?? 'Failed to load Google Doc metadata.'];
        $f = (array) $res['data']; return ['success' => true, 'file' => ['id' => (string) ($f['id'] ?? ''), 'name' => (string) ($f['name'] ?? ''), 'web_view_link' => (string) ($f['webViewLink'] ?? ''), 'owner_email' => (string) ($f['owners'][0]['emailAddress'] ?? ''), 'parent_ids' => array_values(array_filter(array_map('strval', (array) ($f['parents'] ?? []))))]];
    }

    protected function resolveDocumentFolder(array $file, string $token): array
    {
        $folderId = trim((string) (($file["parent_ids"][0] ?? null) ?: $this->defaultFolderId()));
        if ($folderId === "") {
            return ["id" => "", "name" => "My Drive", "web_view_link" => "https://drive.google.com/drive/my-drive"];
        }

        $fallback = [
            "id" => $folderId,
            "name" => "Google Drive folder",
            "web_view_link" => "https://drive.google.com/drive/folders/" . $folderId,
        ];
        $res = $this->req(
            "GET",
            self::DRIVE_API_BASE . "/files/" . urlencode($folderId) . "?fields=id,name,webViewLink",
            $this->auth($token)
        );
        if (!($res["success"] ?? false)) {
            return $fallback;
        }

        return [
            "id" => (string) ($res["data"]["id"] ?? $folderId),
            "name" => (string) ($res["data"]["name"] ?? $fallback["name"]),
            "web_view_link" => (string) ($res["data"]["webViewLink"] ?? $fallback["web_view_link"]),
        ];
    }

    protected function serviceAccountJson(): string
    {
        $json = trim((string) $this->credentials->get($this->credentialSlug(), 'service_account_json'));
        if ($json !== '') return $json;
        return $this->accountId() === 'legacy' ? trim((string) $this->credentials->get('google-drive', 'service_account_json')) : '';
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


    /** @return array{success:bool,url?:string,bytes?:int,message?:string,omit?:bool} */
    protected function fetchRemoteImage(string $url, float $deadline): array
    {
        $timeout = $this->deadlineTimeout($deadline);
        if ($timeout === 0) {
            return ['success' => false, 'message' => 'Remote image validation exceeded its total deadline.'];
        }

        try {
            $response = $this->httpClient()->request('GET', $url, [
                'headers' => [
                    'Accept' => implode(',', self::IMAGE_MIME_TYPES),
                    'User-Agent' => 'Hexa Google Docs Export/1.1',
                ],
                'timeout' => min(self::API_TIMEOUT_SECONDS, $timeout),
                'max_bytes' => self::IMAGE_BYTES,
                'max_redirects' => self::IMAGE_REDIRECTS,
            ]);
        } catch (OutboundHttpException $exception) {
            return [
                'success' => false,
                'message' => $exception->failureCode() === 'response_too_large'
                    ? 'A remote image exceeded the safe per-image size limit.'
                    : 'A remote image could not be safely validated.',
                'omit' => $exception->failureCode() !== 'response_too_large',
            ];
        }

        if ($this->monotonicTime() >= $deadline) {
            return ['success' => false, 'message' => 'Remote image validation exceeded its total deadline.'];
        }
        if (!$response->successful() || $response->body === '') {
            return ['success' => false, 'message' => 'A remote image could not be safely validated.'];
        }

        $contentTypes = $response->headerValues('content-type');
        if (count($contentTypes) !== 1) {
            return ['success' => false, 'message' => 'A remote image returned an invalid media type.'];
        }
        $declaredMime = strtolower(trim(explode(';', $contentTypes[0], 2)[0]));
        $imageInfo = @getimagesizefromstring($response->body);
        $detectedMime = is_array($imageInfo) ? strtolower((string) ($imageInfo['mime'] ?? '')) : '';
        $declaredMimeIsGeneric = $declaredMime === 'application/octet-stream';
        if (
            (!in_array($declaredMime, self::IMAGE_MIME_TYPES, true) && !$declaredMimeIsGeneric)
            || !in_array($detectedMime, self::IMAGE_MIME_TYPES, true)
            || (!$declaredMimeIsGeneric && $declaredMime !== $detectedMime)
        ) {
            return ['success' => false, 'message' => 'A remote image failed media-type validation.'];
        }

        $width = (int) ($imageInfo[0] ?? 0);
        $height = (int) ($imageInfo[1] ?? 0);
        if ($width < 1 || $height < 1 || $width > intdiv(self::IMAGE_PIXELS, $height)) {
            return ['success' => false, 'message' => 'A remote image exceeded the safe pixel limit.'];
        }

        return [
            'success' => true,
            'url' => (string) ($response->effectiveUrl ?? $url),
            'bytes' => strlen($response->body),
        ];
    }

    protected function remoteImageCount(string $html): int
    {
        preg_match_all('/<img\\b([^>]*)>/iu', $html, $tags);
        $count = 0;
        foreach ((array) ($tags[1] ?? []) as $attributes) {
            $source = $this->imageSourceFromAttributes((string) $attributes);
            if ($source === null) {
                continue;
            }
            $url = html_entity_decode(trim($source), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (preg_match('#^https?://#i', $url)) {
                $count++;
            }
        }

        return $count;
    }

    protected function imageSourceFromAttributes(string $attributes): ?string
    {
        if (preg_match(
            '/(?:^|\\s)src\\s*=\\s*(?:(["\\x27])(.*?)\\1|([^\\s"\\x27=<>`]+))/iu',
            $attributes,
            $match,
        ) !== 1) {
            return null;
        }

        return isset($match[3]) && $match[3] !== '' ? $match[3] : (string) ($match[2] ?? '');
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
        $result = $this->googleRequest(
            $method,
            $url,
            array_merge($raw ? [] : ['Accept: application/json'], $headers),
            $body,
            self::API_TIMEOUT_SECONDS,
            $raw ? self::RAW_RESPONSE_BYTES : self::API_RESPONSE_BYTES,
        );
        if (!($result['success'] ?? false)) {
            return $result;
        }

        /** @var OutboundHttpResponse $response */
        $response = $result['response'];
        if (!$response->successful()) {
            return ['success' => false, 'error' => $this->googleHttpError($response), 'status' => $response->status];
        }
        if ($raw) {
            return ['success' => true, 'data' => $response->body, 'status' => $response->status];
        }

        try {
            $data = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['success' => false, 'error' => 'Invalid JSON response from Google API.', 'status' => $response->status];
        }

        return ['success' => true, 'data' => $data, 'status' => $response->status];
    }

    /** @return array{success:bool,response?:OutboundHttpResponse,error?:string,status?:int} */
    protected function googleRequest(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $timeout,
        int $maxBytes,
    ): array {
        if ($timeout < 1) {
            return ['success' => false, 'error' => 'Google API request exceeded its total deadline.'];
        }
        if (!$this->isApprovedGoogleUrl($url)) {
            return ['success' => false, 'error' => 'Google API destination was rejected.'];
        }

        $normalizedHeaders = $this->normalizeRequestHeaders($headers);
        if ($normalizedHeaders === null) {
            return ['success' => false, 'error' => 'Google API request headers were invalid.'];
        }

        try {
            $response = $this->httpClient()->request($method, $url, [
                'headers' => $normalizedHeaders,
                'body' => $body,
                'timeout' => min(self::API_TIMEOUT_SECONDS, $timeout),
                'max_bytes' => $maxBytes,
                'max_redirects' => 0,
            ]);
        } catch (OutboundHttpException $exception) {
            $error = match ($exception->failureCode()) {
                'request_body_too_large' => 'Google API request exceeded the safe size limit.',
                'response_too_large', 'response_headers_too_large' => 'Google API response exceeded the safe size limit.',
                'target_rejected', 'redirect_invalid', 'redirect_limit', 'redirect_cycle' => 'Google API destination was rejected.',
                default => 'Google API request could not be completed.',
            };

            return ['success' => false, 'error' => $error];
        }

        return ['success' => true, 'response' => $response, 'status' => $response->status];
    }

    /** @return array<string,string>|null */
    protected function normalizeRequestHeaders(array $headers): ?array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            if (is_int($name)) {
                if (!is_string($value) || !str_contains($value, ':')) {
                    return null;
                }
                [$name, $value] = explode(':', $value, 2);
            }
            if (!is_string($name) || !is_scalar($value) || is_bool($value)) {
                return null;
            }
            $name = trim($name);
            $value = trim((string) $value);
            $lower = strtolower($name);
            if ($name === '' || $value === '' || isset($normalized[$lower])) {
                return null;
            }
            $normalized[$lower] = [$name, $value];
        }

        return array_column(array_values($normalized), 1, 0);
    }

    protected function isApprovedGoogleUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            return false;
        }

        $origin = 'https://'.strtolower((string) ($parts['host'] ?? ''));

        return in_array($origin, self::GOOGLE_ORIGINS, true);
    }

    protected function googleHttpError(OutboundHttpResponse $response): string
    {
        $body = json_decode($response->body, true);
        $message = is_array($body)
            ? mb_strtolower((string) ($body['error']['message'] ?? $body['message'] ?? ''))
            : '';

        if ($response->status === 401) {
            return 'Google API authentication failed.';
        }
        if ($response->status === 403 && (
            str_contains($message, 'insufficient authentication scope')
            || str_contains($message, 'insufficient authentication scopes')
            || str_contains($message, 'access_token_scope_insufficient')
        )) {
            return 'Google API request had insufficient authentication scope.';
        }

        return match (true) {
            $response->status === 403 => 'Google API denied the request.',
            $response->status === 404 => 'Google API resource was not found.',
            $response->status === 409 => 'Google API reported a conflict.',
            $response->status === 429 => 'Google API rate limit was reached.',
            $response->status >= 500 => 'Google API is temporarily unavailable.',
            default => 'Google API request failed with HTTP '.$response->status.'.',
        };
    }

    protected function acceptedUploadRange(OutboundHttpResponse $response, int $expectedEnd): bool
    {
        $ranges = $response->headerValues('range');

        return count($ranges) === 1
            && preg_match('/^bytes=0-(\d+)$/D', trim($ranges[0]), $match) === 1
            && (int) $match[1] === $expectedEnd;
    }

    protected function deadlineTimeout(float $deadline): int
    {
        $remaining = $deadline - $this->monotonicTime();

        return $remaining > 0 ? (int) max(1, ceil($remaining)) : 0;
    }

    protected function monotonicTime(): float
    {
        return hrtime(true) / 1_000_000_000;
    }

    protected function httpClient(): SafeOutboundHttpClient
    {
        return $this->http ??= app(SafeOutboundHttpClient::class);
    }
}
