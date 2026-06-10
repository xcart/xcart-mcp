<?php

declare(strict_types=1);

namespace Tests\Unit\MCP\Prompts;

use PHPUnit\Framework\TestCase;
use XC\MCP\MCP\Prompts\StoreAnalysis;
use XC\MCP\MCP\Prompts\OrderManagement;
use XC\MCP\MCP\Prompts\ProductManagement;

class PromptsTest extends TestCase
{
    // ── StoreAnalysis ──

    public function testAnalyzeStoreReturnsMessages(): void
    {
        $prompts = new StoreAnalysis();
        $messages = $prompts->analyzeStore();

        $this->assertIsArray($messages);
        $this->assertCount(1, $messages);
        $this->assertSame('user', $messages[0]['role']);
        $this->assertStringContainsString('comprehensive analysis', $messages[0]['content']);
        $this->assertStringContainsString('xcart://store/dashboard', $messages[0]['content']);
        $this->assertStringContainsString('xcart://orders/stats', $messages[0]['content']);
        $this->assertStringContainsString('xcart://products/stats', $messages[0]['content']);
        $this->assertStringContainsString('catalog health', $messages[0]['content']);
        $this->assertStringContainsString('Recommendations', $messages[0]['content']);
    }

    public function testAnalyzeInventoryReturnsMessages(): void
    {
        $prompts = new StoreAnalysis();
        $messages = $prompts->analyzeInventory();

        $this->assertIsArray($messages);
        $this->assertCount(1, $messages);
        $this->assertSame('user', $messages[0]['role']);
        $this->assertStringContainsString('inventory', $messages[0]['content']);
        $this->assertStringContainsString('report_inventory', $messages[0]['content']);
        $this->assertStringContainsString('xcart://products/low-stock', $messages[0]['content']);
        $this->assertStringContainsString('Reorder priority list', $messages[0]['content']);
    }

    // ── OrderManagement ──

    public function testProcessPendingOrdersReturnsMessages(): void
    {
        $prompts = new OrderManagement();
        $messages = $prompts->processPendingOrders();

        $this->assertIsArray($messages);
        $this->assertCount(1, $messages);
        $this->assertSame('user', $messages[0]['role']);
        $this->assertStringContainsString('pending orders', $messages[0]['content']);
        $this->assertStringContainsString('xcart://orders/pending', $messages[0]['content']);
        $this->assertStringContainsString('Recommended Action', $messages[0]['content']);
    }

    public function testDailyOrdersReviewReturnsMessages(): void
    {
        $prompts = new OrderManagement();
        $messages = $prompts->dailyOrdersReview();

        $this->assertIsArray($messages);
        $this->assertCount(1, $messages);
        $this->assertSame('user', $messages[0]['role']);
        $this->assertStringContainsString('daily orders review', $messages[0]['content']);
        $this->assertStringContainsString('order_search', $messages[0]['content']);
        $this->assertStringContainsString('xcart://store/dashboard', $messages[0]['content']);
        $this->assertStringContainsString('Average order value', $messages[0]['content']);
    }

    public function testFindProblemOrdersReturnsMessages(): void
    {
        $prompts = new OrderManagement();
        $messages = $prompts->findProblemOrders();

        $this->assertIsArray($messages);
        $this->assertCount(1, $messages);
        $this->assertSame('user', $messages[0]['role']);
        $this->assertStringContainsString('problems', $messages[0]['content']);
        $this->assertStringContainsString('Pending', $messages[0]['content']);
        $this->assertStringContainsString('Refunded', $messages[0]['content']);
        $this->assertStringContainsString('order_search', $messages[0]['content']);
    }

    // ── ProductManagement ──

    public function testOptimizeCatalogReturnsMessages(): void
    {
        $prompts = new ProductManagement();
        $messages = $prompts->optimizeCatalog();

        $this->assertIsArray($messages);
        $this->assertCount(1, $messages);
        $this->assertSame('user', $messages[0]['role']);
        $this->assertStringContainsString('catalog', $messages[0]['content']);
        $this->assertStringContainsString('missing descriptions', $messages[0]['content']);
        $this->assertStringContainsString('Empty categories', $messages[0]['content']);
        $this->assertStringContainsString('xcart://categories/tree', $messages[0]['content']);
    }

    public function testPricingAnalysisReturnsMessages(): void
    {
        $prompts = new ProductManagement();
        $messages = $prompts->pricingAnalysis();

        $this->assertIsArray($messages);
        $this->assertCount(1, $messages);
        $this->assertSame('user', $messages[0]['role']);
        $this->assertStringContainsString('pricing', $messages[0]['content']);
        $this->assertStringContainsString('xcart://products/stats', $messages[0]['content']);
        $this->assertStringContainsString('report_top_products', $messages[0]['content']);
        $this->assertStringContainsString('outliers', $messages[0]['content']);
    }

    public function testCategoryAuditReturnsMessages(): void
    {
        $prompts = new ProductManagement();
        $messages = $prompts->categoryAudit();

        $this->assertIsArray($messages);
        $this->assertCount(1, $messages);
        $this->assertSame('user', $messages[0]['role']);
        $this->assertStringContainsString('category structure', $messages[0]['content']);
        $this->assertStringContainsString('xcart://categories/tree', $messages[0]['content']);
        $this->assertStringContainsString('empty categories', strtolower($messages[0]['content']));
    }
}
