<?php

namespace Modules\AIBusinessManager\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class OpenAiChatCompletionsService
{
    /**
     * POST /v1/chat/completions (raw JSON). Supports tool_calls in the response.
     *
     * @return array{ok: true, data: array<string, mixed>}|array{ok: false, error: string, details?: string}
     */
    public function create(array $body): array
    {
        $api_key = config('openai.api_key');
        if (! is_string($api_key) || $api_key === '') {
            return ['ok' => false, 'error' => 'no_api_key'];
        }

        $organization = config('openai.organization');
        if (is_string($organization)) {
            $organization = trim($organization);
        }
        if (! is_string($organization) || ! preg_match('/^org[-_]/', $organization)) {
            $organization = null;
        }

        $headers = [
            'Authorization' => 'Bearer '.$api_key,
            'Content-Type' => 'application/json',
        ];
        if ($organization !== null) {
            $headers['OpenAI-Organization'] = $organization;
        }

        try {
            $response = Http::timeout(120)
                ->withHeaders($headers)
                ->post('https://api.openai.com/v1/chat/completions', $body);

            $json = $response->json();
            if (! is_array($json)) {
                return [
                    'ok' => false,
                    'error' => 'invalid_response',
                    'details' => 'Non-JSON response (HTTP '.$response->status().')',
                ];
            }

            if (isset($json['error']) && is_array($json['error'])) {
                $msg = (string) ($json['error']['message'] ?? 'OpenAI API error');

                return ['ok' => false, 'error' => 'api_error', 'details' => $msg];
            }

            if (! $response->successful()) {
                return [
                    'ok' => false,
                    'error' => 'http_error',
                    'details' => 'HTTP '.$response->status().': '.json_encode($json),
                ];
            }

            return ['ok' => true, 'data' => $json];
        } catch (Throwable $e) {
            Log::warning('Eli assistant OpenAI HTTP error: '.$e->getMessage(), ['exception' => $e]);

            return ['ok' => false, 'error' => 'generic', 'details' => $e->getMessage()];
        }
    }
}
