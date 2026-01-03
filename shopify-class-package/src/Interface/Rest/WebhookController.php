<?php

namespace Itmar\ShopifyClassPackage\Interface\Rest;

use WP_REST_Server;
use WP_REST_Request;

if (! defined('ABSPATH')) exit;

final class WebhookController extends BaseController
{
    public function register(): void
    {
        register_rest_route($this->ns(), '/webhook', [[
            'methods'  => WP_REST_Server::CREATABLE, // POST
            'callback' => [$this, 'handle'],
            'permission_callback' => '__return_true', // 署名で保護するのでOK
        ]]);
    }

    public function handle(WP_REST_Request $req)
    {
        try {
            // 1) HMAC 署名検証（例）
            $hmac = $req->get_header('x-shopify-hmac-sha256') ?: '';
            $raw  = $req->get_body();
            $secret = (string) get_option('shopify_webhook_secret', '');
            if (!$this->verifyHmac($raw, $hmac, $secret)) {
                return $this->fail(new \RuntimeException('Invalid signature'), 401);
            }

            // 2) トピック分配
            $topic = $req->get_header('x-shopify-topic') ?: 'unknown';
            $data  = json_decode($raw, true) ?: [];

            // TODO: topic ごとにハンドラへ委譲
            // $this->dispatcher->dispatch($topic, $data);

            return $this->ok(['topic' => $topic]);
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
    }

    private function verifyHmac(string $raw, string $hmac, string $secret): bool
    {
        if ($hmac === '' || $secret === '') return false;
        $calc = base64_encode(hash_hmac('sha256', $raw, $secret, true));
        return hash_equals($calc, $hmac);
    }
}
