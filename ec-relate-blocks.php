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

//ブロックの初期登録
add_action('init', function () use ($block_entry) {
	$plugin_data = get_plugin_data(__FILE__);
	$block_entry->block_init($plugin_data['TextDomain'], __FILE__);
	//ローカライズ
	wp_localize_script('itmar-script-handle', 'itmar_option', array(
		'nonce' => wp_create_nonce('wp_rest'),
		'adminPostUrl' => esc_url(admin_url('admin-post.php')),
	));
});
//独自JSのエンキュー
add_action('enqueue_block_assets', function () {
	if (!is_admin()) {
		$script_path = plugin_dir_path(__FILE__) . 'build/ec-front-module.js';
		wp_enqueue_script(
			'post_front_handle',
			plugins_url('build/ec-front-module.js?', __FILE__),
			array('jquery'),
			filemtime($script_path),
			true
		);
	}
});
//Shopify ログインの中継ページの生成
function itmar_create_shopify_auth_callback_page()
{
	if (get_page_by_path('shopify-auth-callback')) {
		return; // 既に存在
	}
	wp_insert_post([
		'post_title'   => 'Shopify Auth Callback',
		'post_name'    => 'shopify-auth-callback',
		'post_status'  => 'publish',
		'post_type'    => 'page',
	]);
}
register_activation_hook(__FILE__, 'itmar_create_shopify_auth_callback_page');


// REST APIエンドポイント登録（ShopifyのWebhook用など）
add_action('rest_api_init', function () {
	//shopifyのwebhookを受け取るエンドポイント
	register_rest_route('itmar-ec-relate/v1', '/shopify-webhook', [
		'methods' => 'POST',
		'callback' => 'itmar_shopify_webhook_callback',
		'permission_callback' => '__return_true',
	]);
	//顧客アカウント作成のエンドポイント（フォームデータ）
	register_rest_route('itmar-ec-relate/v1', '/shopify-create-customer-form', [
		'methods' => 'POST',
		'callback' => 'itmar_shopify_customer_form',
		'permission_callback' => '__return_true',
	]);

	//チェックアウトURL作出用エンドポイント(カート作成・商品追加)
	register_rest_route('itmar-ec-relate/v1', '/shopify-create-checkout', [
		'methods' => 'POST',
		'callback' => 'itmar_create_shopify_checkout',
		'permission_callback' => '__return_true', //ログインの制限なし
	]);
	//商品情報を取得するエンドポイント
	register_rest_route('itmar-ec-relate/v1', '/get-product-info', [
		'methods' => 'POST',
		'callback' => 'itmar_shopify_products_filtered',
		'permission_callback' => '__return_true',
	]);
	//トークンなどをwp-optionに格納するエンドポイント
	register_rest_route('itmar-ec-relate/v1', '/save-tokens', [
		'methods' => 'POST',
		'callback' => 'itmar_save_tokens',
		'permission_callback' => function () {
			return current_user_can('manage_options'); // 管理者のみ
		}
	]);
	//WebHook設定状況のリスト取得
	register_rest_route('itmar-ec-relate/v1', '/shopify-webhook-list', [
		'methods'  => 'POST',
		'callback' => 'itmar_get_shopify_webhook_list',
		'permission_callback' => function () {
			return current_user_can('edit_posts'); // 管理者 or 編集者に制限
		},
	]);
	//WebHook設定
	register_rest_route('itmar-ec-relate/v1', '/shopify-webhook-register', [
		'methods'  => 'POST',
		'callback' => 'itmar_register_shopify_webhook',
		'permission_callback' => function () {
			return current_user_can('edit_posts'); // 管理者 or 編集者に制限
		},
	]);
	//WebHook削除
	register_rest_route('itmar-ec-relate/v1', '/shopify-webhook-delete', [
		'methods'  => 'POST',
		'callback' => 'itmar_delete_shopify_webhook',
		'permission_callback' => function () {
			return current_user_can('edit_posts');
		},
	]);
	//Stripeのユーザー登録
	register_rest_route('itmar-ec-relate/v1', '/stripe-create-customer', [
		'methods' => 'POST',
		'callback' => 'itmar_stripe_create_customer',
		'permission_callback' => '__return_true',
	]);
	//shopifyユーザーの存在確認
	// register_rest_route('itmar-ec-relate/v1', '/shopify-validate-customer', [
	// 	'methods'  => 'POST',
	// 	'callback' => 'itmar_validate_shopify_customer',
	// 	'permission_callback' => '__return_true',
	// ]);
	//WordPressからのログアウト
	register_rest_route('itmar-ec-relate/v1', '/wp-logout-redirect', [
		'methods'  => 'POST',
		'callback' => 'itmar_wp_logout_redirect',
		'permission_callback' => '__return_true',
	]);
	//カスタマートークンの交換
	register_rest_route('itmar-ec-relate/v1', '/customer-token-exchange', [
		'methods'  => 'POST',
		'callback' => 'itmar_exchange_shopify_token',
		'permission_callback' => '__return_true', // 必要に応じて認可チェック
	]);
	//カートと顧客の紐づけ
	register_rest_route('itmar-ec-relate/v1', '/shopify-cart-customer-bind', [
		'methods'  => 'POST',
		'callback' => 'itmar_bind_customer_to_cart',
		'permission_callback' => function () {
			return current_user_can('read'); // 適宜制限（もしくは true）
		},
	]);
});


