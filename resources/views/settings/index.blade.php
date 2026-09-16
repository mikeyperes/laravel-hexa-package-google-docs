@extends('layouts.app')

@section('title', 'Google Docs - ' . config('hws.app_name'))
@section('header', 'Google Docs')

@section('content')
<div class="max-w-5xl flex flex-col gap-6" x-data="googleDocsSettings()" x-init="init()">
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
                        <button @click="smokeAccount(account.id)" :disabled="testingAccountId === account.id || smokingAccountId === account.id" type="button" class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50">
                            <svg x-show="smokingAccountId === account.id" x-cloak class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                            <span x-text="smokingAccountId === account.id ? 'Running full test…' : 'Full write test'"></span>
                        </button>
                        <button @click="manageAccount(account.id)" type="button" class="rounded-lg border border-sky-200 bg-sky-50 px-4 py-2 text-sm font-medium text-sky-800 hover:bg-sky-100">Manage settings</button>
                        <button x-show="account.id !== defaultAccountId" @click="makeDefault(account.id)" :disabled="savingDefaultAccountId === account.id" type="button" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50" x-text="savingDefaultAccountId === account.id ? 'Checking…' : 'Make default'"></button>
                    </div>
                    <p class="text-xs text-gray-500">Full write test creates, updates, and deletes one temporary Google Doc.</p>

                    <template x-if="accountResults[account.id]">
                        <div class="rounded-xl border p-4" :class="accountResults[account.id].success ? 'border-green-200 bg-green-50 text-green-900' : 'border-red-200 bg-red-50 text-red-900'">
                            <p class="font-semibold" x-text="accountResults[account.id].success ? 'Test passed' : 'Test failed'"></p>
                            <p class="mt-1 text-sm" x-text="accountResults[account.id].message || 'Google did not return a usable result.'"></p>
                            <div x-show="!accountResults[account.id].success" class="mt-4 border-t border-red-200 pt-4 text-sm">
                                <p class="font-semibold">Fix this account in this order</p>
                                <ol class="mt-2 list-decimal space-y-2 pl-5">
                                    <li>Click <strong>Manage settings</strong> on this account and confirm <strong>OAuth user write</strong> is selected.</li>
                                    <li>In Google Cloud, use one OAuth <strong>Web application</strong> client and enable both the Google Drive API and Google Docs API.</li>
                                    <li>Add <code class="rounded bg-red-100 px-1 py-0.5 text-xs">https://developers.google.com/oauthplayground</code> under <strong>Authorized redirect URIs</strong>.</li>
                                    <li>In OAuth Playground, enable <strong>Use your own OAuth credentials</strong>, use the same client ID and secret, and sign in as <strong x-text="account.label"></strong>.</li>
                                    <li>Authorize the Docs + Drive scopes, exchange the code, save the new <strong>refresh token</strong> for this account, then click <strong>Test connection</strong> again.</li>
                                </ol>
                                <p class="mt-3 font-medium">If the old error was HTTP 400, do not reuse the old refresh token. It is usually expired, revoked, or tied to a different OAuth client.</p>
                            </div>
                        </div>
                    </template>

                    <details class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">
                        <summary class="cursor-pointer font-semibold">Setup and repair instructions for <span x-text="account.label"></span></summary>
                        <ol class="mt-3 list-decimal space-y-2 pl-5 text-blue-800">
                            <li>Open <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener" class="font-medium underline">Google Cloud credentials</a> and open the OAuth web client used for this account.</li>
                            <li>Enable the <a href="https://console.cloud.google.com/apis/library/drive.googleapis.com" target="_blank" rel="noopener" class="font-medium underline">Drive API</a> and <a href="https://console.cloud.google.com/apis/library/docs.googleapis.com" target="_blank" rel="noopener" class="font-medium underline">Docs API</a> in that project.</li>
                            <li>Add <code class="rounded bg-blue-100 px-1 py-0.5 text-xs">https://developers.google.com/oauthplayground</code> as an authorized redirect URI.</li>
                            <li>In <a href="https://developers.google.com/oauthplayground" target="_blank" rel="noopener" class="font-medium underline">OAuth Playground</a>, use your own OAuth credentials and authorize <code class="break-all text-xs">https://www.googleapis.com/auth/documents https://www.googleapis.com/auth/drive</code> while signed in as <strong x-text="account.label"></strong>.</li>
                            <li>Exchange the authorization code, copy the new refresh token, save all three OAuth values under this account, and run <strong>Test connection</strong>.</li>
                        </ol>
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
            <p class="mt-1 text-sm text-gray-500">These credentials belong only to this email profile. Saving them does not change another Google account.</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-gray-50 p-4">
            <p class="text-sm font-semibold text-gray-900">Current saved values</p>
            <div class="mt-3 grid gap-3 text-sm md:grid-cols-3">
                <div class="rounded-lg border border-gray-200 bg-white p-3 flex flex-col gap-2">
                    <div class="flex items-center justify-between gap-2"><p class="text-xs font-medium uppercase text-gray-500">Client ID</p><button x-show="context.has_oauth_client_id" @click="toggleCredentialReveal('oauth_client_id')" :disabled="revealingCredential === 'oauth_client_id'" type="button" class="rounded-lg border border-gray-300 px-2 py-1 text-xs font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-50" x-text="revealingCredential === 'oauth_client_id' ? 'Loading…' : (credentialIsRevealed('oauth_client_id') ? 'Hide' : 'Reveal')"></button></div>
                    <p class="font-semibold" :class="context.has_oauth_client_id ? 'text-green-700' : 'text-red-700'" x-text="context.has_oauth_client_id ? 'Saved' : 'Missing'"></p>
                    <code x-show="credentialIsRevealed('oauth_client_id')" x-cloak class="rounded-lg border border-gray-200 bg-gray-50 p-2 text-xs text-gray-900 break-all" x-text="revealedCredentials.oauth_client_id || ''"></code>
                </div>
                <div class="rounded-lg border border-gray-200 bg-white p-3 flex flex-col gap-2">
                    <div class="flex items-center justify-between gap-2"><p class="text-xs font-medium uppercase text-gray-500">Client secret</p><button x-show="context.has_oauth_client_secret" @click="toggleCredentialReveal('oauth_client_secret')" :disabled="revealingCredential === 'oauth_client_secret'" type="button" class="rounded-lg border border-gray-300 px-2 py-1 text-xs font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-50" x-text="revealingCredential === 'oauth_client_secret' ? 'Loading…' : (credentialIsRevealed('oauth_client_secret') ? 'Hide' : 'Reveal')"></button></div>
                    <p class="font-semibold" :class="context.has_oauth_client_secret ? 'text-green-700' : 'text-red-700'" x-text="context.has_oauth_client_secret ? 'Saved' : 'Missing'"></p>
                    <code x-show="credentialIsRevealed('oauth_client_secret')" x-cloak class="rounded-lg border border-gray-200 bg-gray-50 p-2 text-xs text-gray-900 break-all" x-text="revealedCredentials.oauth_client_secret || ''"></code>
                </div>
                <div class="rounded-lg border border-gray-200 bg-white p-3 flex flex-col gap-2">
                    <div class="flex items-center justify-between gap-2"><p class="text-xs font-medium uppercase text-gray-500">Refresh token</p><button x-show="context.has_oauth_refresh_token" @click="toggleCredentialReveal('oauth_refresh_token')" :disabled="revealingCredential === 'oauth_refresh_token'" type="button" class="rounded-lg border border-gray-300 px-2 py-1 text-xs font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-50" x-text="revealingCredential === 'oauth_refresh_token' ? 'Loading…' : (credentialIsRevealed('oauth_refresh_token') ? 'Hide' : 'Reveal')"></button></div>
                    <p class="font-semibold" :class="context.has_oauth_refresh_token ? 'text-green-700' : 'text-red-700'" x-text="context.has_oauth_refresh_token ? 'Saved — replace below if expired' : 'Missing — add below'"></p>
                    <code x-show="credentialIsRevealed('oauth_refresh_token')" x-cloak class="rounded-lg border border-gray-200 bg-gray-50 p-2 text-xs text-gray-900 break-all" x-text="revealedCredentials.oauth_refresh_token || ''"></code>
                </div>
            </div>
            <p x-show="credentialRevealResult?.message" x-cloak class="mt-3 text-sm text-red-700" x-text="credentialRevealResult?.message || ''"></p>
            <p class="mt-3 text-xs text-gray-600">Reveal shows the current saved value for this account in this browser. Use Hide when you are finished. Saving a replacement below changes only this account.</p>
        </div>
        <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900 flex flex-col gap-3">
            <p class="font-semibold">Replace the failed refresh token — exact steps</p>
            <ol class="list-decimal space-y-3 pl-5 text-blue-800">
                <li>Open <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener" class="font-medium text-blue-700 underline hover:text-blue-900">Google Cloud → Credentials</a> and open the OAuth client used for this email. Its type must be <strong>Web application</strong>.</li>
                <li>In the same Google Cloud project, enable the <a href="https://console.cloud.google.com/apis/library/drive.googleapis.com" target="_blank" rel="noopener" class="font-medium text-blue-700 underline">Google Drive API</a> and <a href="https://console.cloud.google.com/apis/library/docs.googleapis.com" target="_blank" rel="noopener" class="font-medium text-blue-700 underline">Google Docs API</a>.</li>
                <li>Under <strong>Authorized redirect URIs</strong>, add <code class="rounded bg-blue-100 px-1.5 py-0.5 text-xs">https://developers.google.com/oauthplayground</code>. Save the OAuth client.</li>
                <li>Copy that client's ID and secret. Open <a href="https://developers.google.com/oauthplayground" target="_blank" rel="noopener" class="font-medium text-blue-700 underline hover:text-blue-900">OAuth Playground</a>, click the gear, enable <strong>Use your own OAuth credentials</strong>, paste both values, and close the gear panel.</li>
                <li>In OAuth Playground Step 1, paste <code class="rounded bg-blue-100 px-1.5 py-0.5 text-xs break-all">https://www.googleapis.com/auth/documents https://www.googleapis.com/auth/drive</code> into the scopes box and click <strong>Authorize APIs</strong>.</li>
                <li>Sign in as <strong x-text="selectedAccountLabel()"></strong> and approve access. If another Google email appears, sign out and restart this step with the correct account.</li>
                <li>In OAuth Playground Step 2, click <strong>Exchange authorization code for tokens</strong>. Copy the value labeled <strong>Refresh token</strong>, not the access token.</li>
                <li>Back on this page, save the client ID, client secret, and new refresh token in the three fields below. The new refresh token replaces the saved hidden token.</li>
                <li>Go to this email's account card, click <strong>Test connection</strong>, then click <strong>Full write test</strong> after the connection passes.</li>
            </ol>
        </div>
        <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950">
            <p class="font-semibold">If Test connection fails</p>
            <ol class="mt-2 list-decimal space-y-2 pl-5">
                <li><strong>HTTP 400 or invalid_grant:</strong> generate a completely new refresh token in OAuth Playground. The saved token is expired, revoked, or tied to a different OAuth client.</li>
                <li><strong>OAuth client rejected:</strong> copy the client ID and secret again from the same web client, save both, then generate another refresh token with those exact values.</li>
                <li><strong>redirect_uri_mismatch:</strong> add the OAuth Playground URL under Authorized redirect URIs and retry authorization.</li>
                <li><strong>Insufficient scope:</strong> generate a new token with the full Docs + Drive scopes shown above. An existing token cannot be upgraded.</li>
                <li><strong>Wrong connected email:</strong> sign out of Google in OAuth Playground, sign in as <strong x-text="selectedAccountLabel()"></strong>, and create the token again.</li>
            </ol>
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
