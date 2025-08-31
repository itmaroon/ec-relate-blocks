<?php

namespace Itmar\ShopifyClassPackage\Interface\Rest;

use WP_REST_Request;
use WP_REST_Server;
use WP_Error;

final class ProductController extends BaseController
{
    /**
     * REST のルート登録（商品情報の取得）
     */
    public function registerRest(): void
    {
        // 公開APIにするなら publicAccess()、RESTノンス必須にするなら gate(null,'wp_rest',false)
        register_rest_route($this->ns(), '/get-product', [[
            'methods'             => WP_REST_Server::CREATABLE, // POST
            'callback'            => [$this, 'getProductInfo'],
            'permission_callback' => $this->gate(null, 'wp_rest'),
            'args' => [
                'fields'  => ['required' => true, 'type' => 'array'],
                'itemNum' => ['required' => false, 'type' => 'integer'],
            ],
        ]]);
    }

    /**
     * WP の各種フック登録（保存/削除/cron）
     */
    public function registerWpHooks(): void
    {
        // 投稿削除前：Shopify 側の削除
        add_action('before_delete_post', [$this, 'onBeforeDeletePost'], 10, 1);

        // 投稿保存：Shopify 商品の作成/更新・削除
        add_action('save_post', [$this, 'onSavePost'], 20, 2);

        // 単発同期ジョブ
        add_action('itmar_shopify_sync_cron', [$this, 'syncProductFromPost'], 10, 1);
        // ステータス遷移監視（ドラフト→公開でメタを消す）
        add_action('transition_post_status', [$this, 'onTransitionPostStatus'], 10, 3);
        // ゴミ箱からの復元
        add_action('untrash_post', [$this, 'onUntrashPost'], 10, 1);
    }

    // =========================
    // REST: 商品一覧（Storefront API, GraphQL）
    // =========================

    public function getProductInfo(WP_REST_Request $request)
    {
        try {
            $fieldTemplates = [
                'title'          => 'title',
                'handle'         => 'handle',
                'description'    => 'description',
                'descriptionHtml' => 'descriptionHtml',
                'vendor'         => 'vendor',
                'productType'    => 'productType',
                'tags'           => 'tags',
                'onlineStoreUrl' => 'onlineStoreUrl',
                'createdAt'      => 'createdAt',
                'updatedAt'      => 'updatedAt',
                'medias'         => <<<GQL
media(first: 250) {
  edges {
    node {
      mediaContentType
      ... on MediaImage {
        image { url altText width height }
      }
      ... on Video {
        alt
        sources { url format mimeType width height }
      }
    }
  }
}
GQL,
                'variants'       => <<<GQL
variants(first: 10) {
  edges {
    node {
      id
      title
      availableForSale
      quantityAvailable
      price { amount currencyCode }
      compareAtPrice { amount currencyCode }
    }
  }
}
GQL,
            ];

            $shopDomain   = (string) get_option('shopify_shop_domain');
            $storefrontTk = (string) get_option('shopify_storefront_token');
            if ($shopDomain === '' || $storefrontTk === '') {
                return $this->fail(new WP_Error('config_missing', 'Shopify storefront config missing', ['status' => 500]), 500);
            }

            $fields  = $request->get_param('fields');
            if (!is_array($fields) || empty($fields)) {
                return $this->fail(new WP_Error('invalid_fields', 'fields パラメータが必要です。', ['status' => 400]), 400);
            }

            $itemNum = (int) ($request->get_param('itemNum') ?? 10);
            if ($itemNum <= 0)   $itemNum = 10;
            if ($itemNum > 250)  $itemNum = 250;

            // 必要フィールドを組み立て
            $selected = array_values(array_filter($fields, fn($f) => isset($fieldTemplates[$f])));
            // variants は常に追加
            if (!in_array('variants', $selected, true)) {
                $selected[] = 'variants';
            }
            // image/images が要求されたら medias を追加
            if (in_array('image', $fields, true) || in_array('images', $fields, true)) {
                if (!in_array('medias', $selected, true)) $selected[] = 'medias';
            }

            $graphqlFieldStr = implode("\n", array_map(fn($f) => $fieldTemplates[$f], $selected));

            $query = <<<GQL
{
  products(first: {$itemNum}, sortKey: CREATED_AT, reverse: true) {
    edges {
      node {
        {$graphqlFieldStr}
      }
    }
  }
}
GQL;

            $resp = wp_remote_post("https://{$shopDomain}/api/2025-04/graphql.json", [
                'headers' => [
                    'Content-Type'                         => 'application/json',
                    'X-Shopify-Storefront-Access-Token'    => $storefrontTk,
                ],
                'body'    => wp_json_encode(['query' => $query]),
                'timeout' => 20,
            ]);

            if (is_wp_error($resp)) {
                return $this->fail($resp, 500);
            }

            $body     = json_decode(wp_remote_retrieve_body($resp), true);
            $products = $body['data']['products']['edges'] ?? [];
            $nodes    = array_map(fn($edge) => $edge['node'] ?? [], $products);

            return $this->ok(['products' => $nodes]);
        } catch (\Throwable $e) {
            return $this->fail($e, 500);
        }
    }

