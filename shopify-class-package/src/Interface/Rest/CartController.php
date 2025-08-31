<?php

namespace Itmar\ShopifyClassPackage\Interface\Rest;

use WP_REST_Request;
use WP_REST_Server;
use Itmar\ShopifyClassPackage\Support\Validation\Sanitizer;

final class CartController extends BaseController
{
  private Sanitizer $sanitizer;

  public function __construct()
  {
    $this->sanitizer = new Sanitizer();
  }

  public function registerRest(): void
  {
    $routes = [
      [
        'route'   => '/cart/lines',
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => [$this, 'updateLines'],
        'permission_callback' => $this->gate(null, 'wp_rest'), //Nonce だけ必須（ログイン不要）

      ],
      [
        'route'   => '/cart/bind',
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => [$this, 'customerBind'],
        'permission_callback' => $this->gate(null, 'wp_rest', true), //ログイン必須 + Nonce
      ],

    ];

    foreach ($routes as $r) {
      register_rest_route($this->ns(), $r['route'], [[
        'methods'  => $r['methods'],
        'callback' => $r['callback'],
        'permission_callback' => $r['permission_callback'],
      ]]);
    }
  }

  public function updateLines(WP_REST_Request $request)
  {
    try {
      $params = $request->get_json_params();

      $lineId = sanitize_text_field($params['lineId'] ?? '');
      $variantId = sanitize_text_field($params['productId'] ?? ''); // これは "gid://shopify/ProductVariant/..." の形式であること
      $quantity = absint($params['quantity'] ?? 0);
      $cartId = sanitize_text_field($params['cartId'] ?? null);
      $mode = sanitize_text_field($params['mode'] ?? '');
      $wp_user_id = sanitize_text_field($params['wp_user_id'] ?? '');

      $formDataObj = [];
      if (!empty($params['form_data'])) {
        $decoded = json_decode($params['form_data'], true); // 文字列→配列
        if (is_array($decoded)) {
          foreach ($decoded as $line) {
            $id = isset($line['id']) ? sanitize_text_field($line['id']) : '';
            $quantity = isset($line['quantity']) ? intval($line['quantity']) : 0;

            // 必要ならIDのパターンをバリデーション
            if (! preg_match('#^gid://shopify/CartLine/[a-z0-9\-]+#i', $id)) {
              continue; // 不正な形式ならスキップ
            }

            $formDataObj[] = [
              'id' => $id,
              'quantity' => $quantity,
            ];
          }
        }
      }

      //カート情報がないときはクッキーに残っていないか（ゲストカート）がないか確認
      if (!$cartId) {
        $cartId = $_COOKIE['shopify_cart_id'] ?? null;
      }
      //カート情報の取得用クエリ
      $CART_FIELDS = <<<GQL
id
buyerIdentity { customer { id email } }
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
		  quantityAvailable
          price { amount currencyCode }
          product {
            id
            title
            handle
            featuredImage { url altText }
          }
        }
      }
    }
  }
}
estimatedCost {
  subtotalAmount { amount currencyCode }
  totalAmount    { amount currencyCode }
  totalTaxAmount { amount currencyCode }
  totalDutyAmount { amount currencyCode }
}
GQL;

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
    cart { {$CART_FIELDS} }
    userErrors { field message }      
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
    cart { {$CART_FIELDS} }
    userErrors { field message }
  }
}
GQL;
        } elseif ($mode === 'calc_again') {
          $linesStr = implode(",\n", array_map(function ($line) {
            return sprintf(
              '{ id: "%s", quantity: %d }',
              addslashes($line['id']),
              intval($line['quantity'])
            );
          }, $formDataObj));
          // カートから削除（cartLinesRemove）
          $query = <<<GQL
mutation {
  cartLinesUpdate(
    cartId: "$cartId",
    lines: [{$linesStr}]
  ) {
    cart { {$CART_FIELDS} }
    userErrors { field, message, code }
    warnings { message }
  }
}
GQL;
        } else {
          //カートデータの読み込みのみ
          $query = <<<GQL
query {
  cart(id: "$cartId") {
    {$CART_FIELDS}
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
    cart { {$CART_FIELDS} }
    userErrors { field message }
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


      $data = json_decode(wp_remote_retrieve_body($response), true);
      $cart = $data['data']['cartCreate']['cart']
        ?? $data['data']['cartLinesAdd']['cart']
        ?? $data['data']['cartLinesRemove']['cart']
        ?? $data['data']['cartLinesUpdate']['cart']
        ?? $data['data']['cart']
        ?? null;


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

      return $this->ok([
        'success' => true,
        'cartId' => $cart['id'],
        'buyerId' => $cart['buyerIdentity']['customer'],
        'cartContents' => $cart['lines']['edges'],
        'estimatedCost' => $cart['estimatedCost'],
        'checkoutUrl' => $cart['checkoutUrl'],
        'itemCount' => $itemCount,
      ]);
    } catch (\Throwable $e) {
      return $this->fail($e);
    }
  }

  public function customerBind(WP_REST_Request $request)
  {

    //パラメータ取得
    $params = $request->get_json_params();
    $cartId = sanitize_text_field($params['cart_id'] ?? '');
    $customerToken = sanitize_text_field($params['customer_token'] ?? '');
    //パラメータの異常処理
    if (!$cartId) {
      return $this->fail(new \WP_Error(
        'rest_invalid_param',
        'Missing parameter: cartId',
        ['status' => 400, 'param' => 'cartId']
      ));
    }
    if (!$customerToken) {
      return $this->fail(new \WP_Error(
        'rest_invalid_param',
        'Missing parameter: customerToken',
        ['status' => 400, 'param' => 'customerToken']
      ));
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
      return $this->fail($response, 500);
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    return $this->ok($body);
  }
}
