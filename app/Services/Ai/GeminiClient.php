<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Server-only Google Gemini client.
 *
 * The API key is sent in a request header, never in a URL, and is never
 * returned to the frontend. Request/response bodies are not logged here; the
 * caller records only token counts and an outcome code.
 */
class GeminiClient
{
    public function configured(): bool
    {
        return trim((string) config('services.gemini.api_key')) !== '';
    }

    public function model(): string
    {
        return (string) config('services.gemini.model', 'gemini-2.5-flash');
    }

    public function region(): string
    {
        return (string) config('services.gemini.region', 'global');
    }

    /**
     * @param  array<string, mixed>  $responseSchema
     * @return array{data: array<string, mixed>, usage: array{input_tokens: int, output_tokens: int, total_tokens: int}, model: string}
     *
     * @throws AiProviderException
     */
    public function generateJson(
        string $systemPrompt,
        string $userPrompt,
        array $responseSchema,
        ?int $maxOutputTokens = null,
    ): array {
        $apiKey = trim((string) config('services.gemini.api_key'));

        if ($apiKey === '') {
            throw new AiProviderException('not_configured', 'AI drafting is not configured.');
        }

        $baseUrl = rtrim((string) config('services.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta'), '/');
        $url = $baseUrl . '/models/' . rawurlencode($this->model()) . ':generateContent';

        $payload = [
            'systemInstruction' => [
                'parts' => [['text' => $systemPrompt]],
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [['text' => $userPrompt]],
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.2,
                'maxOutputTokens' => max(256, $maxOutputTokens ?? (int) config('roicard.ai.max_output_tokens', 2048)),
                'responseMimeType' => 'application/json',
                'responseSchema' => $responseSchema,
            ],
        ];

        $timeout = max(5, (int) config('roicard.ai.timeout_seconds', 20));

        try {
            $response = Http::withHeaders([
                'x-goog-api-key' => $apiKey,
                'User-Agent' => 'Roicard/1.0',
            ])
                ->acceptJson()
                ->asJson()
                ->timeout($timeout)
                ->post($url, $payload);
        } catch (ConnectionException $exception) {
            throw new AiProviderException(
                str_contains(strtolower($exception->getMessage()), 'timed out') ? 'timeout' : 'network_error',
                'The AI service could not be reached.',
                $exception,
            );
        } catch (Throwable $exception) {
            throw new AiProviderException('network_error', 'The AI service could not be reached.', $exception);
        }

        if ($response->status() === 429) {
            throw new AiProviderException('rate_limited', 'The AI service is temporarily busy.');
        }

        if (! $response->successful()) {
            throw new AiProviderException('provider_error', 'The AI service could not complete the request.');
        }

        $body = $response->json();
        $text = data_get($body, 'candidates.0.content.parts.0.text');

        if (! is_string($text) || trim($text) === '') {
            throw new AiProviderException('empty_response', 'The AI service returned no content.');
        }

        $decoded = json_decode($this->stripCodeFence($text), true);

        if (! is_array($decoded)) {
            throw new AiProviderException('invalid_response', 'The AI service returned an unreadable response.');
        }

        return [
            'data' => $decoded,
            'usage' => [
                'input_tokens' => (int) data_get($body, 'usageMetadata.promptTokenCount', 0),
                'output_tokens' => (int) data_get($body, 'usageMetadata.candidatesTokenCount', 0),
                'total_tokens' => (int) data_get($body, 'usageMetadata.totalTokenCount', 0),
            ],
            'model' => $this->model(),
        ];
    }

    protected function stripCodeFence(string $text): string
    {
        $text = trim($text);

        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/is', $text, $matches)) {
            return $matches[1];
        }

        return $text;
    }
}
