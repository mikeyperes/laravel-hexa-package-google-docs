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

    protected function selectAccount(Request $request): void
    {
        $validated = $request->validate(['account_id' => 'sometimes|required|string|max:36']);
        $this->write = $this->write->forAccount($validated['account_id'] ?? $this->write->accountId());
    }

    public function createAccount(Request $request): JsonResponse
    {
        $validated = $request->validate(['label' => 'required|email|max:255']);
        $id = (string) \Illuminate\Support\Str::uuid();
        Setting::setValue('google_docs_account_'.$id.'_label', trim($validated['label']), 'packages');
        Setting::setValue('google_docs_account_'.$id.'_auth_mode', 'oauth_user', 'packages');
        return response()->json(['success' => true, 'account_id' => $id, 'message' => 'Account added. Save its Google credentials and verify the connected identity.']);
    }

    public function setDefaultAccount(Request $request): JsonResponse
    {
        $request->validate(['account_id' => 'required|string|max:36']);
        $this->selectAccount($request);
        $result = $this->write->testWriteConnection();
        if (!($result['success'] ?? false)) {
            return response()->json($result, 422);
        }
        Setting::setValue('google_docs_default_account', $this->write->accountId(), 'packages');
        return response()->json(['success' => true, 'message' => 'Default Google Docs account saved.', 'connected_email' => $result['connected_email'] ?? '']);
    }

    public function index(Request $request): View
    {
        $this->selectAccount($request);
        $context = $this->write->writeContext();
        $general = [
            "default_format" => (string) Setting::getValue("google_docs_default_format", config("google-docs.default_format", "txt")),
            "timeout_seconds" => (int) Setting::getValue("google_docs_timeout_seconds", config("google-docs.timeout_seconds", 15)),
            "user_agent" => (string) Setting::getValue("google_docs_user_agent", config("google-docs.user_agent", "HexaGoogleDocs/1.0")),
            "max_preview_chars" => (int) Setting::getValue("google_docs_max_preview_chars", config("google-docs.max_preview_chars", 1600)),
            "auth_mode" => (string) ($context["auth_mode"] ?? "public_read"),
            "owner_email" => (string) ($context["owner_email"] ?? ""),
            "default_folder_id" => (string) ($context["default_folder_id"] ?? ""),
        ];

        return view("google-docs::settings.index", [
            "credentialSlug" => $this->write->credentialSlug(),
            "settingsConfig" => [
                "accounts" => $this->write->accounts(),
                "accountId" => $this->write->accountId(),
                "defaultAccountId" => (string) Setting::getValue("google_docs_default_account", "legacy"),
                "context" => [
                    "auth_mode" => $general["auth_mode"],
                    "owner_email" => $general["owner_email"],
                    "default_folder_id" => $general["default_folder_id"],
                    "connected_email" => (string) ($context["connected_email"] ?? ""),
                    "has_oauth_credentials" => (bool) ($context["has_oauth_credentials"] ?? false),
                    "has_service_account" => (bool) ($context["has_service_account"] ?? false),
                    "has_write_access" => (bool) ($context["has_write_access"] ?? false),
                ],
                "general" => $general,
                "defaultFormat" => $general["default_format"],
                "routes" => [
                    "accounts" => route("settings.google-docs.accounts"),
                    "defaultAccount" => route("settings.google-docs.default-account"),
                    "general" => route("settings.google-docs.general"),
                    "oauth" => route("settings.google-docs.oauth"),
                    "serviceAccount" => route("settings.google-docs.service-account"),
                    "testRead" => route("settings.google-docs.test-read"),
                    "testWrite" => route("settings.google-docs.test-write"),
                    "createFolder" => route("settings.google-docs.create-folder"),
                    "smoke" => route("settings.google-docs.smoke"),
                ],
            ],
        ]);
    }

    public function saveGeneral(Request $request): JsonResponse
    {
        $this->selectAccount($request);
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
        Setting::setValue($this->write->accountSettingKey('auth_mode'), $validated['auth_mode'], 'packages');
        Setting::setValue($this->write->accountSettingKey('owner_email'), trim((string) ($validated['owner_email'] ?? '')), 'packages');
        Setting::setValue($this->write->accountSettingKey('default_folder_id'), trim((string) ($validated['default_folder_id'] ?? '')), 'packages');

        return response()->json(['success' => true, 'message' => 'Google Docs settings saved.']);
    }

    public function saveOauth(Request $request): JsonResponse
    {
        $this->selectAccount($request);
        $validated = $request->validate([
            'oauth_client_id' => 'required|string|min:20|max:255',
            'oauth_client_secret' => 'required|string|min:10|max:255',
            'oauth_refresh_token' => 'required|string|min:20|max:4000',
        ]);

        $this->credentials->store($this->write->credentialSlug(), 'oauth_client_id', trim($validated['oauth_client_id']));
        $this->credentials->store($this->write->credentialSlug(), 'oauth_client_secret', trim($validated['oauth_client_secret']));
        $this->credentials->store($this->write->credentialSlug(), 'oauth_refresh_token', trim($validated['oauth_refresh_token']));

        $test = $this->write->testWriteConnection();
        return response()->json(array_merge(['success' => (bool) ($test['success'] ?? false)], $test), ($test['success'] ?? false) ? 200 : 422);
    }

    public function saveServiceAccount(Request $request): JsonResponse
    {
        $this->selectAccount($request);
        $validated = $request->validate([
            'service_account_json' => 'required|string|min:40',
        ]);

        $decoded = json_decode($validated['service_account_json'], true);
        if (!is_array($decoded) || empty($decoded['client_email']) || empty($decoded['private_key'])) {
            return response()->json(['success' => false, 'message' => 'Service-account JSON must include client_email and private_key.'], 422);
        }

        $this->credentials->store($this->write->credentialSlug(), 'service_account_json', trim($validated['service_account_json']));
        $test = $this->write->testWriteConnection();
        return response()->json(array_merge(['success' => (bool) ($test['success'] ?? false)], $test), ($test['success'] ?? false) ? 200 : 422);
    }

    public function createFolder(Request $request): JsonResponse
    {
        $this->selectAccount($request);
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
                Setting::setValue($this->write->accountSettingKey('default_folder_id'), (string) ($result['folder_id'] ?? ''), 'packages');
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

    public function testWrite(Request $request): JsonResponse
    {
        $this->selectAccount($request);
        $result = $this->write->testWriteConnection();
        return response()->json($result, ($result['success'] ?? false) ? 200 : 422);
    }

    public function smoke(Request $request): JsonResponse
    {
        $this->selectAccount($request);
        $result = $this->write->smokeTestWrite();
        return response()->json($result, ($result['success'] ?? false) ? 200 : 422);
    }
}
