<?php

namespace HexaPackageSmokeTests\LaravelHexaPackageGoogleDocs;

use Closure;
use hexa_core\Security\Http\OutboundHttpRequest;
use hexa_core\Security\Http\OutboundHttpResponse;
use hexa_core\Security\Http\OutboundUrlGuard;
use hexa_core\Security\Http\SafeOutboundHttpClient;
use hexa_core\Services\CredentialService;
use hexa_package_google_docs\Services\GoogleDocsWriteService;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class GoogleDocsWriteTransportSecurityTest extends TestCase
{
    public function test_private_image_targets_are_rejected_without_transport_dispatch(): void
    {
        $requests = [];
        $service = $this->service($requests, static fn (): OutboundHttpResponse => self::pngResponse());

        foreach ([
            '<p>Story</p><img src="http://127.0.0.1/private.png">',
            '<p>Story</p><img src = http://169.254.169.254/private.png>',
        ] as $html) {
            $result = $service->prepareImages($html);
            $this->assertTrue($result['success']);
            $this->assertSame('<p>Story</p>', $result['html']);
            $this->assertSame([], $result['images']);
        }
        $this->assertSame([], $requests);
        $this->assertStringNotContainsString('169.254.169.254', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function test_image_redirect_to_private_target_is_rejected_before_followup_dispatch(): void
    {
        $requests = [];
        $service = $this->service(
            $requests,
            static fn (): OutboundHttpResponse => new OutboundHttpResponse(
                302,
                ['Location' => 'http://169.254.169.254/latest/meta-data/'],
                '',
            ),
        );

        $result = $service->prepareImages('<p>Before</p><img src="https://cdn.example.net/image.png"><p>After</p>');

        $this->assertTrue($result['success']);
        $this->assertSame('<p>Before</p><p>After</p>', $result['html']);
        $this->assertSame([], $result['images']);
        $this->assertCount(1, $requests);
        $this->assertSame('cdn.example.net', $requests[0]->target->host);
        $this->assertStringNotContainsString('169.254.169.254', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function test_valid_bounded_image_uses_the_revalidated_effective_url(): void
    {
        $requests = [];
        $service = $this->service(
            $requests,
            static function (OutboundHttpRequest $request): OutboundHttpResponse {
                if ($request->target->host === 'cdn.example.net') {
                    return new OutboundHttpResponse(302, ['Location' => 'https://media.example.net/final.png'], '');
                }

                return self::pngResponse();
            },
        );

        $result = $service->prepareImages('<p>Before</p><img src="https://cdn.example.net/image.png" alt="Cover"><p>After</p>');

        $this->assertTrue($result['success']);
        $this->assertCount(2, $requests);
        $this->assertSame('https://media.example.net/final.png', $result['images'][0]['url']);
        $this->assertStringContainsString('HEXA_GOOGLE_DOC_IMAGE_0_', $result['html']);
        $this->assertStringNotContainsString('<img', $result['html']);
        $this->assertSame(4 * 1024 * 1024, $requests[0]->maxResponseBytes);
        $this->assertFalse($requests[0]->curlOptions()[CURLOPT_FOLLOWLOCATION]);
    }

    public function test_per_image_byte_limit_is_enforced(): void
    {
        $requests = [];
        $service = $this->service(
            $requests,
            static fn (): OutboundHttpResponse => self::pngResponse(4 * 1024 * 1024 + 1),
        );

        $result = $service->prepareImages('<img src="https://cdn.example.net/oversized.png">');

        $this->assertFalse($result['success']);
        $this->assertSame('A remote image exceeded the safe per-image size limit.', $result['message']);
        $this->assertCount(1, $requests);
        $this->assertSame(4 * 1024 * 1024, $requests[0]->maxResponseBytes);
    }

    public function test_aggregate_image_byte_limit_is_enforced(): void
    {
        $requests = [];
        $service = $this->service(
            $requests,
            static fn (): OutboundHttpResponse => self::pngResponse(4 * 1024 * 1024),
        );
        $html = implode('', array_map(
            static fn (int $index): string => '<img src="https://cdn.example.net/image-'.$index.'.png">',
            range(1, 5),
        ));

        $result = $service->prepareImages($html);

        $this->assertFalse($result['success']);
        $this->assertSame('Remote images exceeded the safe aggregate size limit.', $result['message']);
        $this->assertCount(5, $requests);
    }

    public function test_excessive_image_count_is_rejected_before_any_fetch(): void
    {
        $requests = [];
        $service = $this->service($requests, static fn (): OutboundHttpResponse => self::pngResponse());
        $html = implode('', array_map(
            static fn (int $index): string => '<img src="https://cdn.example.net/image-'.$index.'.png">',
            range(1, 13),
        ));

        $result = $service->prepareImages($html);

        $this->assertFalse($result['success']);
        $this->assertSame('Google Doc export supports at most 12 remote images.', $result['message']);
        $this->assertSame([], $requests);
    }

    public function test_image_pixel_limit_is_enforced_from_the_file_signature(): void
    {
        $requests = [];
        $gif = 'GIF89a'.pack('v', 65535).pack('v', 65535)."\x80\x00\x00\x00\x00\x00\xff\xff\xff";
        $service = $this->service(
            $requests,
            static fn (): OutboundHttpResponse => new OutboundHttpResponse(200, ['Content-Type' => 'image/gif'], $gif),
        );

        $result = $service->prepareImages('<img src="https://cdn.example.net/huge.gif">');

        $this->assertFalse($result['success']);
        $this->assertSame('A remote image exceeded the safe pixel limit.', $result['message']);
    }

    public function test_image_validation_stops_when_the_total_deadline_is_exhausted(): void
    {
        $requests = [];
        $service = $this->service($requests, static fn (): OutboundHttpResponse => self::pngResponse());
        $service->times = [0.0, 31.0];

        $result = $service->prepareImages('<img src="https://cdn.example.net/image.png">');

        $this->assertFalse($result['success']);
        $this->assertSame('Remote image validation exceeded its total deadline.', $result['message']);
        $this->assertSame([], $requests);
    }

    public function test_authenticated_google_requests_are_not_followed_and_credentials_do_not_cross_origins(): void
    {
        $requests = [];
        $service = $this->service(
            $requests,
            static fn (): OutboundHttpResponse => new OutboundHttpResponse(
                302,
                ['Location' => 'https://attacker.example.net/capture'],
                '',
            ),
        );

        $redirect = $service->requestGoogle(
            'https://docs.googleapis.com/v1/documents/document123',
            ['Authorization: Bearer secret-token'],
        );
        $unapproved = $service->requestGoogle(
            'https://attacker.example.net/capture',
            ['Authorization: Bearer secret-token'],
        );

        $this->assertCount(1, $requests);
        $this->assertSame('Bearer secret-token', $requests[0]->headers['Authorization']);
        $this->assertFalse($requests[0]->curlOptions()[CURLOPT_FOLLOWLOCATION]);
        $this->assertFalse($redirect['success']);
        $this->assertFalse($unapproved['success']);
        $this->assertStringNotContainsString('secret-token', json_encode([$redirect, $unapproved], JSON_THROW_ON_ERROR));
    }

    public function test_google_api_responses_and_transport_failures_are_bounded_and_sanitized(): void
    {
        $oversizedRequests = [];
        $oversized = $this->service(
            $oversizedRequests,
            static fn (): OutboundHttpResponse => new OutboundHttpResponse(200, [], str_repeat('x', 8 * 1024 * 1024 + 1)),
        );

        $oversizedResult = $oversized->requestGoogle('https://www.googleapis.com/drive/v3/files');

        $this->assertFalse($oversizedResult['success']);
        $this->assertSame('Google API response exceeded the safe size limit.', $oversizedResult['error']);
        $this->assertSame(8 * 1024 * 1024, $oversizedRequests[0]->maxResponseBytes);

        $failedRequests = [];
        $failed = $this->service(
            $failedRequests,
            static fn (): never => throw new RuntimeException('token=raw-transport-secret'),
        );

        $failedResult = $failed->requestGoogle('https://www.googleapis.com/drive/v3/files');

        $this->assertFalse($failedResult['success']);
        $this->assertSame('Google API request could not be completed.', $failedResult['error']);
        $this->assertStringNotContainsString('raw-transport-secret', json_encode($failedResult, JSON_THROW_ON_ERROR));
    }

    public function test_raw_drive_responses_do_not_request_json_and_allow_empty_success_bodies(): void
    {
        $requests = [];
        $service = $this->service(
            $requests,
            static fn (): OutboundHttpResponse => new OutboundHttpResponse(204, [], ''),
        );

        $result = $service->requestGoogleRaw(
            'https://www.googleapis.com/drive/v3/files/document123',
            ['Authorization: Bearer secret-token'],
        );

        $this->assertTrue($result['success']);
        $this->assertSame('', $result['data']);
        $this->assertSame(12 * 1024 * 1024, $requests[0]->maxResponseBytes);
        $this->assertArrayNotHasKey('Accept', $requests[0]->headers);
        $this->assertSame('Bearer secret-token', $requests[0]->headers['Authorization']);
    }

    public function test_google_http_error_bodies_are_not_returned_to_callers(): void
    {
        $requests = [];
        $service = $this->service(
            $requests,
            static fn (): OutboundHttpResponse => new OutboundHttpResponse(
                403,
                ['Content-Type' => 'application/json'],
                '{"error":{"message":"credential=raw-google-secret"}}',
            ),
        );

        $result = $service->requestGoogle('https://www.googleapis.com/drive/v3/files');

        $this->assertFalse($result['success']);
        $this->assertSame('Google API denied the request.', $result['error']);
        $this->assertStringNotContainsString('raw-google-secret', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function test_html_import_uses_one_megabyte_or_smaller_resumable_chunks(): void
    {
        $requests = [];
        $putCount = 0;
        $service = $this->service(
            $requests,
            static function (OutboundHttpRequest $request) use (&$putCount): OutboundHttpResponse {
                if ($request->method === 'POST') {
                    return new OutboundHttpResponse(
                        200,
                        ['Location' => 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&upload_id=safe'],
                        '',
                    );
                }

                $putCount++;
                preg_match('/bytes (\d+)-(\d+)\/(\d+)/', $request->headers['Content-Range'], $range);
                if ((int) $range[2] + 1 < (int) $range[3]) {
                    return new OutboundHttpResponse(308, ['Range' => 'bytes=0-'.$range[2]], '');
                }

                return new OutboundHttpResponse(200, ['Content-Type' => 'application/json'], '{"id":"document123456789012345"}');
            },
        );
        $html = '<p>'.str_repeat('x', 2 * 1024 * 1024 + 100).'</p>';

        $result = $service->importHtml($html);

        $this->assertTrue($result['success']);
        $this->assertSame('document123456789012345', $result['document_id']);
        $this->assertSame(3, $putCount);
        $uploadRequests = array_values(array_filter(
            $requests,
            static fn (OutboundHttpRequest $request): bool => $request->method === 'PUT',
        ));
        foreach ($uploadRequests as $request) {
            $this->assertLessThanOrEqual(1024 * 1024, strlen((string) $request->body));
            $this->assertSame('Bearer test-token', $request->headers['Authorization']);
        }
    }

    public function test_html_import_stops_when_its_total_deadline_is_exhausted(): void
    {
        $requests = [];
        $service = $this->service(
            $requests,
            static fn (): OutboundHttpResponse => new OutboundHttpResponse(
                200,
                ['Location' => 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&upload_id=safe'],
                '',
            ),
        );
        $service->times = [0.0, 0.0, 60.0];

        $result = $service->importHtml('<p>Safe article</p>');

        $this->assertFalse($result['success']);
        $this->assertSame('Google Doc HTML upload exceeded its total deadline.', $result['message']);
        $this->assertCount(1, $requests);
    }

    public function test_unapproved_resumable_destination_never_receives_authorization(): void
    {
        $requests = [];
        $service = $this->service(
            $requests,
            static fn (): OutboundHttpResponse => new OutboundHttpResponse(
                200,
                ['Location' => 'https://attacker.example.net/upload'],
                '',
            ),
        );

        $result = $service->importHtml('<p>Safe article</p>');

        $this->assertFalse($result['success']);
        $this->assertSame('Google Drive returned an invalid resumable upload destination.', $result['message']);
        $this->assertCount(1, $requests);
        $this->assertSame('www.googleapis.com', $requests[0]->target->host);
    }

    public function test_writer_source_contains_no_raw_curl_or_multipart_upload_fallback(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/src/Services/GoogleDocsWriteService.php');

        $this->assertStringNotContainsString('curl_', $source);
        $this->assertStringNotContainsString('uploadType=multipart', $source);
        $this->assertStringContainsString('SafeOutboundHttpClient', $source);
    }

    /** @param list<OutboundHttpRequest> $requests */
    private function service(array &$requests, Closure $transport): TransportSecurityGoogleDocsWriteService
    {
        $guard = new OutboundUrlGuard(static fn (string $host): array => ['93.184.216.34']);
        $client = new SafeOutboundHttpClient(
            $guard,
            static function (OutboundHttpRequest $request) use (&$requests, $transport): OutboundHttpResponse {
                $requests[] = $request;

                return $transport($request);
            },
        );

        return new TransportSecurityGoogleDocsWriteService(
            Mockery::mock(CredentialService::class),
            http: $client,
        );
    }

    private static function pngResponse(?int $bytes = null): OutboundHttpResponse
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
        self::assertIsString($png);
        if ($bytes !== null && $bytes > strlen($png)) {
            $png .= str_repeat("\0", $bytes - strlen($png));
        }

        return new OutboundHttpResponse(200, ['Content-Type' => 'image/png'], $png);
    }
}

final class TransportSecurityGoogleDocsWriteService extends GoogleDocsWriteService
{
    /** @var list<float> */
    public array $times = [];

    public function prepareImages(string $html): array
    {
        return $this->prepareInlineImageMarkers($html);
    }

    public function requestGoogle(string $url, array $headers = []): array
    {
        return $this->req('GET', $url, $headers);
    }

    public function requestGoogleRaw(string $url, array $headers = []): array
    {
        return $this->req('DELETE', $url, $headers, raw: true);
    }

    public function importHtml(string $html): array
    {
        return $this->importHtmlDocument('Safe title', $html, 'test-token');
    }

    protected function monotonicTime(): float
    {
        return $this->times !== [] ? (float) array_shift($this->times) : parent::monotonicTime();
    }
}
