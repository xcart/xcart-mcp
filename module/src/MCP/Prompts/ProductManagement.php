<?php

declare(strict_types=1);

namespace XC\MCP\MCP\Prompts;

use Mcp\Capability\Attribute\McpPrompt;

class ProductManagement
{
    #[McpPrompt(
        name: 'optimize_catalog',
        title: 'Optimize Catalog',
        description: 'Find catalog issues: missing descriptions, images, pricing problems, empty categories'
    )]
    public function optimizeCatalog(): array
    {
        return [
            [
                'role' => 'user',
                'content' => implode("\n", [
                    'Analyze the product catalog for issues and optimization opportunities.',
                    '',
                    'Check for:',
                    '1. Products missing descriptions (search with product_search)',
                    '2. Products with price = 0 (use product_search with priceMax=0)',
                    '3. Disabled products that could be re-enabled',
                    '4. Empty categories (from xcart://categories/tree)',
                    '5. Products without categories',
                    '',
                    'Provide:',
                    '- List of issues found, grouped by type',
                    '- Priority ranking (high/medium/low impact on sales)',
                    '- Specific recommendations for each issue',
                    '- Estimated effort to fix (quick fix vs major work)',
                ]),
            ],
        ];
    }

    #[McpPrompt(
        name: 'pricing_analysis',
        title: 'Pricing Analysis',
        description: 'Analyze pricing: distribution, outliers, margin analysis'
    )]
    public function pricingAnalysis(): array
    {
        return [
            [
                'role' => 'user',
                'content' => implode("\n", [
                    'Analyze product pricing across the catalog.',
                    '',
                    'Steps:',
                    '1. Get product statistics (xcart://products/stats) for price ranges',
                    '2. Get top selling products (report_top_products)',
                    '3. Search for price outliers (very cheap or very expensive)',
                    '',
                    'Provide:',
                    '- Price distribution overview (ranges, average, median estimate)',
                    '- Price outliers (unusually high or low)',
                    '- Top sellers price analysis (are best sellers well-priced?)',
                    '- Categories with highest/lowest average prices',
                    '- Recommendations for pricing adjustments',
                ]),
            ],
        ];
    }

    #[McpPrompt(
        name: 'category_audit',
        title: 'Category Audit',
        description: 'Audit category structure: empty categories, unbalanced tree, orphan products'
    )]
    public function categoryAudit(): array
    {
        return [
            [
                'role' => 'user',
                'content' => implode("\n", [
                    'Audit the category structure.',
                    '',
                    'Steps:',
                    '1. Read category tree (xcart://categories/tree)',
                    '2. Identify empty categories (0 products)',
                    '3. Check for categories with very few products (< 3)',
                    '4. Check for categories with too many products (> 100)',
                    '',
                    'Report:',
                    '- Category tree overview (depth, total categories)',
                    '- Empty categories that should be removed or populated',
                    '- Overloaded categories that should be split',
                    '- Suggestions for category restructuring',
                ]),
            ],
        ];
    }
}
