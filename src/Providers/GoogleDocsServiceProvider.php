<?php

namespace hexa_package_google_docs\Providers;

use Illuminate\Support\ServiceProvider;
use hexa_package_google_docs\Services\GoogleDocsService;

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
                'settingsShellClass' => 'max-w-4xl',
                'docsSlug' => 'google-docs',
                'instructions' => [
                    'No API key is required because public Google Docs expose a direct export endpoint for publicly shared documents.',
                    'Beta release: this package is currently read-only and focused on fetching public document content only.',
                    'Paste a public Google Docs URL into the tester to fetch text or HTML exports.',
                ],
                'apiLinks' => [
                    ['label' => 'Google Docs Sharing', 'url' => 'https://support.google.com/docs/answer/2494822'],
                    ['label' => 'Google Docs Export Format', 'url' => 'https://docs.google.com/document/'],
                ],
            ]);
        }

        if (class_exists(\hexa_core\Services\DocumentationService::class)) {
            try {
                $serviceApi = <<<'HTML'
<pre class="bg-gray-900 text-gray-300 text-xs font-mono p-4 rounded-lg whitespace-pre-wrap">use hexa_package_google_docs\Services\GoogleDocsService;
$docs = app(GoogleDocsService::class);

$docs->extractDocumentId($urlOrId);
$docs->normalizeDocumentUrl($urlOrId);
$docs->fetchText($urlOrId);
$docs->fetchHtml($urlOrId);
$docs->fetchDocument($urlOrId, 'txt');
$docs->fetchDocument($urlOrId, 'html');</pre>
HTML;

                app(\hexa_core\Services\DocumentationService::class)->register('google-docs', 'Google Docs', 'hexawebsystems/laravel-hexa-package-google-docs', [
                    [
                        'title' => 'Overview',
                        'content' => 'Beta public-reader package for Google Docs. It reads publicly shared Google Docs through the native export endpoint, so no API key or OAuth setup is required for public documents.',
                    ],
                    [
                        'title' => 'Supported Inputs',
                        'content' => 'Accepts a public Google Docs URL or a raw document ID. This beta release is read-only and currently supports plain-text and HTML exports for public documents only.',
                    ],
                    [
                        'title' => 'GoogleDocsService API',
                        'content' => $serviceApi,
                    ],
                    [
                        'title' => 'Config Keys',
                        'content' => '<pre class="bg-gray-900 text-gray-300 text-xs font-mono p-4 rounded-lg whitespace-pre-wrap break-words">config(\'google-docs.version\')\nconfig(\'google-docs.default_format\')\nconfig(\'google-docs.timeout_seconds\')\nconfig(\'google-docs.user_agent\')\nconfig(\'google-docs.max_preview_chars\')</pre>',
                    ],
                ], 'package');
            } catch (\Throwable $e) {
            }
        }
    }
}