//Shopifyからの通知をうける
function itmar_shopify_webhook_callback(WP_REST_Request $request)
{
	$headers = $request->get_headers();
	// トピックを取得
	$topic = $headers['x_shopify_topic'][0] ?? '';
	$customer_data = $request->get_json_params();

	// Shopify customer_id
	$shopify_customer_id = $customer_data['id'] ?? null;
	$state = $customer_data['state'] ?? '';

	if (!$shopify_customer_id) {
		return new WP_Error('missing_customer_id', 'customer_id が見つかりません', ['status' => 400]);
	}

	if ($state === 'enabled') {
		// WordPress 側 wp_user_pending テーブル更新 or wp_user 作成

		// 例： wp_user_pending 更新
		global $wpdb;
		$pending_table = $wpdb->prefix . 'user_pending';

		$updated = $wpdb->update(
			$pending_table,
			['status' => 'enabled'],
			['shopify_customer_id' => $shopify_customer_id],
			['%s'],
			['%d']
		);

		// 必要なら wp_user に昇格登録してもOK
	}

	return new WP_REST_Response([
		'received'        => true,
		'shopify_customer_id' => $shopify_customer_id,
		'state'           => $state,
	], 200);
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

	//商品情報を格納しているポストタイプを登録
	if (isset($params['productPost'])) {
		update_option('product_post', sanitize_text_field($params['productPost']));
	}
	//Shopifyのトークン等

	if (!isset($params['shop_domain'])) {
		return new WP_Error('missing_params', __('Required domain not available.', "ec-relate-bloks"), ['status' => 400]);
	}
	if (!isset($params['admin_token']) || !isset($params['storefront_token'])) {
		return new WP_Error('missing_params', __('Required token not available.', "ec-relate-bloks"), ['status' => 400]);
	}
	update_option('shopify_shop_domain', sanitize_text_field($params['shop_domain']));
	update_option('shopify_admin_token', sanitize_text_field($params['admin_token']));
	update_option('shopify_storefront_token', sanitize_text_field($params['storefront_token']));


	//Stripeのキー

	if (!isset($params['stripe_key'])) {
		return new WP_Error('missing_params', __('Required API KEY not available.', "ec-relate-bloks"), ['status' => 400]);
	}
	update_option('stripe_key', sanitize_text_field($params['stripe_key']));
	// ← ここが必要
	return rest_ensure_response(['status' => 'ok']);
}

//顧客登録処理
function itmar_shopify_customer_form(WP_REST_Request $request)
{
	// nonce チェック
	$params = $request->get_json_params();
	$nonce  = sanitize_text_field($params['nonce'] ?? '');


	if (!wp_verify_nonce($nonce, 'wp_rest')) {
		return new WP_Error('invalid_nonce', 'Invalid nonce', ['status' => 403]);
	}

	// form_data 取得（フロントエンドから送信された内容）
	$form_data = $params['form_data'] ?? [];

	//メールチェック
	$email  = sanitize_email($form_data['email'] ?? '');
	if (empty($email)) {
		return new WP_REST_Response(array(
			'success' => false,
			'data' => array(
				'err_code' => 'missing_email'
			)
		), 200);
	}
	// WordPress ユーザー検索（wp_user）
	$user = get_user_by('email', $email);

	// 既に登録済みなら終了
	if ($user) {
		$customer_id = get_user_meta($user->ID, 'shopify_customer_id', true);
		if ($customer_id) {
			return new WP_REST_Response(array(
				'success' => true,
			), 200);
		}
		//ログインユーザー情報でshopify顧客を生成
		$result = itmar_create_shopify_customer($user, true);
	} else {
		//フロントエンドで入力されたデータを取得
		$first_name = sanitize_text_field($form_data['memberFirstName'] ?? '');
		$last_name = sanitize_text_field($form_data['memberLastName'] ?? '');
		$name = $form_data['memberDisplayName'] ? sanitize_text_field($form_data['memberFirstName'] ?? '') : $first_name . $last_name;
		$password = $form_data['password'] ?? '';

		// WP_User風のオブジェクトを生成
		$user = (object) [
			'ID'           => 0, // 仮登録など、まだユーザーIDがない場合は 0
			'user_password'    => $password,
			'user_email'   => $email,
			'display_name' => $name,
			'first_name'   => $first_name,
			'last_name'    => $last_name,
			'nickname'     => $name,
			'user_nicename' => sanitize_title($name),
			'user_url'     => '',
			'user_registered' => current_time('mysql'),
			'roles'        => ['subscriber'],
		];
		//shopify登録処理
		$result = itmar_create_shopify_customer($user, false);
	}

	return new WP_REST_Response($result, 200);
}


