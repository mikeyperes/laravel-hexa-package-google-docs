@extends('layouts.app')

@section('title', 'Google Docs - ' . config('hws.app_name'))
@section('header', 'Google Docs')

@section('content')
<div class="max-w-5xl space-y-6" x-data="googleDocsSettings()" x-init="init()">
    <div class="rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900">
        <p class="font-semibold">Shared Google Docs integration</p>
        <p class="mt-1">Read mode imports public Google Docs without OAuth. Write mode creates and updates Google Docs through Google Drive + Google Docs APIs so the same package can be reused across Publish and other Hexa apps.</p>
    </div>

    <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm space-y-4">
        <h2 class="text-lg font-semibold text-gray-900">Google accounts</h2>
        <p class="text-sm text-gray-500">Choose an account to manage its credentials, write mode, sharing email, and Drive folder. New documents use the default account unless a caller selects another account.</p>
        <div class="flex flex-wrap items-end gap-3">
            <label class="block grow">
                <span class="text-sm font-medium text-gray-700">Account to manage</span>
                <select x-model="accountId" @change="selectAccount" class="mt-1 w-full rounded-lg border-gray-300">
                    <template x-for="account in accounts" :key="account.id">
                        <option :value="account.id" x-text="account.label + (account.id === defaultAccountId ? ' (default)' : '')"></option>
                    </template>
                </select>
            </label>
            <button type="button" @click="makeDefault" :disabled="savingAccount || accountId === defaultAccountId" class="rounded-lg bg-sky-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-50">Make default</button>
        </div>
        <p class="text-sm text-gray-600" x-show="accountId === defaultAccountId">This is the default account for new documents.</p>
        <form @submit.prevent="addAccount" class="flex flex-wrap items-end gap-3">
            <label class="block grow">
                <span class="text-sm font-medium text-gray-700">Add Google account</span>
                <input type="email" required x-model="newAccountEmail" placeholder="mediaagency.peres@gmail.com" class="mt-1 w-full rounded-lg border-gray-300">
            </label>
            <button type="submit" :disabled="savingAccount" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium disabled:opacity-50">Add account</button>
        </form>
        <p class="text-xs text-gray-500">Adding an email creates a profile. Authorize that account using the OAuth instructions below, save its credentials, then verify its identity before making it the default.</p>
        <p x-show="accountResult?.message" x-cloak :class="accountResult?.success ? 'text-green-700' : 'text-red-700'" class="text-sm" x-text="accountResult?.message || ''"></p>
    </section>

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1.25fr)_minmax(0,0.9fr)]">
        <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm space-y-5">
            <div>
                <h2 class="text-lg font-semibold text-gray-900">General settings</h2>
                <p class="mt-1 text-sm text-gray-500">Read defaults apply globally. Write mode, sharing email, and Drive folder apply only to the selected account.</p>
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
        <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900 space-y-3">
            <p class="font-semibold">OAuth setup for the selected account</p>
            <ol class="list-decimal space-y-3 pl-5 text-blue-800">
                <li>Open <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener" class="font-medium text-blue-700 underline hover:text-blue-900">Google Cloud Console → Credentials</a> and make sure you are in the <strong>same project</strong> as the OAuth client used on this page.</li>
                <li>If Google shows the consent warning, open <a href="https://console.cloud.google.com/apis/credentials/consent" target="_blank" rel="noopener" class="font-medium text-blue-700 underline hover:text-blue-900">OAuth consent screen</a>, fill the app name, support email, and developer contact email, choose <strong>External</strong>, save, and come back to Credentials.</li>
                <li>Enable both APIs in that same project <strong>before</strong> testing write access: <a href="https://console.cloud.google.com/apis/library/drive.googleapis.com" target="_blank" rel="noopener" class="font-medium text-blue-700 underline hover:text-blue-900">Google Drive API</a> and <a href="https://console.cloud.google.com/apis/library/docs.googleapis.com" target="_blank" rel="noopener" class="font-medium text-blue-700 underline hover:text-blue-900">Google Docs API</a>. If Google says an API was just enabled, wait a few minutes and retry.</li>
                <li>From Credentials, open or create the OAuth client. It must be a <strong>Web application</strong>. On the client page, under <strong>Authorized redirect URIs</strong>, click <strong>Add URI</strong> and paste <code class="rounded bg-blue-100 px-1.5 py-0.5 text-xs">https://developers.google.com/oauthplayground</code>. <strong>Do not</strong> put that value under Authorized JavaScript origins.</li>
                <li>Open <a href="https://developers.google.com/oauthplayground" target="_blank" rel="noopener" class="font-medium text-blue-700 underline hover:text-blue-900">OAuth Playground</a>, click the gear icon, check <strong>Use your own OAuth credentials</strong>, paste the OAuth client ID and client secret from Google Cloud, then click <strong>Close</strong>.</li>
                <li>In OAuth Playground Step 1, use the single scopes input and paste one exact scope pair into the <strong>same box</strong>, separated by a space. For folders created through this app, use <code class="rounded bg-blue-100 px-1.5 py-0.5 text-xs break-all">https://www.googleapis.com/auth/documents https://www.googleapis.com/auth/drive.file</code>. To write into an arbitrary pre-existing or shared folder, use <code class="rounded bg-blue-100 px-1.5 py-0.5 text-xs break-all">https://www.googleapis.com/auth/documents https://www.googleapis.com/auth/drive</code>.</li>
                <li>Click <strong>Authorize APIs</strong>, sign in as <strong>the selected Google account</strong>, and allow access. If you see <span class="font-mono">redirect_uri_mismatch</span>, go back to the OAuth client and fix the Authorized redirect URI step above.</li>
                <li>In OAuth Playground Step 2, click <strong>Exchange authorization code for tokens</strong>. Copy the <strong>refresh token only</strong> from the response and save it into the <strong>Google OAuth refresh token</strong> field below.</li>
                <li>After the three OAuth credential fields below are saved through HexaCore Credentials, go back to the top card and click <strong>Verify write identity</strong>. Then run <strong>Smoke test create/update/delete</strong>.</li>
                <li>When write verification passes, click <strong>Create export folder</strong> to create <span class="font-mono">scalemypublication.com, publish exports</span> and save that folder ID as the default export folder.</li>
            </ol>
            <div class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-xs text-amber-900 space-y-2">
                <p class="font-semibold">Common failure fixes</p>
                <ul class="list-disc space-y-1 pl-5">
                    <li>If Google shows <span class="font-mono">redirect_uri_mismatch</span>, the OAuth client is missing <code class="rounded bg-amber-100 px-1.5 py-0.5">https://developers.google.com/oauthplayground</code> under <strong>Authorized redirect URIs</strong>.</li>
                    <li>If write verification says Drive API is disabled, enable <a href="https://console.cloud.google.com/apis/library/drive.googleapis.com" target="_blank" rel="noopener" class="font-medium text-amber-800 underline hover:text-amber-950">Google Drive API</a> and <a href="https://console.cloud.google.com/apis/library/docs.googleapis.com" target="_blank" rel="noopener" class="font-medium text-amber-800 underline hover:text-amber-950">Google Docs API</a> in the same project, wait a few minutes, and test again.</li>
                    <li>If an existing folder reports insufficient authentication scope, generate a new refresh token with the full <code class="rounded bg-amber-100 px-1.5 py-0.5">https://www.googleapis.com/auth/drive</code> scope. Editing the old token cannot add a scope.</li>
                    <li>These fields are stored through the shared <strong>HexaCore credential system</strong>, not as loose package settings.</li>
                </ul>
            </div>
        </div>
        <div class="grid gap-4 md:grid-cols-2">
            <div class="md:col-span-2">
                <x-hexa-credential-field
                    :slug="$credentialSlug"
                    key-name="oauth_client_id"
                    label="Google OAuth client ID"
                    help="Create this in Google Cloud Console under APIs & Services → Credentials. Save it here, then save the client secret and refresh token below."
                />
            </div>
            <x-hexa-credential-field
                :slug="$credentialSlug"
                key-name="oauth_client_secret"
                label="Google OAuth client secret"
                help="Use the client secret from the same OAuth client as the client ID above."
            />
            <x-hexa-credential-field
                :slug="$credentialSlug"
                key-name="oauth_refresh_token"
                label="Google OAuth refresh token"
                help="Generate this in OAuth Playground while signed in as the selected Google account using the Docs and Drive scopes listed above."
            />
        </div>
    </section>

    <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm space-y-5">
        <div>
            <h2 class="text-lg font-semibold text-gray-900">Service-account write credentials</h2>
            <p class="mt-1 text-sm text-gray-500">Use this only when Hexa should write through a service account. File ownership and sharing still depend on the authenticated writer and Google Drive rules.</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm text-gray-700 space-y-2">
            <p class="font-semibold text-gray-900">Service-account path</p>
            <p>Create or manage service accounts in <a href="https://console.cloud.google.com/iam-admin/serviceaccounts" target="_blank" rel="noopener" class="font-medium text-blue-700 underline hover:text-blue-900">Google Cloud Console → Service Accounts</a>. If you use this mode, save the full JSON key below through the shared HexaCore credential field, then click <strong>Verify write identity</strong> in the connection card above.</p>
        </div>
        <x-hexa-credential-field
            :slug="$credentialSlug"
            key-name="service_account_json"
            label="Google service-account JSON"
            help="Paste the full JSON key as a single value. This is stored through HexaCore CredentialService and used only when Write auth mode is set to Service account write."
        />
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

@push('scripts')
<script type="application/json" id="google-docs-settings-config">{!! Illuminate\Support\Js::encode($settingsConfig) !!}</script>
<x-hexa-package-script package="google-docs" :version="config('google-docs.version')" asset="settings.js" />
@endpush
@endsection
