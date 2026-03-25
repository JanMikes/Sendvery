<?php

declare(strict_types=1);

namespace App\Services\Dmarc;

use App\Exceptions\InvalidDmarcReportXml;

readonly final class ReportAttachmentExtractor
{
    /**
     * Extracts XML content from a DMARC report attachment.
     *
     * @return array<string> Array of XML strings extracted from the archive
     */
    public function extract(string $content, string $filename): array
    {
        if ($this->isGzip($content, $filename)) {
            return $this->extractGzip($content);
        }

        if ($this->isZip($content, $filename)) {
            return $this->extractZip($content);
        }

        if ($this->isXml($content, $filename)) {
            return [$content];
        }

        throw new InvalidDmarcReportXml(sprintf('Unsupported file format: %s', $filename));
    }

    private function isGzip(string $content, string $filename): bool
    {
        if (str_ends_with(strtolower($filename), '.gz') || str_ends_with(strtolower($filename), '.gzip')) {
            return true;
        }

        return strlen($content) >= 2 && $content[0] === "\x1f" && $content[1] === "\x8b";
    }

    private function isZip(string $content, string $filename): bool
    {
        if (str_ends_with(strtolower($filename), '.zip')) {
            return true;
        }

        return strlen($content) >= 4 && $content[0] === 'P' && $content[1] === 'K' && $content[2] === "\x03" && $content[3] === "\x04";
    }

    private function isXml(string $content, string $filename): bool
    {
        if (str_ends_with(strtolower($filename), '.xml')) {
            return true;
        }

        $trimmed = ltrim($content);

        return str_starts_with($trimmed, '<?xml') || str_starts_with($trimmed, '<feedback');
    }

    /** @return array<string> */
    private function extractGzip(string $content): array
    {
        $decompressed = @gzdecode($content);

        if ($decompressed === false) {
            throw new InvalidDmarcReportXml('Failed to decompress gzip archive.');
        }

        return [$decompressed];
    }

    /** @return array<string> */
    private function extractZip(string $content): array
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'dmarc_');
        if ($tempFile === false) { // @codeCoverageIgnore
            throw new InvalidDmarcReportXml('Failed to create temporary file for zip extraction.'); // @codeCoverageIgnore
        }

        try {
            file_put_contents($tempFile, $content);

            $zip = new \ZipArchive();
            $result = $zip->open($tempFile);

            if ($result !== true) {
                throw new InvalidDmarcReportXml('Failed to open zip archive.');
            }

            $xmlFiles = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name === false) { // @codeCoverageIgnore
                    continue; // @codeCoverageIgnore
                }

                if (!str_ends_with(strtolower($name), '.xml')) {
                    continue;
                }

                $fileContent = $zip->getFromIndex($i);
                if ($fileContent === false) { // @codeCoverageIgnore
                    continue; // @codeCoverageIgnore
                }

                $xmlFiles[] = $fileContent;
            }

            $zip->close();

            if ($xmlFiles === []) {
                throw new InvalidDmarcReportXml('No XML files found in zip archive.');
            }

            return $xmlFiles;
        } finally {
            @unlink($tempFile);
        }
    }
}
