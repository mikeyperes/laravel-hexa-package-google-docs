<?php

namespace HexaPackageSmokeTests\LaravelHexaPackageGoogleDocs;

use hexa_core\Services\CredentialService;
use hexa_package_google_docs\Services\GoogleDocsService;
use hexa_package_google_docs\Services\GoogleDocsWriteService;
use hexa_package_google_docs\Services\GoogleDocumentWorkflowService;
use hexa_package_google_drive\Services\GoogleDriveApiClient;
use hexa_package_google_drive\Services\GoogleDriveService;
use Mockery;
use Tests\TestCase;

final class GoogleDocumentWorkflowServiceTest extends TestCase
{
    public function test_it_verifies_public_writer_access_and_scans_editorial_content(): void
    {
        $drive = Mockery::mock(GoogleDriveService::class);
        $drive->shouldReceive('resolveFileReference')->once()->andReturn([
            'id' => 'document123456789',
            'resourceKey' => null,
            'url' => 'https://docs.google.com/document/d/document123456789/edit',
        ]);

        $client = Mockery::mock(GoogleDriveApiClient::class);
        $client->shouldReceive('authQueryParams')->twice()->andReturn(['key' => 'fake-api-key']);
        $client->shouldReceive('buildDriveRequestHeaders')->once()->andReturn([]);
        $client->shouldReceive('request')->once()->andReturn([
            'success' => true,
            'data' => [
                'id' => 'document123456789',
                'name' => 'Client article',
                'mimeType' => 'application/vnd.google-apps.document',
                'permissions' => [['type' => 'anyone', 'role' => 'writer']],
            ],
        ]);
        $documents = Mockery::mock(GoogleDocsService::class);
        $documents->shouldReceive('fetchHtml')->once()->andReturn([
            'success' => true,
            'content' => '<h2>Company profile</h2><p><a href="https://example.com/about">About</a></p><img src="https://example.com/featured.jpg" alt="Featured">',
        ]);

        $result = (new GoogleDocumentWorkflowService(
            $drive,
            $client,
            $documents,
            Mockery::mock(GoogleDocsWriteService::class),
        ))->inspectPublicEditable(
            'https://docs.google.com/document/d/document123456789/edit',
            ['require_featured_image' => true, 'require_h2_headings' => true]
        );

        $this->assertTrue($result['success']);
        $this->assertTrue($result['publicly_editable']);
        $this->assertTrue(data_get($result, 'scan.featured_image_found'));
        $this->assertTrue(data_get($result, 'scan.headings_h2_only'));
        $this->assertSame(2, data_get($result, 'scan.url_count'));
    }

    public function test_it_reports_the_exact_public_edit_permission_remedy(): void
    {
        $drive = Mockery::mock(GoogleDriveService::class);
        $drive->shouldReceive('resolveFileReference')->once()->andReturn([
            'id' => 'document123456789',
            'resourceKey' => null,
            'url' => 'https://docs.google.com/document/d/document123456789/edit',
        ]);

        $client = Mockery::mock(GoogleDriveApiClient::class);
        $client->shouldReceive('authQueryParams')->twice()->andReturn(['key' => 'fake-api-key']);
        $client->shouldReceive('buildDriveRequestHeaders')->once()->andReturn([]);
        $client->shouldReceive('request')->once()->andReturn([
            'success' => true,
            'data' => [
                'id' => 'document123456789',
                'name' => 'Client article',
                'mimeType' => 'application/vnd.google-apps.document',
                'permissions' => [['type' => 'anyone', 'role' => 'reader']],
            ],
        ]);

        $result = (new GoogleDocumentWorkflowService(
            $drive,
            $client,
            Mockery::mock(GoogleDocsService::class),
            Mockery::mock(GoogleDocsWriteService::class),
        ))->inspectPublicEditable(
            'https://docs.google.com/document/d/document123456789/edit'
        );

        $this->assertFalse($result['success']);
        $this->assertFalse($result['publicly_editable']);
        $this->assertStringContainsString('Anyone with the link', $result['message']);
        $this->assertStringContainsString('Editor', $result['message']);
    }

