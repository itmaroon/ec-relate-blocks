<?php

namespace Itmar\ShopifyClassPackage\Interface\Rest;

use WP_REST_Request;
use WP_REST_Server;
use WP_Error;

final class SettingsController extends BaseController
{
    public function register(): void
    {
        // 保存：管理者のみ / RESTノンス必須 / ログイン必須
        register_rest_route($this->ns(), '/settings/save', [[
            'methods'             => WP_REST_Server::CREATABLE, // POST
            'callback'            => [$this, 'saveTokens'],
            'permission_callback' => $this->gate('manage_options', 'wp_rest', true),
        ]]);

        // 取得：管理UI用（トークンはマスク）
        register_rest_route($this->ns(), '/settings', [[
            'methods'             => WP_REST_Server::READABLE, // GET
            'callback'            => [$this, 'getSettings'],
            'permission_callback' => $this->gate('manage_options', 'wp_rest', true),
        ]]);
    }

    public function saveTokens(WP_REST_Request $request)
    {
        try {
            $p = $request->get_json_params() ?: [];

            // 投稿タイプ（任意）
            if (isset($p['productPost']) && $p['productPost'] !== '') {
                update_option('product_post', sanitize_text_field($p['productPost']));
            }

            // Shopify
            foreach (['shop_domain', 'channel_name', 'admin_token', 'storefront_token'] as $k) {
                if (empty($p[$k])) {
                    return $this->fail(new WP_Error('missing_params', __('Required parameter missing: ' . $k, 'ec-relate-bloks'), ['status' => 400]), 400);
                }
            }
            update_option('shopify_shop_domain',      sanitize_text_field($p['shop_domain']));
            update_option('shopify_channel_name',     sanitize_text_field($p['channel_name']));
            update_option('shopify_admin_token',      sanitize_text_field($p['admin_token']));
            update_option('shopify_storefront_token', sanitize_text_field($p['storefront_token']));

            // Stripe
            // if (empty($p['stripe_key'])) {
            //     return $this->fail(new WP_Error('missing_params', __('Required API KEY not available.', 'ec-relate-bloks'), ['status' => 400]), 400);
            // }
            // update_option('stripe_key', sanitize_text_field($p['stripe_key']));

            // 返却
            return $this->ok(['status' => 'ok']);
        } catch (\Throwable $e) {
            return $this->fail($e, 500);
        }
    }

    public function getSettings(WP_REST_Request $request)
    {
        try {
            $mask = fn($v) => $v ? substr($v, 0, 4) . str_repeat('*', max(0, strlen($v) - 8)) . substr($v, -4) : '';

            $data = [
                'productPost'      => (string) get_option('product_post', ''),
                'shop_domain'      => (string) get_option('shopify_shop_domain', ''),
                'channel_name'     => (string) get_option('shopify_channel_name', ''),
                // トークンはマスク
                'admin_token'      => $mask((string) get_option('shopify_admin_token', '')),
                'storefront_token' => $mask((string) get_option('shopify_storefront_token', '')),
                'stripe_key'       => $mask((string) get_option('stripe_key', '')),
            ];
            return $this->ok(['settings' => $data]);
        } catch (\Throwable $e) {
            return $this->fail($e, 500);
        }
    }
}
