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
    public function test_unsafe_images_are_rejected_before_transport(string $url): void
    {
        $calls = 0;
        $writer = $this->writer(function () use (&$calls): OutboundHttpResponse {
            $calls++;

            return $this->imageResponse();
        });

        $this->assertNull($writer->image($url));
        $this->assertSame(0, $calls);
    }

    public function test_valid_public_image_uses_the_shared_bounded_pinned_transport(): void
    {
        $writer = $this->writer(function (OutboundHttpRequest $request): OutboundHttpResponse {
            $this->assertSame('GET', $request->method);
            $this->assertSame(['93.184.216.34'], $request->target->addresses);
            $this->assertSame(8 * 1024 * 1024, $request->maxResponseBytes);
            $this->assertSame(20, $request->timeoutSeconds);

            return $this->imageResponse();
        });

        $this->assertSame(
            ['mime' => 'image/png', 'body' => base64_decode(self::PNG)],
            $writer->image('https://images.example.net/image.png'),
        );
    }

    public function test_public_to_private_redirect_stops_before_the_second_request(): void
    {
        $calls = 0;
        $writer = $this->writer(function () use (&$calls): OutboundHttpResponse {
            $calls++;

            return new OutboundHttpResponse(302, ['Location' => 'http://169.254.169.254/image.png'], '');
        });

        $this->assertNull($writer->image('https://images.example.net/image.png'));
        $this->assertSame(1, $calls);
    }

    public function test_public_redirects_are_revalidated_and_imported(): void
    {
        $hosts = [];
        $writer = $this->writer(function (OutboundHttpRequest $request) use (&$hosts): OutboundHttpResponse {
            $hosts[] = $request->target->host;

            return count($hosts) === 1
                ? new OutboundHttpResponse(302, ['Location' => 'https://cdn.example.net/image.png'], '')
                : $this->imageResponse();
        });

        $this->assertSame(
            '<p>Photo</p><img src="data:image/png;base64,'.self::PNG.'" alt="Photo">',
            $writer->embed('<p>Photo</p><img src="https://images.example.net/image.png" alt="Photo">'),
        );
        $this->assertSame(['images.example.net', 'cdn.example.net'], $hosts);
    }

    public function test_redirect_count_is_bounded(): void
    {
        $calls = 0;
        $writer = $this->writer(function () use (&$calls): OutboundHttpResponse {
            $calls++;

            return new OutboundHttpResponse(302, ['Location' => 'https://images.example.net/'.$calls.'.png'], '');
        });

        $this->assertNull($writer->image('https://images.example.net/start.png'));
        $this->assertSame(6, $calls);
    }

    public function test_oversized_image_is_rejected_by_the_shared_response_limit(): void
    {
        $writer = $this->writer(static fn (): OutboundHttpResponse => new OutboundHttpResponse(
            200,
            ['Content-Type' => 'image/png'],
            str_repeat('x', 8 * 1024 * 1024 + 1),
        ));

        $this->assertNull($writer->image('https://images.example.net/large.png'));
    }

    public function test_transport_error_preserves_the_optional_image_fallback(): void
    {
        $writer = $this->writer(static fn () => throw new OutboundHttpException('transport_failed'));
        $html = '<img src="https://images.example.net/missing.png">';

        $this->assertSame($html, $writer->embed($html));
    }

    public function test_non_image_and_unsuccessful_responses_are_rejected(): void
    {
        foreach ([
            new OutboundHttpResponse(200, ['Content-Type' => 'text/html'], '<html>Error</html>'),
            new OutboundHttpResponse(404, ['Content-Type' => 'image/png'], base64_decode(self::PNG)),
            new OutboundHttpResponse(200, ['Content-Type' => 'image/png'], ''),
            new OutboundHttpResponse(200, ['Content-Type' => 'image/svg+xml'], '<svg/>'),
        ] as $response) {
            $this->assertNull($this->writer(static fn () => $response)->image('https://images.example.net/image'));
        }
    }

    public function test_generic_content_type_uses_image_detection(): void
    {
        $writer = $this->writer(static fn () => new OutboundHttpResponse(
            200,
            ['Content-Type' => 'application/octet-stream'],
            base64_decode(self::PNG),
        ));

        $this->assertSame('image/png', $writer->image('https://images.example.net/image')['mime']);
    }

    private function imageResponse(): OutboundHttpResponse
    {
        return new OutboundHttpResponse(200, ['Content-Type' => 'image/png; charset=binary'], base64_decode(self::PNG));
    }

    private function writer(Closure $transport): SafeImageGoogleDocsWriter
    {
        $guard = new OutboundUrlGuard(static fn (string $host): array => [
            $host === 'private.example.net' ? '10.0.0.1' : '93.184.216.34',
        ]);

        return new SafeImageGoogleDocsWriter(
            $this->createMock(CredentialService::class),
            null,
            new SafeOutboundHttpClient($guard, $transport),
        );
    }
}

final class SafeImageGoogleDocsWriter extends GoogleDocsWriteService
{
    public function image(string $url): ?array
    {
        return $this->fetchRemoteImage($url);
    }

    public function embed(string $html): string
    {
        return $this->embedRemoteImagesForImport($html);
    }
}
