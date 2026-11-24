<?php

namespace App\Services\Ai;

use ZipArchive;

/**
 * Minimal, dependency-free text extraction for CV files.
 *
 * PDF and DOCX are supported. Legacy .doc binaries are not reliably readable
 * without a parser dependency, so they fall back to manual paste instead of
 * sending raw binary/contact data to the provider.
 */
class DocumentTextExtractor
{
    public function extractFromPath(string $path, string $fileName): string
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new AiInputException('missing_file', 'That CV file could not be read. Please upload it again or paste the text.');
        }

        $maxBytes = max(1, (int) config('roicard.ai.max_cv_kb', 10240)) * 1024;
        if (filesize($path) > $maxBytes) {
            throw new AiInputException('file_too_large', 'That CV is larger than the AI input limit. Paste the text instead.');
        }

        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        $text = match ($extension) {
            'docx' => $this->extractDocx($path),
            'pdf' => $this->extractPdf($path),
            'txt' => (string) file_get_contents($path),
            'doc' => throw new AiInputException(
                'unsupported_document',
                'Legacy .doc files cannot be read automatically. Save the CV as PDF/DOCX or paste the text instead.',
            ),
            default => throw new AiInputException(
                'unsupported_document',
                'Upload a PDF or Word (.docx) CV, or paste the text instead.',
            ),
        };

        $text = $this->normalise($text);

        if (mb_strlen($text) < 40) {
            throw new AiInputException(
                'empty_document',
                'We could not read enough text from that CV. Paste the text instead.',
            );
        }

        return $text;
    }

    protected function extractDocx(string $path): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new AiInputException('unsupported_document', 'Word documents cannot be read on this server. Paste the text instead.');
        }

        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new AiInputException('unreadable_document', 'That Word document could not be opened. Paste the text instead.');
        }

        $xml = false;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($index));

            if ($name === 'word/document.xml') {
                $xml = $zip->getFromIndex($index);
                break;
            }
        }

        if ($xml === false) {
            $zip->close();
            throw new AiInputException('unreadable_document', 'That Word document has no readable text. Paste the text instead.');
        }

        $zip->close();

        $xml = (string) preg_replace('/<w:tab[^>]*\/>/i', ' ', $xml);
        $xml = (string) preg_replace('/<w:br[^>]*\/>/i', "\n", $xml);
        $xml = (string) preg_replace('/<\/w:p>/i', "\n", $xml);
        $xml = strip_tags($xml);
        $xml = html_entity_decode($xml, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $xml;
    }

    protected function extractPdf(string $path): string
    {
        $content = (string) file_get_contents($path);
        $text = '';

        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $content, $matches)) {
            foreach ($matches[1] as $stream) {
                $decoded = $this->decompress($stream);

                if ($decoded === '') {
                    continue;
                }

                $text .= $this->extractPdfTextStrings($decoded) . "\n";
            }
        }

        // Some small PDFs store text operators outside compressed streams.
        if (trim($text) === '') {
            $text = $this->extractPdfTextStrings($content);
        }

        return $text;
    }

    protected function decompress(string $stream): string
    {
        foreach (['gzuncompress', 'gzinflate', 'gzdecode'] as $function) {
            if (! function_exists($function)) {
                continue;
            }

            $decoded = @$function($stream);

            if (is_string($decoded) && $decoded !== '') {
                return $decoded;
            }
        }

        return $stream;
    }

    protected function extractPdfTextStrings(string $stream): string
    {
        $parts = [];

        if (preg_match_all('/\((?:\\\\.|[^\\\\()])*\)/s', $stream, $parenthesised)) {
            foreach ($parenthesised[0] as $literal) {
                $parts[] = $this->decodePdfString(substr($literal, 1, -1));
            }
        }

        if (preg_match_all('/<([0-9A-Fa-f\s]+)>\s*(?:Tj|TJ)/', $stream, $hexStrings)) {
            foreach ($hexStrings[1] as $hex) {
                $binary = @hex2bin((string) preg_replace('/\s+/', '', $hex));

                if (is_string($binary) && $binary !== '') {
                    $parts[] = mb_convert_encoding($binary, 'UTF-8', 'UTF-16BE');
                }
            }
        }

        return implode(' ', $parts);
    }

    protected function decodePdfString(string $value): string
    {
        $escapes = [
            'n' => "\n",
            'r' => "\r",
            't' => "\t",
            'b' => "\x08",
            'f' => "\x0C",
            '(' => '(',
            ')' => ')',
            '\\' => '\\',
        ];

        $value = (string) preg_replace_callback(
            '/\\\\([nrtbf()\\\\])/',
            fn (array $matches) => $escapes[$matches[1]] ?? $matches[1],
            $value,
        );

        $value = (string) preg_replace_callback(
            '/\\\\([0-7]{1,3})/',
            fn (array $matches) => chr(octdec($matches[1])),
            $value,
        );

        $value = (string) preg_replace('/\\\\\r?\n/', '', $value);

        if (str_starts_with($value, "\xFE\xFF")) {
            $value = mb_convert_encoding(substr($value, 2), 'UTF-8', 'UTF-16BE');
        } elseif (str_starts_with($value, "\xFF\xFE")) {
            $value = mb_convert_encoding(substr($value, 2), 'UTF-8', 'UTF-16LE');
        }

        return $value;
    }

    protected function normalise(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', ' ', $text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = (string) preg_replace('/[ \t]+/u', ' ', $text);
        $text = (string) preg_replace('/\n\s*\n+/u', "\n", $text);

        return trim($text);
    }
}
