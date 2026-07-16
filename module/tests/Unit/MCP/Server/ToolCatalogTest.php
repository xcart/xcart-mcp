<?php

declare(strict_types=1);

namespace Tests\Unit\MCP\Server;

use PHPUnit\Framework\TestCase;
use XC\MCP\MCP\Server\ToolCatalog;
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

final class ToolCatalogTest extends TestCase
{
    private ToolCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new ToolCatalog();
    }

    public function testTotalToolCountIsFiftyOne(): void
    {
        $this->assertCount(51, $this->catalog->getAllToolNames());
    }

    public function testAllToolNamesAreUnique(): void
    {
        $names = $this->catalog->getAllToolNames();
        $this->assertSame($names, array_values(array_unique($names)));
    }

    public function testDangerousToolNamesAreExactlyTheThree(): void
    {
        $dangerous = $this->catalog->getDangerousToolNames();

        $expected = [
            'product_delete',
            'product_bulk_update_prices',
            'vehicle_disable_all_then_enable',
        ];

        sort($dangerous);
        sort($expected);
        $this->assertSame($expected, $dangerous);
    }

    public function testIsDangerous(): void
    {
        $this->assertTrue($this->catalog->isDangerous('product_delete'));
        $this->assertTrue($this->catalog->isDangerous('product_bulk_update_prices'));
        $this->assertTrue($this->catalog->isDangerous('vehicle_disable_all_then_enable'));
        $this->assertFalse($this->catalog->isDangerous('product_search'));
        $this->assertFalse($this->catalog->isDangerous('nonexistent_tool'));
    }

    public function testVehicleToolClassHasElevenToolsIncludingDisableAll(): void
    {
        $names = $this->catalog->getToolNamesForClass(VehicleTools::class);

        $this->assertCount(11, $names);
        $this->assertContains('vehicle_disable_all_then_enable', $names);
    }

    /**
     * @return array<string, array{class-string, int}>
     */
    public static function toolClassCounts(): array
    {
        return [
            'ProductTools'  => [ProductTools::class, 6],
            'OrderTools'    => [OrderTools::class, 4],
            'CategoryTools' => [CategoryTools::class, 4],
            'SearchTools'   => [SearchTools::class, 1],
            'ReportTools'   => [ReportTools::class, 3],
            'VehicleTools'  => [VehicleTools::class, 11],
            'BrandTools'    => [BrandTools::class, 5],
            'AsapTools'     => [AsapTools::class, 6],
            'Turn14Tools'   => [Turn14Tools::class, 5],
            'SemaDataTools' => [SemaDataTools::class, 6],
        ];
    }

    /**
     * @param class-string $class
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('toolClassCounts')]
    public function testToolCountPerClass(string $class, int $expected): void
    {
        $this->assertCount($expected, $this->catalog->getToolNamesForClass($class));
    }

    public function testPerClassCountsSumToTotal(): void
    {
        $sum = 0;
        foreach (self::toolClassCounts() as [$class, $expected]) {
            $sum += \count($this->catalog->getToolNamesForClass($class));
        }
        $this->assertSame(51, $sum);
    }
}
