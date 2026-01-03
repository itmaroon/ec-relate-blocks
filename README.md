# EC RELATE BLOCKS

WordPress の商品ページを Shopify（在庫＋決済）と連携する Gutenberg ブロックプラグイン。

---

## 概要

EC RELATE BLOCKS は、WordPress サイトに Shopify のヘッドレス購買フローを組み込むための **2つの Gutenberg ブロック**を提供します。

- **`itmar/product-block`**
  - WordPress の投稿タイプを Shopify 商品と連携します。
  - 連携対象の投稿が公開／更新されると、（設定と権限に応じて）Shopify 側の商品データの作成／更新を行います。
  - WordPress を **コンテンツ（商品紹介ページ）層**、Shopify を **コマース（在庫＋決済）層**として運用する設計です。

- **`itmar/cart-block`**
  - Shopify Storefront API の Cart を利用したカート UI を提供します。
  - 購入手続きは Shopify Checkout へ遷移して完了します。

### 重要な考え方（推奨運用）

- 在庫と決済は **Shopify を正（source of truth）**として扱います。
- WordPress は **マーケティング／商品紹介コンテンツ**を正として扱います。
- 本プラグインは WordPress のページから Shopify Checkout へスムーズに誘導することに重点を置きます。

---

## セキュリティ／データ取り扱い（重要）

- **Admin API Token** と **Storefront API Token** は REST 経由で WordPress の **options にサーバーサイド保存**されます。  
  投稿コンテンツ（`post_content`）へ埋め込まれず、フロントエンド HTML へも出力されません。
- エディタ UI でも秘密情報トークンを **ブロック属性として永続化しない設計**です。トークンは `post_content` に保存されるべきではありません。

---

## ブロック概要

### itmar/product-block

サイトエディタ（テンプレート）に配置し、Shopify 接続と投稿タイプ連携を有効化します。

**主な設定（ブロックインスペクター → “EC setting”）**

- Select Product Post Type（連携する投稿タイプ）
- Store Site URL（Shopify ドメイン）
- Shop ID（Customer Account API 用）
- Channel Name（販売チャネル／公開先名）
- Headless Client ID（Customer Account API の `client_id`）
- Admin API Token（サーバーサイド保存。投稿には保存しません）
- Storefront API Token（サーバーサイド保存。投稿には保存しません）
- 表示関連（表示フィールド選択、表示数、カートアイコンのメディアID 等）

---

### itmar/cart-block

カート UI を表示します。Shopify Storefront API の Cart を利用して以下を行います。

- カート作成／更新（行の追加・数量変更・削除）
- 合計金額の表示
- Shopify Checkout URL を取得し、決済へ遷移

---

## Shopify 側の準備（必須）

Shopify ストアと API 資格情報が必要です。

### 1) Admin API Access Token（商品／顧客の管理）

- Shopify アプリ（Custom app）を作成し、Admin API アクセストークンを発行します。
- 必要最小限の権限のみ付与してください。

### 2) Storefront API Access Token（商品／カート／Checkout）

- Shopify 側で Storefront アクセストークンを作成します。

### 3) Customer Accounts（Headless）（任意。ただし顧客ログインを使う場合は推奨）

- Shopify の新しい Customer Accounts / Customer Account API を有効化します。
- Shopify の “Headless”（販売チャネル）をインストールし、Customer Account API 用 OAuth の **Client ID** を取得します。
- **Shop ID** も必要です。

**Shop ID 取得のヒント**

`https://{your-shop-domain}/.well-known/openid-configuration` の `issuer` が  
`https://shopify.com/authentication/{shopId}` 形式なので、末尾が Shop ID です。

---

## インストール

### WordPress 管理画面から

1. 管理画面 →「プラグイン」→「新規追加」へ移動
2. “EC RELATE BLOCKS” を検索
3. インストールして有効化

### ZIP から

1. プラグインの `.zip` をダウンロード
2. 管理画面 →「プラグイン」→「新規追加」
3. 「プラグインのアップロード」をクリックし `.zip` を選択 →「今すぐインストール」
4. 有効化

---

## クイックスタート（推奨手順）

### 1) 接続設定を行う