//shopifyユーザーの登録処理
function itmar_create_shopify_customer($user, $is_save)
{
	// Shopify 送信用 データ組立
	$shop_domain = get_option('shopify_shop_domain');
	$admin_token = get_option('shopify_admin_token');

	$customer_payload = [
		'first_name' => $user->first_name ?: '',
		'last_name'  => $user->last_name  ?: '',
		'email'      => $user->user_email ?: '',
		'verified_email'   => true,
		'tags'             => 'WP-Site-User', // 任意
	];


	// 顧客登録 API 呼び出し
	$response = wp_remote_post("https://{$shop_domain}/admin/api/2025-04/customers.json", [
		'headers' => [
			'X-Shopify-Access-Token' => $admin_token,
			'Content-Type'           => 'application/json',
		],
		'body' => json_encode([
			'customer' => $customer_payload
		])
	]);

	$body = json_decode(wp_remote_retrieve_body($response), true);

	//既に登録されているなどのエラー
	if (!isset($body['customer']['id'])) {
		return array(
			'success' => false,
			'data' => array(
				'err_code' => 'email_exists'
			)
		);
	}

	$customer_id = $body['customer']['id'];

	if ($is_save) { //既にWordPressユーザー登録が終わっている
		//shopifyで登録したユーザーIDをuser_metaに保存
		update_user_meta($user->ID, 'shopify_customer_id', $customer_id);
	} else { //WordPressへの仮登録
		// 必要に応じて仮登録のテーブル作成
		itmar_create_pending_users_table_if_not_exists();
		// DB保存
		global $wpdb;
		$table = $wpdb->prefix . 'pending_users';
		// トークン生成（64文字程度の一意な文字列）
		$token = wp_generate_password(48, false, false);

		$result = $wpdb->insert(
			$table,
			[
				'email' => $user->user_email ?: '',
				'name' => $user->display_name,
				'first_name' => $user->first_name,
				'last_name' => $user->last_name,
				'password' => $user->user_password,
				'token' => $token,
				'created_at' => current_time('mysql'),
				'is_used' => 0,
			],
			['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d']
		);

		if (!$result) {
			wp_send_json_error([['err_code' => 'save_error']]);
		}
	}

	return array(
		'success' => true,
		'data' => array(
			'customer_id' => $customer_id
		)
	);
}



//shopifyユーザーの存在確認
function itmar_validate_shopify_customer()
{
	// nonce チェック
	if (!wp_verify_nonce($_POST['nonce'], 'wp_rest')) {
		wp_send_json_error(['message' => 'Nonce validation failed']);
		exit;
	}


	//WordPressユーザー
	$wp_current_user = wp_get_current_user();
	$wp_user_mail = $wp_current_user->user_email;
	//WordPressユーザー情報からカート情報を取得
	$shopify_cart_id = get_user_meta($wp_current_user->ID, 'shopify_cart_id', true);

	//トークンの取得
	$customer_token = sanitize_text_field($_POST['customerAccessToken'] ?? '');

	if ($customer_token) {
		//カスタマトークンによる顧客情報の取得
		$shop_id   = isset($_POST['shop_id']) ? trim($_POST['shop_id']) : '';
		//shopifyカスタマトークンによるカスタマ情報の取得
		$endpoint = "https://shopify.com/{$shop_id}/account/customer/api/2025-04/graphql";

		$query = <<<GQL
query {
  customer {
    id
    emailAddress {
      emailAddress
    }
    firstName
    lastName
  }
}
GQL;


		$response = wp_remote_post($endpoint, [
			'headers' => [
				'Content-Type'  => 'application/json',
				'Authorization' => $customer_token,
			],
			'body' => json_encode([
				'query' => $query,
			]),
		]);
	}

	//問い合わせ結果を返す
	if (is_wp_error($response)) {
		return new WP_REST_Response([
			'success' => false,
			'message' => 'Shopify APIへの接続に失敗しました',
			'error' => $response->get_error_message(),
		], 500);
	}

	$body = json_decode(wp_remote_retrieve_body($response), true);
	$customer = $body['customer'] ?? $body['data']['customer'] ?? null;

	if ($customer) {
		// Shopify上、有効な顧客
		if (!is_user_logged_in()) { //ログイン状態でない場合は仮登録状態かどうかを検査
			$pending_user = itmar_pending_user_check($customer['emailAddress']['emailAddress']);
			if ($pending_user) { //トークンから割り出されたユーザーと仮登録ユーザーが一致すれば本ユーザーにしてログインさせる
				$res = itmar_process_token_registration($pending_user->token, true);
				if ($res['success']) {
					$is_login = is_user_logged_in();
					wp_send_json_success([
						'valid' => false,
						'reload' => $is_login,
						'message' => 'ユーザーの本登録成功'
					]);

					exit;
				}
			}
		}
		wp_send_json_success([
			'valid' => true,
			'customer' => $customer,
			'wp_user_id' => $wp_current_user->ID,
			'wp_user_mail' => $wp_user_mail,
			'cart_id' => $shopify_cart_id
		]);
		exit;
	} else {
		// 不存在
		wp_send_json_success([
			'valid' => false,
			'wp_user_mail' => $wp_user_mail,
			'message' => 'Shopify上の顧客情報が見つかりません'
		]);
		exit;
	}
}

