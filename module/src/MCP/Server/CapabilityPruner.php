<?php

declare(strict_types=1);

namespace XC\MCP\MCP\Server;

use XC\MCP\MCP\Tools\BrandTools;
use XC\MCP\MCP\Tools\VehicleTools;

/**
 * Pure decision of which capabilities must be hidden from the registry given
 * the runtime environment (dangerous-tools setting + availability of optional
 * QSL modules).
 *
 * Extracted from ServerFactory so the safety logic is unit-testable without
 * building a full MCP Server (this module ships without an X-Cart runtime).
 * The tool names to hide are derived from ToolCatalog, never duplicated as
 * literals.
 */
final class CapabilityPruner
{
    /**
     * Optional QSL groups. Each maps to the Doctrine entity that proves the
     * module is installed, the tool class whose tools depend on it, and the
     * resource URIs to hide when the module is absent.
     *
     * @var array<string, array{entity: string, toolClass: class-string, resources: list<string>}>
     */
    private const OPTIONAL_GROUPS = [
        'vehicle' => [
            'entity' => 'QSL\Make\Model\Level1',
            'toolClass' => VehicleTools::class,
            'resources' => ['xcart://vehicles/stats', 'xcart://vehicles/makes'],
        ],
        'brand' => [
            'entity' => 'QSL\ShopByBrand\Model\Brand',
            'toolClass' => BrandTools::class,
            'resources' => ['xcart://brands/list'],
        ],
    ];

    public function __construct(
        private readonly ToolCatalog $catalog,
    ) {}

    /**
     * Optional groups mapped to the entity that proves availability.
     *
     * @return array<string, string> group key => entity class-string
     */
    public function optionalGroupEntities(): array
    {
        $entities = [];
        foreach (self::OPTIONAL_GROUPS as $key => $group) {
            $entities[$key] = $group['entity'];
        }

        return $entities;
    }

    /**
     * Compute the tools and resources to unregister.
     *
     * @param array<string, bool> $groupAvailable group key => is the entity available?
     *
     * @return array{tools: list<string>, resources: list<string>}
     */
    public function capabilitiesToHide(bool $dangerousToolsEnabled, array $groupAvailable): array
    {
        $tools = [];
        $resources = [];

        // Dangerous tools are hidden unless explicitly enabled.
        if (!$dangerousToolsEnabled) {
            foreach ($this->catalog->getDangerousToolNames() as $tool) {
                $tools[] = $tool;
            }
        }

        // Optional-module tools/resources are hidden when the module is absent.
        foreach (self::OPTIONAL_GROUPS as $key => $group) {
            if (($groupAvailable[$key] ?? false) === true) {
                continue;
            }

            foreach ($this->catalog->getToolNamesForClass($group['toolClass']) as $tool) {
                $tools[] = $tool;
            }
            foreach ($group['resources'] as $uri) {
                $resources[] = $uri;
            }
        }

        return [
            'tools' => array_values(array_unique($tools)),
            'resources' => array_values(array_unique($resources)),
        ];
    }
}
