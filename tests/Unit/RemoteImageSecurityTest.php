<?php

namespace hexa_package_google_docs\Tests\Unit;

use Closure;
use hexa_core\Security\Http\OutboundHttpException;
use hexa_core\Security\Http\OutboundHttpRequest;
use hexa_core\Security\Http\OutboundHttpResponse;
use hexa_core\Security\Http\OutboundUrlGuard;
use hexa_core\Security\Http\SafeOutboundHttpClient;
use hexa_core\Services\CredentialService;
use hexa_package_google_docs\Services\GoogleDocsWriteService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RemoteImageSecurityTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=';

    public static function unsafeUrls(): array
    {
        return array_map(static fn (string $url): array => [$url], [
            'http://127.0.0.1/image.png',
            'http://[::1]/image.png',
            'http://169.254.169.254/image.png',
            'http://10.0.0.1/image.png',
            'https://private.example.net/image.png',
            'https://user:secret@images.example.net/image.png',
            'file:///etc/passwd',
            'ftp://images.example.net/image.png',
        ]);
    }

    #[DataProvider('unsafeUrls')]
    public function test_unsafe_images_are_never_imported_or_dispatched(string $url): void
    {
        $calls = 0;
        $writer = $this->writer(function () use (&$calls): OutboundHttpResponse {
            $calls++;

            return $this->imageResponse();
        });

        $result = $writer->prepare('<img src="'.htmlspecialchars($url, ENT_QUOTES).'">');

        $this->assertTrue($result['success']);
        $this->assertSame(0, $calls);
        $this->assertSame([], $result['images'] ?? []);
        $this->assertStringNotContainsString('HEXA_GOOGLE_DOC_IMAGE_', (string) ($result['html'] ?? ''));
        $this->assertStringNotContainsString('<img', (string) ($result['html'] ?? ''));
        $this->assertStringNotContainsString($url, html_entity_decode((string) ($result['html'] ?? ''), ENT_QUOTES | ENT_HTML5));
    }

    public function test_valid_public_image_uses_the_shared_bounded_pinned_transport(): void
    {
        $writer = $this->writer(function (OutboundHttpRequest $request): OutboundHttpResponse {
            $this->assertSame('GET', $request->method);
            $this->assertSame(['93.184.216.34'], $request->target->addresses);
            $this->assertSame(4 * 1024 * 1024, $request->maxResponseBytes);
            $this->assertSame(20, $request->timeoutSeconds);
            $this->assertFalse($request->curlOptions()[CURLOPT_FOLLOWLOCATION]);

            return $this->imageResponse();
        });

        $result = $writer->prepare('<img src="https://images.example.net/image.png" alt="Photo">');

        $this->assertTrue($result['success']);
        $this->assertSame('https://images.example.net/image.png', $result['images'][0]['url']);
        $this->assertSame('Photo', $result['images'][0]['alt']);
        $this->assertStringContainsString('HEXA_GOOGLE_DOC_IMAGE_0_', $result['html']);
    }

    public function test_public_to_private_redirect_stops_before_the_second_request(): void
    {
        $calls = 0;
        $writer = $this->writer(function () use (&$calls): OutboundHttpResponse {
            $calls++;

            return new OutboundHttpResponse(302, ['Location' => 'http://169.254.169.254/image.png'], '');
        });

        $result = $writer->prepare('<p>Before</p><img src="https://images.example.net/image.png"><p>After</p>');

        $this->assertTrue($result['success']);
        $this->assertSame('<p>Before</p><p>After</p>', $result['html']);
        $this->assertSame([], $result['images']);
        $this->assertSame(1, $calls);
    }

    public function test_public_redirects_are_revalidated_and_the_effective_url_is_imported(): void
    {
        $hosts = [];
        $writer = $this->writer(function (OutboundHttpRequest $request) use (&$hosts): OutboundHttpResponse {
            $hosts[] = $request->target->host;

            return count($hosts) === 1
                ? new OutboundHttpResponse(302, ['Location' => 'https://cdn.example.net/image.png'], '')
                : $this->imageResponse();
        });

        $result = $writer->prepare('<p>Photo</p><img src="https://images.example.net/image.png" alt="Photo">');

        $this->assertTrue($result['success']);
        $this->assertSame(['images.example.net', 'cdn.example.net'], $hosts);
        $this->assertSame('https://cdn.example.net/image.png', $result['images'][0]['url']);
        $this->assertStringContainsString('HEXA_GOOGLE_DOC_IMAGE_0_', $result['html']);
        $this->assertStringNotContainsString('<img', $result['html']);
    }

    public function test_redirect_count_is_bounded(): void
    {
        $calls = 0;
        $writer = $this->writer(function () use (&$calls): OutboundHttpResponse {
            $calls++;

            return new OutboundHttpResponse(302, ['Location' => 'https://images.example.net/'.$calls.'.png'], '');
        });

        $result = $writer->prepare('<p>Before</p><img src="https://images.example.net/start.png"><p>After</p>');

        $this->assertTrue($result['success']);
        $this->assertSame('<p>Before</p><p>After</p>', $result['html']);
        $this->assertSame([], $result['images']);
        $this->assertSame(4, $calls);
    }

    public function test_oversized_image_is_rejected_by_the_shared_response_limit(): void
    {
        $writer = $this->writer(static fn (): OutboundHttpResponse => new OutboundHttpResponse(
            200,
            ['Content-Type' => 'image/png'],
            str_repeat('x', 4 * 1024 * 1024 + 1),
        ));

        $result = $writer->prepare('<img src="https://images.example.net/large.png">');

        $this->assertFalse($result['success']);
        $this->assertSame('A remote image exceeded the safe per-image size limit.', $result['message']);
    }

    public function test_transport_error_omits_only_the_unavailable_image_and_preserves_text(): void
    {
        $writer = $this->writer(static fn () => throw new OutboundHttpException('transport_failed'));

        $result = $writer->prepare('<p>Before</p><img src="https://images.example.net/missing.png" alt="Missing"><p>After</p>');

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['images']);
        $this->assertSame('<p>Before</p><p>After</p>', $result['html']);
    }

    public function test_non_image_unsuccessful_and_mismatched_media_responses_are_rejected(): void
    {
        foreach ([
            new OutboundHttpResponse(200, ['Content-Type' => 'text/html'], '<html>Error</html>'),
            new OutboundHttpResponse(404, ['Content-Type' => 'image/png'], base64_decode(self::PNG, true)),
            new OutboundHttpResponse(200, ['Content-Type' => 'image/png'], ''),
            new OutboundHttpResponse(200, ['Content-Type' => 'image/svg+xml'], '<svg/>'),
        ] as $response) {
            $result = $this->writer(static fn () => $response)
                ->prepare('<img src="https://images.example.net/image">');

            $this->assertFalse($result['success']);
        }
    }

    public function test_generic_octet_stream_is_accepted_only_when_magic_bytes_are_an_allowed_raster_image(): void
    {
        $valid = $this->writer(static fn () => new OutboundHttpResponse(
            200,
            ['Content-Type' => 'application/octet-stream'],
            base64_decode(self::PNG, true),
        ))->prepare('<img src="https://images.example.net/image">');

        $this->assertTrue($valid['success']);
        $this->assertSame('https://images.example.net/image', $valid['images'][0]['url']);

        $invalid = $this->writer(static fn () => new OutboundHttpResponse(
            200,
            ['Content-Type' => 'application/octet-stream'],
            'not-an-image',
        ))->prepare('<img src="https://images.example.net/not-an-image">');

        $this->assertFalse($invalid['success']);
        $this->assertSame('A remote image failed media-type validation.', $invalid['message']);
    }

    private function imageResponse(): OutboundHttpResponse
    {
        return new OutboundHttpResponse(
            200,
            ['Content-Type' => 'image/png; charset=binary'],
            base64_decode(self::PNG, true),
        );
    }

    private function writer(Closure $transport): MarkerImageGoogleDocsWriter
    {
        $guard = new OutboundUrlGuard(static fn (string $host): array => [
            $host === 'private.example.net' ? '10.0.0.1' : '93.184.216.34',
        ]);

        return new MarkerImageGoogleDocsWriter(
            $this->createMock(CredentialService::class),
            null,
            new SafeOutboundHttpClient($guard, $transport),
        );
    }
}

final class MarkerImageGoogleDocsWriter extends GoogleDocsWriteService
{
    public function prepare(string $html): array
    {
        return $this->prepareInlineImageMarkers($html);
    }
}
