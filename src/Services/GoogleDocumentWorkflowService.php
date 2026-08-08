<?php

namespace hexa_package_google_docs\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;
use hexa_package_google_drive\Services\GoogleDriveApiClient;
use hexa_package_google_drive\Services\GoogleDriveService;

class GoogleDocumentWorkflowService
{
    private const API_BASE = 'https://www.googleapis.com/drive/v3';

    private const DOCUMENT_MIME_TYPE = 'application/vnd.google-apps.document';

    public function __construct(
        private readonly GoogleDriveService $drive,
        private readonly GoogleDriveApiClient $apiClient,
        private readonly GoogleDocsService $documents,
        private readonly GoogleDocsWriteService $writer,
    ) {}

    /**
     * Verify public edit access and scan a Google Doc without changing it.
     *
     * @param  array{require_featured_image?:bool,require_h2_headings?:bool}  $requirements
     * @return array<string, mixed>
     */
    public function inspectPublicEditable(string $url, array $requirements = []): array
    {
        $reference = $this->documentReference($url);
        if ($reference === null) {
            return $this->failure(
                'Enter a valid Google Docs link, such as https://docs.google.com/document/d/.../edit.'
            );
        }

        $metadata = $this->metadata(
            $reference['id'],
            $reference['resourceKey'] ?? null
        );
        if (! ($metadata['success'] ?? false)) {
            return $this->failure(
                $this->readFailureMessage((string) ($metadata['error'] ?? 'Google Drive could not read this document.')),
                ['document' => $this->documentPayload($reference['id'], null, $url)]
            );
        }

        $file = (array) ($metadata['data'] ?? []);
        if (($file['mimeType'] ?? null) !== self::DOCUMENT_MIME_TYPE) {
            return $this->failure(
                'This link is readable, but it is not a native Google Doc. Create or convert it in Google Docs and paste that link.',
                ['document' => $this->documentPayload($reference['id'], $file, $url)]
            );
        }

        $permissions = (array) ($file['permissions'] ?? []);
        if ($permissions === []) {
            $permissionResult = $this->permissions(
                $reference['id'],
                $reference['resourceKey'] ?? null
            );
            if ($permissionResult['success'] ?? false) {
                $permissions = (array) data_get($permissionResult, 'data.permissions', []);
            }
        }

        $publiclyEditable = $this->hasAnyoneWriterPermission($permissions);
        if (! $publiclyEditable) {
            return $this->failure(
                'This Google Doc is not publicly editable. Open Share, set General access to Anyone with the link, choose Editor, and check again.',
                [
                    'accessible' => true,
                    'publicly_editable' => false,
                    'document' => $this->documentPayload($reference['id'], $file, $url),
                ]
            );
        }

        $export = $this->documents->fetchHtml($url);
        if (! ($export['success'] ?? false)) {
            return $this->failure(
                'The Google Doc is publicly editable, but its content could not be scanned. Confirm that downloads are allowed and try again. '.
                    trim((string) ($export['message'] ?? '')),
                [
                    'accessible' => true,
                    'publicly_editable' => true,
                    'document' => $this->documentPayload($reference['id'], $file, $url),
                ]
            );
        }

        $scan = $this->scanHtml((string) ($export['content'] ?? ''));
        $requirements = $this->requirements($requirements);
        $errors = [];
        if ($requirements['require_featured_image'] && ! $scan['featured_image_found']) {
            $errors[] = 'Add the featured image inside the Google Doc, then check the link again.';
        }
        if ($requirements['require_h2_headings'] && ! $scan['headings_h2_only']) {
            $errors[] = 'Change every document heading to Heading 2 (H2). Found: '.
                implode(', ', $scan['non_h2_headings']).'.';
        }

        $document = $this->documentPayload($reference['id'], $file, $url);

        return [
            'success' => $errors === [],
            'accessible' => true,
            'publicly_editable' => true,
            'message' => $errors === []
                ? $this->successMessage($scan)
                : implode(' ', $errors),
            'errors' => $errors,
            'document' => $document,
            'scan' => $scan,
        ];
    }

