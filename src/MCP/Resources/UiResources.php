<?php

declare(strict_types=1);

namespace XC\MCP\MCP\Resources;

use Mcp\Capability\Attribute\McpResource;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Extension\Apps\McpApps;

/**
 * MCP Apps (io.modelcontextprotocol/ui) UI resources.
 *
 * These expose self-contained HTML applications under the ui:// scheme.
 * Supporting clients (e.g. Claude Desktop) render them in a sandboxed iframe;
 * the app talks back to the host over a postMessage JSON-RPC bridge and pulls
 * live data by calling regular MCP tools (e.g. report_sales).
 *
 * The descriptor carries the _meta.ui marker so clients recognise it as an
 * MCP App. The HTML itself ships inside the module (modules/XC/MCP/ui/).
 */
class UiResources
{
    #[McpResource(
        uri: 'ui://sales-dashboard',
        name: 'sales_dashboard_ui',
        title: 'Sales Dashboard (App)',
        description: 'Interactive sales dashboard rendered in supporting MCP clients. Pulls figures from the report_sales tool.',
        mimeType: McpApps::MIME_TYPE,
        meta: ['io.modelcontextprotocol/ui' => new \stdClass()],
    )]
    public function salesDashboard(): string
    {
        return $this->loadUi('sales-dashboard.html');
    }

    /**
     * Load a bundled UI file. Resolved against the X-Cart root so it works
     * whether this class runs from the module path or a compiled class cache.
     */
    private function loadUi(string $file): string
    {
        $candidates = [];
        if (defined('LC_DIR_ROOT')) {
            $candidates[] = \LC_DIR_ROOT . 'modules/XC/MCP/ui/' . $file;
        }
        $candidates[] = dirname(__DIR__, 3) . '/ui/' . $file;

        foreach ($candidates as $path) {
            if (is_file($path)) {
                $html = file_get_contents($path);
                if ($html !== false) {
                    return $html;
                }
            }
        }

        throw new ToolCallException("UI resource '{$file}' not found.");
    }
}
