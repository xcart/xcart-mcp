<?php

declare(strict_types=1);

namespace XC\MCP\Model;

use Doctrine\ORM\Mapping as ORM;

/**
 * One turn in an MCP chat. Roles follow the Ollama/OpenAI convention.
 *
 * @ORM\Entity (repositoryClass="XC\MCP\Model\Repo\McpChatMessage")
 * @ORM\Table  (name="mcp_chat_messages",
 *      indexes={ @ORM\Index (name="chat_idx", columns={"chat_id"}) }
 * )
 */
class McpChatMessage extends \XLite\Model\AEntity
{
    public const ROLE_SYSTEM    = 'system';
    public const ROLE_USER      = 'user';
    public const ROLE_ASSISTANT = 'assistant';
    public const ROLE_TOOL      = 'tool';

    /**
     * @var integer
     *
     * @ORM\Id
     * @ORM\GeneratedValue (strategy="AUTO")
     * @ORM\Column         (type="integer", options={ "unsigned": true })
     */
    protected $id;

    /**
     * @var McpChat
     *
     * @ORM\ManyToOne  (targetEntity="XC\MCP\Model\McpChat", inversedBy="messages")
     * @ORM\JoinColumn (name="chat_id", referencedColumnName="id", onDelete="CASCADE")
     */
    protected $chat;

    /**
     * One of: system, user, assistant, tool.
     *
     * @var string
     *
     * @ORM\Column (type="string", length=16)
     */
    protected $role = self::ROLE_USER;

    /**
     * @var string Message body text.
     *
     * @ORM\Column (type="text")
     */
    protected $content = '';

    /**
     * Assistant tool_calls payload (when role=assistant and the model emitted tool calls).
     * Stored as JSON-encoded string.
     *
     * @var string
     *
     * @ORM\Column (type="text")
     */
    protected $tool_calls = '';

    /**
     * Identifier of the tool_call this message answers (only for role=tool).
     *
     * @var string
     *
     * @ORM\Column (type="string", length=128)
     */
    protected $tool_call_id = '';

    /**
     * Tool name (only for role=tool).
     *
     * @var string
     *
     * @ORM\Column (type="string", length=128)
     */
    protected $tool_name = '';

    /**
     * @var integer Unix timestamp.
     *
     * @ORM\Column (type="integer", options={ "unsigned": true })
     */
    protected $created_at = 0;
}
