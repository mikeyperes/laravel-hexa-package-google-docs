<?php

namespace hexa_package_google_docs\Providers;

use Illuminate\Support\ServiceProvider;
use hexa_package_google_docs\Services\GoogleDocsService;
use hexa_package_google_docs\Services\GoogleDocsWriteService;

class GoogleDocsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/google-docs.php', 'google-docs');

        $this->app->singleton(GoogleDocsService::class, function ($app) {
            return new GoogleDocsService(
                $app->make(\hexa_core\Services\GenericService::class)
            );
        });

        $this->app->singleton(GoogleDocsWriteService::class, function ($app) {
            return new GoogleDocsWriteService(
                $app->make(\hexa_core\Services\CredentialService::class)
            );
        });
    }

    public function boot(): void
    {
        if (!config('google-docs.enabled', true)) {
            return;
        }

        $this->loadRoutesFrom(__DIR__ . '/../../routes/google-docs.php');
        $this->loadViewsFrom(__DIR__ . '/../../resources/views', 'google-docs');
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');

        $this->publishes([
            __DIR__ . '/../../config/google-docs.php' => config_path('google-docs.php'),
        ], 'google-docs-config');

        $registry = app(\hexa_core\Services\PackageRegistryService::class);
        $icon = 'M7 4a2 2 0 00-2 2v12a2 2 0 002 2h6m4-10V8a2 2 0 00-.586-1.414l-3-3A2 2 0 0012.586 3H7m5 1v4h4m-8 5h8m-8 4h8m-8-8h4';

        if (method_exists($registry, 'registerSectionGroup')) {
            $registry->registerSectionGroup('Google Docs', 'Integrations', $icon, 63);
        }

        $registry->registerSidebarLink(
            'settings.google-docs',
            'Settings',
            $icon,
            'Google Docs',
            'google-docs',
            63
        );

        if (method_exists($registry, 'registerPackage')) {
            $registry->registerPackage('google-docs', 'hexawebsystems/laravel-hexa-package-google-docs', [
                'title' => 'Google Docs',
                'color' => 'sky',
                'icon' => $icon,
                'settingsRoute' => 'settings.google-docs',
                'settingsShellClass' => 'max-w-5xl',
                'docsSlug' => 'google-docs',
                'instructions' => [
                    'Use Google Docs read mode to import public Google Docs URLs without OAuth.',
                    'Enable OAuth user write mode or service-account write mode to create, update, and delete Google Docs from Hexa.',
                    'Drive API manages the file lifecycle; Docs API manages the document body content.',
                    'If you need docs to be created under a specific mailbox, verify the connected write identity on the settings screen before exporting documents.',
                ],
                'apiLinks' => [
                    ['label' => 'Google Docs API', 'url' => 'https://developers.google.com/docs/api'],
                    ['label' => 'Google Drive API', 'url' => 'https://developers.google.com/drive/api'],
                    ['label' => 'Google Docs Sharing', 'url' => 'https://support.google.com/docs/answer/2494822'],
                ],
            ]);
        }

        if (class_exists(\hexa_core\Services\DocumentationService::class)) {
            try {
                $readApi = <<<'HTML'
<pre class="bg-gray-900 text-gray-300 text-xs font-mono p-4 rounded-lg whitespace-pre-wrap">use hexa_package_google_docs\Services\GoogleDocsService;
$docs = app(GoogleDocsService::class);

$docs->extractDocumentId($urlOrId);
$docs->normalizeDocumentUrl($urlOrId);
$docs->fetchText($urlOrId);
$docs->fetchHtml($urlOrId);
$docs->fetchDocument($urlOrId, 'txt');
$docs->fetchDocument($urlOrId, 'html');</pre>
HTML;

                $writeApi = <<<'HTML'
<pre class="bg-gray-900 text-gray-300 text-xs font-mono p-4 rounded-lg whitespace-pre-wrap">use hexa_package_google_docs\Services\GoogleDocsWriteService;
$docs = app(GoogleDocsWriteService::class);

$docs->writeContext();
$docs->testWriteConnection();
$docs->createDocumentFromHtml($title, $html);
$docs->updateDocumentFromHtml($documentId, $title, $html);
$docs->deleteDocument($documentId);
$docs->smokeTestWrite();</pre>
HTML;

                app(\hexa_core\Services\DocumentationService::class)->register('google-docs', 'Google Docs', 'hexawebsystems/laravel-hexa-package-google-docs', [
                    [
                        'title' => 'Overview',
                        'content' => 'Shared Google Docs package for public-document imports and authenticated document export. Public read mode uses the native Google Docs export endpoint. Write mode uses Google Drive for file lifecycle and Google Docs for body updates.',
                    ],
                    [
                        'title' => 'Read API',
                        'content' => $readApi,
                    ],
                    [
                        'title' => 'Write API',
                        'content' => $writeApi,
                    ],
                    [
                        'title' => 'Config Keys',
                        'content' => '<pre class="bg-gray-900 text-gray-300 text-xs font-mono p-4 rounded-lg whitespace-pre-wrap break-words">config(\'google-docs.version\')\nconfig(\'google-docs.default_format\')\nconfig(\'google-docs.timeout_seconds\')\nconfig(\'google-docs.user_agent\')\nconfig(\'google-docs.max_preview_chars\')\nconfig(\'google-docs.auth_mode\')\nconfig(\'google-docs.owner_email\')\nconfig(\'google-docs.default_folder_id\')</pre>',
                    ],
                ], 'package');
            } catch (\Throwable $e) {
            }
        }
    }
}
