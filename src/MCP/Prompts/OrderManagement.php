<?php

declare(strict_types=1);

namespace XC\MCP\MCP\Prompts;

use Mcp\Capability\Attribute\McpPrompt;

class OrderManagement
{
    #[McpPrompt(
        name: 'process_pending_orders',
        title: 'Process Pending Orders',
        description: 'Review all pending orders and suggest actions for each'
    )]
    public function processPendingOrders(): array
    {
        return [
            [
                'role' => 'user',
                'content' => implode("\n", [
                    'Review all pending orders and suggest actions.',
                    '',
                    'Steps:',
                    '1. Read pending orders (xcart://orders/pending)',
                    '2. For each order, read full details using xcart://orders/{orderId}',
                    '3. Check product availability for order items',
                    '',
                    'For each order, determine:',
                    '- Can it be processed? (payment received, items in stock)',
                    '- What action is needed? (ship, contact customer, wait for payment, refund)',
                    '- Are there any issues? (out of stock items, suspicious address)',
                    '',
                    'Present as a table with columns:',
                    'Order # | Date | Total | Customer | Status | Recommended Action',
                ]),
            ],
        ];
    }

    #[McpPrompt(
        name: 'daily_orders_review',
        title: 'Daily Orders Review',
        description: 'Daily orders summary: new, processed, shipped, issues'
    )]
    public function dailyOrdersReview(): array
    {
        return [
            [
                'role' => 'user',
                'content' => implode("\n", [
                    'Provide a daily orders review.',
                    '',
                    'Steps:',
                    '1. Search orders for the last 24 hours using order_search tool',
                    '2. Get today\'s sales summary from xcart://store/dashboard',
                    '',
                    'Report should include:',
                    '- Total orders today vs yesterday',
                    '- Revenue today vs yesterday',
                    '- Breakdown by status (new, processing, shipped)',
                    '- Any unusual patterns (large orders, refunds, multiple orders from same customer)',
                    '- Average order value comparison',
                    '- Top products sold today',
                ]),
            ],
        ];
    }

    #[McpPrompt(
        name: 'find_problem_orders',
        title: 'Find Problem Orders',
        description: 'Find orders with potential problems: stuck, unpaid, returns'
    )]
    public function findProblemOrders(): array
    {
        return [
            [
                'role' => 'user',
                'content' => implode("\n", [
                    'Find orders that may have problems.',
                    '',
                    'Look for:',
                    '1. Orders stuck in "Pending" payment for more than 24 hours',
                    '2. Orders stuck in "Processing" shipping for more than 3 days',
                    '3. Orders with "Refunded" status in the last 7 days',
                    '4. Orders with notes indicating issues',
                    '',
                    'Use order_search tool with appropriate filters.',
                    '',
                    'For each problem order, suggest a resolution:',
                    '- Cancel (if unpaid for too long)',
                    '- Contact customer (if stuck in processing)',
                    '- Review refund (if recently refunded)',
                ]),
            ],
        ];
    }
}
