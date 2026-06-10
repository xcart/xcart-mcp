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
}
