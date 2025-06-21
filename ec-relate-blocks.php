<?php

/**
 * Plugin Name:       EC RELATE BLOCKS
 * Plugin URI:        https://itmaroon.net
 * Description:       We provide blocks to build EC sites in cooperation with various EC companies.
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Version:           0.1.0
 * Author:            Web Creator ITmaroon
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ec-relate-blocks
 * Domain Path:       /languages 
 *
 * @package           itmar
 */


//PHPファイルに対する直接アクセスを禁止
if (!defined('ABSPATH')) exit;

// プラグイン情報取得に必要なファイルを読み込む
if (!function_exists('get_plugin_data')) {
	require_once(ABSPATH . 'wp-admin/includes/plugin.php');
}

require_once __DIR__ . '/vendor/itmar/loader-package/src/register_autoloader.php';
$block_entry = new \Itmar\BlockClassPackage\ItmarEntryClass();

//define('STRIPE_API_SECRET', getenv('STRIPE_API_SECRET'));

//\Stripe\Stripe::setApiKey(STRIPE_API_SECRET);


//ブロックの初期登録
add_action('init', function () use ($block_entry) {
	$plugin_data = get_plugin_data(__FILE__);
	$block_entry->block_init($plugin_data['TextDomain'], __FILE__);
	//nonceのローカライズ
	wp_localize_script('itmar-script-handle', 'itmar_option', array(
		'nonce' => wp_create_nonce('wp_rest'),
	));
});

// REST APIエンドポイント登録（ShopifyのWebhook用など）
add_action('rest_api_init', function () {
	register_rest_route('itmar-ec-relate/v1', '/shopify-webhook', [
		'methods' => 'POST',
		'callback' => 'itmar_shopify_webhook_callback',
		'permission_callback' => '__return_true',
	]);
	register_rest_route('itmar-ec-relate/v1', '/check-or-create-customer', [
		'methods' => 'POST',
		'callback' => 'itmar_check_create_shopify_customer',
		'permission_callback' => function () {
			return current_user_can('read'); // WordPressが nonce を検証し、ログイン済みユーザーか確認
		},
	]);
	register_rest_route('itmar-ec-relate/v1', '/create-checkout', [
		'methods' => 'POST',
		'callback' => 'itmar_create_shopify_checkout',
		'permission_callback' => function () {
			return current_user_can('read'); // WordPressが nonce を検証し、ログイン済みユーザーか確認
		},
	]);
	register_rest_route('itmar-ec-relate/v1', '/save-tokens', [
		'methods' => 'POST',
		'callback' => 'itmar_save_tokens',
		'permission_callback' => function () {
			return current_user_can('manage_options'); // 管理者のみ
		}
	]);
});

function itmar_shopify_webhook_callback(WP_REST_Request $request)
{
	$order_data = $request->get_json_params();
	// 任意の保存処理など
	return new WP_REST_Response(['received' => true, 'order' => $order_data], 200);
}


// 依存するプラグインが有効化されているかのアクティベーションフック
register_activation_hook(__FILE__, function () use ($block_entry) {
	$plugin_data = get_plugin_data(__FILE__);
	$block_entry->activation_check($plugin_data, ['block-collections']); // ここでメソッドを呼び出し
});

// 管理画面での通知フック
add_action('admin_notices', function () use ($block_entry) {
	$plugin_data = get_plugin_data(__FILE__);
	$block_entry->show_admin_dependency_notices($plugin_data, ['block-collections']);
});

//トークンの格納
function itmar_save_tokens(WP_REST_Request $request)
{
	$params = $request->get_json_params();
	if (!isset($params['shop_domain'])) {
		return new WP_Error('missing_params', __('Required domain not available.', "ec-relate-bloks"), ['status' => 400]);
	}
	if (!isset($params['admin_token']) || !isset($params['storefront_token'])) {
		return new WP_Error('missing_params', __('Required token not available.', "ec-relate-bloks"), ['status' => 400]);
	}
	update_option('shopify_shop_domain', sanitize_text_field($params['shop_domain']));
	update_option('shopify_admin_token', sanitize_text_field($params['admin_token']));
	update_option('shopify_storefront_token', sanitize_text_field($params['storefront_token']));

	return ['status' => 'ok'];
}

