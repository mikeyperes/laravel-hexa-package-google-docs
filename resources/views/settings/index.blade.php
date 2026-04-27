@extends('layouts.app')

@section('title', 'Google Docs - ' . config('hws.app_name'))
@section('header', 'Google Docs')

@section('content')
<div class="max-w-4xl space-y-6"
     x-data="googleDocsSettings()"
     x-init="init()">

    <div class="bg-sky-50 border border-sky-200 rounded-xl p-4 text-sm text-sky-900">
        <p class="font-semibold mb-1">Public Google Docs only</p>
        <p>No API key is required. This package reads public Google Docs through Google's export endpoint and returns text or HTML content.</p>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 space-y-4">
        <div>
            <h2 class="text-lg font-semibold text-gray-900">Package Settings</h2>
            <p class="text-sm text-gray-500 mt-1">These defaults are used when testing or fetching public documents from Hexa.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <label class="block">
                <span class="text-sm font-medium text-gray-700">Default export format</span>
                <select x-model="form.default_format" class="mt-1 w-full rounded-lg border-gray-300 focus:border-sky-500 focus:ring-sky-500">
                    <option value="txt">Plain text</option>
                    <option value="html">HTML</option>
                </select>
            </label>

            <label class="block">
                <span class="text-sm font-medium text-gray-700">Timeout seconds</span>
                <input x-model="form.timeout_seconds" type="number" min="5" max="60" class="mt-1 w-full rounded-lg border-gray-300 focus:border-sky-500 focus:ring-sky-500">
            </label>

            <label class="block md:col-span-2">
                <span class="text-sm font-medium text-gray-700">User-Agent</span>
                <input x-model="form.user_agent" type="text" class="mt-1 w-full rounded-lg border-gray-300 focus:border-sky-500 focus:ring-sky-500">
            </label>

            <label class="block md:col-span-2">
                <span class="text-sm font-medium text-gray-700">Preview character limit</span>
                <input x-model="form.max_preview_chars" type="number" min="200" max="5000" class="mt-1 w-full rounded-lg border-gray-300 focus:border-sky-500 focus:ring-sky-500">
            </label>
        </div>

        <div class="flex items-center gap-3">
            <button @click="save" :disabled="saving" type="button" class="inline-flex items-center gap-2 rounded-lg bg-sky-600 px-4 py-2 text-sm font-medium text-white hover:bg-sky-700 disabled:opacity-50">
                <svg x-show="saving" x-cloak class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                <span x-text="saving ? 'Saving...' : 'Save settings'"></span>
            </button>
            <template x-if="saveResult">
                <div class="text-sm" :class="saveResult.success ? 'text-green-700' : 'text-red-700'" x-text="saveResult.message"></div>
            </template>
        </div>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 space-y-4">
        <div>
            <h2 class="text-lg font-semibold text-gray-900">Public Document Tester</h2>
            <p class="text-sm text-gray-500 mt-1">Paste a public Google Docs URL and fetch the exported content directly from production.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
            <label class="block md:col-span-3">
                <span class="text-sm font-medium text-gray-700">Google Docs URL or document ID</span>
                <input x-model="testForm.doc_url" type="text" class="mt-1 w-full rounded-lg border-gray-300 focus:border-sky-500 focus:ring-sky-500" placeholder="https://docs.google.com/document/d/.../edit">
            </label>
            <label class="block">
                <span class="text-sm font-medium text-gray-700">Format</span>
                <select x-model="testForm.format" class="mt-1 w-full rounded-lg border-gray-300 focus:border-sky-500 focus:ring-sky-500">
                    <option value="txt">Plain text</option>
                    <option value="html">HTML</option>
                </select>
            </label>
        </div>

        <div class="flex items-center gap-3">
            <button @click="runTest" :disabled="testing || !testForm.doc_url.trim()" type="button" class="inline-flex items-center gap-2 rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:opacity-50">
                <svg x-show="testing" x-cloak class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                <span x-text="testing ? 'Fetching...' : 'Fetch document'"></span>
            </button>
            <template x-if="testResult && testResult.message">
                <div class="text-sm" :class="testResult.success ? 'text-green-700' : 'text-red-700'" x-text="testResult.message"></div>
            </template>
        </div>

        <template x-if="testResult && testResult.success">
            <div class="space-y-4 border-t border-gray-100 pt-4">
                <dl class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                    <div class="rounded-lg bg-gray-50 p-3 border border-gray-200">
                        <dt class="text-gray-500">Title</dt>
                        <dd class="mt-1 font-medium text-gray-900" x-text="testResult.title || 'Untitled Document'"></dd>
                    </div>
                    <div class="rounded-lg bg-gray-50 p-3 border border-gray-200">
                        <dt class="text-gray-500">Document ID</dt>
                        <dd class="mt-1 font-mono text-xs text-gray-900 break-all" x-text="testResult.document_id"></dd>
                    </div>
                    <div class="rounded-lg bg-gray-50 p-3 border border-gray-200">
                        <dt class="text-gray-500">Export URL</dt>
                        <dd class="mt-1 text-xs break-all"><a class="text-sky-700 hover:underline" :href="testResult.export_url" target="_blank" x-text="testResult.export_url"></a></dd>
                    </div>
                    <div class="rounded-lg bg-gray-50 p-3 border border-gray-200">
                        <dt class="text-gray-500">Bytes / MIME</dt>
                        <dd class="mt-1 text-gray-900"><span x-text="testResult.byte_length"></span> bytes <span class="text-gray-500">•</span> <span x-text="testResult.mime_type || 'unknown'"></span></dd>
                    </div>
                </dl>

                <div class="rounded-xl border border-gray-200 overflow-hidden">
                    <div class="px-4 py-3 bg-gray-50 border-b border-gray-200">
                        <h3 class="text-sm font-semibold text-gray-900">Extracted content</h3>
                    </div>
                    <pre class="p-4 text-sm text-gray-900 whitespace-pre-wrap break-words overflow-x-auto" x-text="displayContent()"></pre>
                </div>
            </div>
        </template>
    </div>
</div>

<script>
function googleDocsSettings() {
    return {
        saving: false,
        testing: false,
        saveResult: null,
        testResult: null,
        form: {
            default_format: @js($defaultFormat),
            timeout_seconds: @js($timeoutSeconds),
            user_agent: @js($userAgent),
            max_preview_chars: @js($maxPreviewChars),
        },
        testForm: {
            doc_url: '',
            format: @js($defaultFormat),
        },
        init() {},
        async save() {
            this.saving = true;
            this.saveResult = null;
            try {
                const response = await fetch(@js(route('settings.google-docs.save')), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': @js(csrf_token()),
                    },
                    body: JSON.stringify(this.form),
                });
                this.saveResult = await response.json();
            } catch (error) {
                this.saveResult = { success: false, message: error.message };
            } finally {
                this.saving = false;
            }
        },
        async runTest() {
            this.testing = true;
            this.testResult = null;
            try {
                const response = await fetch(@js(route('settings.google-docs.test')), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': @js(csrf_token()),
                    },
                    body: JSON.stringify(this.testForm),
                });
                this.testResult = await response.json();
            } catch (error) {
                this.testResult = { success: false, message: error.message };
            } finally {
                this.testing = false;
            }
        },
        displayContent() {
            if (!this.testResult) {
                return '';
            }
            return this.testResult.format === 'html'
                ? (this.testResult.plain_text || this.testResult.content || '')
                : (this.testResult.content || '');
        },
    };
}
</script>
@endsection