    // =========================
    // WP Hooks: 削除/保存/同期
    // =========================

    public function onBeforeDeletePost(int $postId): void
    {
        // ここは “product” 固定ではなく、オプションで定義された投稿タイプを尊重
        $productPostType = (string) get_option('product_post') ?: 'product';
        if (get_post_type($postId) !== $productPostType) return;

        $shopifyId = get_post_meta($postId, 'shopify_product_id', true);
        if (!$shopifyId) return;

        $shopDomain = (string) get_option('shopify_shop_domain');
        $adminToken = (string) get_option('shopify_admin_token');
        if ($shopDomain === '' || $adminToken === '') return;

        wp_remote_request("https://{$shopDomain}/admin/api/2025-04/products/{$shopifyId}.json", [
            'method'  => 'DELETE',
            'headers' => ['X-Shopify-Access-Token' => $adminToken],
            'timeout' => 20,
        ]);

        // ローカルの関連メタも削除
        delete_post_meta($postId, 'shopify_product_id');
        delete_post_meta($postId, 'shopify_variant_id');
    }

    public function onSavePost(int $postId, \WP_Post $post): void
    {
        $productPostType = (string) get_option('product_post') ?: 'product';
        if ($post->post_type !== $productPostType) return;

        // 自動保存・リビジョンは無視
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if ('revision' === get_post_type($postId)) return;

        $shopDomain = (string) get_option('shopify_shop_domain');
        $adminToken = (string) get_option('shopify_admin_token');
        if ($adminToken === '' || $shopDomain === '') return;

        // ❶ ゴミ箱へ → Shopify は削除せず、ステータスを draft/archived にする
        if ($post->post_status === 'trash') {
            $shopifyId = get_post_meta($postId, 'shopify_product_id', true);
            if ($shopifyId) {
                $this->updateShopifyProductStatus($shopifyId, 'draft'); // or 'archived'
            }
            return; // ← 削除もメタ消去もしない
        }

        // ❷ 下書き → Shopify も draft に
        if ($post->post_status === 'draft') {
            $shopifyId = get_post_meta($postId, 'shopify_product_id', true);
            if ($shopifyId) {
                $this->updateShopifyProductStatus($shopifyId, 'draft');
            }
            return;
        }

        // 公開以外は何もしない
        if ($post->post_status !== 'publish') return;

        // 二重スケジュール防止
        if (wp_next_scheduled('itmar_shopify_sync_cron', [$postId])) return;

        // 少し遅延させて（保存完了後）同期実行
        wp_schedule_single_event(time() + 5, 'itmar_shopify_sync_cron', [$postId]);
    }