    public function test_html_scan_flags_non_h2_headings_and_missing_featured_image(): void
    {
        $service = new GoogleDocumentWorkflowService(
            Mockery::mock(GoogleDriveService::class),
            Mockery::mock(GoogleDriveApiClient::class),
            Mockery::mock(GoogleDocsService::class),
            Mockery::mock(GoogleDocsWriteService::class),
        );

        $scan = $service->scanHtml(
            '<h1>Wrong heading</h1><p>Visit https://example.com/story for details.</p>'
        );

        $this->assertFalse($scan['featured_image_found']);
        $this->assertFalse($scan['headings_h2_only']);
        $this->assertSame(['H1: Wrong heading'], $scan['non_h2_headings']);
        $this->assertSame(['https://example.com/story'], $scan['urls']);
    }

    public function test_non_h2_headings_are_advisory_while_the_word_limit_remains_required(): void
    {
        $drive = Mockery::mock(GoogleDriveService::class);
        $drive->shouldReceive('resolveFileReference')->twice()->andReturn([
            'id' => 'document123456789',
            'resourceKey' => null,
            'url' => 'https://docs.google.com/document/d/document123456789/edit',
        ]);
        $client = Mockery::mock(GoogleDriveApiClient::class);
        $client->shouldReceive('authQueryParams')->times(4)->andReturn(['key' => 'fake-api-key']);
        $client->shouldReceive('buildDriveRequestHeaders')->twice()->andReturn([]);
        $client->shouldReceive('request')->twice()->andReturn([
            'success' => true,
            'data' => [
                'id' => 'document123456789',
                'name' => 'Client article',
                'mimeType' => 'application/vnd.google-apps.document',
                'permissions' => [['type' => 'anyone', 'role' => 'writer']],
            ],
        ]);
        $documents = Mockery::mock(GoogleDocsService::class);
        $documents->shouldReceive('fetchHtml')->twice()->andReturn([
            'success' => true,
            'content' => '<h1>Company profile</h1><p>Three article words.</p>',
        ]);
        $service = new GoogleDocumentWorkflowService(
            $drive,
            $client,
            $documents,
            Mockery::mock(GoogleDocsWriteService::class),
        );

        $advisory = $service->inspectPublicEditable(
            'https://docs.google.com/document/d/document123456789/edit',
            ['require_h2_headings' => true, 'max_word_count' => 1000]
        );
        $blocked = $service->inspectPublicEditable(
            'https://docs.google.com/document/d/document123456789/edit',
            ['require_h2_headings' => true, 'max_word_count' => 2]
        );

        $this->assertTrue($advisory['success']);
        $this->assertNotEmpty($advisory['warnings']);
        $this->assertFalse($blocked['success']);
        $this->assertStringContainsString('fewer than 2 words', $blocked['message']);
    }

    public function test_internal_copy_uses_the_existing_google_docs_reader_and_writer(): void
    {
        $drive = Mockery::mock(GoogleDriveService::class);
        $drive->shouldReceive('resolveFileReference')->twice()->andReturn(
            [
                'id' => 'source123456789012345',
                'resourceKey' => null,
                'url' => 'https://docs.google.com/document/d/source123456789012345/edit',
            ],
            [
                'id' => 'internal1234567890123',
                'resourceKey' => null,
                'url' => 'https://docs.google.com/document/d/internal1234567890123/edit',
            ],
        );

        $client = Mockery::mock(GoogleDriveApiClient::class);
        $client->shouldReceive('authQueryParams')->times(4)->andReturn(['key' => 'fake-api-key']);
        $client->shouldReceive('buildDriveRequestHeaders')->twice()->andReturn([]);
        $client->shouldReceive('request')->twice()->andReturn(
            [
                'success' => true,
                'data' => [
                    'id' => 'source123456789012345',
                    'name' => 'Client article',
                    'mimeType' => 'application/vnd.google-apps.document',
                    'permissions' => [['type' => 'anyone', 'role' => 'writer']],
                ],
            ],
            [
                'success' => true,
                'data' => [
                    'id' => 'internal1234567890123',
                    'name' => 'Internal article',
                    'mimeType' => 'application/vnd.google-apps.document',
                    'permissions' => [['type' => 'anyone', 'role' => 'writer']],
                ],
            ],
        );

        $html = '<h2>Company profile</h2><p><a href="https://example.com/about">About</a></p><img src="https://example.com/featured.png" alt="Featured">';
        $documents = Mockery::mock(GoogleDocsService::class);
        $documents->shouldReceive('fetchHtml')->times(3)->andReturn([
            'success' => true,
            'content' => $html,
        ]);

        $writer = Mockery::mock(GoogleDocsWriteService::class);
        $writer->shouldReceive('createDocumentFromHtml')
            ->once()
            ->with('Internal article', $html, null)
            ->andReturn([
                'success' => true,
                'document_id' => 'internal1234567890123',
                'normalized_url' => 'https://docs.google.com/document/d/internal1234567890123/edit',
            ]);

        $result = (new GoogleDocumentWorkflowService($drive, $client, $documents, $writer))
            ->createPublicEditableCopy(
                'https://docs.google.com/document/d/source123456789012345/edit',
                'Internal article',
                ['require_featured_image' => true, 'require_h2_headings' => true],
            );

        $this->assertTrue($result['success']);
        $this->assertSame('internal1234567890123', data_get($result, 'internal_document.id'));
        $this->assertTrue(data_get($result, 'scan.featured_image_found'));
        $this->assertTrue(data_get($result, 'scan.headings_h2_only'));
    }

