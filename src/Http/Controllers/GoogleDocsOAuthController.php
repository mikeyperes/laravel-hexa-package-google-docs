<?php

namespace hexa_package_google_docs\Http\Controllers;

use hexa_core\Http\Controllers\Controller;
use hexa_package_google_docs\Services\GoogleDocsOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class GoogleDocsOAuthController extends Controller
{
    public function redirect(Request $request, GoogleDocsOAuthService $oauth): RedirectResponse
    {
        $validated = $request->validate([
            'account_id' => ['required', 'string', 'max:36'],
        ]);

        try {
            $url = $oauth->authorizationUrl(
                $request->session(),
                $validated['account_id'],
                $request->user()?->id,
            );

            return redirect()->away($url);
        } catch (Throwable $exception) {
            return redirect()
                ->route('settings.google-docs', ['account_id' => $validated['account_id']])
                ->with('error', $exception->getMessage());
        }
    }

    public function callback(Request $request, GoogleDocsOAuthService $oauth): RedirectResponse
    {
        $state = $request->string('state')->toString();
        $accountId = $state !== '' ? $oauth->accountIdForState($request->session(), $state) : null;

        if ($request->filled('error')) {
            if ($state !== '') {
                $accountId = $oauth->cancel($request->session(), $state) ?? $accountId;
            }
            $description = trim($request->string('error_description')->toString());
            $message = $description !== ''
                ? 'Google authorization was not completed: '.$description
                : 'Google authorization was not completed. Click Refresh token to try again.';

            return $this->settingsRedirect($accountId)->with('error', $message);
        }

        $validated = $request->validate([
            'state' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:4096'],
        ]);

        try {
            $result = $oauth->complete(
                $request->session(),
                $validated['state'],
                $validated['code'],
                $request->user()?->id,
            );

            return $this->settingsRedirect($result['account_id'])
                ->with($result['success'] ? 'success' : 'warning', $result['message']);
        } catch (Throwable $exception) {
            return $this->settingsRedirect($accountId)->with('error', $exception->getMessage());
        }
    }

    protected function settingsRedirect(?string $accountId): RedirectResponse
    {
        return redirect()->route(
            'settings.google-docs',
            $accountId !== null && $accountId !== '' ? ['account_id' => $accountId] : [],
        );
    }
}
