<?php

declare(strict_types=1);

namespace XC\MCP\MCP\Server;

class McpToolRegistry
{
    public function __construct(
        private readonly ToolCatalog $catalog,
    ) {}

    /**
     * Return metadata for the settings page.
     *
     * Names and danger classification come from ToolCatalog (the single source
     * of truth derived from #[McpTool] / #[ToolAnnotation(destructiveHint)]), so
     * the settings page never carries its own copy of the dangerous-tools list.
     *
     * @return list<array{name: string, label: string, danger: bool}>
     */
    public function getToolDefinitions(): array
    {
        $tools = [];
        foreach ($this->catalog->getAllToolNames() as $name) {
            $tools[] = [
                'name' => $name,
                'label' => $this->nameToLabel($name),
                'danger' => $this->catalog->isDangerous($name),
            ];
        }

        return $tools;
    }

    private function nameToLabel(string $name): string
    {
        return ucwords(str_replace('_', ' ', $name));
    }
}