    //下書きからの遷移でメタデータを制御
    public function onTransitionPostStatus(string $new, string $old, \WP_Post $post): void
    {
        // 対象の投稿タイプのみ
        $productPostType = (string) get_option('product_post') ?: 'product';
        if ($post->post_type !== $productPostType) {
            return;
        }

        // ドラフト → 公開 のときだけ実行
        if ($old === 'draft' && $new === 'publish') {
            delete_post_meta($post->ID, 'shopify_product_id');
            delete_post_meta($post->ID, 'shopify_variant_id');
            // （任意）ログ
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf('[Shopify] Cleared linkage meta on publish (post_id=%d)', $post->ID));
            }
        }
    }

    public function onUntrashPost(int $postId): void
    {
        $productPostType = (string) get_option('product_post') ?: 'product';
        if (get_post_type($postId) !== $productPostType) return;

        $shopifyId = get_post_meta($postId, 'shopify_product_id', true);
        if ($shopifyId) {
            // 復元時点の WP ステータスに合わせて Shopify を戻す
            $status = get_post_status($postId);
            if ($status === 'publish') {
                $this->updateShopifyProductStatus($shopifyId, 'active');
                // ★ 復元時点でも公開を保証（Online Store 公開）
                try {
                    $channelName = (string) get_option('shopify_channel_name');
                    $this->publishToChannel((int)$shopifyId, $channelName); // ★ 追加
                } catch (\Throwable $e) {
                    if (defined('WP_DEBUG') && WP_DEBUG) error_log('[Shopify publish on untrash] ' . $e->getMessage());
                }
                // 最新内容を反映したいなら同期もキック
                if (!wp_next_scheduled('itmar_shopify_sync_cron', [$postId])) {
                    wp_schedule_single_event(time() + 5, 'itmar_shopify_sync_cron', [$postId]);
                }
            } elseif ($status === 'draft') {
                $this->updateShopifyProductStatus($shopifyId, 'draft');
            }
        }
    }


    /**
     * WP → Shopify 同期の本体（cron から呼ばれる）
     */
    public function syncProductFromPost(int $postId): void
    {
        $productPostType = (string) get_option('product_post') ?: 'product';
        if (get_post_type($postId) !== $productPostType) return;

        // 投稿情報
        $title       = get_the_title($postId);
        $description = get_the_excerpt($postId);
        $imageUrl    = get_the_post_thumbnail_url($postId, 'full');
        $price       = get_post_meta($postId, 'price', true) ?: '0';
        $quantity    = get_post_meta($postId, 'quantity', true) ?: '0';

        // Shopify 接続情報
        $shopDomain = (string) get_option('shopify_shop_domain');
        $adminToken = (string) get_option('shopify_admin_token');
        $channelName = (string) get_option('shopify_channel_name');
        if ($adminToken === '' || $shopDomain === '') return;

        // バリアント
        $variant = [
            'price'                => $price,
            'option1'              => 'Default Title',
            'inventory_management' => 'shopify',
            'inventory_policy'     => 'deny',
        ];

        // 商品データ
        $productData = [
            'product' => [
                'title'     => $title,
                'body_html' => $description,
                'variants'  => [$variant],
            ],
        ];

        // 既存 or 新規
        $existingId = get_post_meta($postId, 'shopify_product_id', true);
        if ($existingId) {
            $resp = wp_remote_request("https://{$shopDomain}/admin/api/2025-04/products/{$existingId}.json", [
                'method'  => 'PUT',
                'headers' => [
                    'X-Shopify-Access-Token' => $adminToken,
                    'Content-Type'           => 'application/json',
                ],
                'body'    => wp_json_encode($productData),
                'timeout' => 20,
            ]);
        } else {
            $resp = wp_remote_post("https://{$shopDomain}/admin/api/2025-04/products.json", [
                'headers' => [
                    'X-Shopify-Access-Token' => $adminToken,
                    'Content-Type'           => 'application/json',
                ],
                'body'    => wp_json_encode($productData),
                'timeout' => 20,
            ]);
            $body = json_decode(wp_remote_retrieve_body($resp), true);
            if (!empty($body['product']['id'])) {
                update_post_meta($postId, 'shopify_product_id', $body['product']['id']);
                update_post_meta($postId, 'shopify_variant_id', $body['product']['variants'][0]['id'] ?? '');
                $existingId = $body['product']['id'];
            }
        }

        // 作成/更新後に Online Store へ公開（販売チャネル割当）
        if ($existingId) {
            try {
                $this->publishToChannel((int)$existingId, $channelName); // 別チャネルにしたい場合は名前を渡す
            } catch (\Throwable $e) {
                if (defined('WP_DEBUG') && WP_DEBUG) error_log('[Shopify publish] ' . $e->getMessage());
            }
        }

        // 在庫同期
        $variantId = get_post_meta($postId, 'shopify_variant_id', true);
        if ($variantId) {
            // variant → inventory_item_id
            $vResp = wp_remote_get("https://{$shopDomain}/admin/api/2025-04/variants/{$variantId}.json", [
                'headers' => ['X-Shopify-Access-Token' => $adminToken],
                'timeout' => 20,
            ]);
            $vBody = json_decode(wp_remote_retrieve_body($vResp), true);
            $inventoryItemId = $vBody['variant']['inventory_item_id'] ?? null;

            if ($inventoryItemId) {
                // location 一覧
                $locResp = wp_remote_get("https://{$shopDomain}/admin/api/2025-04/locations.json", [
                    'headers' => ['X-Shopify-Access-Token' => $adminToken],
                    'timeout' => 20,
                ]);
                $locBody  = json_decode(wp_remote_retrieve_body($locResp), true);
                $locations = $locBody['locations'] ?? [];

                if (!empty($locations)) {
                    $stockQty = max((int)$quantity, 0);

                    // まず全ロケーションを 0 に
                    foreach ($locations as $loc) {
                        $locId = $loc['id'];
                        wp_remote_post("https://{$shopDomain}/admin/api/2025-04/inventory_levels/set.json", [
                            'headers' => [
                                'X-Shopify-Access-Token' => $adminToken,
                                'Content-Type'           => 'application/json',
                            ],
                            'body'    => wp_json_encode([
                                'location_id'       => $locId,
                                'inventory_item_id' => $inventoryItemId,
                                'available'         => 0,
                            ]),
                            'timeout' => 20,
                        ]);
                    }

                    // 先頭ロケーションに在庫を設定
                    $mainLocId = $locations[0]['id'];
                    wp_remote_post("https://{$shopDomain}/admin/api/2025-04/inventory_levels/set.json", [
                        'headers' => [
                            'X-Shopify-Access-Token' => $adminToken,
                            'Content-Type'           => 'application/json',
                        ],
                        'body'    => wp_json_encode([
                            'location_id'       => $mainLocId,
                            'inventory_item_id' => $inventoryItemId,
                            'available'         => $stockQty,
                        ]),
                        'timeout' => 20,
                    ]);
                }
            }
        }

        // 画像同期（既存IDがある場合）
        if ($existingId) {
            // 既存画像を削除
            $imgListResp = wp_remote_get("https://{$shopDomain}/admin/api/2025-04/products/{$existingId}/images.json", [
                'headers' => ['X-Shopify-Access-Token' => $adminToken],
                'timeout' => 20,
            ]);
            $imgList = json_decode(wp_remote_retrieve_body($imgListResp), true);
            foreach (($imgList['images'] ?? []) as $img) {
                $imageId = $img['id'];
                wp_remote_request("https://{$shopDomain}/admin/api/2025-04/products/{$existingId}/images/{$imageId}.json", [
                    'method'  => 'DELETE',
                    'headers' => ['X-Shopify-Access-Token' => $adminToken],
                    'timeout' => 20,
                ]);
            }

            // ギャラリー or アイキャッチ
            $images = [];
            $gallery = function_exists('get_field') ? get_field('gallary', $postId) : null; // ACF 前提なら存在確認
            $thumbId = get_post_thumbnail_id($postId);
            if ($gallery && is_array($gallery)) {
                foreach ($gallery as $img) {
                    if (isset($img['id'])) $images[] = (int)$img['id'];
                }
            } elseif ($thumbId) {
                $images[] = (int)$thumbId;
            }

            foreach ($images as $attachmentId) {
                $filePath = get_attached_file($attachmentId);
                if ($filePath && file_exists($filePath)) {
                    $imageData = base64_encode((string) file_get_contents($filePath));
                    $uploadResp = wp_remote_post("https://{$shopDomain}/admin/api/2025-04/products/{$existingId}/images.json", [
                        'headers' => [
                            'X-Shopify-Access-Token' => $adminToken,
                            'Content-Type'           => 'application/json',
                        ],
                        'body'    => wp_json_encode([
                            'image' => [
                                'attachment' => $imageData,
                                'alt'        => get_the_title($postId),
                            ],
                        ]),
                        'timeout' => 30,
                    ]);
                    // ログ（任意）
                    $upBody = json_decode(wp_remote_retrieve_body($uploadResp), true);
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('Shopify image upload: ' . print_r($upBody, true));
                    }
                }
            }
        }
    }

    // =========================
    // ★ GraphQL（Admin）公開ヘルパ
    // =========================

    /** ★ Publication ID を名前から解決（Online Store は固定ID -1 を使用） */
    private function resolvePublicationId(string $publicationName = 'Online Store'): string
    {
        if ($publicationName === 'Online Store') {
            return 'gid://shopify/Publication/-1';
        }
        $q = <<<'GQL'
query($first:Int!){
  publications(first:$first){
    nodes{ id name }
  }
}
GQL;
        $data = $this->gql($q, ['first' => 50]);
        foreach ($data['publications']['nodes'] ?? [] as $n) {
            if (($n['name'] ?? '') === $publicationName) {
                return $n['id'];
            }
        }
        throw new \RuntimeException("Publication '{$publicationName}' が見つかりません。");
    }

    /** ★ 商品を ACTIVE に（公開前の安全策） */
    private function ensureProductActive(int $productId): void
    {
        $gid = "gid://shopify/Product/{$productId}";
        $m = <<<'GQL'
mutation SetActive($id: ID!) {
  productUpdate(input: { id: $id, status: ACTIVE }) {
    product { id status }
    userErrors { field message }
  }
}
GQL;
        $res = $this->gql($m, ['id' => $gid]);
        if (!empty($res['productUpdate']['userErrors'])) {
            throw new \RuntimeException('productUpdate: ' . wp_json_encode($res['productUpdate']['userErrors'], JSON_UNESCAPED_UNICODE));
        }
    }

    /** ★ 指定販売チャネルに公開（publishablePublish） */
    private function publishToChannel(int $productId, string $publicationName = 'Online Store'): void
    {
        $this->ensureProductActive($productId);
        $publicationId = $this->resolvePublicationId($publicationName);
        $gid = "gid://shopify/Product/{$productId}";
        $m = <<<'GQL'
mutation($pid:ID!, $pub:ID!){
  publishablePublish(id:$pid, input:{publicationId:$pub}) {
    userErrors{ field message }
  }
}
GQL;
        $res = $this->gql($m, ['pid' => $gid, 'pub' => $publicationId]);
        if (!empty($res['publishablePublish']['userErrors'])) {
            throw new \RuntimeException('publishablePublish: ' . wp_json_encode($res['publishablePublish']['userErrors'], JSON_UNESCAPED_UNICODE));
        }
    }

    //Shopify の商品ステータス更新ヘルパ
    private function updateShopifyProductStatus(string $productId, string $status): void
    {
        $shopDomain = (string) get_option('shopify_shop_domain');
        $adminToken = (string) get_option('shopify_admin_token');
        if (!$shopDomain || !$adminToken) return;

        $status = in_array($status, ['active', 'draft', 'archived'], true) ? $status : 'draft';

        wp_remote_request("https://{$shopDomain}/admin/api/2025-04/products/{$productId}.json", [
            'method'  => 'PUT',
            'headers' => [
                'X-Shopify-Access-Token' => $adminToken,
                'Content-Type'           => 'application/json',
            ],
            'body'    => wp_json_encode(['product' => ['id' => $productId, 'status' => $status]]),
            'timeout' => 20,
        ]);
    }
}
