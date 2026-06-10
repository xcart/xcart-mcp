<?php

declare(strict_types=1);

namespace XC\MCP\MCP\Prompts;

use Mcp\Capability\Attribute\McpPrompt;

class StoreAnalysis
{
    #[McpPrompt(
        name: 'analyze_store',
        title: 'Analyze Store',
        description: 'Comprehensive store health analysis: sales trends, inventory issues, pending orders'
    )]
    public function analyzeStore(): array
    {
        return [
            [
                'role' => 'user',
                'content' => implode("\n", [
                    'Perform a comprehensive analysis of the X-Cart store.',
                    '',
                    'Steps:',
                    '1. Read store dashboard (xcart://store/dashboard) for overall metrics',
                    '2. Read order statistics (xcart://orders/stats) for sales trends',
                    '3. Read product statistics (xcart://products/stats) for catalog health',
                    '4. Check low stock products (xcart://products/low-stock) for inventory issues',
                    '5. Check pending orders (xcart://orders/pending) for orders needing attention',
                    '',
                    'Provide a structured report with:',
                    '- Executive summary (2-3 sentences)',
                    '- Sales performance (today vs yesterday, this month vs last month)',
                    '- Inventory alerts (out of stock, low stock)',
                    '- Orders requiring attention',
                    '- Recommendations (top 3 actionable items)',
                ]),
            ],
        ];
    }

    #[McpPrompt(
        name: 'analyze_inventory',
        title: 'Analyze Inventory',
        description: 'Inventory analysis: low stock, out of stock, overstock, reorder recommendations'
    )]
    public function analyzeInventory(): array
    {
        return [
            [
                'role' => 'user',
                'content' => implode("\n", [
                    'Analyze the store inventory and provide recommendations.',
                    '',
                    'Steps:',
                    '1. Get inventory report using report_inventory tool',
                    '2. Read low stock products (xcart://products/low-stock)',
                    '3. Get top selling products using report_top_products tool',
                    '',
                    'Provide:',
                    '- Products that need immediate restocking (out of stock + high demand)',
                    '- Products with dangerously low stock (< 5 units)',
                    '- Potential overstock (high quantity + low sales)',
                    '- Total stock value assessment',
                    '- Reorder priority list (sorted by urgency)',
                ]),
            ],
        ];
    }
}
