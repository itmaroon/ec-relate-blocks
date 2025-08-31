<?php

namespace Itmar\ShopifyClassPackage\Bootstrap;

use Itmar\ShopifyClassPackage\Interface\Rest\CartController;
use Itmar\ShopifyClassPackage\Interface\Rest\CustomerController;
use Itmar\ShopifyClassPackage\Interface\Rest\ProductController;
use Itmar\ShopifyClassPackage\Interface\Rest\SettingsController;

final class Plugin
{
    public function boot(): void
    {
        add_action('init', function () {
            (new CustomerController())->registerAjax();
            (new ProductController())->registerWpHooks();
        });

        add_action('rest_api_init',  function () {
            (new CartController())->registerRest();
            (new CustomerController())->registerRest();
            (new ProductController())->registerRest();
            (new SettingsController())->register();
        }, 10);
    }
}