    public function test_folder_access_uses_the_active_google_docs_writer(): void
    {
        $writer = new FolderAccessGoogleDocsWriteService(app(CredentialService::class));
        $writer->tokenResult = [
            'success' => true,
            'auth_mode' => 'oauth_user',
            'access_token' => 'test-access-token',
            'connected_email' => 'writer@example.test',
        ];
        $writer->responses = [[
            'success' => true,
            'data' => [
                'id' => 'folder123',
                'name' => 'Client Article Submissions',
                'mimeType' => 'application/vnd.google-apps.folder',
                'webViewLink' => 'https://drive.google.com/drive/folders/folder123',
                'capabilities' => ['canAddChildren' => true, 'canEdit' => true],
            ],
        ]];

        $result = $writer->testFolderAccess('folder123');

        $this->assertTrue($result['success']);
        $this->assertSame('writer@example.test', $result['connected_email']);
        $this->assertSame('Client Article Submissions', $result['folder_name']);
    }

    public function test_folder_access_keeps_valid_oauth_credentials_and_directs_the_user_to_an_app_folder(): void
    {
        $writer = new FolderAccessGoogleDocsWriteService(app(CredentialService::class));
        $writer->tokenResult = [
            'success' => true,
            'auth_mode' => 'oauth_user',
            'access_token' => 'test-access-token',
            'connected_email' => 'writer@example.test',
        ];
        $writer->responses = [
            ['success' => false, 'status' => 404, 'error' => 'File not found: folder123.'],
        ];

        $result = $writer->testFolderAccess('folder123');

        $this->assertFalse($result['success']);
        $this->assertTrue($result['credentials_valid']);
        $this->assertSame('folder_not_available_to_app', $result['reason']);
        $this->assertStringContainsString('credentials are valid', $result['message']);
        $this->assertStringContainsString('Create the shared uploads folder', $result['message']);
        $this->assertStringNotContainsString('Reconnect', $result['message']);
    }

    public function test_folder_access_reports_when_an_existing_folder_needs_the_full_drive_scope(): void
    {
        $writer = new FolderAccessGoogleDocsWriteService(app(CredentialService::class));
        $writer->tokenResult = [
            'success' => true,
            'auth_mode' => 'oauth_user',
            'access_token' => 'test-access-token',
            'connected_email' => 'writer@example.test',
        ];
        $writer->responses = [[
            'success' => false,
            'status' => 403,
            'error' => 'Request had insufficient authentication scopes.',
        ]];

        $result = $writer->testFolderAccess('folder123');

        $this->assertFalse($result['success']);
        $this->assertTrue($result['credentials_valid']);
        $this->assertSame('drive_scope_required', $result['reason']);
        $this->assertStringContainsString('https://www.googleapis.com/auth/documents', $result['message']);
        $this->assertStringContainsString('https://www.googleapis.com/auth/drive', $result['message']);
        $this->assertStringNotContainsString('Create the shared uploads folder', $result['message']);
    }
}

final class FolderAccessGoogleDocsWriteService extends GoogleDocsWriteService
{
    /** @var array<string, mixed> */
    public array $tokenResult = [];

    /** @var array<int, array<string, mixed>> */
    public array $responses = [];

    protected function token(): array
    {
        return $this->tokenResult;
    }

    protected function req(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        bool $raw = false,
    ): array {
        return array_shift($this->responses) ?? ['success' => false, 'error' => 'No fake response queued.'];
    }
}
