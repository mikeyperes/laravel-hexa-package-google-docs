<?php

namespace HexaPackageSmokeTests\LaravelHexaPackageGoogleDocs;

use hexa_core\Support\PackageAssetRegistry;
use Tests\TestCase;

class FrontendArchitectureTest extends TestCase
{
    public function test_frontend_workflows_are_static_and_allowlisted(): void
    {
        $root = dirname(__DIR__, 2);
        $assets = app(PackageAssetRegistry::class)->assetsFor("google-docs");

        foreach (["settings.js"] as $asset) {
            $this->assertArrayHasKey($asset, $assets);
            $this->assertFileExists($assets[$asset]);
            $content = (string) file_get_contents($assets[$asset]);
            $this->assertDoesNotMatchRegularExpression('/@json|\{\{|\}\}|@(?:if|foreach|php|route)\b/', $content);
        }
    }

    public function test_view_references_external_workflow(): void
    {
        $root = dirname(__DIR__, 2);
        $view = (string) file_get_contents($root . "/resources/views/settings/index.blade.php");

        $this->assertStringContainsString("settings.js", $view);
        $this->assertStringNotContainsString("window.googleDocsSettings = function", $view);
    }

    public function test_oauth_setup_copy_distinguishes_app_files_from_existing_drive_content(): void
    {
        $root = dirname(__DIR__, 2);
        $view = (string) file_get_contents($root . "/resources/views/settings/index.blade.php");

        $this->assertStringContainsString(
            'https://www.googleapis.com/auth/documents https://www.googleapis.com/auth/drive.file',
            $view
        );
        $this->assertStringContainsString(
            'https://www.googleapis.com/auth/documents https://www.googleapis.com/auth/drive',
            $view
        );
        $this->assertStringContainsString('pre-existing or shared folder', $view);
    }
}