    /**
     * Create or resume an internal copy and ensure that copy is publicly editable.
     *
     * @param  array{require_featured_image?:bool,require_h2_headings?:bool}  $requirements
     * @return array<string, mixed>
     */
    public function createPublicEditableCopy(
        string $sourceUrl,
        string $copyName,
        array $requirements = [],
        ?string $existingCopyId = null,
    ): array {
        $source = $this->inspectPublicEditable($sourceUrl, $requirements);
        if (! ($source['success'] ?? false)) {
            return $source + ['source' => $source['document'] ?? null];
        }

        $copyId = trim((string) $existingCopyId);
        $internalDocument = null;
        if ($copyId !== '') {
            $internalDocument = $this->documentPayload($copyId, null, null);
        } else {
            $sourceContent = $this->documents->fetchHtml($sourceUrl);
            if (! ($sourceContent['success'] ?? false)) {
                return $this->failure(
                    'The source Google Doc was verified, but its content could not be prepared for the internal copy. '.
                        trim((string) ($sourceContent['message'] ?? '')),
                    ['source' => $source['document'] ?? null, 'scan' => $source['scan'] ?? null]
                );
            }

            $created = $this->writer->createDocumentFromHtml(
                $this->copyName($copyName),
                (string) ($sourceContent['content'] ?? '')
            );
            if (! ($created['success'] ?? false)) {
                return $this->failure(
                    'The internal Google Doc could not be created: '.
                        trim((string) ($created['message'] ?? 'Google Docs rejected the copy.')),
                    ['source' => $source['document'] ?? null, 'scan' => $source['scan'] ?? null]
                );
            }

            $copyId = trim((string) ($created['document_id'] ?? ''));
            if ($copyId === '') {
                return $this->failure(
                    'Google Docs created no usable document reference.',
                    ['source' => $source['document'] ?? null, 'scan' => $source['scan'] ?? null]
                );
            }

            $internalDocument = [
                'id' => $copyId,
                'name' => $this->copyName($copyName),
                'mime_type' => self::DOCUMENT_MIME_TYPE,
                'url' => trim((string) ($created['normalized_url'] ?? ''))
                    ?: 'https://docs.google.com/document/d/'.rawurlencode($copyId).'/edit',
                'submitted_url' => null,
            ];
        }

        $verified = $this->inspectPublicEditable((string) $internalDocument['url'], $requirements);
        if (! ($verified['success'] ?? false)) {
            return $this->failure(
                'The internal Google Doc exists, but final verification failed: '.($verified['message'] ?? 'Unknown error.'),
                [
                    'source' => $source['document'] ?? null,
                    'scan' => $source['scan'] ?? null,
                    'internal_document' => $internalDocument,
                ]
            );
        }

        return [
            'success' => true,
            'message' => 'Internal Google Doc created and verified with public edit access.',
            'source' => $source['document'] ?? null,
            'internal_document' => $verified['document'],
            'scan' => $verified['scan'],
        ];
    }

