<?php

declare(strict_types=1);

namespace XC\MCP\MCP\Util;

use Doctrine\ORM\EntityManagerInterface;
use XC\MCP\Model\McpChat;
use XC\MCP\Model\McpChatMessage;
use XC\MCP\Model\Repo\McpChat as McpChatRepo;

/**
 * Facade over McpChat / McpChatMessage entities. Handles ownership checks (profile_id) so
 * the controller never has to.
 */
class ChatStorage
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @return list<array<string, mixed>> */
    public function listChats(int $profileId): array
    {
        /** @var McpChatRepo $repo */
        $repo  = $this->em->getRepository(McpChat::class);
        $chats = $repo->findForProfile($profileId);

        return array_map(fn (McpChat $c) => $this->summarize($c), $chats);
    }

    public function createChat(int $profileId, string $model, string $systemPrompt = ''): McpChat
    {
        $chat = new McpChat();
        $chat->setProfileId($profileId);
        $chat->setTitle('New chat');
        $chat->setModel($model);
        $chat->setSystemPrompt($systemPrompt);
        $now = time();
        $chat->setCreatedAt($now);
        $chat->setUpdatedAt($now);
        $this->em->persist($chat);
        $this->em->flush();
        return $chat;
    }

    public function getOwnedChat(int $chatId, int $profileId): ?McpChat
    {
        $chat = $this->em->find(McpChat::class, $chatId);
        if ($chat === null || (int) $chat->getProfileId() !== $profileId) {
            return null;
        }
        return $chat;
    }

    public function deleteChat(McpChat $chat): void
    {
        $this->em->remove($chat);
        $this->em->flush();
    }

    public function appendMessage(McpChat $chat, string $role, string $content, array $extra = []): McpChatMessage
    {
        $msg = new McpChatMessage();
        $msg->setChat($chat);
        $msg->setRole($role);
        $msg->setContent($content);
        $msg->setToolCalls(isset($extra['tool_calls']) ? (string) json_encode($extra['tool_calls']) : '');
        $msg->setToolCallId((string) ($extra['tool_call_id'] ?? ''));
        $msg->setToolName((string) ($extra['tool_name'] ?? ''));
        $msg->setCreatedAt(time());
        $this->em->persist($msg);

        $chat->setUpdatedAt(time());
        if ($role === McpChatMessage::ROLE_USER && ($chat->getTitle() === 'New chat' || $chat->getTitle() === '')) {
            $chat->setTitle($this->derivedTitle($content));
        }
        $this->em->flush();
        return $msg;
    }

    /**
     * Build the message list to send to Ollama. Includes a leading system prompt (chat-level
     * override or module-level default) when any is configured.
     *
     * @return list<array{role: string, content: string, tool_calls?: array<int, mixed>, tool_call_id?: string, name?: string}>
     */
    public function buildOllamaMessages(McpChat $chat, string $defaultSystemPrompt = ''): array
    {
        $out    = [];
        $system = trim($chat->getSystemPrompt() !== '' ? $chat->getSystemPrompt() : $defaultSystemPrompt);
        if ($system !== '') {
            $out[] = ['role' => McpChatMessage::ROLE_SYSTEM, 'content' => $system];
        }

        foreach ($chat->getMessages() as $m) {
            /** @var McpChatMessage $m */
            $entry = ['role' => $m->getRole(), 'content' => $m->getContent()];
            if ($m->getRole() === McpChatMessage::ROLE_ASSISTANT && $m->getToolCalls() !== '') {
                $decoded = json_decode($m->getToolCalls(), true);
                if (is_array($decoded) && $decoded !== []) {
                    $entry['tool_calls'] = $decoded;
                }
            }
            if ($m->getRole() === McpChatMessage::ROLE_TOOL) {
                if ($m->getToolCallId() !== '') {
                    $entry['tool_call_id'] = $m->getToolCallId();
                }
                if ($m->getToolName() !== '') {
                    $entry['name'] = $m->getToolName();
                }
            }
            $out[] = $entry;
        }
        return $out;
    }

    public function describeChat(McpChat $chat): array
    {
        $summary = $this->summarize($chat);
        $summary['messages'] = array_map(static function (McpChatMessage $m) {
            return [
                'id'           => (int) $m->getId(),
                'role'         => $m->getRole(),
                'content'      => $m->getContent(),
                'tool_calls'   => $m->getToolCalls() !== '' ? json_decode($m->getToolCalls(), true) : null,
                'tool_call_id' => $m->getToolCallId() ?: null,
                'tool_name'    => $m->getToolName() ?: null,
                'created_at'   => (int) $m->getCreatedAt(),
            ];
        }, $chat->getMessages()->toArray());
        return $summary;
    }

    private function summarize(McpChat $chat): array
    {
        return [
            'id'         => (int) $chat->getId(),
            'title'      => $chat->getTitle(),
            'model'      => $chat->getModel(),
            'created_at' => (int) $chat->getCreatedAt(),
            'updated_at' => (int) $chat->getUpdatedAt(),
        ];
    }

    private function derivedTitle(string $userMessage): string
    {
        $clean = preg_replace('/\s+/', ' ', trim($userMessage));
        $clean = $clean === null ? '' : $clean;
        if ($clean === '') {
            return 'Chat';
        }
        return mb_substr($clean, 0, 60);
    }
}
