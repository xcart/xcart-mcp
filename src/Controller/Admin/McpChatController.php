<?php

declare(strict_types=1);

namespace XC\MCP\Controller\Admin;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use XC\MCP\MCP\Util\ChatStorage;
use XC\MCP\MCP\Util\OllamaClient;
use XC\MCP\MCP\Util\ToolBridge;
use XC\MCP\Model\McpChatMessage;

/**
 * Admin-only AJAX endpoints used by the chat tab on the MCP settings page.
 */
class McpChatController
{
    private const MAX_AGENT_ITERATIONS = 6;

    public function __construct(
        private readonly ChatStorage $chatStorage,
        private readonly ToolBridge $toolBridge,
    ) {
    }

    public function ping(Request $request): Response
    {
        $deny = $this->requireAdmin();
        if ($deny !== null) {
            return $deny;
        }

        $mcp = \XLite\Core\Config::getInstance()->XC?->MCP;
        if (!($mcp?->ollama_enabled ?? false)) {
            return new JsonResponse(['ok' => false, 'error' => 'Ollama chat disabled in settings'], 503);
        }

        $client = OllamaClient::fromConfig();
        if (!$client->ping()) {
            return new JsonResponse(['ok' => false, 'error' => "Ollama unreachable at {$client->baseUrl()}"], 502);
        }

        try {
            $tags   = $client->listModels();
            $models = array_values(array_filter(array_map(
                static fn ($m) => $m['name'] ?? $m['model'] ?? '',
                $tags['models'] ?? []
            )));
            $tools  = $this->toolBridge->describeForOllama();
            return new JsonResponse([
                'ok'           => true,
                'baseUrl'      => $client->baseUrl(),
                'models'       => $models,
                'defaultModel' => (string) ($mcp?->ollama_model ?? ''),
                'toolCount'    => count($tools),
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 502);
        }
    }

    public function listChats(Request $request): Response
    {
        $deny = $this->requireAdmin();
        if ($deny !== null) {
            return $deny;
        }
        return new JsonResponse(['chats' => $this->chatStorage->listChats($this->profileId())]);
    }

    public function createChat(Request $request): Response
    {
        $deny = $this->requireAdmin();
        if ($deny !== null) {
            return $deny;
        }
        $mcp     = \XLite\Core\Config::getInstance()->XC?->MCP;
        $payload = json_decode($request->getContent(), true);
        $payload = is_array($payload) ? $payload : [];

        $model        = trim((string) ($payload['model'] ?? $mcp?->ollama_model ?? 'gemma4:e4b'));
        $systemPrompt = (string) ($payload['system_prompt'] ?? '');
        $chat         = $this->chatStorage->createChat($this->profileId(), $model, $systemPrompt);

        return new JsonResponse(['chat' => $this->chatStorage->describeChat($chat)]);
    }

    public function getChat(Request $request, int $id): Response
    {
        $deny = $this->requireAdmin();
        if ($deny !== null) {
            return $deny;
        }
        $chat = $this->chatStorage->getOwnedChat($id, $this->profileId());
        if ($chat === null) {
            return new JsonResponse(['error' => 'Chat not found'], 404);
        }
        return new JsonResponse(['chat' => $this->chatStorage->describeChat($chat)]);
    }

    public function deleteChat(Request $request, int $id): Response
    {
        $deny = $this->requireAdmin();
        if ($deny !== null) {
            return $deny;
        }
        $chat = $this->chatStorage->getOwnedChat($id, $this->profileId());
        if ($chat === null) {
            return new JsonResponse(['error' => 'Chat not found'], 404);
        }
        $this->chatStorage->deleteChat($chat);
        return new JsonResponse(['ok' => true]);
    }

    /**
     * Streaming chat endpoint. Returns text/event-stream and runs an agentic loop:
     * model → (optional tool_calls) → execute tools → repeat → emit deltas.
     */
    public function send(Request $request): Response
    {
        $deny = $this->requireAdmin();
        if ($deny !== null) {
            return $deny;
        }

        $mcp = \XLite\Core\Config::getInstance()->XC?->MCP;
        if (!($mcp?->ollama_enabled ?? false)) {
            return new JsonResponse(['error' => 'Ollama chat disabled in settings'], 503);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'Invalid JSON payload'], 400);
        }

        $userText = trim((string) ($payload['text'] ?? ''));
        if ($userText === '') {
            return new JsonResponse(['error' => 'text is required'], 400);
        }

        $chatId   = isset($payload['chat_id']) ? (int) $payload['chat_id'] : 0;
        $model    = trim((string) ($payload['model'] ?? $mcp?->ollama_model ?? 'gemma4:e4b'));
        $useTools = (bool) ($payload['use_tools'] ?? true);

        $profileId = $this->profileId();
        $chat = $chatId > 0 ? $this->chatStorage->getOwnedChat($chatId, $profileId) : null;
        if ($chat === null) {
            $chat = $this->chatStorage->createChat($profileId, $model);
        } elseif ($model !== '' && $chat->getModel() !== $model) {
            $chat->setModel($model);
        }

