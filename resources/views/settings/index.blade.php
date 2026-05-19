@extends('layouts.app')

@section('title', 'Google Docs - ' . config('hws.app_name'))
@section('header', 'Google Docs')

@section('content')
<div class="max-w-5xl space-y-6" x-data="googleDocsSettings()" x-init="init()">
    <div class="rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900">
        <p class="font-semibold">Shared Google Docs integration</p>
        <p class="mt-1">Read mode imports public Google Docs without OAuth. Write mode creates and updates Google Docs through Google Drive + Google Docs APIs so the same package can be reused across Publish and other Hexa apps.</p>
    </div>

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1.25fr)_minmax(0,0.9fr)]">
        <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm space-y-5">
            <div>
                <h2 class="text-lg font-semibold text-gray-900">General settings</h2>
                <p class="mt-1 text-sm text-gray-500">These defaults control public-read behavior, write mode, target owner email, and the default Drive folder for exported documents.</p>
            </div>
            <div class="grid gap-4 md:grid-cols-2">
                <label class="block">
                    <span class="text-sm font-medium text-gray-700">Default export format</span>
                    <select x-model="general.default_format" class="mt-1 w-full rounded-lg border-gray-300 focus:border-sky-500 focus:ring-sky-500">
                        <option value="txt">Plain text</option>
                        <option value="html">HTML</option>
                    </select>
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-gray-700">Timeout seconds</span>
                    <input x-model="general.timeout_seconds" type="number" min="5" max="60" class="mt-1 w-full rounded-lg border-gray-300 focus:border-sky-500 focus:ring-sky-500">
                </label>
                <label class="block md:col-span-2">
                    <span class="text-sm font-medium text-gray-700">User-Agent</span>
                    <input x-model="general.user_agent" type="text" class="mt-1 w-full rounded-lg border-gray-300 focus:border-sky-500 focus:ring-sky-500">
                </label>
                <label class="block md:col-span-2">
                    <span class="text-sm font-medium text-gray-700">Preview character limit</span>
                    <input x-model="general.max_preview_chars" type="number" min="200" max="5000" class="mt-1 w-full rounded-lg border-gray-300 focus:border-sky-500 focus:ring-sky-500">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-gray-700">Write auth mode</span>
                    <select x-model="general.auth_mode" class="mt-1 w-full rounded-lg border-gray-300 focus:border-sky-500 focus:ring-sky-500">
                        <option value="public_read">Public read only</option>
                        <option value="oauth_user">OAuth user write</option>
                        <option value="service_account">Service account write</option>
                    </select>
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-gray-700">Target owner / share email</span>
                    <input x-model="general.owner_email" type="email" class="mt-1 w-full rounded-lg border-gray-300 focus:border-sky-500 focus:ring-sky-500" placeholder="contact@michaelperes.com">
                </label>
                <label class="block md:col-span-2">
                    <span class="text-sm font-medium text-gray-700">Default Drive folder ID</span>
                    <input x-model="general.default_folder_id" type="text" class="mt-1 w-full rounded-lg border-gray-300 focus:border-sky-500 focus:ring-sky-500" placeholder="Google Drive folder ID">
                </label>
                <div class="md:col-span-2 rounded-xl border border-emerald-200 bg-emerald-50 p-4 space-y-3">
                    <div>
                        <p class="text-sm font-semibold text-emerald-900">Create export folder in Google Drive</p>
                        <p class="mt-1 text-xs text-emerald-800">Creates a folder for article exports under the active Google Docs write identity. When successful, the folder ID is saved as the default export folder.</p>
                    </div>
                    <div class="grid gap-3 md:grid-cols-2">
                        <label class="block">
                            <span class="text-sm font-medium text-emerald-900">Folder name</span>
                            <input x-model="folder.folder_name" type="text" class="mt-1 w-full rounded-lg border-emerald-300 focus:border-emerald-500 focus:ring-emerald-500" placeholder="scalemypublication.com, publish exports">
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-emerald-900">Parent folder ID (optional)</span>
                            <input x-model="folder.parent_folder_id" type="text" class="mt-1 w-full rounded-lg border-emerald-300 focus:border-emerald-500 focus:ring-emerald-500" placeholder="Leave blank for Drive root">
                        </label>
                    </div>
                    <label class="inline-flex items-center gap-2 text-sm text-emerald-900">
                        <input x-model="folder.set_as_default" type="checkbox" class="rounded border-emerald-300 text-emerald-600 focus:ring-emerald-500">
                        <span>Save created folder as the default export folder</span>
                    </label>
                    <div class="flex flex-wrap items-center gap-3">
                        <button @click="createFolder" :disabled="creatingFolder" type="button" class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50">
                            <svg x-show="creatingFolder" x-cloak class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                            <span x-text="creatingFolder ? &quot;Creating…&quot; : &quot;Create export folder&quot;"></span>
                        </button>
                        <a x-show="folderResult?.success && folderResult?.web_view_link" x-cloak class="text-sm font-medium text-emerald-700 hover:underline" :href="folderResult?.web_view_link || &quot;#&quot;" target="_blank" rel="noopener">Open folder</a>
                        <p x-show="folderResult" x-cloak class="text-sm" :class="folderResult?.success ? &quot;text-emerald-700&quot; : &quot;text-red-700&quot;" x-text="folderResult?.message || &quot;&quot;"></p>
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <button @click="saveGeneral" :disabled="savingGeneral" type="button" class="inline-flex items-center gap-2 rounded-lg bg-sky-600 px-4 py-2 text-sm font-medium text-white hover:bg-sky-700 disabled:opacity-50">
                    <svg x-show="savingGeneral" x-cloak class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                    <span x-text="savingGeneral ? 'Saving…' : 'Save settings'"></span>
                </button>
                <p x-show="generalResult" x-cloak class="text-sm" :class="generalResult?.success ? 'text-green-700' : 'text-red-700'" x-text="generalResult?.message || ''"></p>
            </div>
        </section>

        <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm space-y-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-900">Current connection</h2>
                <p class="mt-1 text-sm text-gray-500">This reflects the real package write context on production.</p>
            </div>
            <dl class="grid gap-3 text-sm">
                <div class="rounded-lg border border-gray-200 bg-gray-50 p-3"><dt class="text-gray-500">Auth mode</dt><dd class="mt-1 font-medium text-gray-900" x-text="context.auth_mode"></dd></div>
                <div class="rounded-lg border border-gray-200 bg-gray-50 p-3"><dt class="text-gray-500">Connected account</dt><dd class="mt-1 font-medium text-gray-900 break-all" x-text="context.connected_email || 'Not verified yet'"></dd></div>
                <div class="rounded-lg border border-gray-200 bg-gray-50 p-3"><dt class="text-gray-500">Owner/share target</dt><dd class="mt-1 font-medium text-gray-900 break-all" x-text="context.owner_email || 'Not set'"></dd></div>
                <div class="rounded-lg border border-gray-200 bg-gray-50 p-3"><dt class="text-gray-500">Write access</dt><dd class="mt-1 font-medium" :class="context.has_write_access ? 'text-green-700' : 'text-red-700'" x-text="context.has_write_access ? 'Configured' : 'Not configured'"></dd></div>
                <div class="rounded-lg border border-gray-200 bg-gray-50 p-3"><dt class="text-gray-500">OAuth credentials</dt><dd class="mt-1 font-medium text-gray-900" x-text="context.has_oauth_credentials ? 'Present' : 'Missing'"></dd></div>
                <div class="rounded-lg border border-gray-200 bg-gray-50 p-3"><dt class="text-gray-500">Service-account JSON</dt><dd class="mt-1 font-medium text-gray-900" x-text="context.has_service_account ? 'Present' : 'Missing'"></dd></div>
            </dl>
            <div class="flex flex-wrap gap-3 pt-2">
                <button @click="testWrite" :disabled="testingWrite" type="button" class="inline-flex items-center gap-2 rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:opacity-50">
                    <svg x-show="testingWrite" x-cloak class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                    <span x-text="testingWrite ? 'Verifying…' : 'Verify write identity'"></span>
                </button>
                <button @click="smokeWrite" :disabled="smokingWrite" type="button" class="inline-flex items-center gap-2 rounded-lg bg-white px-4 py-2 text-sm font-medium text-gray-700 border border-gray-300 hover:bg-gray-50 disabled:opacity-50">
                    <svg x-show="smokingWrite" x-cloak class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                    <span x-text="smokingWrite ? 'Running…' : 'Smoke test create/update/delete'"></span>
                </button>
            </div>
            <p x-show="writeResult" x-cloak class="text-sm" :class="writeResult?.success ? 'text-green-700' : 'text-red-700'" x-text="writeResult?.message || ''"></p>
        </section>
    </div>

    <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm space-y-5">
        <div>
            <h2 class="text-lg font-semibold text-gray-900">OAuth user write credentials</h2>
            <p class="mt-1 text-sm text-gray-500">Use this when docs should be created directly under the authenticated Google mailbox.</p>
        </div>
        <div class="grid gap-4 md:grid-cols-2">
            <label class="block md:col-span-2"><span class="text-sm font-medium text-gray-700">Client ID</span><input x-model="oauth.oauth_client_id" type="text" class="mt-1 w-full rounded-lg border-gray-300 focus:border-sky-500 focus:ring-sky-500" placeholder="Google OAuth client ID"></label>
            <label class="block"><span class="text-sm font-medium text-gray-700">Client secret</span><input x-model="oauth.oauth_client_secret" type="password" class="mt-1 w-full rounded-lg border-gray-300 focus:border-sky-500 focus:ring-sky-500" placeholder="Google OAuth client secret"></label>
            <label class="block"><span class="text-sm font-medium text-gray-700">Refresh token</span><input x-model="oauth.oauth_refresh_token" type="password" class="mt-1 w-full rounded-lg border-gray-300 focus:border-sky-500 focus:ring-sky-500" placeholder="Google OAuth refresh token"></label>
        </div>
        <div class="flex items-center gap-3">
            <button @click="saveOauth" :disabled="savingOauth" type="button" class="inline-flex items-center gap-2 rounded-lg bg-sky-600 px-4 py-2 text-sm font-medium text-white hover:bg-sky-700 disabled:opacity-50"><svg x-show="savingOauth" x-cloak class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg><span x-text="savingOauth ? 'Saving…' : 'Save OAuth credentials'"></span></button>
            <p x-show="oauthResult" x-cloak class="text-sm" :class="oauthResult?.success ? 'text-green-700' : 'text-red-700'" x-text="oauthResult?.message || ''"></p>
        </div>
    </section>

    <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm space-y-5">
        <div>
            <h2 class="text-lg font-semibold text-gray-900">Service-account write credentials</h2>
            <p class="mt-1 text-sm text-gray-500">Use this when Hexa should write through a service account. Ownership still depends on the authenticated writer and Google sharing rules.</p>
        </div>
        <label class="block"><span class="text-sm font-medium text-gray-700">Service-account JSON</span><textarea x-model="serviceAccount.service_account_json" rows="10" class="mt-1 w-full rounded-lg border-gray-300 font-mono text-xs focus:border-sky-500 focus:ring-sky-500" placeholder='{type:service_account, ...}'></textarea></label>
        <div class="flex items-center gap-3">
            <button @click="saveServiceAccount" :disabled="savingServiceAccount" type="button" class="inline-flex items-center gap-2 rounded-lg bg-sky-600 px-4 py-2 text-sm font-medium text-white hover:bg-sky-700 disabled:opacity-50"><svg x-show="savingServiceAccount" x-cloak class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg><span x-text="savingServiceAccount ? 'Saving…' : 'Save service account'"></span></button>
            <p x-show="serviceAccountResult" x-cloak class="text-sm" :class="serviceAccountResult?.success ? 'text-green-700' : 'text-red-700'" x-text="serviceAccountResult?.message || ''"></p>
        </div>
    </section>

    <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm space-y-5">
        <div>
            <h2 class="text-lg font-semibold text-gray-900">Public document tester</h2>
            <p class="mt-1 text-sm text-gray-500">This keeps the existing public-read workflow intact for importing published Google Docs URLs.</p>
        </div>
        <div class="grid gap-4 md:grid-cols-4 items-end">
            <label class="block md:col-span-3"><span class="text-sm font-medium text-gray-700">Google Docs URL or document ID</span><input x-model="read.doc_url" type="text" class="mt-1 w-full rounded-lg border-gray-300 focus:border-sky-500 focus:ring-sky-500" placeholder="https://docs.google.com/document/d/.../edit"></label>
            <label class="block"><span class="text-sm font-medium text-gray-700">Format</span><select x-model="read.format" class="mt-1 w-full rounded-lg border-gray-300 focus:border-sky-500 focus:ring-sky-500"><option value="txt">Plain text</option><option value="html">HTML</option></select></label>
        </div>
        <div class="flex items-center gap-3">
            <button @click="runReadTest" :disabled="testingRead || !read.doc_url.trim()" type="button" class="inline-flex items-center gap-2 rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:opacity-50"><svg x-show="testingRead" x-cloak class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg><span x-text="testingRead ? 'Fetching…' : 'Fetch document'"></span></button>
            <p x-show="readResult?.message" x-cloak class="text-sm" :class="readResult?.success ? 'text-green-700' : 'text-red-700'" x-text="readResult?.message || ''"></p>
        </div>
        <template x-if="readResult && readResult.success">
            <div class="space-y-4 border-t border-gray-100 pt-4">
                <dl class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                    <div class="rounded-lg bg-gray-50 p-3 border border-gray-200"><dt class="text-gray-500">Title</dt><dd class="mt-1 font-medium text-gray-900" x-text="readResult.title || 'Untitled Document'"></dd></div>
                    <div class="rounded-lg bg-gray-50 p-3 border border-gray-200"><dt class="text-gray-500">Document ID</dt><dd class="mt-1 font-mono text-xs text-gray-900 break-all" x-text="readResult.document_id"></dd></div>
                    <div class="rounded-lg bg-gray-50 p-3 border border-gray-200"><dt class="text-gray-500">Export URL</dt><dd class="mt-1 text-xs break-all"><a class="text-sky-700 hover:underline" :href="readResult.export_url" target="_blank" x-text="readResult.export_url"></a></dd></div>
                    <div class="rounded-lg bg-gray-50 p-3 border border-gray-200"><dt class="text-gray-500">Bytes / MIME</dt><dd class="mt-1 text-gray-900"><span x-text="readResult.byte_length"></span> bytes <span class="text-gray-500">•</span> <span x-text="readResult.mime_type || 'unknown'"></span></dd></div>
                </dl>
                <div class="rounded-xl border border-gray-200 overflow-hidden"><div class="px-4 py-3 bg-gray-50 border-b border-gray-200"><h3 class="text-sm font-semibold text-gray-900">Extracted content</h3></div><pre class="p-4 text-sm text-gray-900 whitespace-pre-wrap break-words overflow-x-auto" x-text="displayReadContent()"></pre></div>
            </div>
        </template>
    </section>
