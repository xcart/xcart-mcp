<?php

declare(strict_types=1);

namespace XC\MCP\Model;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A persisted MCP chat conversation belonging to an admin profile.
 *
 * @ORM\Entity (repositoryClass="XC\MCP\Model\Repo\McpChat")
 * @ORM\Table  (name="mcp_chats",
 *      indexes={
 *          @ORM\Index (name="profile_idx", columns={"profile_id"}),
 *          @ORM\Index (name="updated_idx", columns={"updated_at"})
 *      }
 * )
 */
class McpChat extends \XLite\Model\AEntity
{
    /**
     * @var integer
     *
     * @ORM\Id
     * @ORM\GeneratedValue (strategy="AUTO")
     * @ORM\Column         (type="integer", options={ "unsigned": true })
     */
    protected $id;

    /**
     * Profile that owns the chat. Stored as ID (no FK enforcement to avoid coupling with X-Cart's profile lifecycle).
     *
     * @var integer
     *
     * @ORM\Column (type="integer", options={ "unsigned": true })
     */
    protected $profile_id = 0;

    /**
     * @var string
     *
     * @ORM\Column (type="string", length=255)
     */
    protected $title = 'New chat';

    /**
     * Selected model tag for this chat (e.g. "gemma4:e4b").
     *
     * @var string
     *
     * @ORM\Column (type="string", length=128)
     */
    protected $model = '';

    /**
     * Optional system prompt override; empty means use module default.
     *
     * @var string
     *
     * @ORM\Column (type="text")
     */
    protected $system_prompt = '';

    /**
     * @var integer Unix timestamp
     *
     * @ORM\Column (type="integer", options={ "unsigned": true })
     */
    protected $created_at = 0;

    /**
     * @var integer Unix timestamp
     *
     * @ORM\Column (type="integer", options={ "unsigned": true })
     */
    protected $updated_at = 0;

    /**
     * @var Collection<int, McpChatMessage>
     *
     * @ORM\OneToMany (targetEntity="XC\MCP\Model\McpChatMessage", mappedBy="chat", cascade={"persist","remove"}, orphanRemoval=true)
     * @ORM\OrderBy   ({"id" = "ASC"})
     */
    protected $messages;

    public function __construct(array $data = [])
    {
        $this->messages = new ArrayCollection();
        parent::__construct($data);
    }

    public function getMessages(): Collection
    {
        return $this->messages;
    }
}
