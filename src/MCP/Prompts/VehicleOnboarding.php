<?php

declare(strict_types=1);

namespace XC\MCP\MCP\Prompts;

use Mcp\Capability\Attribute\McpPrompt;

class VehicleOnboarding
{
    #[McpPrompt(
        name: 'vehicle_onboarding',
        title: 'Vehicle Onboarding',
        description: 'Guided workflow for setting up vehicles for a new client. Disables all vehicles, then enables only the specified makes and year range.'
    )]
    public function vehicleOnboarding(?string $makes = null, ?int $yearFrom = null, ?int $yearTo = null): array
    {
        $intro = [
            'Help me set up vehicles for a new client in the store.',
        ];

        if ($makes) {
            $intro[] = '';
            $intro[] = 'The client sells for these makes: ' . $makes;
        }
        if ($yearFrom || $yearTo) {
            $range = [];
            if ($yearFrom) {
                $range[] = 'from ' . $yearFrom;
            }
            if ($yearTo) {
                $range[] = 'to ' . $yearTo;
            }
            $intro[] = 'Year range: ' . implode(' ', $range);
        }

        return [
            [
                'role' => 'user',
                'content' => implode("\n", array_merge($intro, [
                    '',
                    'Steps:',
                    '1. Run vehicle_stats to get the current state of vehicles in the store',
                    '   (total makes, enabled makes, total vehicles, enabled vehicles)',
                    '2. Confirm which Makes the client sells for — if not provided above, ask me',
                    '3. Use vehicle_disable_all_then_enable with the specified makes and year range',
                    '   to disable everything first, then enable only what the client needs',
                    '4. Run vehicle_stats again to verify the changes were applied correctly',
                    '5. Run vehicle_makes_list to review which makes are now enabled',
                    '',
                    'Guidelines:',
                    '- Always show the before/after stats so the client can confirm',
                    '- If a make name is ambiguous or not found, list similar makes and ask for clarification',
                    '- Default year range is current model year minus 20 years if not specified',
                    '- Report the final state clearly: how many makes enabled, how many vehicles active',
                ])),
            ],
        ];
    }

    #[McpPrompt(
        name: 'brand_audit',
        title: 'Brand Audit',
        description: 'Audit brands in the store. Find brands with missing logos, zero products, and recommend cleanup actions.'
    )]
    public function brandAudit(): array
    {
        return [
            [
                'role' => 'user',
                'content' => implode("\n", [
                    'Audit all brands in the store and recommend cleanup actions.',
                    '',
                    'Steps:',
                    '1. Use brand_list to get all brands in the store',
                    '2. Check which brands have logos and which are missing logos',
                    '3. Identify brands that have 0 products assigned',
                    '4. Recommend actions based on findings',
                    '',
                    'Provide:',
                    '- Total number of brands',
                    '- Brands with logos vs without logos (count and list)',
                    '- Brands with 0 products (candidates for disabling or removal)',
                    '- Brands that are disabled but have products (possible issue)',
                    '- Recommended actions:',
                    '  - Disable or remove brands with 0 products',
                    '  - Add missing logos for active brands with products',
                    '  - Re-enable brands that have products but are disabled',
                ]),
            ],
        ];
    }
}
