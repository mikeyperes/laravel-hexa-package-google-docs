<?php

namespace hexa_package_google_docs\Http\Controllers;

use hexa_core\Http\Controllers\Controller;
use hexa_core\Models\Setting;
use hexa_core\Services\CredentialService;
use hexa_package_google_docs\Services\GoogleDocsService;
use hexa_package_google_docs\Services\GoogleDocsWriteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GoogleDocsSettingController extends Controller
{
    public function __construct(
        protected GoogleDocsService $docs,
        protected GoogleDocsWriteService $write,
        protected CredentialService $credentials,
    ) {
    }

    public function index(): View
    {
        $context = $this->write->writeContext();
        return view('google-docs::settings.index', [
            'defaultFormat' => (string) Setting::getValue('google_docs_default_format', config('google-docs.default_format', 'txt')),
            'timeoutSeconds' => (int) Setting::getValue('google_docs_timeout_seconds', config('google-docs.timeout_seconds', 15)),
            'userAgent' => (string) Setting::getValue('google_docs_user_agent', config('google-docs.user_agent', 'HexaGoogleDocs/1.0')),
            'maxPreviewChars' => (int) Setting::getValue('google_docs_max_preview_chars', config('google-docs.max_preview_chars', 1600)),
            'authMode' => (string) ($context['auth_mode'] ?? 'public_read'),
            'ownerEmail' => (string) ($context['owner_email'] ?? ''),
            'defaultFolderId' => (string) ($context['default_folder_id'] ?? ''),
            'connectedEmail' => (string) ($context['connected_email'] ?? ''),
            'hasOauthCredentials' => (bool) ($context['has_oauth_credentials'] ?? false),
            'hasServiceAccount' => (bool) ($context['has_service_account'] ?? false),
            'hasWriteAccess' => (bool) ($context['has_write_access'] ?? false),
            'oauthClientIdMasked' => $this->credentials->getMasked('google-docs', 'oauth_client_id'),
        ]);
    }

    public function saveGeneral(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'default_format' => 'required|in:txt,html',
            'timeout_seconds' => 'required|integer|min:5|max:60',
            'user_agent' => 'required|string|min:3|max:255',
            'max_preview_chars' => 'required|integer|min:200|max:5000',
            'auth_mode' => 'required|in:public_read,oauth_user,service_account',
            'owner_email' => 'nullable|email|max:255',
            'default_folder_id' => 'nullable|string|max:255',
        ]);

        Setting::setValue('google_docs_default_format', $validated['default_format'], 'packages');
        Setting::setValue('google_docs_timeout_seconds', (string) $validated['timeout_seconds'], 'packages');
        Setting::setValue('google_docs_user_agent', $validated['user_agent'], 'packages');
        Setting::setValue('google_docs_max_preview_chars', (string) $validated['max_preview_chars'], 'packages');
        Setting::setValue('google_docs_auth_mode', $validated['auth_mode'], 'packages');
        Setting::setValue('google_docs_owner_email', trim((string) ($validated['owner_email'] ?? '')), 'packages');
        Setting::setValue('google_docs_default_folder_id', trim((string) ($validated['default_folder_id'] ?? '')), 'packages');

        return response()->json(['success' => true, 'message' => 'Google Docs settings saved.']);
    }

    public function saveOauth(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'oauth_client_id' => 'required|string|min:20|max:255',
            'oauth_client_secret' => 'required|string|min:10|max:255',
            'oauth_refresh_token' => 'required|string|min:20|max:4000',
        ]);

        $this->credentials->store('google-docs', 'oauth_client_id', trim($validated['oauth_client_id']));
        $this->credentials->store('google-docs', 'oauth_client_secret', trim($validated['oauth_client_secret']));
        $this->credentials->store('google-docs', 'oauth_refresh_token', trim($validated['oauth_refresh_token']));

        $test = $this->write->testWriteConnection();
        return response()->json(array_merge(['success' => (bool) ($test['success'] ?? false)], $test), ($test['success'] ?? false) ? 200 : 422);
    }

    public function saveServiceAccount(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'service_account_json' => 'required|string|min:40',
        ]);

        $decoded = json_decode($validated['service_account_json'], true);
        if (!is_array($decoded) || empty($decoded['client_email']) || empty($decoded['private_key'])) {
            return response()->json(['success' => false, 'message' => 'Service-account JSON must include client_email and private_key.'], 422);
        }

        $this->credentials->store('google-docs', 'service_account_json', trim($validated['service_account_json']));
        $test = $this->write->testWriteConnection();
        return response()->json(array_merge(['success' => (bool) ($test['success'] ?? false)], $test), ($test['success'] ?? false) ? 200 : 422);
    }

    public function createFolder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'folder_name' => 'required|string|min:2|max:255',
            'parent_folder_id' => 'nullable|string|max:255',
            'set_as_default' => 'nullable|boolean',
        ]);

        $result = $this->write->createFolder(
            trim($validated['folder_name']),
            trim((string) ($validated['parent_folder_id'] ?? ''))
        );

        if ($result['success'] ?? false) {
            if ($validated['set_as_default'] ?? true) {
                Setting::setValue('google_docs_default_folder_id', (string) ($result['folder_id'] ?? ''), 'packages');
                $result['default_folder_id'] = (string) ($result['folder_id'] ?? '');
            }
        }

        return response()->json($result, ($result['success'] ?? false) ? 200 : 422);
    }

    public function testRead(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'doc_url' => 'required|string|min:20',
            'format' => 'nullable|in:txt,html',
        ]);

        $result = $this->docs->fetchDocument(
            $validated['doc_url'],
            $validated['format'] ?? (string) Setting::getValue('google_docs_default_format', config('google-docs.default_format', 'txt'))
        );

        return response()->json($result, ($result['success'] ?? false) ? 200 : 422);
    }

    public function testWrite(): JsonResponse
    {
        $result = $this->write->testWriteConnection();
        return response()->json($result, ($result['success'] ?? false) ? 200 : 422);
    }

    public function smoke(): JsonResponse
    {
        $result = $this->write->smokeTestWrite();
        return response()->json($result, ($result['success'] ?? false) ? 200 : 422);
    }
}
