<?php

declare(strict_types=1);

namespace XC\MCP\MCP\Prompts;

use Mcp\Capability\Attribute\McpPrompt;

class CategoryMapping
{
    #[McpPrompt(
        name: 'map_asap_categories',
        title: 'Map ASAP Categories',
        description: 'Guided workflow to map ASAP Network categories to X-Cart categories. Handles duplication, suggests mappings, and applies them in bulk.'
    )]
    public function mapAsapCategories(): array
    {
        return [
            [
                'role' => 'user',
                'content' => implode("\n", [
                    'Help me map ASAP Network supplier categories to X-Cart store categories.',
                    '',
                    'Background: ASAP Network has ~5000 categories with heavy duplication — the same subcategory',
                    '(e.g. "Intercooler Kit") appears under multiple root categories (e.g. "Heating & Cooling" and "Performance").',
                    'These duplicates should all map to the same X-Cart category.',
                    '',
                    'Steps:',
                    '1. Read ASAP mapping summary (xcart://asap/mapping-summary) to understand current state',
                    '2. Run asap_deduplicate_report to identify duplicate categories',
                    '3. Read X-Cart category tree (xcart://categories/tree) to see existing store categories',
                    '4. Run asap_auto_map with apply=false to preview automatic name matches',
                    '5. Review the proposals — check for false positives in fuzzy matches',
                    '6. Apply confirmed mappings using asap_bulk_map',
                    '7. For remaining unmapped categories, suggest which X-Cart categories they should map to',
                    '   (or whether new X-Cart categories should be created)',
                    '',
                    'Guidelines:',
                    '- When duplicates exist, map ALL instances to the same X-Cart category',
                    '- Prefer mapping to existing X-Cart categories over creating new ones',
                    '- Group ASAP subcategories logically — automotive parts taxonomy matters',
                    '- Report progress: how many mapped, how many remaining, what needs manual review',
                ]),
            ],
        ];
    }

    #[McpPrompt(
        name: 'map_sema_categories',
        title: 'Map SEMA Data Categories',
        description: 'Guided workflow to map SEMA Data categories to X-Cart categories. Suggests name matches and applies them in bulk.'
    )]
    public function mapSemaCategories(): array
    {
        return [
            [
                'role' => 'user',
                'content' => implode("\n", [
                    'Help me map SEMA Data supplier categories to X-Cart store categories.',
                    '',
                    'Background: SEMA Data has ~5800 categories. Some subcategories repeat under multiple',
                    'root categories; those duplicates should all map to the same X-Cart category.',
                    '',
                    'Steps:',
                    '1. Read SEMA mapping summary (xcart://sema/mapping-summary) to understand current state',
                    '2. Run sema_deduplicate_report to identify categories that repeat across roots',
                    '3. Read X-Cart category tree (xcart://categories/tree) to see existing store categories',
                    '4. Run sema_auto_map with apply=false to preview automatic name matches',
                    '5. Review the proposals — check for false positives in fuzzy matches',
                    '6. Apply confirmed mappings using sema_bulk_map',
                    '7. For remaining unmapped categories, suggest which X-Cart categories they should map to',
                    '   (or whether new X-Cart categories should be created)',
                    '',
                    'Guidelines:',
                    '- When duplicates exist, map ALL instances to the same X-Cart category',
                    '- Prefer mapping to existing X-Cart categories over creating new ones',
                    '- Group SEMA subcategories logically — automotive parts taxonomy matters',
                    '- Report progress: how many mapped, how many remaining, what needs manual review',
                ]),
            ],
        ];
    }

    #[McpPrompt(
        name: 'category_audit_suppliers',
        title: 'Audit Supplier Categories',
        description: 'Audit category mapping across all suppliers (Turn14, ASAP, SEMA). Find inconsistencies, unmapped categories, and suggest unified taxonomy.'
    )]
    public function categoryAuditSuppliers(): array
    {
        return [
            [
                'role' => 'user',
                'content' => implode("\n", [
                    'Audit category mappings across all supplier integrations and X-Cart store categories.',
                    '',
                    'Steps:',
                    '1. Read X-Cart category tree (xcart://categories/tree)',
                    '2. Read ASAP mapping summary (xcart://asap/mapping-summary) if available',
                    '3. Read Turn14 mapping summary (xcart://turn14/mapping-summary) if available',
                    '4. Read SEMA Data mapping summary (xcart://sema/mapping-summary) if available',
                    '5. Compare mapping completeness across suppliers',
                    '',
                    'Provide:',
                    '- Overall mapping progress per supplier (% mapped)',
                    '- X-Cart categories that have mappings from multiple suppliers (good — unified)',
                    '- X-Cart categories with products but no supplier mappings (orphaned)',
                    '- Supplier categories mapped to different X-Cart categories for same product type (conflict)',
                    '- Recommendations for unifying the category taxonomy',
                ]),
            ],
        ];
    }
}