- サイトエディタを開きます。
- グローバルテンプレート（例：ヘッダー、またはサイト全体で使うテンプレート）に `itmar/product-block` を配置します。
- ブロックインスペクター → “EC setting” で以下を入力します:
  - Shopify ドメイン（Store Site URL）
  - Channel Name（例：Online Store）
  - Headless Client ID / Shop ID（顧客ログイン利用時）
  - Admin API Token / Storefront API Token（WordPress options に保存）

### 2) 連携する投稿タイプを選ぶ

- `itmar/product-block` で商品投稿タイプ（例：`product`）を選択します。

### 3) カートを追加する

- カート UI を表示したいテンプレート／ページに `itmar/cart-block` を配置します。

### 4) 商品投稿を公開する

- 選択した投稿タイプの投稿を作成／編集し、公開／更新します。
- これにより（設定に応じて）Shopify 側の商品作成／更新が行われます。

---

## FAQ

### このプラグインは WordPress 上でカード情報を処理しますか？

いいえ。決済は Shopify Checkout で行われます。本プラグインはカード情報を処理・保存しません。

### API トークンはどこに保存され、閲覧者に露出しますか？

トークンは WordPress の options にサーバーサイド保存されます。投稿コンテンツに埋め込まれず、フロントエンド HTML にも出力されません。

### Shopify Webhook は必要ですか？

現行バージョンでは不要です。  
在庫・決済は Shopify を正として扱い、必要に応じて Shopify から最新情報を取得する設計です。  
Webhook は将来的にキャッシュ無効化等の用途でオプション機能として追加される可能性があります。

### Shopify 側の在庫／価格変更は WordPress に即時反映されますか？

デフォルトでは Shopify を正として扱います。WordPress 側へ即時反映が必要な場合、短いキャッシュ設計や Webhook の導入（上級者向け）が一般的です。

### 顧客ログイン（Headless）には Shopify 側で何が必要ですか？

新しい Customer Accounts と Headless Client ID が必要です。ログインは OAuth 2.0 Authorization Code + PKCE を利用します。

---

## スクリーンショット（例）

1. 商品ブロック設定（EC setting）
2. 商品一覧／商品カードの出力例
3. カートブロック UI
4. Shopify Checkout への遷移例
5. （任意）顧客ログイン（Headless）フロー

---

## 更新履歴

### 0.1.0

- 初回リリース
- 商品ブロック（投稿タイプ連携＋Shopify連携）
- カートブロック（Storefront Cart＋Checkout 遷移）
- Customer Accounts（Headless）連携サポート

---

## 関連リンク

- ec-relate-blocks: GitHub  
  https://github.com/itmaroon/ec-relate-bloks
- block-class-package: GitHub  
  https://github.com/itmaroon/block-class-package
- block-class-package: Packagist  
  https://packagist.org/packages/itmar/block-class-package
- itmar-block-packages: npm  
  https://www.npmjs.com/package/itmar-block-packages
- itmar-block-packages: GitHub  
  https://github.com/itmaroon/itmar-block-packages

---

## 開発者向けメモ

1. PHP クラス管理は Composer を利用しています。  
2. 共通の関数／コンポーネントは npm パッケージとして公開し、他プラグインでも再利用しています。

---

## 外部サービス（External Services）

本プラグインは EC 機能提供のため、Shopify（外部サービス）へ通信します。

### サービス提供者

- Shopify

### 利用する API（有効化した機能／設定により異なります）

- Shopify Admin API（商品／顧客の管理）
- Shopify Storefront API（商品／カート／Checkout）
- Shopify Customer Account API / OAuth エンドポイント（任意：顧客ログイン）

### Shopify へ送信される可能性があるデータ（例）

- ストアドメイン、チャネル名、API 資格情報（サーバーサイド）
- 商品作成／更新に必要な商品データ（タイトル、説明、画像、価格、在庫数 等）
- カート行情報（バリアントID、数量）
- 顧客認証・本人性連携（OAuth のコード交換、customer access token 等：有効化時）

### 送信タイミング

- 設定保存時（管理者のみ）
- 商品／カート機能の表示やカート操作時
- WordPress 投稿の変更に基づいて Shopify 商品を作成／更新する時
- 顧客認証（有効化時）

### 重要

- 決済処理は Shopify が担当します。
- Shopify の利用規約・プライバシーポリシーをご確認ください:  
  https://www.shopify.com/legal/terms  
  https://www.shopify.com/legal/privacy