add_action('wp_ajax_shopify-validate-customer', 'itmar_validate_shopify_customer');
add_action('wp_ajax_nopriv_shopify-validate-customer', 'itmar_validate_shopify_customer');


//カスタマトークンでカートを紐づけする関数
function itmar_bind_customer_to_cart(WP_REST_Request $request)
{
	// nonce チェック
	$params = $request->get_json_params();
	$nonce  = sanitize_text_field($params['nonce'] ?? '');

	if (!wp_verify_nonce($nonce, 'wp_rest')) {
		return new WP_Error('invalid_nonce', 'Invalid nonce', ['status' => 403]);
	}

	//パラメータ取得
	$cartId = sanitize_text_field($params['cart_id'] ?? '');
	$customerToken = sanitize_text_field($params['customer_token'] ?? '');

	if (!$cartId || !$customerToken) {
		return new WP_REST_Response(['error' => 'Missing parameters'], 400);
	}

	$query = <<<GRAPHQL
    mutation cartBuyerIdentityUpdate(\$cartId: ID!, \$buyerIdentity: CartBuyerIdentityInput!) {
      cartBuyerIdentityUpdate(cartId: \$cartId, buyerIdentity: \$buyerIdentity) {
        cart {
          id
          buyerIdentity {
            customer {
              id
              email
            }
          }
        }
        userErrors {
          field
          message
        }
      }
    }
  GRAPHQL;

	$variables = [
		'cartId' => $cartId,
		'buyerIdentity' => [
			'customerAccessToken' => $customerToken
		]
	];

	$shop_domain = get_option('shopify_shop_domain');
	$access_token   = get_option('shopify_storefront_token');

	$endpoint = "https://{$shop_domain}/api/2025-04/graphql.json";


	$response = wp_remote_post($endpoint, [
		'headers' => [
			'Content-Type' => 'application/json',
			'X-Shopify-Storefront-Access-Token' => $access_token,
		],
		'body' => json_encode([
			'query' => $query,
			'variables' => $variables
		]),
		'timeout' => 20,
	]);

	if (is_wp_error($response)) {
		return new WP_REST_Response(['error' => $response->get_error_message()], 500);
	}

	$body = json_decode(wp_remote_retrieve_body($response), true);
	return new WP_REST_Response($body);
}

