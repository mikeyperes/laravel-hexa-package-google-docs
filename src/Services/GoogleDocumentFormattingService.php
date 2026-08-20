<?php

namespace hexa_package_google_docs\Services;

class GoogleDocumentFormattingService
{
    /**
     * Build explicit Google Docs API requests so HTML-import defaults cannot
     * silently inflate headings or collapse paragraph spacing.
     *
     * @return array<int,array<string,mixed>>
     */
    public function buildRequests(array $document): array
    {
        $requests = [];
        $titleAssigned = false;

        foreach ($this->paragraphs($document) as $paragraph) {
            $start = (int) $paragraph['start'];
            $end = (int) $paragraph['end'];
            $textEnd = (int) $paragraph['text_end'];
            $text = (string) $paragraph['text'];
            $namedStyle = (string) $paragraph['named_style'];

            if (trim($text) === '') {
                $requests[] = $this->paragraphSpacingRequest($start, $end, 4, 6, 100, false);
                continue;
            }

            if (!$titleAssigned) {
                $titleAssigned = true;
                $requests[] = $this->namedParagraphStyleRequest($start, $end, 'TITLE');
                $requests[] = $this->paragraphSpacingRequest($start, $end, 0, 10, 100, true);
                $requests[] = $this->textStyleRequest($start, $textEnd, 20, true);
                continue;
            }

            if (in_array($namedStyle, ['HEADING_1', 'HEADING_2'], true)) {
                $requests[] = $this->namedParagraphStyleRequest($start, $end, 'HEADING_2');
                $requests[] = $this->paragraphSpacingRequest($start, $end, 14, 6, 100, true);
                $requests[] = $this->textStyleRequest($start, $textEnd, 14, true);
                continue;
            }

            if ($namedStyle === 'HEADING_3') {
                $requests[] = $this->namedParagraphStyleRequest($start, $end, 'HEADING_3');
                $requests[] = $this->paragraphSpacingRequest($start, $end, 12, 5, 100, true);
                $requests[] = $this->textStyleRequest($start, $textEnd, 12, true);
                continue;
            }

            if (in_array($namedStyle, ['HEADING_4', 'HEADING_5', 'HEADING_6'], true)) {
                $requests[] = $this->namedParagraphStyleRequest($start, $end, 'HEADING_4');
                $requests[] = $this->paragraphSpacingRequest($start, $end, 10, 4, 100, true);
                $requests[] = $this->textStyleRequest($start, $textEnd, 11, true);
                continue;
            }

            $requests[] = $this->paragraphSpacingRequest($start, $end, 0, 8, 115, false);
            $requests[] = $this->textStyleRequest($start, $textEnd, 11);
        }

        return $titleAssigned ? $requests : [];
    }

    public function verify(array $document): array
    {
        $issues = [];
        $counts = ['title' => 0, 'heading' => 0, 'body' => 0];
        $titleAssigned = false;
        $namedStyleFontSizes = [];

        foreach ((array) data_get($document, 'namedStyles.styles', []) as $namedStyle) {
            $type = (string) ($namedStyle['namedStyleType'] ?? '');
            $size = (float) data_get($namedStyle, 'textStyle.fontSize.magnitude', 0);
            if ($type !== '' && $size > 0) {
                $namedStyleFontSizes[$type] = $size;
            }
        }

        foreach ($this->paragraphs($document) as $paragraph) {
            if (trim((string) $paragraph['text']) === '') {
                continue;
            }

            $style = (array) data_get($paragraph, 'paragraph.paragraphStyle', []);
            $namedStyle = (string) ($style['namedStyleType'] ?? 'NORMAL_TEXT');
            $textStyles = [];
            foreach ((array) data_get($paragraph, 'paragraph.elements', []) as $element) {
                if (trim((string) data_get($element, 'textRun.content', '')) === '') {
                    continue;
                }
                $textStyles[] = (array) data_get($element, 'textRun.textStyle', []);
            }
            $fontSizes = array_values(array_filter(array_map(
                static fn (array $textStyle): float => (float) data_get($textStyle, 'fontSize.magnitude', 0),
                $textStyles
            ), static fn (float $size): bool => $size > 0));
            if ($fontSizes === [] && isset($namedStyleFontSizes[$namedStyle])) {
                $fontSizes[] = $namedStyleFontSizes[$namedStyle];
            }
            if ($fontSizes === [] && $namedStyle === 'NORMAL_TEXT') {
                // Docs omits a run-level size when it equals the native 11pt
                // Normal text default, even after an explicit style update.
                $fontSizes[] = 11;
            }

            $minimumFont = $fontSizes === [] ? 0 : min($fontSizes);
            $maximumFont = $fontSizes === [] ? 0 : max($fontSizes);
            $spaceBelow = (float) data_get($style, 'spaceBelow.magnitude', 0);
            $lineSpacing = (float) ($style['lineSpacing'] ?? 0);

            if (!$titleAssigned) {
                $titleAssigned = true;
                $counts['title']++;
                if ($namedStyle !== 'TITLE') $issues[] = 'Document title is not using the native TITLE style.';
                if ($minimumFont < 19.5 || $maximumFont > 20.5) $issues[] = 'Document title is not 20pt.';
                if ($spaceBelow < 9.5) $issues[] = 'Document title spacing is too tight.';
                if ($lineSpacing < 99.5 || $lineSpacing > 100.5) $issues[] = 'Document title is not using 100% line spacing.';
                continue;
            }

            if (str_starts_with($namedStyle, 'HEADING_')) {
                $counts['heading']++;
                $expected = $namedStyle === 'HEADING_2' ? 14 : ($namedStyle === 'HEADING_3' ? 12 : 11);
                if ($minimumFont < ($expected - 0.5) || $maximumFont > ($expected + 0.5)) $issues[] = 'A document heading has the wrong font size.';
                if ($spaceBelow < 3.5) $issues[] = 'A document heading is missing native space below.';
                if ($lineSpacing < 99.5 || $lineSpacing > 100.5) $issues[] = 'A document heading is not using 100% line spacing.';
                continue;
            }

            $counts['body']++;
            if ($minimumFont < 10.5 || $maximumFont > 11.5) $issues[] = 'A body paragraph is not 11pt.';
            if ($spaceBelow < 7.5) $issues[] = 'A body paragraph is missing native space below.';
            if ($lineSpacing < 114.5 || $lineSpacing > 115.5) $issues[] = 'A body paragraph is not using 115% line spacing.';
        }

        if ($counts['title'] !== 1) $issues[] = 'Exactly one formatted document title is required.';
        if ($counts['body'] < 1) $issues[] = 'At least one formatted body paragraph is required.';
        $issues = array_values(array_unique($issues));

        return [
            'success' => $issues === [],
            'message' => $issues === [] ? 'Google Doc native typography and paragraph spacing verified.' : implode(' ', $issues),
            'formatting_verified' => $issues === [],
            'title_font_pt' => 20,
            'heading_2_font_pt' => 14,
            'body_font_pt' => 11,
            'body_space_below_pt' => 8,
            'title_line_spacing_percent' => 100,
            'heading_2_line_spacing_percent' => 100,
            'line_spacing_percent' => 115,
            'paragraph_counts' => $counts,
            'issues' => $issues,
        ];
    }