        $this->chatStorage->appendMessage($chat, McpChatMessage::ROLE_USER, $userText);
        $chatStorage = $this->chatStorage;
        $toolBridge  = $this->toolBridge;
        $maxIter     = self::MAX_AGENT_ITERATIONS;
        $chatId      = (int) $chat->getId();
        $modelToUse  = $chat->getModel() ?: $model;

        $response = new StreamedResponse(static function () use ($chatStorage, $toolBridge, $chat, $modelToUse, $useTools, $maxIter) {
            $client = OllamaClient::fromConfig();

            $emit = static function (array $event): void {
                echo 'data: ' . json_encode($event, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n\n";
                if (function_exists('ob_get_level') && ob_get_level() > 0) {
                    @ob_flush();
                }
                @flush();
            };

            $emit(['type' => 'meta', 'chat_id' => (int) $chat->getId(), 'model' => $modelToUse]);

            $toolsPayload = $useTools ? $toolBridge->describeForOllama() : [];
            $options      = $toolsPayload !== [] ? ['tools' => $toolsPayload] : [];

            try {
                for ($iter = 0; $iter < $maxIter; $iter++) {
                    $messages   = $chatStorage->buildOllamaMessages($chat);
                    $contentBuf = '';
                    $toolCalls  = [];

                    $final = $client->chatStream(
                        $modelToUse,
                        $messages,
                        $options,
                        static function (array $chunk) use (&$contentBuf, &$toolCalls, $emit) {
                            $delta = (string) ($chunk['message']['content'] ?? '');
                            if ($delta !== '') {
                                $contentBuf .= $delta;
                                $emit(['type' => 'delta', 'content' => $delta]);
                            }
                            if (!empty($chunk['message']['tool_calls']) && is_array($chunk['message']['tool_calls'])) {
                                $toolCalls = $chunk['message']['tool_calls'];
                            }
                        }
                    );

                    // Late: some Ollama builds put tool_calls only in the final done-chunk.
                    if (empty($toolCalls) && !empty($final['message']['tool_calls'])) {
                        $toolCalls = $final['message']['tool_calls'];
                    }

                    // Persist this assistant turn.
                    $chatStorage->appendMessage(
                        $chat,
                        McpChatMessage::ROLE_ASSISTANT,
                        $contentBuf,
                        $toolCalls !== [] ? ['tool_calls' => $toolCalls] : []
                    );

                    if ($toolCalls === []) {
                        break;
                    }

                    foreach ($toolCalls as $tc) {
                        $name   = (string) ($tc['function']['name'] ?? '');
                        $rawArg = $tc['function']['arguments'] ?? [];
                        $args   = is_string($rawArg) ? (json_decode($rawArg, true) ?? []) : $rawArg;
                        $tcId   = (string) ($tc['id'] ?? '');

                        $emit(['type' => 'tool_call', 'name' => $name, 'args' => $args, 'id' => $tcId]);

                        try {
                            $result = $toolBridge->execute($name, is_array($args) ? $args : []);
                            $resultJson = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                            if ($resultJson === false) {
                                $resultJson = json_encode(['error' => 'tool result not serializable']);
                            }
                        } catch (\Throwable $e) {
                            $resultJson = json_encode(['error' => $e->getMessage()]);
                        }

                        $emit(['type' => 'tool_result', 'name' => $name, 'id' => $tcId, 'content' => $resultJson]);

                        $chatStorage->appendMessage(
                            $chat,
                            McpChatMessage::ROLE_TOOL,
                            (string) $resultJson,
                            ['tool_call_id' => $tcId, 'tool_name' => $name]
                        );
                    }
                }

                $emit(['type' => 'done']);
            } catch (\Throwable $e) {
                \XLite\Logger::getLogger('mcp')->warning('Chat stream failed: ' . $e->getMessage());
                $emit(['type' => 'error', 'error' => $e->getMessage()]);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Content-Encoding', 'identity');
        return $response;
    }

    private function profileId(): int
    {
        $p = \XLite\Core\Auth::getInstance()->getProfile();
        return $p !== null && method_exists($p, 'getProfileId') ? (int) $p->getProfileId() : 0;
    }

    private function requireAdmin(): ?Response
    {
        if (!class_exists(\XLite\Core\Auth::class)) {
            return new JsonResponse(['error' => 'Auth unavailable'], 500);
        }

        $auth    = \XLite\Core\Auth::getInstance();
        $profile = $auth->getProfile();
        if ($profile === null) {
            return new JsonResponse(['error' => 'Not authenticated'], 401);
        }

        $isAdmin = method_exists($profile, 'isAdmin') ? (bool) $profile->isAdmin() : false;
        if (!$isAdmin) {
            return new JsonResponse(['error' => 'Admin required'], 403);
        }

        return null;
    }
}
