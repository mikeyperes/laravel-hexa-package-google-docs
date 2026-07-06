<?php

namespace hexa_package_google_docs\Services;

use hexa_core\Models\Setting;
use hexa_core\Services\GenericService;
use Illuminate\Support\Facades\Http;

class GoogleDocsService
{
    public function __construct(
        protected GenericService $generic,
        protected GoogleDocsWriteService $writer,
    ) {
    }

    public function extractDocumentId(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('#/document/(?:u/\d+/)?d/([a-zA-Z0-9_-]+)#', $value, $matches)) {
            return $matches[1];
        }

        if (preg_match('/^[a-zA-Z0-9_-]{20,}$/', $value) === 1) {
            return $value;
        }

        return null;
    }

    public function normalizeDocumentUrl(string $value): ?string
    {
        $documentId = $this->extractDocumentId($value);
        if ($documentId === null) {
            return null;
        }

        return 'https://docs.google.com/document/d/' . $documentId . '/edit';
    }

    public function buildExportUrl(string $documentId, string $format = 'txt'): string
    {
        return 'https://docs.google.com/document/d/' . $documentId . '/export?format=' . $this->normalizeFormat($format);
    }

    public function fetchText(string $value): array
    {
        return $this->fetchDocument($value, 'txt');
    }

    public function fetchHtml(string $value): array
    {
        return $this->fetchDocument($value, 'html');
    }

    public function fetchDocument(string $value, string $format = 'txt'): array
    {
        $documentId = $this->extractDocumentId($value);
        if ($documentId === null) {
            return [
                'success' => false,
                'message' => 'Invalid Google Docs URL or document ID.',
            ];
        }

        $format = $this->normalizeFormat($format);
        $exportUrl = $this->buildExportUrl($documentId, $format);
        $response = Http::timeout($this->timeoutSeconds())
            ->withHeaders([
                'User-Agent' => $this->userAgent(),
                'Accept' => $format === 'html' ? 'text/html,text/plain;q=0.9,*/*;q=0.8' : 'text/plain,*/*;q=0.8',
            ])
            ->get($exportUrl);

        if (!$response->successful()) {
            $apiFallback = $this->fetchWithAuthenticatedDrive($documentId, $format);
            if (($apiFallback["success"] ?? false) === true) {
                return $apiFallback;
            }

            return [
                'success' => false,
                'message' => 'Google Docs export returned HTTP ' . $response->status() . '.',
                'document_id' => $documentId,
                'format' => $format,
                'export_url' => $exportUrl,
            ];
        }

        $content = $this->stripBom((string) $response->body());
        if (trim($content) === '') {
            $apiFallback = $this->fetchWithAuthenticatedDrive($documentId, $format);
            if (($apiFallback["success"] ?? false) === true) {
                return $apiFallback;
            }

            return [
                'success' => false,
                'message' => 'Google Docs export returned an empty response.',
                'document_id' => $documentId,
                'format' => $format,
                'export_url' => $exportUrl,
            ];
        }

        $plainText = $format === 'html'
            ? $this->htmlToPlainText($content)
            : trim($content);

        return [
            'success' => true,
            'message' => 'Fetched public Google Doc successfully.',
            'document_id' => $documentId,
            'normalized_url' => $this->normalizeDocumentUrl($documentId),
            'export_url' => $exportUrl,
            'format' => $format,
            'mime_type' => (string) $response->header('Content-Type', ''),
            'byte_length' => strlen($content),
            'title' => $this->detectTitle($content, $format),
            'content' => $content,
            'plain_text' => $plainText,
            'preview' => mb_substr($plainText, 0, $this->maxPreviewChars()),
        ];
    }

    protected function fetchWithAuthenticatedDrive(string $documentId, string $format): array
    {
        $api = $this->writer->exportDocumentContent($documentId, $format);
        if (($api["success"] ?? false) !== true) {
            return array_merge([
                "success" => false,
                "document_id" => $documentId,
                "format" => $format,
            ], $api);
        }

        $content = $this->stripBom((string) ($api["content"] ?? ""));
        if (trim($content) === "") {
            return [
                "success" => false,
                "message" => "Google Drive API export returned an empty response.",
                "document_id" => $documentId,
                "format" => $format,
                "auth_mode" => (string) ($api["auth_mode"] ?? ""),
                "connected_email" => (string) ($api["connected_email"] ?? ""),
            ];
        }

        $plainText = $format === "html"
            ? $this->htmlToPlainText($content)
            : trim($content);

        return [
            "success" => true,
            "message" => (string) ($api["message"] ?? "Fetched Google Doc through Google Drive API."),
            "document_id" => $documentId,
            "normalized_url" => $this->normalizeDocumentUrl($documentId),
            "export_url" => null,
            "format" => $format,
            "mime_type" => (string) ($api["mime_type"] ?? ""),
            "byte_length" => strlen($content),
            "title" => $this->detectTitle($content, $format),
            "content" => $content,
            "plain_text" => $plainText,
            "preview" => mb_substr($plainText, 0, $this->maxPreviewChars()),
            "auth_mode" => (string) ($api["auth_mode"] ?? ""),
            "connected_email" => (string) ($api["connected_email"] ?? ""),
        ];
    }

    protected function normalizeFormat(string $format): string
    {
        return in_array($format, ['txt', 'html'], true) ? $format : $this->defaultFormat();
    }

    protected function detectTitle(string $content, string $format): string
    {
        if ($format === 'html') {
            $plainBody = $this->htmlToPlainText($content);
            $lines = preg_split('/\r\n|\r|\n/', $plainBody) ?: [];
            foreach ($lines as $line) {
                $line = trim($this->stripBom($line));
                if ($line !== '') {
                    return mb_substr($line, 0, 180);
                }
            }

            if (preg_match('/<title>(.*?)<\/title>/is', $content, $matches) === 1) {
                $title = trim($this->stripBom(html_entity_decode(strip_tags($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                if ($title !== '') {
                    return mb_substr($title, 0, 180);
                }
            }
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($content)) ?: [];
        foreach ($lines as $line) {
            $line = trim($this->stripBom($line));
            if ($line !== '') {
                return mb_substr($line, 0, 180);
            }
        }

        return 'Untitled Document';
    }

    protected function htmlToPlainText(string $html): string
    {
        $bodyHtml = $this->extractBodyHtml($html);
        $bodyHtml = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $bodyHtml) ?? $bodyHtml;
        $bodyHtml = preg_replace('/<br\s*\/?\s*>/i', "\n", $bodyHtml) ?? $bodyHtml;
        $bodyHtml = preg_replace('/<\/(p|div|li|h1|h2|h3|h4|h5|h6|tr)>/i', "\n", $bodyHtml) ?? $bodyHtml;
        $text = html_entity_decode(strip_tags($bodyHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/\r\n|\r/", "\n", $text) ?? $text;
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/ ?\n ?/', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($this->stripBom($text));
    }

    protected function extractBodyHtml(string $html): string
    {
        if (preg_match('/<body\b[^>]*>(.*)<\/body>/is', $html, $matches) === 1) {
            return $matches[1];
        }

        return $html;
    }

    protected function stripBom(string $value): string
    {
        $value = preg_replace('/^\x{FEFF}/u', '', $value) ?? $value;

        if (str_starts_with($value, "\xEF\xBB\xBF")) {
            return substr($value, 3);
        }

        return $value;
    }

    protected function defaultFormat(): string
    {
        $value = (string) Setting::getValue('google_docs_default_format', config('google-docs.default_format', 'txt'));
        return in_array($value, ['txt', 'html'], true) ? $value : 'txt';
    }

    protected function timeoutSeconds(): int
    {
        $value = (int) Setting::getValue('google_docs_timeout_seconds', config('google-docs.timeout_seconds', 15));
        return max(5, min($value, 60));
    }

    protected function maxPreviewChars(): int
    {
        $value = (int) Setting::getValue('google_docs_max_preview_chars', config('google-docs.max_preview_chars', 1600));
        return max(200, min($value, 5000));
    }

    protected function userAgent(): string
    {
        $value = trim((string) Setting::getValue('google_docs_user_agent', config('google-docs.user_agent', 'HexaGoogleDocs/1.0')));
        return $value !== '' ? $value : 'HexaGoogleDocs/1.0';
    }
}