    /**
     * @return array<int,array{start:int,end:int,text_end:int,text:string,named_style:string,paragraph:array<string,mixed>}>
     */
    private function paragraphs(array $document): array
    {
        $paragraphs = [];

        foreach ((array) data_get($document, 'body.content', []) as $block) {
            if (!is_array($block) || !is_array($block['paragraph'] ?? null)) {
                continue;
            }

            $elements = (array) data_get($block, 'paragraph.elements', []);
            $text = '';
            foreach ($elements as $element) {
                $text .= (string) data_get($element, 'textRun.content', '');
            }

            $start = (int) ($block['startIndex'] ?? ($elements[0]['startIndex'] ?? 0));
            $end = (int) ($block['endIndex'] ?? 0);
            if ($end <= $start && $elements !== []) {
                $last = end($elements);
                $end = (int) ($last['endIndex'] ?? $start);
            }
            if ($start < 1 || $end <= $start) {
                continue;
            }

            $textEnd = str_ends_with($text, "\n") ? max($start, $end - 1) : $end;
            $paragraphs[] = [
                'start' => $start,
                'end' => $end,
                'text_end' => $textEnd,
                'text' => $text,
                'named_style' => (string) data_get($block, 'paragraph.paragraphStyle.namedStyleType', 'NORMAL_TEXT'),
                'paragraph' => (array) $block['paragraph'],
            ];
        }

        return $paragraphs;
    }

    private function namedParagraphStyleRequest(int $start, int $end, string $namedStyle): array
    {
        return [
            'updateParagraphStyle' => [
                'range' => ['startIndex' => $start, 'endIndex' => $end],
                'paragraphStyle' => ['namedStyleType' => $namedStyle],
                'fields' => 'namedStyleType',
            ],
        ];
    }

    private function paragraphSpacingRequest(int $start, int $end, int $above, int $below, int $lineSpacing, bool $keepWithNext): array
    {
        return [
            'updateParagraphStyle' => [
                'range' => ['startIndex' => $start, 'endIndex' => $end],
                'paragraphStyle' => [
                    'spaceAbove' => ['magnitude' => $above, 'unit' => 'PT'],
                    'spaceBelow' => ['magnitude' => $below, 'unit' => 'PT'],
                    'lineSpacing' => $lineSpacing,
                    'keepWithNext' => $keepWithNext,
                ],
                'fields' => 'spaceAbove,spaceBelow,lineSpacing,keepWithNext',
            ],
        ];
    }

    private function textStyleRequest(int $start, int $end, int $fontSize, ?bool $bold = null): array
    {
        $style = [
            'weightedFontFamily' => ['fontFamily' => 'Arial'],
            'fontSize' => ['magnitude' => $fontSize, 'unit' => 'PT'],
        ];
        $fields = ['weightedFontFamily', 'fontSize'];
        if ($bold !== null) {
            $style['bold'] = $bold;
            $fields[] = 'bold';
        }

        return [
            'updateTextStyle' => [
                'range' => ['startIndex' => $start, 'endIndex' => max($start, $end)],
                'textStyle' => $style,
                'fields' => implode(',', $fields),
            ],
        ];
    }
}