//WordPressログアウトのURL生成
function itmar_wp_logout_redirect(WP_REST_Request $request)
{
	// nonce チェック
	$params = $request->get_json_params();
	$nonce  = sanitize_text_field($params['nonce'] ?? '');

	if (!wp_verify_nonce($nonce, 'wp_rest')) {
		return new WP_Error('invalid_nonce', 'Invalid nonce', ['status' => 403]);
	}

	$redirect_to = esc_url_raw($request->get_param('redirect_url'));
	if (!$redirect_to) {
		$redirect_to = home_url(); // フォールバック先
	}
	$logout_url = wp_logout_url($redirect_to);
	// HTMLエンティティを戻す
	$logout_url = html_entity_decode($logout_url);

	wp_send_json_success([
		'logout_url' => $logout_url,
	]);
}
//カスタマートークンの発行
function itmar_exchange_shopify_token(WP_REST_Request $request)
{
	// nonce チェック
	$params = $request->get_json_params();
	$nonce  = sanitize_text_field($params['nonce'] ?? '');

	if (!wp_verify_nonce($nonce, 'wp_rest')) {
		return new WP_Error('invalid_nonce', 'Invalid nonce', ['status' => 403]);
	}
	$client_id = isset($params['client_id']) ? trim($params['client_id']) : '';
	$shop_id   = isset($params['shop_id']) ? trim($params['shop_id']) : '';
	$code          = isset($params['code']) ? trim($params['code']) : '';
	$code_verifier = isset($params['code_verifier']) ? trim($params['code_verifier']) : '';
	$redirect_uri  = isset($params['redirect_uri']) ? esc_url_raw($params['redirect_uri']) : '';
	if (!$code || !$code_verifier || !$redirect_uri || !$client_id || !$shop_id) {
		return new WP_REST_Response([
			'success' => false,
			'message' => '必要なパラメータが不足しています',
		], 400);
	}



	$token_endpoint = "https://shopify.com/authentication/{$shop_id}/oauth/token";

	$response = wp_remote_post($token_endpoint, [
		'headers' => [
			'Content-Type' => 'application/x-www-form-urlencoded',
			'Accept'        => 'application/json',
		],
		'body' => http_build_query([
			'client_id'     => $client_id,
			'code'          => $code,
			'code_verifier' => $code_verifier,
			'grant_type'    => 'authorization_code',
			'redirect_uri'  => $redirect_uri,
		]),
	]);

	if (is_wp_error($response)) {
		return new WP_REST_Response([
			'success' => false,
			'message' => 'Shopifyトークン交換失敗',
			'error'   => $response->get_error_message(),
		], 500);
	}

	$body = json_decode(wp_remote_retrieve_body($response), true);

	if (isset($body['error'])) {
		return new WP_REST_Response([
			'success' => false,
			'error' => $body['error'],
			'description' => $body['error_description'] ?? 'No description'
		], 400);
	}

	return new WP_REST_Response([
		'success' => true,
		'token'   => $body,
	], 200);
}

