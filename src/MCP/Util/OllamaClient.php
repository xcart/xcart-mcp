<?php

declare(strict_types=1);

namespace XC\MCP\MCP\Util;

class OllamaClient
{
    public function __construct(private readonly string $baseUrl)
    {
    }

    public static function fromConfig(): self
    {
        $mcp = \XLite\Core\Config::getInstance()->XC?->MCP;
        $url = trim((string) ($mcp?->ollama_url ?? 'http://localhost:11434'));
        return new self(rtrim($url, '/'));
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * GET /api/tags — list installed models.
     * @return array{models: array<int, array<string, mixed>>}
     */
    public function listModels(): array
    {
        $body = $this->request('GET', '/api/tags');
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Ollama /api/tags returned non-JSON payload');
        }
        return $decoded;
    }

    /**
     * POST /api/chat (non-streaming).
     *
     * @param array<int, array{role: string, content: string}> $messages
     * @param array<string, mixed> $options Extra options merged into request body (temperature, num_ctx, …).
     * @return array{message: array{role: string, content: string}, done: bool, total_duration?: int}
     */
    public function chat(string $model, array $messages, array $options = []): array
    {
        $payload = array_merge([
            'model' => $model,
            'messages' => $messages,
            'stream' => false,
        ], $options);

        $body = $this->request('POST', '/api/chat', $payload);
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded['message']['content'])) {
            throw new \RuntimeException('Ollama /api/chat returned unexpected payload: ' . substr($body, 0, 200));
        }
        return $decoded;
    }

    /**
     * Streaming variant of POST /api/chat. The callback fires once per NDJSON chunk
     * Ollama emits. Each chunk is an array shaped like:
     *   ['message' => ['role' => 'assistant', 'content' => 'partial text'], 'done' => bool, ...]
     * Returns the final (done=true) chunk so callers can capture totals, tool_calls,
     * and the accumulated content if they want.
     *
     * @param array<int, array<string, mixed>> $messages
     * @param array<string, mixed> $options Extra options merged into request body (tools, temperature, …).
     * @param callable(array<string, mixed>): void $onChunk
     * @return array<string, mixed>
     */
    public function chatStream(string $model, array $messages, array $options, callable $onChunk): array
    {
        $payload = array_merge([
            'model'    => $model,
            'messages' => $messages,
            'stream'   => true,
        ], $options);
        $payload['stream'] = true;

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('json_encode failed: ' . json_last_error_msg());
        }

        $ch = curl_init($this->baseUrl . '/api/chat');
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }

        $buffer = '';
        $final  = [];

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/x-ndjson', 'Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 0); // disabled — streams can run minutes
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($ch, $data) use (&$buffer, &$final, $onChunk) {
            $buffer .= $data;
            while (($nl = strpos($buffer, "\n")) !== false) {
                $line   = substr($buffer, 0, $nl);
                $buffer = substr($buffer, $nl + 1);
                $line   = trim($line);
                if ($line === '') {
                    continue;
                }
                $chunk = json_decode($line, true);
                if (!is_array($chunk)) {
                    continue;
                }
                $onChunk($chunk);
                if (!empty($chunk['done'])) {
                    $final = $chunk;
                }
            }
            return strlen($data);
        });

        $ok     = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err    = curl_error($ch);

        if ($ok === false) {
            throw new \RuntimeException("Ollama stream failed: {$err}");
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("Ollama /api/chat (stream) returned HTTP {$status}");
        }

        return $final;
    }

    /**
     * Lightweight reachability check. Returns true if /api/tags responds 2xx within the timeout.
     */
    public function ping(float $timeoutSec = 2.0): bool
    {
        try {
            $this->request('GET', '/api/tags', null, $timeoutSec);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function request(string $method, string $path, ?array $payload = null, float $timeoutSec = 120.0): string
    {
        $ch = curl_init($this->baseUrl . $path);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }

        $headers = ['Accept: application/json'];
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, (int) max(500, $timeoutSec * 1000 / 4));
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, (int) ($timeoutSec * 1000));

        if ($payload !== null) {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                throw new \RuntimeException('json_encode failed: ' . json_last_error_msg());
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
            $headers[] = 'Content-Type: application/json';
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);

        if ($body === false) {
            throw new \RuntimeException("Ollama request failed ({$method} {$path}): {$err}");
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("Ollama {$method} {$path} returned HTTP {$status}: " . substr((string) $body, 0, 300));
        }
        return (string) $body;
    }
}
