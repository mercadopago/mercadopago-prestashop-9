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
     * @var mixed
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
     * @return mixed
     */
    private function initializeTwig()
    {
        try {
            // Try to use PrestaShop's Twig container first
            $context = \Context::getContext();
            if ($context && isset($context->controller)) {
                $controller = $context->controller;
                if (method_exists($controller, 'getContainer')) {
                    try {
                        $container = $controller->getContainer();
                        if ($container && $container->has('twig')) {
                            $twig = $container->get('twig');
                            // Register our translation function if PrestaShop's Twig doesn't have one.
                            // Twig 3's getFunction() returns null when the function is missing
                            // (it does not throw, unlike Twig 1/2), so check for null directly.
                            if ($twig->getFunction('l') === null) {
                                $module = $this->module;
                                $lFunction = new \Twig\TwigFunction('l', function (string $string) use ($module) {
                                    return $module->l($string);
                                });
                                $twig->addFunction($lFunction);
                            }
                            return $twig;
                        }
                    } catch (\Exception $e) {
                        // Container not available, continue to fallback
                    }
                }
            }

            // Fallback: Try to load Twig from PrestaShop vendor
            if (!class_exists('\Twig\Loader\FilesystemLoader') && !class_exists('Twig_Loader_Filesystem')) {
                $prestashopAutoload = _PS_ROOT_DIR_ . '/vendor/autoload.php';
                if (file_exists($prestashopAutoload) && !class_exists('\Twig\Loader\FilesystemLoader')) {
                    require_once $prestashopAutoload;
                }
                
                if (!class_exists('\Twig\Loader\FilesystemLoader') && !class_exists('Twig_Loader_Filesystem')) {
                    return null;
                }
            }

            $templatePath = $this->module->getLocalPath() . 'views/';
            
            if (!is_dir($templatePath)) {
                return null;
            }

            // Use namespace or legacy class names
            $loaderClass = class_exists('\Twig\Loader\FilesystemLoader')
                ? '\Twig\Loader\FilesystemLoader'
                : 'Twig_Loader_Filesystem';
            $envClass = class_exists('\Twig\Environment')
                ? '\Twig\Environment'
                : 'Twig_Environment';
            $functionClass = class_exists('\Twig\TwigFunction')
                ? '\Twig\TwigFunction'
                : 'Twig_SimpleFunction';

            $loader = new $loaderClass($templatePath);
            $twig = new $envClass($loader, [
                'cache' => false,
                'debug' => false,
                'auto_reload' => true,
            ]);

            // Add translation function
            $module = $this->module;
            $lFunction = new $functionClass('l', function (string $string) use ($module) {
                return $module->l($string);
            });
            $twig->addFunction($lFunction);

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
