@extends('layouts.app')

@section('title', 'Google Docs - ' . config('hws.app_name'))
@section('header', 'Google Docs')

@section('content')
<div class="max-w-5xl flex flex-col gap-6" x-data="googleDocsSettings()" x-init="init()">
    @if(session('success'))
        <div class="rounded-xl border border-green-200 bg-green-50 p-4 text-sm font-medium text-green-900">{{ session('success') }}</div>
    @endif
    @if(session('warning'))
        <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm font-medium text-amber-950">{{ session('warning') }}</div>
    @endif
    @if(session('error'))
        <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm font-medium text-red-900">{{ session('error') }}</div>
    @endif

    <div class="rounded-xl border border-sky-200 bg-sky-50 p-5 text-sky-900">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold">Google Docs account control</h2>
                <p class="mt-1 text-sm">Every Google identity is listed below with its own status, connection test, full write test, settings, and repair steps.</p>
            </div>
            <span class="rounded-lg border border-sky-200 bg-white px-3 py-1.5 text-sm font-semibold" x-text="accounts.length + (accounts.length === 1 ? ' account' : ' accounts')"></span>
        </div>
    </div>

    <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm flex flex-col gap-5">
        <div>
            <h2 class="text-lg font-semibold text-gray-900">Authenticated Google accounts</h2>
            <p class="mt-1 text-sm text-gray-500">Test the exact email you intend to use. A green credential status means values are saved; a successful connection test confirms Google accepts them now.</p>
        </div>

        <div class="flex flex-col gap-4">
            <template x-for="account in accounts" :key="account.id">
                <article class="rounded-xl border bg-white p-5 flex flex-col gap-4" :class="account.id === accountId ? 'border-sky-400' : 'border-gray-200'">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="text-base font-semibold text-gray-900 break-all" x-text="accountEmail(account)"></h3>
                                <span x-show="account.id === defaultAccountId" class="rounded-lg bg-sky-100 px-2 py-1 text-xs font-semibold text-sky-800">Default</span>
                                <span x-show="account.id === accountId" class="rounded-lg bg-gray-100 px-2 py-1 text-xs font-semibold text-gray-700">Open below</span>
                            </div>
                            <p x-show="account.connected_email && account.connected_email.toLowerCase() !== account.label.toLowerCase()" class="mt-1 text-xs text-gray-500">Profile email: <span x-text="account.label"></span></p>
                        </div>
                        <span class="rounded-lg px-3 py-1.5 text-xs font-semibold" :class="account.has_write_access ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-900'" x-text="account.has_write_access ? 'Credentials saved' : 'Setup required'"></span>
                    </div>

                    <dl class="grid gap-3 text-sm md:grid-cols-3">
                        <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
                            <dt class="text-xs font-medium uppercase text-gray-500">Connected as</dt>
                            <dd class="mt-1 font-medium text-gray-900 break-all" x-text="account.connected_email || 'Not verified yet'"></dd>
                        </div>
                        <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
                            <dt class="text-xs font-medium uppercase text-gray-500">Sign-in method</dt>
                            <dd class="mt-1 font-medium text-gray-900" x-text="authModeLabel(account.auth_mode)"></dd>
                        </div>
                        <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
                            <dt class="text-xs font-medium uppercase text-gray-500">Export folder</dt>
                            <dd class="mt-1 font-medium text-gray-900" x-text="account.default_folder_id ? 'Configured' : 'Not set'"></dd>
                        </div>
                    </dl>

                    <div x-show="account.auth_mode === 'oauth_user'" class="rounded-lg border border-gray-200 bg-gray-50 p-3">
                        <p class="text-xs font-medium uppercase text-gray-500">Saved OAuth values</p>
                        <div class="mt-2 flex flex-wrap gap-2 text-xs font-medium">
                            <span class="rounded-lg px-2 py-1" :class="account.has_oauth_client_id ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'" x-text="account.has_oauth_client_id ? 'Client ID saved' : 'Client ID missing'"></span>
                            <span class="rounded-lg px-2 py-1" :class="account.has_oauth_client_secret ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'" x-text="account.has_oauth_client_secret ? 'Client secret saved' : 'Client secret missing'"></span>
                            <span class="rounded-lg px-2 py-1" :class="account.has_oauth_refresh_token ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'" x-text="account.has_oauth_refresh_token ? 'Refresh token saved (hidden)' : 'Refresh token missing'"></span>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-3">
                        <button @click="testAccount(account.id)" :disabled="testingAccountId === account.id || smokingAccountId === account.id" type="button" class="inline-flex items-center gap-2 rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:opacity-50">
                            <svg x-show="testingAccountId === account.id" x-cloak class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                            <span x-text="testingAccountId === account.id ? 'Testing…' : 'Test connection'"></span>
                        </button>
                        <button x-show="account.auth_mode === 'oauth_user'" @click="refreshToken(account.id)" :disabled="refreshingAccountId !== null" type="button" class="inline-flex items-center gap-2 rounded-lg bg-sky-700 px-4 py-2 text-sm font-medium text-white hover:bg-sky-800 disabled:opacity-50">
                            <svg x-show="refreshingAccountId === account.id" x-cloak class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                            <span x-text="refreshingAccountId === account.id ? 'Opening Google…' : 'Refresh token'"></span>
                        </button>
                        <button @click="smokeAccount(account.id)" :disabled="testingAccountId === account.id || smokingAccountId === account.id" type="button" class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50">
                            <svg x-show="smokingAccountId === account.id" x-cloak class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                            <span x-text="smokingAccountId === account.id ? 'Running full test…' : 'Full write test'"></span>
                        </button>
                        <button @click="manageAccount(account.id)" type="button" class="rounded-lg border border-sky-200 bg-sky-50 px-4 py-2 text-sm font-medium text-sky-800 hover:bg-sky-100">Manage settings</button>
                        <button x-show="account.id !== defaultAccountId" @click="makeDefault(account.id)" :disabled="savingDefaultAccountId === account.id" type="button" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50" x-text="savingDefaultAccountId === account.id ? 'Checking…' : 'Make default'"></button>
                    </div>
                    <ul class="flex flex-col gap-1 text-xs text-gray-600">
                        <li><strong>Test connection</strong> checks that Google accepts this account's saved token.</li>
                        <li x-show="account.auth_mode === 'oauth_user'"><strong>Refresh token</strong> opens Google in this tab so you can sign in and issue a new token. Code saves it and tests it when Google sends you back.</li>
                        <li><strong>Full write test</strong> creates one temporary Google Doc as this account, then deletes it.</li>
                    </ul>

                    <template x-if="accountResults[account.id]">
                        <div class="rounded-xl border p-4" :class="accountResults[account.id].success ? 'border-green-200 bg-green-50 text-green-900' : 'border-red-200 bg-red-50 text-red-900'">
                            <p class="font-semibold" x-text="accountResults[account.id].success ? 'Test passed' : 'Test failed'"></p>
                            <p class="mt-1 text-sm" x-text="accountResults[account.id].message || 'Google did not return a usable result.'"></p>
                            <p x-show="!accountResults[account.id].success" class="mt-3 border-t border-red-200 pt-3 text-sm font-medium">To fix it, follow <strong>How to reconnect</strong> below, starting at step 1.</p>
                        </div>
                    </template>

                    <details x-show="account.auth_mode === 'oauth_user'" x-effect="if (accountResults[account.id] && !accountResults[account.id].success) $el.open = true" class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-950">
                        <summary class="cursor-pointer font-semibold">How to reconnect <span x-text="account.label"></span> — step by step</summary>
                        <div class="mt-4 flex flex-col gap-5">
                            <div>
                                <p class="font-semibold">Reconnect this account</p>
                                <ol class="mt-2 flex list-decimal flex-col gap-2 pl-5">
                                    <li>Open <a href="https://console.cloud.google.com/auth/audience" target="_blank" rel="noopener" class="font-medium underline">Google Cloud → Audience ↗</a>. If <strong>Publishing status</strong> says <strong>Testing</strong>, click <strong>Publish app</strong>, then <strong>Confirm</strong>. While an app is in Testing, Google cancels its tokens after 7 days. If it says <strong>In production</strong>, skip this step.</li>
                                    <li>Come back to this page and click <strong>Refresh token</strong> on this card.</li>
                                    <li>Google asks you to choose an account. Choose <strong x-text="account.label"></strong>. If it is not listed, click <strong>Use another account</strong> and sign in as <strong x-text="account.label"></strong>.</li>
                                    <li>If Google shows <strong>Google hasn't verified this app</strong>, click <strong>Advanced</strong>, then the <strong>Go to … (unsafe)</strong> link below it.</li>
                                    <li>If Google shows checkboxes for what the app can access, tick <strong>Select all</strong> so Google Docs and Google Drive are both checked. Then click <strong>Continue</strong>.</li>
                                    <li>Google sends you back to this page. A green banner at the top saying <strong>Refresh token saved and Google Docs connection verified</strong> means it worked. Any other banner: find its wording under <strong>If you see an error</strong> below.</li>
                                    <li>Click <strong>Full write test</strong> on this card. <strong>Test passed</strong> means Code created and deleted a Google Doc as this account.</li>
                                </ol>
                            </div>

                            <div>
                                <p class="font-semibold">If you see an error</p>
                                <ul class="mt-2 flex list-disc flex-col gap-2 pl-5">
                                    <li><strong>Google page: Error 400: redirect_uri_mismatch</strong> — open <a href="https://console.cloud.google.com/auth/clients" target="_blank" rel="noopener" class="font-medium underline">Google Cloud → Clients ↗</a> and click the client whose Client ID matches this account. To see this account's Client ID, click <strong>Manage settings</strong>, then <strong>Reveal</strong> beside <strong>Google OAuth client ID</strong>. Under <strong>Authorized redirect URIs</strong>, click <strong>Add URI</strong>, paste <code class="break-all rounded bg-blue-100 px-1.5 py-0.5 text-xs">{{ route('settings.google-docs.oauth.callback') }}</code>, and click <strong>Save</strong>. Wait 5 minutes, then click <strong>Refresh token</strong> again.</li>
                                    <li><strong>Google page: Access blocked … has not completed the Google verification process</strong> — the app is still in Testing. Do step 1 above, or on <a href="https://console.cloud.google.com/auth/audience" target="_blank" rel="noopener" class="font-medium underline">Audience ↗</a> click <strong>Add users</strong> under <strong>Test users</strong> and add <strong x-text="account.label"></strong>. Then click <strong>Refresh token</strong> again.</li>
                                    <li><strong>Google authorized … but this profile is …</strong> — you picked the wrong Google account. Click <strong>Refresh token</strong> again and choose <strong x-text="account.label"></strong>.</li>
                                    <li><strong>Google did not issue a new offline refresh token</strong> — signed in as <strong x-text="account.label"></strong>, open <a href="https://myaccount.google.com/connections" target="_blank" rel="noopener" class="font-medium underline">Google Account → Third-party connections ↗</a>, select this app, and remove its access. Then click <strong>Refresh token</strong> again.</li>
                                    <li><strong>The Google OAuth request is invalid or expired</strong> — more than 10 minutes passed, or you finished in a different browser. Click <strong>Refresh token</strong> again and finish within 10 minutes.</li>
                                    <li><strong>Google authorization was not completed</strong> — the Google screen was cancelled. Click <strong>Refresh token</strong> again and click <strong>Continue</strong> at the end.</li>
                                    <li><strong>Google OAuth token exchange failed</strong> — the Client secret saved here does not belong to that client. In <a href="https://console.cloud.google.com/auth/clients" target="_blank" rel="noopener" class="font-medium underline">Clients ↗</a>, open the matching client and copy its secret. Click <strong>Manage settings</strong>, click <strong>Change</strong> beside <strong>Google OAuth client secret</strong>, paste it, and save. Then click <strong>Refresh token</strong> again.</li>
                                    <li><strong>Test says an API has not been used in project … or it is disabled</strong> — in that project, click <strong>Enable</strong> on <a href="https://console.cloud.google.com/apis/library/drive.googleapis.com" target="_blank" rel="noopener" class="font-medium underline">Google Drive API ↗</a> and <a href="https://console.cloud.google.com/apis/library/docs.googleapis.com" target="_blank" rel="noopener" class="font-medium underline">Google Docs API ↗</a>. Wait 5 minutes, then click <strong>Test connection</strong>.</li>
                                </ul>
                            </div>

                            <div x-show="!account.has_oauth_client_id || !account.has_oauth_client_secret">
                                <p class="font-semibold">First-time setup — this account is missing its Client ID or Client secret</p>
                                <ol class="mt-2 flex list-decimal flex-col gap-2 pl-5">
                                    <li>Open <a href="https://console.cloud.google.com/auth/clients" target="_blank" rel="noopener" class="font-medium underline">Google Cloud → Clients ↗</a> and click <strong>Create client</strong>. Set <strong>Application type</strong> to <strong>Web application</strong>.</li>
                                    <li>Under <strong>Authorized redirect URIs</strong>, click <strong>Add URI</strong> and paste <code class="break-all rounded bg-blue-100 px-1.5 py-0.5 text-xs">{{ route('settings.google-docs.oauth.callback') }}</code>. Click <strong>Create</strong> and keep the Client ID and Client secret it shows.</li>
                                    <li>In the same project, click <strong>Enable</strong> on <a href="https://console.cloud.google.com/apis/library/drive.googleapis.com" target="_blank" rel="noopener" class="font-medium underline">Google Drive API ↗</a> and <a href="https://console.cloud.google.com/apis/library/docs.googleapis.com" target="_blank" rel="noopener" class="font-medium underline">Google Docs API ↗</a>.</li>
                                    <li>Click <strong>Manage settings</strong> on this card. Save the Client ID and Client secret in <strong>OAuth credentials</strong>.</li>
                                    <li>Follow <strong>Reconnect this account</strong> above.</li>
                                </ol>
                            </div>
                        </div>
                    </details>
                </article>
            </template>
        </div>

        <div class="border-t border-gray-200 pt-5">
            <form @submit.prevent="addAccount" class="flex flex-wrap items-end gap-3">
                <label class="block grow">
                    <span class="text-sm font-medium text-gray-700">Add another Google email account</span>
                    <input type="email" required x-model="newAccountEmail" placeholder="name@gmail.com" class="mt-1 w-full rounded-lg border-gray-300">
                </label>
                <button type="submit" :disabled="savingAccount" class="rounded-lg bg-sky-600 px-4 py-2 text-sm font-medium text-white hover:bg-sky-700 disabled:opacity-50" x-text="savingAccount ? 'Adding…' : 'Add account'"></button>
            </form>
            <p class="mt-2 text-xs text-gray-500">This creates a separate credential profile. Its OAuth values, connection test, default folder, and results stay attached to that email.</p>
            <p x-show="accountResult?.message" x-cloak :class="accountResult?.success ? 'text-green-700' : 'text-red-700'" class="mt-2 text-sm" x-text="accountResult?.message || ''"></p>
        </div>
    </section>

    <section id="account-settings" class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm flex flex-col gap-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase text-sky-700">Account settings</p>
                <h2 class="mt-1 text-lg font-semibold text-gray-900 break-all" x-text="selectedAccountLabel()"></h2>
                <p class="mt-1 text-sm text-gray-500">Only this account is changed by the fields in this section.</p>
            </div>
            <button x-show="accountId !== defaultAccountId" @click="makeDefault(accountId)" :disabled="savingDefaultAccountId === accountId" type="button" class="rounded-lg border border-sky-200 bg-sky-50 px-4 py-2 text-sm font-medium text-sky-800 hover:bg-sky-100 disabled:opacity-50" x-text="savingDefaultAccountId === accountId ? 'Checking account…' : 'Make this the default'"></button>
        </div>

        <div class="grid gap-3 text-sm md:grid-cols-3">
            <div class="rounded-lg border border-gray-200 bg-gray-50 p-3"><p class="text-xs font-medium uppercase text-gray-500">Connected Google email</p><p class="mt-1 font-semibold text-gray-900 break-all" x-text="context.connected_email || 'Not verified yet'"></p></div>
            <div class="rounded-lg border border-gray-200 bg-gray-50 p-3"><p class="text-xs font-medium uppercase text-gray-500">Credentials</p><p class="mt-1 font-semibold" :class="context.has_write_access ? 'text-green-700' : 'text-amber-800'" x-text="context.has_write_access ? 'Saved — test now' : 'Setup required'"></p></div>
            <div class="rounded-lg border border-gray-200 bg-gray-50 p-3"><p class="text-xs font-medium uppercase text-gray-500">Default account</p><p class="mt-1 font-semibold text-gray-900" x-text="accountId === defaultAccountId ? 'Yes' : 'No'"></p></div>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <label class="block">
                <span class="text-sm font-medium text-gray-700">Write authentication</span>
                <select x-model="general.auth_mode" class="mt-1 w-full rounded-lg border-gray-300 focus:border-sky-500 focus:ring-sky-500">
                    <option value="public_read">Public read only</option>
                    <option value="oauth_user">OAuth user write</option>
                    <option value="service_account">Service account write</option>
                </select>
            </label>
            <label class="block">
                <span class="text-sm font-medium text-gray-700">Share new documents with</span>
                <input x-model="general.owner_email" type="email" class="mt-1 w-full rounded-lg border-gray-300 focus:border-sky-500 focus:ring-sky-500" placeholder="contact@michaelperes.com">
            </label>
            <label class="block md:col-span-2">
                <span class="text-sm font-medium text-gray-700">Default Google Drive folder ID</span>
                <input x-model="general.default_folder_id" type="text" class="mt-1 w-full rounded-lg border-gray-300 focus:border-sky-500 focus:ring-sky-500" placeholder="Leave blank until a folder is created">
            </label>
        </div>

        <details class="rounded-xl border border-gray-200 bg-gray-50 p-4">
            <summary class="cursor-pointer text-sm font-semibold text-gray-900">Advanced public-import defaults</summary>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <label class="block"><span class="text-sm font-medium text-gray-700">Default export format</span><select x-model="general.default_format" class="mt-1 w-full rounded-lg border-gray-300"><option value="txt">Plain text</option><option value="html">HTML</option></select></label>
                <label class="block"><span class="text-sm font-medium text-gray-700">Timeout seconds</span><input x-model="general.timeout_seconds" type="number" min="5" max="60" class="mt-1 w-full rounded-lg border-gray-300"></label>
                <label class="block md:col-span-2"><span class="text-sm font-medium text-gray-700">User-Agent</span><input x-model="general.user_agent" type="text" class="mt-1 w-full rounded-lg border-gray-300"></label>
                <label class="block md:col-span-2"><span class="text-sm font-medium text-gray-700">Preview character limit</span><input x-model="general.max_preview_chars" type="number" min="200" max="5000" class="mt-1 w-full rounded-lg border-gray-300"></label>
            </div>
        </details>

        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 flex flex-col gap-3">
            <div>
                <p class="text-sm font-semibold text-emerald-900">Create this account's export folder</p>
                <p class="mt-1 text-xs text-emerald-800">The folder is created using <strong x-text="selectedAccountLabel()"></strong> and saved as this account's default folder.</p>
            </div>
            <div class="grid gap-3 md:grid-cols-2">
                <label class="block"><span class="text-sm font-medium text-emerald-900">Folder name</span><input x-model="folder.folder_name" type="text" class="mt-1 w-full rounded-lg border-emerald-300" placeholder="scalemypublication.com, publish exports"></label>
                <label class="block"><span class="text-sm font-medium text-emerald-900">Parent folder ID (optional)</span><input x-model="folder.parent_folder_id" type="text" class="mt-1 w-full rounded-lg border-emerald-300" placeholder="Leave blank for Drive root"></label>
            </div>
            <label class="inline-flex items-center gap-2 text-sm text-emerald-900"><input x-model="folder.set_as_default" type="checkbox" class="rounded border-emerald-300 text-emerald-600"><span>Save this as the account's default export folder</span></label>
            <div class="flex flex-wrap items-center gap-3">
                <button @click="createFolder" :disabled="creatingFolder" type="button" class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50"><svg x-show="creatingFolder" x-cloak class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg><span x-text="creatingFolder ? 'Creating…' : 'Create export folder'"></span></button>
                <a x-show="folderResult?.success && folderResult?.web_view_link" x-cloak class="text-sm font-medium text-emerald-700 hover:underline" :href="folderResult?.web_view_link || '#'" target="_blank" rel="noopener">Open folder</a>
                <p x-show="folderResult" x-cloak class="text-sm" :class="folderResult?.success ? 'text-emerald-700' : 'text-red-700'" x-text="folderResult?.message || ''"></p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <button @click="saveGeneral" :disabled="savingGeneral" type="button" class="inline-flex items-center gap-2 rounded-lg bg-sky-600 px-4 py-2 text-sm font-medium text-white hover:bg-sky-700 disabled:opacity-50"><svg x-show="savingGeneral" x-cloak class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg><span x-text="savingGeneral ? 'Saving…' : 'Save account settings'"></span></button>
            <button @click="testAccount(accountId)" :disabled="testingAccountId === accountId" type="button" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50" x-text="testingAccountId === accountId ? 'Testing…' : 'Test this account'"></button>
            <p x-show="generalResult" x-cloak class="text-sm" :class="generalResult?.success ? 'text-green-700' : 'text-red-700'" x-text="generalResult?.message || ''"></p>
        </div>
    </section>

    <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm flex flex-col gap-5">
        <div>
            <p class="text-xs font-semibold uppercase text-sky-700">OAuth credentials for</p>
            <h2 class="mt-1 text-lg font-semibold text-gray-900 break-all" x-text="selectedAccountLabel()"></h2>
            <p class="mt-1 text-sm text-gray-500">Each saved value is shown below as its own full-width row. Reveal, Copy, Change, and save behavior comes from shared Hexa Core.</p>
        </div>
        <div class="rounded-xl border border-sky-200 bg-sky-50 p-5 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="font-semibold text-sky-950">Automatic token refresh</p>
                <p class="mt-1 text-sm text-sky-800">Click once, choose <strong x-text="selectedAccountLabel()"></strong> at Google, and approve access. Hexa saves the new refresh token and tests this account when Google sends you back.</p>
            </div>
            <button @click="refreshToken(accountId)" :disabled="refreshingAccountId !== null" type="button" class="inline-flex shrink-0 items-center justify-center gap-2 rounded-lg bg-sky-700 px-5 py-3 text-sm font-semibold text-white hover:bg-sky-800 disabled:opacity-50">
                <svg x-show="refreshingAccountId === accountId" x-cloak class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                <span x-text="refreshingAccountId === accountId ? 'Opening Google…' : 'Refresh token'"></span>
            </button>
        </div>

        <div class="flex flex-col gap-4">
            <x-hexa-credential-field
                :slug="$credentialSlug"
                key-name="oauth_client_id"
                label="Google OAuth client ID"
                help="The Web application client ID saved for this email profile."
            />
            <x-hexa-credential-field
                :slug="$credentialSlug"
                key-name="oauth_client_secret"
                label="Google OAuth client secret"
                help="The client secret from the same Web application client as the client ID above."
            />
            <x-hexa-credential-field
                :slug="$credentialSlug"
                key-name="oauth_refresh_token"
                label="Google OAuth refresh token"
                help="Refresh token saves this value automatically. Manual replacement remains available through Change."
            />
        </div>

        <p class="text-sm text-gray-600">Step-by-step reconnect instructions and the fix for every error are on the <strong x-text="selectedAccountLabel()"></strong> card above, under <strong>How to reconnect</strong>.</p>
    </section>

    <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm flex flex-col gap-5">
        <div>
            <h2 class="text-lg font-semibold text-gray-900">Service-account write credentials</h2>
            <p class="mt-1 text-sm text-gray-500">These values apply only to <strong x-text="selectedAccountLabel()"></strong>. Use this mode only when Hexa should write through a service account.</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm text-gray-700 flex flex-col gap-2">
            <p class="font-semibold text-gray-900">Service-account path</p>
            <p>Create or manage service accounts in <a href="https://console.cloud.google.com/iam-admin/serviceaccounts" target="_blank" rel="noopener" class="font-medium text-blue-700 underline hover:text-blue-900">Google Cloud Console → Service Accounts</a>. Save the full JSON key below, select <strong>Service account write</strong> in this account's settings, then click <strong>Test connection</strong> on its card.</p>
        </div>
        <x-hexa-credential-field
            :slug="$credentialSlug"
            key-name="service_account_json"
            label="Google service-account JSON"
            help="Paste the full JSON key as a single value. This is stored through HexaCore CredentialService and used only when Write auth mode is set to Service account write."
        />
    </section>

    <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm flex flex-col gap-5">
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
            <div class="flex flex-col gap-4 border-t border-gray-100 pt-4">
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
