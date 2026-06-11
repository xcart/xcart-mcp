<?php

declare(strict_types=1);

namespace XC\MCP\MCP\Server;

use Mcp\Capability\Attribute\McpTool;

class McpToolRegistry
{
    /**
     * Scan tool classes and return metadata for the settings page.
     *
     * @return list<array{name: string, label: string, danger: bool}>
     */
    public function getToolDefinitions(): array
    {
        $toolClasses = [
            \XC\MCP\MCP\Tools\ProductTools::class,
            \XC\MCP\MCP\Tools\OrderTools::class,
            \XC\MCP\MCP\Tools\CategoryTools::class,
            \XC\MCP\MCP\Tools\SearchTools::class,
            \XC\MCP\MCP\Tools\ReportTools::class,
            \XC\MCP\MCP\Tools\VehicleTools::class,
            \XC\MCP\MCP\Tools\BrandTools::class,
            \XC\MCP\MCP\Tools\AsapTools::class,
            \XC\MCP\MCP\Tools\Turn14Tools::class,
            \XC\MCP\MCP\Tools\SemaDataTools::class,
        ];

        // Keep in sync with McpAuthorizer::DANGEROUS_TOOLS.
        $dangerousTools = [
            'product_delete',
            'product_bulk_update_prices',
            'vehicle_disable_all_then_enable',
        ];

        $tools = [];
        foreach ($toolClasses as $class) {
            $ref = new \ReflectionClass($class);
            foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                $attrs = $method->getAttributes(McpTool::class);
                if (empty($attrs)) {
                    continue;
                }
                $attr = $attrs[0]->newInstance();
                $name = $attr->name;
                $tools[] = [
                    'name' => $name,
                    'label' => $this->nameToLabel($name),
                    'danger' => in_array($name, $dangerousTools, true),
                ];
            }
        }

        return $tools;
    }

    private function nameToLabel(string $name): string
    {
        return ucwords(str_replace('_', ' ', $name));
    }
}