//顧客登録処理
function itmar_check_create_shopify_customer()
{
	$user = wp_get_current_user();

	if (!$user || 0 === $user->ID) {
		return new WP_Error('not_logged_in', __('Please Login.', "ec-relate-bloks"), ['status' => 401]);
	}

	$customer_id = get_user_meta($user->ID, 'shopify_customer_id', true);
	if ($customer_id) {
		return ['id' => $customer_id];
	}

	// 登録処理（Admin API 使用）
	$shop_domain = get_option('shopify_shop_domain');
	$admin_token = get_option('shopify_admin_token'); // セキュアに管理すること
	$response = wp_remote_post("https://{$shop_domain}/admin/api/2025-04/customers.json", [
		'headers' => [
			'X-Shopify-Access-Token' => $admin_token,
			'Content-Type' => 'application/json',
		],
		'body' => json_encode([
			'customer' => [
				'first_name' => $user->first_name,
				'last_name'  => $user->last_name,
				'email'      => $user->user_email,
			]
		])
	]);

	$body = json_decode(wp_remote_retrieve_body($response), true);
	if (!isset($body['customer']['id'])) {
		return new WP_Error('create_failed', '顧客登録に失敗しました。', ['status' => 500]);
	}

	$customer_id = $body['customer']['id'] ?? null;
	if (!$customer_id) {
		return new WP_Error('missing_customer_id', '顧客IDが取得できませんでした。');
	}

	update_user_meta($user->ID, 'shopify_customer_id', $customer_id);

	// 招待メール送信
	$invite_response = wp_remote_post("https://{$shop_domain}/admin/api/2025-04/customers/{$customer_id}/send_invite.json", [
		'headers' => [
			'X-Shopify-Access-Token' => $admin_token,
			'Content-Type' => 'application/json',
		],
	]);

	if (is_wp_error($invite_response)) {
		return new WP_Error('invite_failed', '招待メールの送信に失敗しました。', $invite_response->get_error_message());
	}

	$invite_body = json_decode(wp_remote_retrieve_body($invite_response), true);

	if (isset($invite_body['errors'])) {
		return new WP_Error('invite_failed', '招待メールの送信に失敗しました。', $invite_body['errors']);
	}

	return [
		'message'     => '顧客登録および招待メールの送信が完了しました。',
		'id' => $customer_id,
	];
}

//Wordpressからshopifyに商品を登録する
add_action('save_post_product', function ($post_id, $post) {
	// 自動保存・リビジョンを無視
	if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
	if ('revision' === get_post_type($post_id)) return;
	// 非公開 or ゴミ箱 の場合 → Shopify 商品削除
	if (in_array($post->post_status, ['draft', 'trash'])) {
		$shopify_id = get_post_meta($post_id, 'shopify_product_id', true);
		if (!$shopify_id) return;

		$shop_domain = get_option('shopify_shop_domain');
		$admin_token = get_option('shopify_admin_token');

		$response = wp_remote_request("https://{$shop_domain}/admin/api/2025-04/products/{$shopify_id}.json", [
			'method' => 'DELETE',
			'headers' => [
				'X-Shopify-Access-Token' => $admin_token,
			],
		]);

		// メタも消す（ローカルと同期）
		delete_post_meta($post_id, 'shopify_product_id');
		delete_post_meta($post_id, 'shopify_variant_id');

		return;
	}
	//データ公開の時のみ処理
	if ($post->post_status !== 'publish') return;

	// 既に予約済なら skip
	if (wp_next_scheduled('itmar_shopify_sync_cron', [$post_id])) return;

	// 1分後に同期スケジュール（WordPress保存完了後に確実に呼ばれる）
	wp_schedule_single_event(time() + 5, 'itmar_shopify_sync_cron', [$post_id]);
}, 20, 2);

// 実行用フック
add_action('itmar_shopify_sync_cron', 'itmar_register_shopify_product_from_wp');


