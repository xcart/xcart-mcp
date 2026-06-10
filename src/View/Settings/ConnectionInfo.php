<?php

declare(strict_types=1);

namespace XC\MCP\View\Settings;

use XCart\Extender\Mapping\ListChild;

/**
 * Connection / "Copy as prompt" block shown at the top of the MCP AI Integration
 * addon settings page (above the auto-generated options form).
 *
 * @ListChild (list="crud.modulesettings.header", zone="admin", weight="0")
 */
class ConnectionInfo extends \XLite\View\AView
{
    /**
     * @return string
     */
    protected function getDefaultTemplate()
    {
        return 'modules/XC/MCP/settings/connection_info.twig';
    }

    /**
     * Only render on this module's settings page.
     *
     * @return bool
     */
    protected function isVisible()
    {
        $controller = \XLite::getController();

        return parent::isVisible()
            && method_exists($controller, 'getModule')
            && $controller->getModule() === 'XC-MCP';
    }

    /**
     * Current static API key, read straight from the DB rather than the cached
     * Config tree — the tree can lag behind the stored value (the key is set by
     * a raw UPDATE on install), which would otherwise render an empty key and
     * break the Test-connection button with "Missing Authorization header".
     *
     * @return string
     */
    public function getMcpApiKey(): string
    {
        try {
            $option = \XLite\Core\Database::getRepo('XLite\Model\Config')
                ->findOneBy(['name' => 'mcp_api_key', 'category' => 'XC\MCP']);

            return $option ? (string) $option->getValue() : '';
        } catch (\Throwable) {
            return '';
        }
    }
}
