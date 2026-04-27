<?php

namespace hexa_package_google_docs\Http\Controllers;

use hexa_core\Http\Controllers\Controller;
use hexa_core\Models\Setting;
use hexa_package_google_docs\Services\GoogleDocsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GoogleDocsSettingController extends Controller
{
    public function __construct(
        protected GoogleDocsService $docs,
    ) {
    }

    public function index(): View
    {
        return view('google-docs::settings.index', [
            'defaultFormat' => (string) Setting::getValue('google_docs_default_format', config('google-docs.default_format', 'txt')),
            'timeoutSeconds' => (int) Setting::getValue('google_docs_timeout_seconds', config('google-docs.timeout_seconds', 15)),
            'userAgent' => (string) Setting::getValue('google_docs_user_agent', config('google-docs.user_agent', 'HexaGoogleDocs/1.0')),
            'maxPreviewChars' => (int) Setting::getValue('google_docs_max_preview_chars', config('google-docs.max_preview_chars', 1600)),
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'default_format' => 'required|in:txt,html',
            'timeout_seconds' => 'required|integer|min:5|max:60',
            'user_agent' => 'required|string|min:3|max:255',
            'max_preview_chars' => 'required|integer|min:200|max:5000',
        ]);

        Setting::setValue('google_docs_default_format', $validated['default_format'], 'packages');
        Setting::setValue('google_docs_timeout_seconds', (string) $validated['timeout_seconds'], 'packages');
        Setting::setValue('google_docs_user_agent', $validated['user_agent'], 'packages');
        Setting::setValue('google_docs_max_preview_chars', (string) $validated['max_preview_chars'], 'packages');

        hexaLogInfo('google-docs', 'Google Docs settings saved', [
            'default_format' => $validated['default_format'],
            'timeout_seconds' => (int) $validated['timeout_seconds'],
            'max_preview_chars' => (int) $validated['max_preview_chars'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Google Docs settings saved.',
        ]);
    }

    public function test(Request $request): JsonResponse
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
}