function itmar_register_shopify_product_from_wp($post_id)
{
	//カスタム投稿投稿タイプ（product）にのみ対応
	if (get_post_type($post_id) !== 'product') return;

	// 投稿情報の取得
	$title = get_the_title($post_id);
	$description = get_the_excerpt($post_id);
	$image_url = get_the_post_thumbnail_url($post_id, 'full');
	$price = get_post_meta($post_id, 'price', true) ?: '0';
	$quantity = get_post_meta($post_id, 'quantity', true) ?: '0';

	//shopify情報の取得
	$shop_domain = get_option('shopify_shop_domain');
	$admin_token = get_option('shopify_admin_token');
	// トークンチェックj
	if (!$admin_token) return;

	// バリアント定義
	$variant = [
		'price' => $price,
		'option1' => 'Default Title',
		'inventory_management' => 'shopify', // ★ Shopify管理下に
		'inventory_policy' => 'deny',        // ★ 在庫切れで売り切れにする
	];
	//商品データ
	$product_data = [
		'product' => [
			'title' => $title,
			'body_html' => $description,
			'variants' => [$variant],
		]
	];

	$existing_id = get_post_meta($post_id, 'shopify_product_id', true);
	if ($existing_id) {
		// 商品更新
		$response = wp_remote_request("https://{$shop_domain}/admin/api/2025-04/products/{$existing_id}.json", [
			'method' => 'PUT',
			'headers' => [
				'X-Shopify-Access-Token' => $admin_token,
				'Content-Type' => 'application/json',
			],
			'body' => json_encode($product_data),
		]);
	} else {
		// 新規登録
		$response = wp_remote_post("https://{$shop_domain}/admin/api/2025-04/products.json", [
			'headers' => [
				'X-Shopify-Access-Token' => $admin_token,
				'Content-Type' => 'application/json',
			],
			'body' => json_encode($product_data),
		]);

		$body = json_decode(wp_remote_retrieve_body($response), true);
		if (!empty($body['product']['id'])) {
			update_post_meta($post_id, 'shopify_product_id', $body['product']['id']);
			update_post_meta($post_id, 'shopify_variant_id', $body['product']['variants'][0]['id']);
			$existing_id = $body['product']['id']; // 新規登録時のため、ここで記録
		}
	}

	// 在庫同期
	$variant_id = get_post_meta($post_id, 'shopify_variant_id', true);
	if ($variant_id) {
		// variant から inventory_item_id を取得
		$variant_response = wp_remote_get("https://{$shop_domain}/admin/api/2025-04/variants/{$variant_id}.json", [
			'headers' => [
				'X-Shopify-Access-Token' => $admin_token,
			],
		]);
		$variant_body = json_decode(wp_remote_retrieve_body($variant_response), true);
		$inventory_item_id = $variant_body['variant']['inventory_item_id'] ?? null;

		if ($inventory_item_id) {
			// location_id を取得
			$location_response = wp_remote_get("https://{$shop_domain}/admin/api/2025-04/locations.json", [
				'headers' => [
					'X-Shopify-Access-Token' => $admin_token,
				],
			]);
			$location_body = json_decode(wp_remote_retrieve_body($location_response), true);
			$locations = $location_body['locations'] ?? [];

			if (!empty($locations) && $inventory_item_id) {
				$stock_qty = max(intval($quantity), 0);

				// 2️⃣ 各ロケーションの在庫数を一旦クリア（0にセット）
				foreach ($locations as $loc) {
					$loc_id = $loc['id'];

					wp_remote_post("https://{$shop_domain}/admin/api/2025-04/inventory_levels/set.json", [
						'headers' => [
							'X-Shopify-Access-Token' => $admin_token,
							'Content-Type' => 'application/json',
						],
						'body' => json_encode([
							'location_id' => $loc_id,
							'inventory_item_id' => $inventory_item_id,
							'available' => 0,
						]),
					]);
				}

				// 3️⃣ メインのロケーション（locations[0]）にだけ在庫をセット
				$main_location_id = $locations[0]['id'];

				wp_remote_post("https://{$shop_domain}/admin/api/2025-04/inventory_levels/set.json", [
					'headers' => [
						'X-Shopify-Access-Token' => $admin_token,
						'Content-Type' => 'application/json',
					],
					'body' => json_encode([
						'location_id' => $main_location_id,
						'inventory_item_id' => $inventory_item_id,
						'available' => $stock_qty,
					]),
				]);
			}
		}
	}

	// ★★★ ギャラリー画像アップロード ★★★
	if ($existing_id) {
		// 既存画像一覧取得
		$image_list_response = wp_remote_get("https://{$shop_domain}/admin/api/2025-04/products/{$existing_id}/images.json", [
			'headers' => [
				'X-Shopify-Access-Token' => $admin_token,
			],
		]);

		$image_list_body = json_decode(wp_remote_retrieve_body($image_list_response), true);
		if (!empty($image_list_body['images'])) {
			foreach ($image_list_body['images'] as $img) {
				$image_id = $img['id'];
				// 画像削除
				wp_remote_request("https://{$shop_domain}/admin/api/2025-04/products/{$existing_id}/images/{$image_id}.json", [
					'method' => 'DELETE',
					'headers' => [
						'X-Shopify-Access-Token' => $admin_token,
					],
				]);
			}
		}
		// ギャラリー画像アップロード
		$images = [];
		$gallery = get_field('gallary', $post_id);
		$thumbnail_id = get_post_thumbnail_id($post_id, 'full');
		if ($gallery && is_array($gallery)) {
			foreach ($gallery as $image) {
				$images[] = $image['id'];
			}
		} else { //アイキャッチ画像
			if ($image_url) {
				$images[] = $thumbnail_id;
			}
		}
		if ($images && is_array($images)) {
			foreach ($images as $image_id) {
				$image_path = get_attached_file($image_id); // ファイルパスを取得
				if (file_exists($image_path)) {
					$image_data = base64_encode(file_get_contents($image_path));

					// 1枚ずつ POST する
					$upload_response = wp_remote_post("https://{$shop_domain}/admin/api/2025-04/products/{$existing_id}/images.json", [
						'headers' => [
							'X-Shopify-Access-Token' => $admin_token,
							'Content-Type' => 'application/json',
						],
						'body' => json_encode([
							'image' => [
								'attachment' => $image_data,
								'alt' => get_the_title($post_id), // 商品タイトルを alt に
							],
						]),
					]);

					// ログ出力
					$upload_result = json_decode(wp_remote_retrieve_body($upload_response), true);
					error_log('Image upload: ' . print_r($upload_result, true));
				}
			}
		}
	}
}

