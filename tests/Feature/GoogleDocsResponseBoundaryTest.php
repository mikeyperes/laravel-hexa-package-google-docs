<?php

namespace HexaPackageSmokeTests\LaravelHexaPackageGoogleDocs;

use hexa_core\Services\GenericService;
use hexa_package_google_docs\Services\GoogleDocsService;
use hexa_package_google_docs\Services\GoogleDocsWriteService;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

final class GoogleDocsResponseBoundaryTest extends TestCase
{
    private const DOCUMENT_ID = 'document123456789012345';

    public function test_public_export_reads_a_small_stream_without_changing_the_result_contract(): void
    {
        Http::fake([
            '*' => Http::response('<html><body><p>Small document</p></body></html>', 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
            ]),
        ]);

        $result = $this->service()->fetchHtml(self::DOCUMENT_ID);

        $this->assertTrue($result['success']);
        $this->assertSame('html', $result['format']);
        $this->assertSame('<html><body><p>Small document</p></body></html>', $result['content']);
        $this->assertSame(strlen($result['content']), $result['byte_length']);
    }

    public function test_public_export_stops_at_the_body_cap_and_returns_a_sanitized_failure(): void
    {
        Http::fake([
            '*' => Http::response(str_repeat('x', 65), 200, [
                'Content-Type' => 'text/plain',
            ]),
        ]);

        $result = $this->service()->fetchText(self::DOCUMENT_ID);

        $this->assertFalse($result['success']);
        $this->assertSame(
            'Google Docs export exceeded the safe response limit or could not be read.',
            $result['message']
        );
        $this->assertArrayNotHasKey('content', $result);
        $this->assertStringNotContainsString(str_repeat('x', 20), json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function test_public_export_rejects_an_oversized_content_length_before_body_processing(): void
    {
        Http::fake([
            '*' => Http::response('small', 200, [
                'Content-Type' => 'text/plain',
                'Content-Length' => '65',
            ]),
        ]);

        $result = $this->service()->fetchText(self::DOCUMENT_ID);

        $this->assertFalse($result['success']);
        $this->assertSame(
            'Google Docs export exceeded the safe response limit or could not be read.',
            $result['message']
        );
    }

    public function test_authenticated_fallback_cannot_bypass_the_response_cap(): void
    {
        Http::fake(['*' => Http::response('Forbidden', 403)]);

        $writer = Mockery::mock(GoogleDocsWriteService::class);
        $writer->shouldReceive('exportDocumentContent')
            ->once()
            ->with(self::DOCUMENT_ID, 'html')
            ->andReturn([
                'success' => true,
                'content' => str_repeat('private-content-', 10),
            ]);

        $result = $this->service($writer)->fetchHtml(self::DOCUMENT_ID);

        $this->assertFalse($result['success']);
        $this->assertSame(
            'Google Docs export exceeded the safe response limit or could not be read.',
            $result['message']
        );
        $this->assertArrayNotHasKey('content', $result);
    }

    private function service(?GoogleDocsWriteService $writer = null): ResponseBoundaryGoogleDocsService
    {
        return new ResponseBoundaryGoogleDocsService(
            Mockery::mock(GenericService::class),
            $writer ?? Mockery::mock(GoogleDocsWriteService::class),
        );
    }
}

final class ResponseBoundaryGoogleDocsService extends GoogleDocsService
{
    protected function timeoutSeconds(): int
    {
        return 5;
    }

    protected function maxPreviewChars(): int
    {
        return 200;
    }

    protected function maxResponseBytes(): int
    {
        return 64;
    }

    protected function userAgent(): string
    {
        return 'GoogleDocsResponseBoundaryTest/1.0';
    }
}
