<?php

declare(strict_types=1);

namespace XC\MCP\MCP\Security;

/**
 * MCP tool annotations per spec 2025-06-18.
 *
 * Applied alongside #[McpTool] to declare tool behavior hints.
 * Used by McpAuthorizer to enforce access control based on tool characteristics.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
class ToolAnnotation
{
    public function __construct(
        /** Tool does not modify any state. */
        public readonly bool $readOnlyHint = false,

        /** Tool performs destructive operations (delete, bulk modify). */
        public readonly bool $destructiveHint = false,

        /** Tool may interact with external services or the "real world". */
        public readonly bool $openWorldHint = false,

        /** Tool is idempotent -- calling it multiple times has the same effect. */
        public readonly bool $idempotentHint = false,
    ) {}
}
