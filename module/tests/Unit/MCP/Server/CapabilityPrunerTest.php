<?php

declare(strict_types=1);

namespace Tests\Unit\MCP\Server;

use PHPUnit\Framework\TestCase;
use XC\MCP\MCP\Server\CapabilityPruner;
use XC\MCP\MCP\Server\ToolCatalog;
use XC\MCP\MCP\Tools\VehicleTools;

/**
 * Safety logic: which tools/resources must be hidden from the registry given
 * the dangerous-tools setting and optional-module availability.
 */
final class CapabilityPrunerTest extends TestCase
{
    private ToolCatalog $catalog;
    private CapabilityPruner $pruner;

    private const DANGEROUS = [
        'product_delete',
        'product_bulk_update_prices',
        'vehicle_disable_all_then_enable',
    ];

    private const VEHICLE_RESOURCES = ['xcart://vehicles/stats', 'xcart://vehicles/makes'];
    private const BRAND_RESOURCE = 'xcart://brands/list';

    protected function setUp(): void
    {
        $this->catalog = new ToolCatalog();
        $this->pruner = new CapabilityPruner($this->catalog);
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     */
    private function assertSameSet(array $a, array $b, string $message = ''): void
    {
        sort($a);
        sort($b);
        $this->assertSame($a, $b, $message);
    }

    public function testOptionalGroupEntities(): void
    {
        $this->assertSame(
            [
                'vehicle' => 'QSL\Make\Model\Level1',
                'brand' => 'QSL\ShopByBrand\Model\Brand',
            ],
            $this->pruner->optionalGroupEntities()
        );
    }

    public function testDangerousOffBothGroupsAvailableHidesOnlyDangerousTools(): void
    {
        $hidden = $this->pruner->capabilitiesToHide(false, ['vehicle' => true, 'brand' => true]);

        $this->assertSameSet(self::DANGEROUS, $hidden['tools']);
        $this->assertSame([], $hidden['resources']);
    }

    public function testDangerousOnBothGroupsAvailableHidesNothing(): void
    {
        $hidden = $this->pruner->capabilitiesToHide(true, ['vehicle' => true, 'brand' => true]);

        $this->assertSame([], $hidden['tools']);
        $this->assertSame([], $hidden['resources']);
    }

    public function testDangerousOnVehicleUnavailableHidesVehicleOnly(): void
    {
        $hidden = $this->pruner->capabilitiesToHide(true, ['vehicle' => false, 'brand' => true]);

        $vehicleTools = $this->catalog->getToolNamesForClass(VehicleTools::class);
        $this->assertCount(11, $vehicleTools);
        $this->assertSameSet($vehicleTools, $hidden['tools']);
        $this->assertSameSet(self::VEHICLE_RESOURCES, $hidden['resources']);

        // Brand tools must NOT be hidden.
        $this->assertNotContains('brand_list', $hidden['tools']);
        $this->assertNotContains(self::BRAND_RESOURCE, $hidden['resources']);
    }

    public function testDangerousOnBrandUnavailableHidesBrandOnly(): void
    {
        $hidden = $this->pruner->capabilitiesToHide(true, ['vehicle' => true, 'brand' => false]);

        $brandTools = $this->catalog->getToolNamesForClass(\XC\MCP\MCP\Tools\BrandTools::class);
        $this->assertNotEmpty($brandTools);
        $this->assertSameSet($brandTools, $hidden['tools']);
        $this->assertSame([self::BRAND_RESOURCE], $hidden['resources']);
    }

    public function testDangerousOffVehicleUnavailableUnionsWithoutDuplicates(): void
    {
        $hidden = $this->pruner->capabilitiesToHide(false, ['vehicle' => false, 'brand' => true]);

        $vehicleTools = $this->catalog->getToolNamesForClass(VehicleTools::class);
        $expected = array_values(array_unique([...self::DANGEROUS, ...$vehicleTools]));

        $this->assertSameSet($expected, $hidden['tools']);

        // vehicle_disable_all_then_enable is BOTH dangerous and a vehicle tool:
        // it must appear exactly once.
        $occurrences = array_keys($hidden['tools'], 'vehicle_disable_all_then_enable', true);
        $this->assertCount(1, $occurrences);

        // No duplicates at all.
        $this->assertSame($hidden['tools'], array_values(array_unique($hidden['tools'])));

        $this->assertSameSet(self::VEHICLE_RESOURCES, $hidden['resources']);
    }
}