</div>

<script>
function googleDocsSettings() {
    return {
        savingGeneral: false,
        savingOauth: false,
        savingServiceAccount: false,
        testingRead: false,
        testingWrite: false,
        smokingWrite: false,
        creatingFolder: false,
        generalResult: null,
        oauthResult: null,
        serviceAccountResult: null,
        readResult: null,
        writeResult: null,
        folderResult: null,
        context: {
            auth_mode: @js($authMode),
            owner_email: @js($ownerEmail),
            default_folder_id: @js($defaultFolderId),
            connected_email: @js($connectedEmail),
            has_oauth_credentials: @js($hasOauthCredentials),
            has_service_account: @js($hasServiceAccount),
            has_write_access: @js($hasWriteAccess),
        },
        general: {
            default_format: @js($defaultFormat),
            timeout_seconds: @js($timeoutSeconds),
            user_agent: @js($userAgent),
            max_preview_chars: @js($maxPreviewChars),
            auth_mode: @js($authMode),
            owner_email: @js($ownerEmail),
            default_folder_id: @js($defaultFolderId),
        },
        oauth: { oauth_client_id: '', oauth_client_secret: '', oauth_refresh_token: '' },
        serviceAccount: { service_account_json: '' },
        folder: { folder_name: "scalemypublication.com, publish exports", parent_folder_id: "", set_as_default: true },
        read: { doc_url: '', format: @js($defaultFormat) },
        init() {},
        async postJson(url, body) {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': @js(csrf_token()),
                },
                body: JSON.stringify(body),
            });
            const data = await response.json().catch(() => ({}));
            return { response, data };
        },
        async saveGeneral() {
            this.savingGeneral = true; this.generalResult = null;
            try {
                const { data } = await this.postJson(@js(route('settings.google-docs.general')), this.general);
                this.generalResult = data;
                if (data?.success) {
                    this.context.auth_mode = this.general.auth_mode;
                    this.context.owner_email = this.general.owner_email;
                    this.context.default_folder_id = this.general.default_folder_id;
                }
            } catch (error) {
                this.generalResult = { success: false, message: error.message };
            } finally { this.savingGeneral = false; }
        },
        async saveOauth() {
            this.savingOauth = true; this.oauthResult = null;
            try {
                const { data } = await this.postJson(@js(route('settings.google-docs.oauth')), this.oauth);
                this.oauthResult = data;
                if (data?.success) {
                    this.context.has_oauth_credentials = true;
                    this.context.has_write_access = true;
                    if (data.connected_email) this.context.connected_email = data.connected_email;
                }
            } catch (error) {
                this.oauthResult = { success: false, message: error.message };
            } finally { this.savingOauth = false; }
        },
        async saveServiceAccount() {
            this.savingServiceAccount = true; this.serviceAccountResult = null;
            try {
                const { data } = await this.postJson(@js(route('settings.google-docs.service-account')), this.serviceAccount);
                this.serviceAccountResult = data;
                if (data?.success) {
                    this.context.has_service_account = true;
                    this.context.has_write_access = true;
                    if (data.connected_email) this.context.connected_email = data.connected_email;
                }
            } catch (error) {
                this.serviceAccountResult = { success: false, message: error.message };
            } finally { this.savingServiceAccount = false; }
        },
        async runReadTest() {
            this.testingRead = true; this.readResult = null;
            try {
                const { data } = await this.postJson(@js(route('settings.google-docs.test-read')), this.read);
                this.readResult = data;
            } catch (error) {
                this.readResult = { success: false, message: error.message };
            } finally { this.testingRead = false; }
        },
        async testWrite() {
            this.testingWrite = true; this.writeResult = null;
            try {
                const { data } = await this.postJson(@js(route('settings.google-docs.test-write')), {});
                this.writeResult = data;
                if (data?.connected_email !== undefined) this.context.connected_email = data.connected_email || '';
            } catch (error) {
                this.writeResult = { success: false, message: error.message };
            } finally { this.testingWrite = false; }
        },
        async createFolder() {
            this.creatingFolder = true; this.folderResult = null;
            try {
                const { data } = await this.postJson(@js(route("settings.google-docs.create-folder")), this.folder);
                this.folderResult = data;
                if (data?.success && data?.folder_id) {
                    this.general.default_folder_id = data.folder_id;
                    this.context.default_folder_id = data.folder_id;
                }
            } catch (error) {
                this.folderResult = { success: false, message: error.message };
            } finally { this.creatingFolder = false; }
        },
        async smokeWrite() {
            this.smokingWrite = true; this.writeResult = null;
            try {
                const { data } = await this.postJson(@js(route('settings.google-docs.smoke')), {});
                this.writeResult = data;
            } catch (error) {
                this.writeResult = { success: false, message: error.message };
            } finally { this.smokingWrite = false; }
        },
        displayReadContent() {
            if (!this.readResult) return '';
            return this.readResult.format === 'html' ? (this.readResult.plain_text || this.readResult.content || '') : (this.readResult.content || '');
        },
    };
}
</script>
@endsection