    /** @return array<string, mixed> */
    public function scanHtml(string $html): array
    {
        $images = [];
        $urls = [];
        $headings = [];
        $nonH2 = [];

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8">'.$html,
            LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded) {
            $xpath = new DOMXPath($document);
            foreach ($xpath->query('//img') ?: [] as $image) {
                if (! $image instanceof DOMElement) {
                    continue;
                }
                $images[] = [
                    'src' => trim($image->getAttribute('src')),
                    'alt' => trim($image->getAttribute('alt')),
                ];
            }
            foreach ($xpath->query('//a[@href] | //img[@src]') ?: [] as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }
                $candidate = $node->tagName === 'a'
                    ? $node->getAttribute('href')
                    : $node->getAttribute('src');
                if (filter_var($candidate, FILTER_VALIDATE_URL)) {
                    $urls[] = trim($candidate);
                }
            }
            foreach ($xpath->query('//h1 | //h2 | //h3 | //h4 | //h5 | //h6') ?: [] as $heading) {
                if (! $heading instanceof DOMElement) {
                    continue;
                }
                $label = strtoupper($heading->tagName).': '.trim($heading->textContent);
                $headings[] = $label;
                if (strtolower($heading->tagName) !== 'h2') {
                    $nonH2[] = $label;
                }
            }
        }

        if (preg_match_all('~https?://[^\s<>"\']+~iu', strip_tags($html), $matches)) {
            $urls = array_merge($urls, $matches[0]);
        }

        $urls = array_values(array_slice(array_unique(array_map(
            static fn (string $value): string => rtrim(trim($value), '.,;:!?)]}'),
            $urls
        )), 0, 100));

        return [
            'featured_image_found' => $images !== [],
            'image_count' => count($images),
            'images' => array_slice($images, 0, 20),
            'url_count' => count($urls),
            'urls' => $urls,
            'heading_count' => count($headings),
            'headings' => array_slice($headings, 0, 100),
            'headings_h2_only' => $nonH2 === [],
            'non_h2_headings' => array_slice($nonH2, 0, 100),
        ];
    }

    /** @return array{id:string,resourceKey:string|null,url:string}|null */
    private function documentReference(string $url): ?array
    {
        $url = trim($url);
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (! in_array($host, ['docs.google.com', 'drive.google.com'], true)) {
            return null;
        }

        $reference = $this->drive->resolveFileReference($url);
        if ($reference === null) {
            return null;
        }

        return [
            'id' => (string) $reference['id'],
            'resourceKey' => isset($reference['resourceKey'])
                ? (string) $reference['resourceKey']
                : null,
            'url' => $url,
        ];
    }

    /** @return array<string, mixed> */
    private function metadata(string $fileId, ?string $resourceKey): array
    {
        return $this->apiClient->request(
            'GET',
            $this->url(self::API_BASE.'/files/'.rawurlencode($fileId), array_merge([
                'supportsAllDrives' => 'true',
                'fields' => 'id,name,mimeType,webViewLink,capabilities(canCopy),permissions(id,type,role,allowFileDiscovery)',
            ], $this->apiClient->authQueryParams())),
            $this->readHeaders($fileId, $resourceKey)
        );
    }

    /** @return array<string, mixed> */
    private function permissions(string $fileId, ?string $resourceKey = null): array
    {
        return $this->apiClient->request(
            'GET',
            $this->url(self::API_BASE.'/files/'.rawurlencode($fileId).'/permissions', array_merge([
                'supportsAllDrives' => 'true',
                'fields' => 'permissions(id,type,role,allowFileDiscovery)',
            ], $this->apiClient->authQueryParams())),
            $this->readHeaders($fileId, $resourceKey)
        );
    }

    /** @param array<int, mixed> $permissions */
    private function hasAnyoneWriterPermission(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (! is_array($permission) || ($permission['type'] ?? null) !== 'anyone') {
                continue;
            }
            if (in_array($permission['role'] ?? null, ['writer', 'organizer', 'fileOrganizer', 'owner'], true)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    private function readHeaders(string $fileId, ?string $resourceKey): array
    {
        $keys = [$fileId => $resourceKey];
        $headers = $this->apiClient->buildDriveRequestHeaders($keys);
        if ($headers !== [] || $this->apiClient->authQueryParams() !== []) {
            return $headers;
        }

        return $this->apiClient->buildDriveWriteRequestHeaders($keys);
    }

    /** @return array<string, mixed> */
    private function documentPayload(string $fileId, ?array $file, ?string $submittedUrl): array
    {
        return [
            'id' => $fileId,
            'name' => trim((string) ($file['name'] ?? '')) ?: null,
            'mime_type' => $file['mimeType'] ?? self::DOCUMENT_MIME_TYPE,
            'url' => 'https://docs.google.com/document/d/'.rawurlencode($fileId).'/edit',
            'submitted_url' => $submittedUrl,
        ];
    }

    /** @return array{require_featured_image:bool,require_h2_headings:bool} */
    private function requirements(array $requirements): array
    {
        return [
            'require_featured_image' => filter_var(
                $requirements['require_featured_image'] ?? false,
                FILTER_VALIDATE_BOOL
            ),
            'require_h2_headings' => filter_var(
                $requirements['require_h2_headings'] ?? false,
                FILTER_VALIDATE_BOOL
            ),
        ];
    }

    /** @param array<string, mixed> $scan */
    private function successMessage(array $scan): string
    {
        return sprintf(
            'Google Doc is publicly editable. Featured image: %s. URLs found: %d. Headings: %s.',
            $scan['featured_image_found'] ? 'found' : 'not found',
            (int) $scan['url_count'],
            $scan['headings_h2_only'] ? 'all H2' : 'non-H2 headings found'
        );
    }

    private function readFailureMessage(string $error): string
    {
        $lower = strtolower($error);
        if (str_contains($lower, 'not found') || str_contains($lower, 'permission') || str_contains($lower, 'forbidden')) {
            return 'Google Drive could not open this document. Confirm the link is correct and set General access to Anyone with the link.';
        }

        return 'Google Drive could not verify this document: '.$error;
    }

    private function copyName(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);

        return mb_substr($name !== '' ? $name : 'Internal order document', 0, 255);
    }

    /** @param array<string, scalar> $query */
    private function url(string $base, array $query): string
    {
        return $query === [] ? $base : $base.'?'.http_build_query($query);
    }

    /** @param array<string, mixed> $context @return array<string, mixed> */
    private function failure(string $message, array $context = []): array
    {
        return array_merge([
            'success' => false,
            'message' => trim($message),
            'errors' => [trim($message)],
        ], $context);
    }
}
