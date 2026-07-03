<?php
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/OSL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace MercadoPago\Service\Configuration;

/**
 * Template Renderer Service
 * Handles Twig template rendering with proper initialization
 * Follows SOLID: Single Responsibility - only handles template rendering
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class TemplateRenderer
{
    /**
     * @var \mercadopago
     */
    private $module;

    /**
     * @var \Twig\Environment|null
     */
    private $twig;

    /**
     * Constructor
     *
     * @param \mercadopago $module
     */
    public function __construct(\mercadopago $module)
    {
        $this->module = $module;
        $this->twig = $this->initializeTwig();
    }

    /**
     * Initialize Twig environment
     *
     * Twig is a PrestaShop core dependency (not bundled by this module), so its
     * namespace must stay excluded from PHP-Scoper (see scoper.inc.php) — Twig
     * classes are exposed globally at runtime by PS itself.
     *
     * We always use our own Twig instance pointing at views/ instead of the PS
     * container Twig: the container Twig has no loader path for our templates, and
     * our templates only need the l() translation function we register below.
     *
     * @return \Twig\Environment|null
     */
    private function initializeTwig(): ?\Twig\Environment
    {
        try {
            $templatePath = $this->module->getLocalPath() . 'views/';

            if (!is_dir($templatePath)) {
                return null;
            }

            $loader = new \Twig\Loader\FilesystemLoader($templatePath);
            $twig = new \Twig\Environment($loader, [
                'cache' => false,
                'auto_reload' => true,
            ]);

            $module = $this->module;
            $twig->addFunction(new \Twig\TwigFunction('l', function (string $string) use ($module): string {
                return $module->l($string);
            }));

            return $twig;
        } catch (\Throwable $e) {
            error_log('Twig initialization error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Render a Twig template
     *
     * @param string $template
     * @param array $variables
     * @return string
     */
    public function render(string $template, array $variables = []): string
    {
        if (!$this->twig) {
            // Return a harmless comment to avoid Smarty fatal due to empty content
            return '<!-- Template renderer not available -->';
        }

        try {
            $rendered = $this->twig->render($template, $variables);
            if ($rendered === '') {
                return '<!-- Template rendered empty -->';
            }

            return $rendered;
        } catch (\Throwable $e) {
            // Return a harmless comment to avoid Smarty fatal due to empty content
            return '<!-- Template rendering error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . ' -->';
        }
    }
}
