import { __ } from "@wordpress/i18n";

import {
	Button,
	PanelBody,
	PanelRow,
	ToggleControl,
	Modal,
	Notice,
	ToolbarGroup,
	ToolbarButton,
	SelectControl,
	TextControl,
	__experimentalBoxControl as BoxControl,
	__experimentalBorderBoxControl as BorderBoxControl,
} from "@wordpress/components";
import {
	useBlockProps,
	InnerBlocks,
	InspectorControls,
	BlockControls,
	__experimentalPanelColorGradientSettings as PanelColorGradientSettings,
	__experimentalBorderRadiusControl as BorderRadiusControl,
} from "@wordpress/block-editor";

import { useState, useEffect, useRef } from "@wordpress/element";
import { useSelect, dispatch } from "@wordpress/data";
import {
	ArchiveSelectControl,
	borderProperty,
	radiusProperty,
	marginProperty,
	paddingProperty,
	useIsIframeMobile,
	isValidUrlWithUrlApi,
} from "itmar-block-packages";

import "./editor.scss";

export default function Edit({ attributes, setAttributes, clientId }) {
	const {
		provider,
		storeUrl,
		adminToken,
		storefrontToken,
		backgroundColor,
		backgroundGradient,
		default_val,
		mobile_val,
	} = attributes;

	//単色かグラデーションかの選択
	const bgColor = backgroundColor || backgroundGradient;

	//モバイルのフラグ
	const isMobile = useIsIframeMobile();

	//スペースのリセットバリュー
	const padding_resetValues = {
		top: "10px",
		left: "10px",
		right: "10px",
		bottom: "10px",
	};

	//ボーダーのリセットバリュー
	const border_resetValues = {
		top: "0px",
		left: "0px",
		right: "0px",
		bottom: "0px",
	};

	const units = [
		{ value: "px", label: "px" },
		{ value: "em", label: "em" },
		{ value: "rem", label: "rem" },
	];

	const blockProps = useBlockProps();

	//トークンをサーバに格納
	async function saveTokens() {
		const res = await fetch("/wp-json/itmar-ec-relate/v1/save-tokens", {
			method: "POST",
			headers: {
				"Content-Type": "application/json",
				"X-WP-Nonce": itmar_option.nonce, // ローカルスクリプトで渡す
			},
			credentials: "include",
			body: JSON.stringify({
				shop_domain: storeUrl,
				admin_token: adminToken,
				storefront_token: storefrontToken,
			}),
		});

		const json = await res.json();
		if (json.status === "ok") {
			console.log("トークン保存成功");
		} else {
			console.error("保存失敗", json);
		}
	}

	//商品情報の取得
	async function fetchShopifyProducts(storeUrl, adminToken, storefrontToken) {
		//引数がすべてそろってから処理する
		if (!storeUrl || !adminToken || !storefrontToken) {
			return;
		}

		const query = `
    {
        products(first: 5) {
            edges {
                node {
                    id
                    title
                    description
                    images(first: 1) {
                        edges {
                            node {
                                src
                            }
                        }
                    }
					variants(first: 1) {
						edges {
							node {
								id
							}
						}
					}
                }
            }
        }
    }`;

		const res = await fetch(`https://${storeUrl}/api/2025-04/graphql.json`, {
			method: "POST",
			headers: {
				"Content-Type": "application/json",
				"X-Shopify-Storefront-Access-Token": storefrontToken,
			},
			body: JSON.stringify({ query }),
		});

		const json = await res.json();
		return (json.data?.products?.edges || []).map(({ node }) => ({
			id: node.id,
			title: node.title,
			description: node.description,
			image: node.images?.edges[0]?.node?.src ?? "",
			variantId: node.variants?.edges[0]?.node?.id ?? null, // ✅ 追加
		}));
	}

	//ユーザー登録処理
	const createCustomer = async () => {
		// ユーザーが Shopify 顧客登録済かチェック
		const response = await fetch(
			"/wp-json/itmar-ec-relate/v1/check-or-create-customer",
			{
				method: "POST",
				credentials: "include",
				headers: {
					"X-WP-Nonce": itmar_option.nonce,
					"Content-Type": "application/json",
				},
			},
		);

		const customerData = await response.json();
		console.log(customerData);

		if (!customerData || !customerData.id) {
			console.error("Shopify顧客の取得/作成に失敗しました");
			return;
		}
	};

	//カートに入れる処理
	const purchaseProduct = async (productId) => {
		// 顧客IDが取得できたので、次にカート or チェックアウト処理へ
		await fetch("/wp-json/itmar-ec-relate/v1/create-checkout", {
			method: "POST",
			credentials: "include",
			headers: {
				"X-WP-Nonce": itmar_option.nonce,
				"Content-Type": "application/json",
			},
			body: JSON.stringify({
				productId: productId,
			}),
		})
			.then((res) => res.json())
			.then((data) => {
				// チェックアウトURLへ遷移（Shopifyホストの checkout 画面）
				window.open(data.checkout_url, "_blank");
			});
	};

	//商品情報を蓄える
	const [products, setProducts] = useState(null);
	//プロバイダ又はトークンの変更で商品情報の変更
	useEffect(() => {
		if (provider === "shopify") {
			fetchShopifyProducts(storeUrl, adminToken, storefrontToken).then(
				(products) => {
					setProducts(products);
				},
			);
		}
	}, [provider, storeUrl, adminToken, storefrontToken]);
	//トークンの変更があればサーバーに格納
	useEffect(() => {
		saveTokens();
	}, [storeUrl, adminToken, storefrontToken]);

	//編集中の値を確保するための状態変数
	const [url_editing, setUrlValue] = useState(storeUrl);
	const [store_editing, setStoreValue] = useState(storefrontToken);
	const [admin_editing, setAdminValue] = useState(adminToken);

	return (
		<>
			<InspectorControls>
				<PanelBody title={__("EC setting", "ec-relate-bloks")}>
					<SelectControl
						label={__("Provider", "ec-relate-bloks")}
						value={provider}
						options={[
							{ label: "Shopify", value: "shopify" },
							// 他のECにも拡張可
						]}
						onChange={(value) => setAttributes({ provider: value })}
					/>
					{provider === "shopify" && (
						<>
							<TextControl
								label={__("Store Site URL", "ec-relate-bloks")}
								value={url_editing}
								onChange={(newVal) => setUrlValue(newVal)} // 一時的な編集値として保存する
								onBlur={() => {
									setAttributes({ storeUrl: url_editing });
								}}
							/>
							<TextControl
								label={__("Admin API Token", "ec-relate-bloks")}
								value={admin_editing}
								onChange={(newVal) => setAdminValue(newVal)} // 一時的な編集値として保存する
								onBlur={() => {
									setAttributes({ adminToken: admin_editing });
								}}
							/>
							<TextControl
								label={__("Storefront API Token", "ec-relate-bloks")}
								value={store_editing}
								onChange={(newVal) => setStoreValue(newVal)} // 一時的な編集値として保存する
								onBlur={() => {
									setAttributes({ storefrontToken: store_editing });
								}}
							/>
						</>
					)}
				</PanelBody>
			</InspectorControls>

			<div {...blockProps}>
				<button onClick={createCustomer}>ユーザー登録</button>
				{products?.length ? (
					products.map((p) => (
						<div key={p.id}>
							<h3>{p.title}</h3>
							{p.image && <img src={p.image} width="200" />}
							<p>{p.description}</p>
							<button onClick={() => purchaseProduct(p.variantId)}>購入</button>
						</div>
					))
				) : products?.length === 0 ? (
					<p>入荷している商品はありません。</p>
				) : (
					<p>商品を読み込み中...</p>
				)}
			</div>
		</>
	);
}