//商品データ削除時の処理
add_action('before_delete_post', function ($post_id) {
	if (get_post_type($post_id) !== 'product') return;

	$shopify_id = get_post_meta($post_id, 'shopify_product_id', true);
	if (!$shopify_id) return;

	$shop_domain = get_option('shopify_shop_domain');
	$admin_token = get_option('shopify_admin_token');

	$responce = wp_remote_request("https://{$shop_domain}/admin/api/2025-04/products/{$shopify_id}.json", [
		'method' => 'DELETE',
		'headers' => [
			'X-Shopify-Access-Token' => $admin_token,
		],
	]);
});


//Shopifyチェックアウト作成
function itmar_create_shopify_checkout(WP_REST_Request $request)
{
	$params = $request->get_json_params();
	$variantId = sanitize_text_field($params['productId']); // これは "gid://shopify/ProductVariant/..." の形式であること

	$query = <<<GQL
mutation {
  cartCreate(
    input: {
      lines: [
        {
          merchandiseId: "$variantId",
          quantity: 1
        }
      ]
    }
  ) {
    cart {
      id
      checkoutUrl
    }
    userErrors {
      field
      message
    }
  }
}
GQL;
	$shop_domain = get_option('shopify_shop_domain');
	$token = get_option('shopify_storefront_token');
	$response = wp_remote_post("https://{$shop_domain}/api/2025-04/graphql.json", [
		'headers' => [
			'X-Shopify-Storefront-Access-Token' => $token,
			'Content-Type' => 'application/json',
		],
		'body' => json_encode(['query' => $query]),
	]);

	$body = json_decode(wp_remote_retrieve_body($response), true);
	if (!empty($body['data']['cartCreate']['cart']['checkoutUrl'])) {
		return ['checkout_url' => $body['data']['cartCreate']['cart']['checkoutUrl']];
	}

	return new WP_Error(
		'checkout_failed',
		'チェックアウトURLの取得に失敗しました',
		['response' => $body]
	);
}
