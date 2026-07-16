<?php

declare(strict_types=1);

namespace XC\MCP\MCP\Server;

use Mcp\Capability\Attribute\McpTool;
use XC\MCP\MCP\Security\ToolAnnotation;
use XC\MCP\MCP\Tools\AsapTools;
use XC\MCP\MCP\Tools\BrandTools;
use XC\MCP\MCP\Tools\CategoryTools;
use XC\MCP\MCP\Tools\OrderTools;
use XC\MCP\MCP\Tools\ProductTools;
use XC\MCP\MCP\Tools\ReportTools;
use XC\MCP\MCP\Tools\SearchTools;
use XC\MCP\MCP\Tools\SemaDataTools;
use XC\MCP\MCP\Tools\Turn14Tools;
use XC\MCP\MCP\Tools\VehicleTools;

/**
 * Single source of truth for tool metadata derived from PHP attributes.
 *
 * Reflects the module's tool classes once and reads #[McpTool] together with
 * #[ToolAnnotation]. It exists so that "which tools are dangerous" and "which
 * tools belong to a class" live in exactly one place instead of being repeated
 * as string literals across ServerFactory, McpAuthorizer and McpToolRegistry.
 *
 * The reflection is XLite-free: the tool classes' XLite `use` imports stay lazy
 * (never resolved), only the McpTool / ToolAnnotation attribute classes are
 * instantiated. That keeps this catalog usable even when optional QSL modules
 * are absent and makes it unit-testable without an X-Cart runtime.
 */
final class ToolCatalog
{
    /** Tool classes scanned for #[McpTool] methods. */
    private const TOOL_CLASSES = [
        ProductTools::class,
        OrderTools::class,
        CategoryTools::class,
        SearchTools::class,
        ReportTools::class,
        VehicleTools::class,
        BrandTools::class,
        AsapTools::class,
        Turn14Tools::class,
        SemaDataTools::class,
    ];

    /** @var array<class-string, list<string>>|null */
    private ?array $namesByClass = null;

    /** @var list<string>|null */
    private ?array $dangerous = null;

    /**
     * @return list<string> All registered tool names, in declaration order.
     */
    public function getAllToolNames(): array
    {
        $all = [];
        foreach ($this->namesByClass() as $names) {
            foreach ($names as $name) {
                $all[] = $name;
            }
        }

        return $all;
    }

    /**
     * Tool names declared in the given tool class.
     *
     * @param class-string $class
     *
     * @return list<string>
     */
    public function getToolNamesForClass(string $class): array
    {
        return $this->namesByClass()[$class] ?? [];
    }

    /**
     * Names of tools flagged destructive via #[ToolAnnotation(destructiveHint: true)].
     * This is the single source of "danger" for the whole module.
     *
     * @return list<string>
     */
    public function getDangerousToolNames(): array
    {
        if ($this->dangerous === null) {
            $this->scan();
        }

        return $this->dangerous;
    }

    public function isDangerous(string $toolName): bool
    {
        return in_array($toolName, $this->getDangerousToolNames(), true);
    }

    /**
     * @return array<class-string, list<string>>
     */
    private function namesByClass(): array
    {
        if ($this->namesByClass === null) {
            $this->scan();
        }

        return $this->namesByClass;
    }

    private function scan(): void
    {
        $namesByClass = [];
        $dangerous = [];

        foreach (self::TOOL_CLASSES as $class) {
            $names = [];
            $ref = new \ReflectionClass($class);

            foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                $toolAttrs = $method->getAttributes(McpTool::class);
                if ($toolAttrs === []) {
                    continue;
                }

                $name = $toolAttrs[0]->newInstance()->name ?? $method->getName();
                $names[] = $name;

                foreach ($method->getAttributes(ToolAnnotation::class) as $annotation) {
                    if ($annotation->newInstance()->destructiveHint) {
                        $dangerous[] = $name;
                    }
                }
            }

            $namesByClass[$class] = $names;
        }

        $this->namesByClass = $namesByClass;
        $this->dangerous = array_values(array_unique($dangerous));
    }
}
