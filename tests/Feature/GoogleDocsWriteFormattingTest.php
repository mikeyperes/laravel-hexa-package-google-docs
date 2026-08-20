<?php

namespace HexaPackageSmokeTests\LaravelHexaPackageGoogleDocs;

use hexa_package_google_docs\Services\GoogleDocumentFormattingService;
use Tests\TestCase;

final class GoogleDocsWriteFormattingTest extends TestCase
{
    public function test_it_builds_compact_native_typography_and_real_paragraph_spacing_requests(): void
    {
        $requests = (new GoogleDocumentFormattingService())->buildRequests($this->document([
            $this->paragraph(1, 19, "A compact title\n", 'HEADING_1', 28),
            $this->paragraph(19, 48, "Opening body paragraph.\n", 'NORMAL_TEXT', 14),
            $this->paragraph(48, 66, "A useful section\n", 'HEADING_2', 24),
            $this->paragraph(66, 98, "Second body paragraph.\n", 'NORMAL_TEXT', 14),
        ]));

        $titleNamedStyle = $this->findRequest($requests, 'updateParagraphStyle', 1, 'namedStyleType');
        $this->assertSame('TITLE', data_get($titleNamedStyle, 'updateParagraphStyle.paragraphStyle.namedStyleType'));

        $titleText = $this->findRequest($requests, 'updateTextStyle', 1);
        $this->assertSame(20, data_get($titleText, 'updateTextStyle.textStyle.fontSize.magnitude'));
        $this->assertTrue(data_get($titleText, 'updateTextStyle.textStyle.bold'));

        $titleSpacing = $this->findRequest($requests, 'updateParagraphStyle', 1, 'lineSpacing');
        $this->assertSame(100, data_get($titleSpacing, 'updateParagraphStyle.paragraphStyle.lineSpacing'));

        $bodySpacing = $this->findRequest($requests, 'updateParagraphStyle', 19, 'spaceBelow');
        $this->assertSame(8, data_get($bodySpacing, 'updateParagraphStyle.paragraphStyle.spaceBelow.magnitude'));
        $this->assertSame(115, data_get($bodySpacing, 'updateParagraphStyle.paragraphStyle.lineSpacing'));

        $bodyText = $this->findRequest($requests, 'updateTextStyle', 19);
        $this->assertSame(11, data_get($bodyText, 'updateTextStyle.textStyle.fontSize.magnitude'));

        $headingText = $this->findRequest($requests, 'updateTextStyle', 48);
        $this->assertSame(14, data_get($headingText, 'updateTextStyle.textStyle.fontSize.magnitude'));

        $headingSpacing = $this->findRequest($requests, 'updateParagraphStyle', 48, 'lineSpacing');
        $this->assertSame(100, data_get($headingSpacing, 'updateParagraphStyle.paragraphStyle.lineSpacing'));
    }

    public function test_it_verifies_actual_native_sizes_and_spacing_instead_of_assuming_import_styles(): void
    {
        $service = new GoogleDocumentFormattingService();
        $valid = $service->verify($this->document([
            $this->paragraph(1, 19, "A compact title\n", 'TITLE', 20, 10, 100),
            $this->paragraph(19, 48, "Opening body paragraph.\n", 'NORMAL_TEXT', null, 8, 115),
            $this->paragraph(48, 66, "A useful section\n", 'HEADING_2', 14, 6, 100),
            $this->paragraph(66, 98, "Second body paragraph.\n", 'NORMAL_TEXT', null, 8, 115),
        ]));

        $this->assertTrue($valid['success']);
        $this->assertTrue($valid['formatting_verified']);
        $this->assertSame(20, $valid['title_font_pt']);
        $this->assertSame(8, $valid['body_space_below_pt']);
        $this->assertSame(100, $valid['title_line_spacing_percent']);
        $this->assertSame(100, $valid['heading_2_line_spacing_percent']);
        $this->assertSame(115, $valid['line_spacing_percent']);

        $invalid = $service->verify($this->document([
            $this->paragraph(1, 19, "An oversized title\n", 'HEADING_1', 28, 0, 100),
            $this->paragraph(19, 48, "Collapsed body paragraph.\n", 'NORMAL_TEXT', 14, 0, 100),
        ]));

        $this->assertFalse($invalid['success']);
        $this->assertNotEmpty($invalid['issues']);
        $this->assertStringContainsString('20pt', $invalid['message']);
        $this->assertStringContainsString('space below', $invalid['message']);
    }

    private function document(array $paragraphs): array
    {
        return ['body' => ['content' => $paragraphs]];
    }

    private function paragraph(
        int $start,
        int $end,
        string $text,
        string $namedStyle,
        ?int $fontSize,
        int $spaceBelow = 0,
        int $lineSpacing = 100,
    ): array {
        $textStyle = ['weightedFontFamily' => ['fontFamily' => 'Arial']];
        if ($fontSize !== null) {
            $textStyle['fontSize'] = ['magnitude' => $fontSize, 'unit' => 'PT'];
        }

        return [
            'startIndex' => $start,
            'endIndex' => $end,
            'paragraph' => [
                'paragraphStyle' => [
                    'namedStyleType' => $namedStyle,
                    'spaceBelow' => ['magnitude' => $spaceBelow, 'unit' => 'PT'],
                    'lineSpacing' => $lineSpacing,
                ],
                'elements' => [[
                    'startIndex' => $start,
                    'endIndex' => $end,
                    'textRun' => [
                        'content' => $text,
                        'textStyle' => $textStyle,
                    ],
                ]],
            ],
        ];
    }

    private function findRequest(array $requests, string $type, int $startIndex, ?string $field = null): array
    {
        foreach ($requests as $request) {
            if (!isset($request[$type])) {
                continue;
            }
            if ((int) data_get($request, $type . '.range.startIndex') !== $startIndex) {
                continue;
            }
            if ($field !== null && !str_contains((string) data_get($request, $type . '.fields', ''), $field)) {
                continue;
            }

            return $request;
        }

        $this->fail("No {$type} request starting at {$startIndex} matched the expected field.");
    }
}