//Wordpressからshopifyに商品を登録する
add_action('save_post', function ($post_id, $post) {
	$product_post = get_option('product_post');
	//オプションで指定された投稿タイプでのみ処理する
	if ($post->post_type === $product_post) {
		// 自動保存・リビジョンを無視
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
		if ('revision' === get_post_type($post_id)) return;
		// 非公開 or ゴミ箱 の場合 → 商品削除
		if (in_array($post->post_status, ['draft', 'trash'])) {
			$shopify_id = get_post_meta($post_id, 'shopify_product_id', true);
			if (!$shopify_id) return;
			//Shopifyの処理
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
	}
}, 20, 2);

// 実行用フック
add_action('itmar_shopify_sync_cron', 'itmar_register_shopify_product_from_wp');


function itmar_register_shopify_product_from_wp($post_id)
{
	//カスタム投稿投稿タイプ（product）にのみ対応
	$product_post = get_option('product_post');
	if (get_post_type($post_id) !== $product_post) return;

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
	$lineId = sanitize_text_field($params['lineId'] ?? '');
	$variantId = sanitize_text_field($params['productId'] ?? ''); // これは "gid://shopify/ProductVariant/..." の形式であること
	$quantity = absint($params['quantity'] ?? 0);
	$cartId = sanitize_text_field($params['cartId'] ?? null);
	$mode = sanitize_text_field($params['mode'] ?? '');
	$wp_user_id = sanitize_text_field($params['wp_user_id'] ?? '');

	//モードによってカートの生成を変える
	$shouldCreateNewCart = ($mode === 'soon_buy');
	//すぐに買うでなく、クライアントからcartIDの指定がない
	if (!$shouldCreateNewCart && !$cartId) {
		$cartId = $_COOKIE['shopify_cart_id'] ?? null;
	}


	if ($cartId) {
		if ($mode === 'into_cart' && $variantId) {
			// 既存カートに追加（cartLinesAdd）
			$query = <<<GQL
mutation {
  cartLinesAdd(
    cartId: "$cartId",
    lines: [
      {
        merchandiseId: "$variantId",
        quantity: $quantity
      }
    ]
  ) {
    cart {
      id
	  buyerIdentity {
		customer {
			id
			email
		}
	  }
      checkoutUrl
	  lines(first: 100) {
      edges {
        node {
		  id
          quantity
		  merchandise {
            ... on ProductVariant {
              id
              title
              price {
                amount
                currencyCode
              }
              product {
                id
                title
                handle
                featuredImage {
                  url
                  altText
                }
				
              }
            }
          }
        }
      }
	}
    }
    userErrors {
      field
      message
    }
  }
}
GQL;
		} elseif ($mode === 'trush_out' && $lineId) {
			// カートから削除（cartLinesRemove）
			$query = <<<GQL
mutation {
  cartLinesRemove(
    cartId: "$cartId",
    lineIds: ["$lineId"]
  ) {
    cart {
      id
      checkoutUrl
      lines(first: 100) {
        edges {
          node {
            id
            quantity
            merchandise {
              ... on ProductVariant {
                id
                title
                price { amount currencyCode }
                product { id title handle featuredImage { url altText } }
              }
            }
          }
        }
      }
      estimatedCost {
        subtotalAmount { amount currencyCode }
        totalAmount    { amount currencyCode }
      }
    }
    userErrors { field message }
  }
}
GQL;
		} else {
			//カートデータの読み込みのみ
			$query = <<<GQL
query {
  cart(id: "$cartId") {
    id
	buyerIdentity {
      customer {
        id
        email
      }
    }
    checkoutUrl
    lines(first: 100) {
      edges {
        node {
		  id
          quantity
		  merchandise {
            ... on ProductVariant {
              id
              title
              price {
                amount
                currencyCode
              }
              product {
                id
                title
                handle
                featuredImage {
                  url
                  altText
                }
				
              }
            }
          }
        }
      }
    }
  }
}
GQL;
		}
	} else {
		// 新しいカート作成（cartCreate）
		$query = <<<GQL
mutation {
  cartCreate(
    input: {
      lines: [
        {
          merchandiseId: "$variantId",
          quantity: $quantity
        }
      ]
    }
  ) {
    cart {
      id
      checkoutUrl
	  lines(first: 50) {
        edges {
          node {
            quantity
          }
        }
      }
    }
    userErrors {
      field
      message
    }
  }
}
GQL;
	}

	//カスタムエンドポイントに問い合わせ
	$shop_domain = get_option('shopify_shop_domain');
	$token = get_option('shopify_storefront_token');
	$response = wp_remote_post("https://{$shop_domain}/api/2025-04/graphql.json", [
		'headers' => [
			'X-Shopify-Storefront-Access-Token' => $token,
			'Content-Type' => 'application/json',
		],
		'body' => json_encode(['query' => $query]),
	]);

	if (is_wp_error($response)) {
		return new WP_REST_Response(['success' => false, 'message' => '通信エラー'], 500);
	}

	$data = json_decode(wp_remote_retrieve_body($response), true);
	$cart = $data['data']['cartCreate']['cart']
		?? $data['data']['cartLinesAdd']['cart']
		?? $data['data']['cart']
		?? null;

	if (!$cart) {
		return new WP_REST_Response([
			'success' => false,
			'message' => 'カートの作成に失敗しました',
		], 500);
	}
	// 「すぐに購入」でない場合
	$itemCount = 0;
	if ($mode !== 'soon_buy') {
		if ($wp_user_id) {
			//cartId をuser_metaに保存
			update_user_meta($wp_user_id, 'shopify_cart_id', $cart['id']);
		} else {
			//cartId をcookieに保存
			setcookie('shopify_cart_id', $cart['id'], time() + WEEK_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN);
		}
		// 商品数を合計
		if (!empty($cart['lines']['edges'])) {
			foreach ($cart['lines']['edges'] as $edge) {
				$itemCount += intval($edge['node']['quantity']);
			}
		}
	}


	return new WP_REST_Response([
		'success' => true,
		'cartId' => $cart['id'],
		'buyerId' => $cart['buyerIdentity']['customer'],
		'cartContents' => $cart['lines']['edges'],
		'checkoutUrl' => $cart['checkoutUrl'],
		'itemCount' => $itemCount,
	], 200);
}

//登録されているWebhookのリストを取得
function itmar_get_shopify_webhook_list(WP_REST_Request $request)
{
	$shop_domain  = get_option('shopify_shop_domain');
	$admin_token  = get_option('shopify_admin_token');

	if (!$shop_domain || !$admin_token) {
		return new WP_Error('missing_credentials', 'Shopify認証情報が未設定です', ['status' => 400]);
	}
	//現在のコールバックURL
	$params = $request->get_json_params();
	$current_callback_url = sanitize_text_field($params['callbackUrl'] ?? '');

	$query = <<<GQL
    {
      webhookSubscriptions(first: 20) {
        edges {
          node {
            id
            topic
            endpoint {
              __typename
              ... on WebhookHttpEndpoint {
                callbackUrl
              }
            }
          }
        }
      }
    }
    GQL;

	$response = wp_remote_post("https://{$shop_domain}/admin/api/2025-04/graphql.json", [
		'headers' => [
			'X-Shopify-Access-Token' => $admin_token,
			'Content-Type'           => 'application/json',
		],
		'body' => json_encode(['query' => $query]),
	]);

	if (is_wp_error($response)) {
		return new WP_Error('api_error', 'Shopify APIエラー', $response->get_error_message());
	}

	$body = json_decode(wp_remote_retrieve_body($response), true);

	$edges = $body['data']['webhookSubscriptions']['edges'] ?? [];

	$valid_webhooks = [];

	foreach ($edges as $edge) {
		$id = $edge['node']['id'];
		$topic = $edge['node']['topic'];
		$callback = $edge['node']['endpoint']['callbackUrl'] ?? '';

		if ($callback === $current_callback_url) {
			// 一致するURL → 残す
			$valid_webhooks[] = [
				'id' => $id,
				'topic' => $topic,
				'callbackUrl' => $callback,
			];
		} else {
			// 一致しないURL → 削除
			$delete_query = <<<GQL
mutation {
			  webhookSubscriptionDelete(id: "$id") {
				userErrors {
				  field
				  message
				}
				deletedWebhookSubscriptionId
			  }
			}
GQL;

			$delete_response = wp_remote_post("https://{$shop_domain}/admin/api/2025-04/graphql.json", [
				'headers' => [
					'X-Shopify-Access-Token' => $admin_token,
					'Content-Type'           => 'application/json',
				],
				'body' => json_encode(['query' => $delete_query]),
			]);
		}
	}

	return [
		'webhooks' => $valid_webhooks,
	];
}
//Webhookを登録
function itmar_register_shopify_webhook(WP_REST_Request $request)
{
	$params      = $request->get_json_params();
	$topic       = sanitize_text_field($params['topic'] ?? '');
	$callbackUrl = esc_url_raw($params['callbackUrl'] ?? '');

	if (empty($topic) || empty($callbackUrl)) {
		return new WP_Error('invalid_params', __("Required parameters are missing", "ec-relate-bloks"), ['status' => 400]);
	}

	$shop_domain  = get_option('shopify_shop_domain');
	$admin_token  = get_option('shopify_admin_token');

	// REST API の topic は lowercase / slash区切り → 変換
	$topic_rest = strtolower(str_replace('_', '/', $topic));

	$response = wp_remote_post("https://{$shop_domain}/admin/api/2025-04/webhooks.json", [
		'headers' => [
			'X-Shopify-Access-Token' => $admin_token,
			'Content-Type'           => 'application/json',
		],
		'body' => json_encode([
			'webhook' => [
				'topic'   => $topic_rest,
				'address' => $callbackUrl,
				'format'  => 'json',
			]
		]),
	]);

	if (is_wp_error($response)) {
		return new WP_Error('api_error', __("Shopify API Error", "ec-relate-bloks"), $response->get_error_message());
	}

	$body = json_decode(wp_remote_retrieve_body($response), true);

	if (!empty($body['webhook']['id'])) {
		return [
			'success' => true,
			'id'      => $body['webhook']['id'],
		];
	} else {
		return new WP_Error(
			'create_failed',
			'Webhook create failed',
			$body
		);
	}
}

//Webhookを削除
function itmar_delete_shopify_webhook(WP_REST_Request $request)
{
	$params     = $request->get_json_params();
	$gid        = sanitize_text_field($params['webhook_id'] ?? '');

	if (empty($gid)) {
		return new WP_Error('invalid_params', 'Webhook IDが不足しています', ['status' => 400]);
	}

	// gid://shopify/WebhookSubscription/XXXXXXXXX → 数字だけ抜き出し
	if (preg_match('#WebhookSubscription/(\d+)$#', $gid, $matches)) {
		$webhook_id = (int) $matches[1];
	} else {
		return new WP_Error('invalid_gid', 'Webhook ID形式が正しくありません', ['id' => $gid]);
	}

	$shop_domain  = get_option('shopify_shop_domain');
	$admin_token  = get_option('shopify_admin_token');

	$response = wp_remote_request("https://{$shop_domain}/admin/api/2025-04/webhooks/{$webhook_id}.json", [
		'method'  => 'DELETE',
		'headers' => [
			'X-Shopify-Access-Token' => $admin_token,
		],
	]);

	if (is_wp_error($response)) {
		return new WP_Error('api_error', 'Shopify APIエラー', $response->get_error_message());
	}

	if (wp_remote_retrieve_response_code($response) === 200) {
		return [
			'success' => true,
			'deleted_id' => $webhook_id,
		];
	} else {
		return new WP_Error(
			'delete_failed',
			'Webhook削除に失敗しました',
			[
				'status_code' => wp_remote_retrieve_response_code($response),
				'body'        => wp_remote_retrieve_body($response),
			]
		);
	}
}

//Stripeの顧客登録
function itmar_stripe_create_customer(WP_REST_Request $request)
{

	// POSTデータ受け取り
	$params = $request->get_json_params();
	$form_data = isset($params['form_data']) ? $params['form_data'] : [];

	// 必須項目チェック
	if (empty($form_data['email'])) {
		return new WP_Error('missing_data', 'Emailが未入力です', array('status' => 400));
	}

	// Stripe の API キーをセット
	$stripeKey = get_option('stripe_key');
	\Stripe\Stripe::setApiKey($stripeKey);

	try {
		// Stripe Customer 作成
		$customer = \Stripe\Customer::create([
			'email' => sanitize_email($form_data['email']),
			'name'  => $form_data['first_name'] . ' ' . $form_data['last_name'],
			// 必要なら phone, address なども追加
		]);

		// 成功時レスポンス
		return rest_ensure_response([
			'success'     => true,
			'customer_id' => $customer->id, // Stripe 側の customer ID
			'message'     => 'Stripe customer created successfully.',
		]);
	} catch (Exception $e) {
		// エラー時レスポンス
		return new WP_Error('stripe_error', $e->getMessage(), array('status' => 500));
	}
}
//商品リスト取得用の関数

function itmar_shopify_products_filtered($request)
{
	$field_templates = [
		'title' => 'title',
		'handle' => 'handle',
		'description' => 'description',
		'descriptionHtml' => 'descriptionHtml',
		'vendor' => 'vendor',
		'productType' => 'productType',
		'availableForSale' => 'availableForSale',
		'tags' => 'tags',
		'onlineStoreUrl' => 'onlineStoreUrl',
		'createdAt' => 'createdAt',
		'updatedAt' => 'updatedAt',

		// ✅ 最初の画像・動画・YouTube動画に対応（サムネイル画像含む）
		'medias' => <<<GQL
media(first: 250) {
  edges {
    node {
      mediaContentType
	  ... on MediaImage {
            image {
              url
              altText
              width
              height
            }
          }
      ... on Video {
            alt
            sources {
              url
              format
              mimeType
              width
              height
            }
          }
		}
	}
}
GQL,

		'variants' => <<<GQL
variants(first: 10) {
  edges {
    node {
		id
		title
      	price {
        	amount
        	currencyCode
      	}
		compareAtPrice {
        	amount
        	currencyCode
      	}
    }
  }
}
GQL,
	];

	$shopify_domain = get_option('shopify_shop_domain');
	$access_token   = get_option('shopify_storefront_token');

	$fields = $request->get_param('fields');
	if (!is_array($fields) || empty($fields)) {
		return new WP_Error('invalid_fields', 'fields パラメータが必要です。', ['status' => 400]);
	}

	// 取得件数
	$item_num = (int) $request->get_param('itemNum');

	// 上限値を設定（Shopify APIの仕様では最大250）
	if ($item_num <= 0) {
		$item_num = 10; // デフォルト値
	} elseif ($item_num > 250) {
		$item_num = 250; // 上限保護
	}

	// フィールドをそのまま GraphQL フィールドとして使用
	$selectedFields = array_filter($fields, fn($f) => isset($field_templates[$f]));
	//variantsは必ず含める
	$selectedFields[] = 'variants';
	// image または images が指定されていれば medias を含める
	if (in_array('image', $fields, true) || in_array('images', $fields, true)) {
		$selectedFields[] = 'medias';
	}
	$graphqlFieldStr = implode("\n", array_map(fn($f) => $field_templates[$f], $selectedFields));

	$query = <<<GQL
	{
		products(first: {$item_num} sortKey: CREATED_AT, reverse: true) {
	         edges {
				node {
					{$graphqlFieldStr}
				}
			}
		}
	}
GQL;



	$response = wp_remote_post("https://{$shopify_domain}/api/2025-04/graphql.json", [
		'headers' => [
			'Content-Type' => 'application/json',
			'X-Shopify-Storefront-Access-Token' => $access_token,
		],
		'body' => json_encode(['query' => $query]),
	]);

	if (is_wp_error($response)) {
		return new WP_Error('api_error', $response->get_error_message(), ['status' => 500]);
	}

	$body = json_decode(wp_remote_retrieve_body($response), true);
	$products = $body['data']['products']['edges'] ?? [];

	return array_map(fn($product) => $product['node'], $products);
}
