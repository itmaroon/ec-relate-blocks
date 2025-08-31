import apiFetch from "@wordpress/api-fetch";
import { __ } from "@wordpress/i18n";
import { displayFormated, sendRegistrationRequest } from "itmar-block-packages";

//中継ページのDOMContentLoadedイベントハンドラ（ログイン・ログアウト後の元のページへのリダイレクト）
window.addEventListener("DOMContentLoaded", async () => {
	const urlParams = new URLSearchParams(window.location.search);
	//ログアウト処理
	const logoutCompleted = urlParams.get("shopify_logout_completed");

	if (logoutCompleted) {
		// 保存しておいたURLを取得
		const redirectTo =
			localStorage.getItem("shopify_logout_redirect_to") || "/";
		if (redirectTo) {
			// クリーンアップ
			localStorage.removeItem("shopify_shop_id");
			localStorage.removeItem("shopify_logout_redirect_to");
			localStorage.removeItem("shopify_client_access_token");
			localStorage.removeItem("shopify_client_id_token");
			document.cookie =
				"shopify_cart_id=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";

			// ログアウトして元のページに戻る

			try {
				const response = await sendRegistrationRequest(
					"/wp-json/itmar-ec-relate/v1/wp-logout-redirect",
					{ redirect_url: redirectTo, _wpnonce: itmar_option.nonce },
					"rest",
				);

				if (response.success && response.logout_url) {
					window.location.href = response.logout_url;
				} else {
					console.error("ログアウトURLの取得に失敗しました");
				}
			} catch (error) {
				console.error("ログアウト処理でエラー:", error);
			}
		}
	}

	//ログイン処理
	const code = urlParams.get("code");
	const state = urlParams.get("state");

	//codeとstateがURLに含まれるページに限る
	if (!code || !state) return;

	// LocalStorage に保存していた値を取り出す
	const shopId = localStorage.getItem("shopify_shop_id");
	const clientId = localStorage.getItem("shopify_client_id");
	const userMail = localStorage.getItem("shopify_user_mail");
	const redirectUri = localStorage.getItem("shopify_redirect_uri");
	const savedState = localStorage.getItem("shopify_state");
	const codeVerifier = localStorage.getItem("shopify_code_verifier");

	// バリデーション：state が一致しているか
	if (state !== savedState || !codeVerifier) {
		console.error("認証コードまたはステートが無効です。");
		return;
	}

	try {
		//トークンの交換用カスタムエンドポイントに送る
		const tokenChangeUrl =
			"/wp-json/itmar-ec-relate/v1/customer/token-exchange";
		const postData = {
			code: code, // Shopify OAuthから返されたコード
			code_verifier: codeVerifier, // ローカルで保持していた code_verifier
			redirect_uri: redirectUri, // 認証リクエストと同じリダイレクト先
			shop_id: shopId,
			client_id: clientId,
			user_mail: userMail,
			nonce: itmar_option.nonce,
		};
		//トークンの取得

		const token_res = await sendRegistrationRequest(
			tokenChangeUrl,
			postData,
			"rest",
		);
		if (token_res.success) {
			//トークンをlocalStrageに記録
			localStorage.setItem(
				"shopify_client_access_token",
				token_res.token.access_token,
			);
			localStorage.setItem("shopify_client_id_token", token_res.token.id_token);
			localStorage.setItem(
				"shopify_access_expires_at",
				Math.floor(token_res.expires_at / 1000),
			);
		} else {
			alert("トークンの取得に失敗しました。");
		}

		// リダイレクト先を復元
		const decodedState = JSON.parse(atob(state));
		const redirectTo = decodedState.return_url || "/";

		if (redirectTo) {
			localStorage.removeItem("shopify_code_verifier");
			localStorage.removeItem("shopify_state");
			localStorage.removeItem("shopify_nonce");

			window.location.href = redirectTo;
		} else {
			console.log("リダイレクト先が指定されていません。");
		}
	} catch (error) {
		console.error("エラーが発生しました:", error);
	}
});

jQuery(function ($) {
	//WordPressのログインユーザーとShopifyのログインユーザーの取得
	if (!itmar_option.isLoggedIn) {
		localStorage.removeItem("shopify_client_access_token"); //WordPressにログインしていなければlocalStrageのアクセストークンは消去
	}
	const shopId = localStorage.getItem("shopify_shop_id");
	const clientId = localStorage.getItem("shopify_client_id");
	const accessToken = localStorage.getItem("shopify_client_access_token");
	console.log("customer token: ", accessToken);

	//クッキーの取得関数
	function getCookie(name) {
		const match = document.cookie.match(
			new RegExp("(^| )" + name + "=([^;]+)"),
		);
		if (match) return match[2];
		return null;
	}
	//Design Titleのテキスト編集関数
	function textEmbed(embedText, embedDom) {
		const displayText =
			embedText != null
				? displayFormated(
						embedText,
						embedDom.data("user_format"),
						embedDom.data("free_format"),
						embedDom.data("decimal"),
				  )
				: null;
		embedDom.find("h1,h2,h3,h4,h5,h6").each(function () {
			const $div = $(this).find("div");
			if ($div.length > 0) {
				$div.text(displayText);
			}
		});
	}

	//カート情報の更新
	function updateCartInfo(prmObj) {
		//カートアイコンの処理
		const $cart_icon = $(
			`.wp-block-itmar-design-title[data-unique_id="${prmObj.uniqueId}"]`,
		);

		if ($cart_icon.length === 0) return;

		//カートアイコンに数量を設定
		if (prmObj.rawCartId) {
			textEmbed(prmObj.itemCount, $cart_icon);
		} else {
			//shopifyのみ再ログインさせる
			textEmbed(prmObj.itemCount, $cart_icon);

			// $cart_icon
			// 	.find(".modal_open_btn")
			// 	.off("click")
			// 	.on("click", function (e) {
			// 		e.preventDefault(); // デフォルトの挙動も止める
			// 		console.log("Shopify再ログイン処理を開始");
			// 		// ここに再ログインの処理
			// 	});
			return;
		}

		//カートの表示ダイアログ
		const modal_cart_id = $cart_icon.find(".modal_open_btn").data("modal_id");
		const modal_cart_dlg = $(`#${modal_cart_id}`);
		const cart_block = modal_cart_dlg.find(".wp-block-itmar-cart-block");
		//ひな型部分は非表示
		cart_block.find(".unit_hide").hide();
		//カートデータの書き換え
		replaceContent(
			prmObj.cartContents,
			prmObj.wp_user_id,
			prmObj.rawCartId,
			prmObj.uniqueId,
			cart_block,
		);

		// CheckOutのURLを書き換える
		const checkoutBtn = modal_cart_dlg.find('button[data-key="go_checkout"]');
		checkoutBtn.attr("data-selected_page", prmObj.checkoutUrl);

		//合計額の設定
		const subTotal = modal_cart_dlg.find(
			'div[data-unique_id="subtotalAmount"]',
		);
		textEmbed(prmObj.estimatedCost.subtotalAmount.amount, subTotal);

		const taxTotal = modal_cart_dlg.find(
			'div[data-unique_id="totalTaxAmount"]',
		);
		textEmbed(
			prmObj.estimatedCost.totalTaxAmount?.amount
				? prmObj.estimatedCost.totalTaxAmount?.amount
				: 0,
			taxTotal,
		);

		const total = modal_cart_dlg.find('div[data-unique_id="totalAmount"]');

		textEmbed(prmObj.estimatedCost.totalAmount.amount, total);
	}

	//カートのバインド処理関数
	const cart_bind = async (rawCartId, cart_icon_id, wp_user_id) => {
		if (!rawCartId) {
			//カート情報がないときはアイコンの処理のみ
			const prmObj = {
				uniqueId: cart_icon_id,
				rawCartId: rawCartId,
				itemCount: 0,
				checkoutUrl: "",
			};
			updateCartInfo(prmObj);
			return;
		}

		//カート情報の更新
		const targetUrl = "/wp-json/itmar-ec-relate/v1/cart/lines";
		const cartId = decodeURIComponent(rawCartId);

		const postData = {
			cartId: cartId,
			wp_user_id: wp_user_id,
			mode: "bind_cart",
			nonce: itmar_option.nonce,
		};
		const res = await sendRegistrationRequest(targetUrl, postData, "rest");

		if (res.success) {
			const mergedItems = res.cartContents.map((edge) => {
				const lineId = edge.node.id;
				const price = edge.node.merchandise.price;
				const quantityAvailable = edge.node.merchandise.quantityAvailable;
				const product = edge.node.merchandise.product;
				const quantity = edge.node.quantity;
				// priceとproductを1つのオブジェクトに合体
				return {
					...product,
					lineId,
					price, // priceプロパティを追加
					quantity, // quantityプロパティを追加
					quantityAvailable, //在庫数量を追加
				};
			});
			//カート情報の更新
			const prmObj = {
				uniqueId: cart_icon_id,
				wp_user_id: wp_user_id,
				rawCartId: rawCartId,
				itemCount: res.itemCount,
				estimatedCost: res.estimatedCost,
				checkoutUrl: res.checkoutUrl,
				cartContents: mergedItems,
			};
			updateCartInfo(prmObj);
		} else {
			console.warn("カートの情報はありません。");
		}

		//カスタマトークンがありカートと紐づけ未了の場合は紐づけ
		if (accessToken && !res.buyerId) {
			const targetUrl = "/wp-json/itmar-ec-relate/v1/cart/bind";

			const postData = {
				cart_id: cartId,
				customer_token: accessToken,
				nonce: itmar_option.nonce,
			};
			const res = await sendRegistrationRequest(targetUrl, postData, "rest");
			console.log("CART BINDレスポンス:", res);
			// 昇格成功後に Cookie を削除する
			document.cookie =
				"shopify_cart_id=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
		}
	};

	//jquery読み込み後のイベントハンドラ
	$(document).ready(function ($) {
		//メインブロックを含んだページのレンダリング時のハンドラ
		const main_block = $(".wp-block-itmar-product-block");

		//メインブロックを含まない場合は処理を抜ける
		if (main_block.length < 1) return;
		//カートのアイコンのIDを取得
		const cart_icon_id = main_block.data("cart_icon_id");

		//ひな型の要素をスケルトンスクリーンでラップする
		main_block
			.find(
				".unit_hide .wp-block-itmar-design-title,.wp-block-itmar-design-button,.itmar_ex_block",
			)
			.each(function () {
				$(this).wrap('<div class="hide-wrapper"></div>');
				$(this).css("visibility", "hidden"); // 中身を非表示
			});

		//WordPressとShopifyのログインユーザー検証をIDで行う
		let wp_user_id = "";
		let wp_user_email = "";
		let bind_cart_id = "";
		let rawCartId = "";

		//ユーザー情報の確認とカートの処理
		(async () => {
			try {
				if (shopId && accessToken) {
					const targetUrl = "/wp-admin/admin-ajax.php";
					const postData = {
						action: "validate-customer",
						shop_id: shopId,
						client_id: clientId,
						customerAccessToken: accessToken,
						_wpnonce: itmar_option.nonce,
					};
					const res = await sendRegistrationRequest(
						targetUrl,
						postData,
						"ajax",
					);
					console.log(res);
					//本登録の成功(Shopifyログインによるユーザー登録)
					if (res.success && res.data?.reload) {
						//一旦リロード
						window.location.reload();
					}

					//ログインユーザー情報の突き合わせ

					if (res.success) {
						wp_user_email = res.data.wp_user_mail;
						//トークンが有効ならユーザー情報が取れるのでそれをセット
						const shopify_customer_email = res.data.valid
							? res.data.customer?.emailAddress?.emailAddress
							: "";
						//リフレッシュトークンで認証されていたらlocalStrageのものを入れ替え
						if (res.data.access_token) {
							localStorage.setItem(
								"shopify_client_access_token",
								res.data.access_token,
							);
						}
						//メールアドレスが一致すればIDをセット
						if (wp_user_email === shopify_customer_email) {
							wp_user_id = res.data.wp_user_id;
							bind_cart_id = res.data.cart_id;
						}
					}
				}

				//取得するフィールド
				const selected_fields = main_block.data("selected_fields"); // [{ key, label, block }]
				if (!selected_fields) return;
				const field_keys = selected_fields.map((f) => f.key);

				//取得する商品数
				const itemNum = main_block.data("number_of_items");

				const productData = await apiFetch({
					path: "/itmar-ec-relate/v1/get-product",
					method: "POST",
					data: {
						fields: field_keys,
						itemNum: itemNum,
					},
				});

				//カート情報の取得とそれに基づくカートアイコンのレンダリング
				rawCartId = bind_cart_id
					? bind_cart_id //WordPressとShopifyのログインユーザーが一致してかつバインドされたカートIDが存在する
					: getCookie("shopify_cart_id"); //cookieにカート情報がある（匿名カート）
				console.log("cartId: ", rawCartId);
				//商品情報の表示
				replaceContent(
					productData.products,
					wp_user_id,
					rawCartId,
					cart_icon_id,
					main_block,
				);

				//カートの生成と顧客とのバインド処理
				if (rawCartId || wp_user_email) {
					await cart_bind(rawCartId, cart_icon_id, wp_user_id);
				}
				//ひな型部分は非表示
				main_block.find(".unit_hide").hide();
			} catch (err) {
				alert("顧客関連通信エラーが発生しました。");
				console.error(err.message);
			}
		})();
	});

	//カートのアニメーションを制御するためのクラスを操作する関数
	function cartAnimeClass($target_cart, addClass) {
		$target_cart.find(".spinner, .particles").each(function () {
			let $el = $(this);
			// 元の class 一覧
			let classes = $el.attr("class").split(/\s+/);
			// spinner / particle 以外を削除
			let keep = classes.filter((c) => c === "spinner" || c === "particles");
			// spinner / particle ＋ exec に置き換える
			$el.attr("class", `${keep} ${addClass}`);
		});
	}

	//アニメーションの終了を捕捉する関数
	function catchEndedAnime($target_cart, anime_name, add_class) {
		// アニメが始まる前にバインドしておく（.one で多重回避）
		$target_cart.find(".particles").each(function () {
			const $el = $(this);
			$el.one(
				"animationend webkitAnimationEnd oAnimationEnd MSAnimationEnd",
				function (ev) {
					const name = ev.originalEvent
						? ev.originalEvent.animationName
						: ev.animationName;
					if (name && name !== anime_name) return; // 'burst' だけ拾う
					// 'particles' を残して 'hold' に戻す
					cartAnimeClass($target_cart, add_class);
				},
			);
		});
	}

	//カートのデータを操作する要求を出す
	function cartControl(
		submitter,
		targetForm,
		cartId,
		cart_icon_id,
		lineId,
		wp_user_id,
		variantId,
		quantity,
	) {
		//クリックされたボタンのkeyを取得
		const $button = $(submitter);
		const key = $button.data("key");
		//カートアイコンのDOM要素を取得
		const $target_cart = $(`[data-unique_id="${cart_icon_id}"]`);
		//アニメーションの終了イベントを捕捉
		catchEndedAnime($target_cart, "burst", "hold");

		//即時購入の場合はカート情報をクリア
		if (key === "soon_buy") {
			cartId = "";
		} else {
			// spinner と particle の子要素をアニメーション
			cartAnimeClass($target_cart, "exec");
		}

		const targetUrl = "/wp-json/itmar-ec-relate/v1/cart/lines";
		//フォーム内のインプットデータ
		const formDataObj = targetForm
			.find('[class*="unit_design_"]') // 条件1: クラス名に unit_design_ を含む
			.filter(function () {
				return $(this).closest(".template_unit").length === 0; // 先祖に .template_unit がない
			})
			.map(function () {
				const $el = $(this);
				const id = $el.find('button[data-key="trush_out"]').data("line-id"); // data-line-id 属性

				const quantity =
					parseInt($el.find(".sp_field_quantity input").val(), 10) || 0;

				return {
					id: id,
					quantity: quantity,
				};
			})
			.get(); // jQueryのmap結果を純配列に変換

		const postData = {
			form_data: JSON.stringify(formDataObj),
			lineId: lineId,
			cartId: cartId,
			productId: variantId,
			quantity: quantity,
			mode: key,
			wp_user_id: wp_user_id,
			nonce: itmar_option.nonce,
		};

		// REST APIへPOST送信
		(async () => {
			try {
				const res = await sendRegistrationRequest(targetUrl, postData, "rest");

				if (key === "soon_buy") {
					if (res.checkoutUrl) {
						window.open(res.checkoutUrl, "_blank");
					} else {
						alert("チェックアウトURLの取得に失敗しました。");
						console.error("Unexpected response:", res);
					}
				} else if (
					key === "into_cart" ||
					key === "trush_out" ||
					key === "calc_again"
				) {
					if (res.success) {
						//ログイン状態でカート情報があって顧客バインドがなされていないときはバインド
						if (wp_user_id && res.cartId && !res.buyerId) {
							await cart_bind(res.cartId, cart_icon_id, wp_user_id);
						}

						const mergedItems = res.cartContents.map((edge) => {
							const lineId = edge.node.id;
							const price = edge.node.merchandise.price;
							const quantityAvailable = edge.node.merchandise.quantityAvailable;
							const product = edge.node.merchandise.product;
							const quantity = edge.node.quantity;
							// priceとproductを1つのオブジェクトに合体
							return {
								...product,
								lineId,
								price, // priceプロパティを追加
								quantity, // quantityプロパティを追加
								quantityAvailable, //在庫数量を追加
							};
						});
						//カート情報の更新
						const prmObj = {
							uniqueId: cart_icon_id,
							wp_user_id: wp_user_id,
							rawCartId: res.cartId,
							itemCount: res.itemCount,
							estimatedCost: res.estimatedCost,
							checkoutUrl: res.checkoutUrl,
							cartContents: mergedItems,
						};
						updateCartInfo(prmObj);
					} else {
						alert("カートの作成に失敗しました");
					}
					//カートアニメーションの終了
					cartAnimeClass($target_cart, "done");
				}
			} catch (err) {
				alert("サーバー通信エラーが発生しました。");
				console.error("サーバー通信エラー:", err);
			}
		})();
	}

	//テンプレートにコンテンツを流し込む関数
	function replaceContent(
		productData,
		wp_user_id,
		rawCartId,
		cart_icon_id,
		target_block,
	) {
		try {
			//テンプレート以外のユニットを一旦クリア
			target_block.children().not(".template_unit").remove();

			//カートIDを生成
			const cartId = rawCartId ? decodeURIComponent(rawCartId) : "";
			//target_blockの親がフォームの場合はここでsubmit処理を定義
			const $parentForm = target_block.closest("form");

			$parentForm
				.off("submit.uniqueParentForm")
				.on("submit.uniqueParentForm", function (e) {
					e.preventDefault();
					const $form = $(this);
					const submitter = e.originalEvent?.submitter;

					const $btn = $(submitter);
					const lineId = $btn.data("lineId");
					const variantId = $btn.data("variantId");
					const quantity = Number($btn.data("quantity")) || 1;

					cartControl(
						submitter,
						$form,
						cartId,
						cart_icon_id,
						lineId,
						wp_user_id,
						variantId,
						quantity,
					);
				})
				.on(
					"click",
					'button[type="submit"], input[type="submit"]',
					function () {
						$(this.form).data("submitter", this);
					},
				);

			//productDataの内容によってレンダリングされたDOM要素からデザインの要素を選択
			for (const [i, product] of productData.entries()) {
				const first_media = product.media?.edges?.[0]?.node;
				const first_media_info =
					first_media?.mediaContentType === "VIDEO"
						? first_media?.sources[0]
						: first_media?.mediaContentType === "IMAGE"
						? first_media?.image
						: null; //first_mediaがnullならnullが返る

				//productDataの内容によってデザインのテンプレートを選定

				const selectUnit = selectTemplateUnit(
					target_block,
					first_media_info?.width / first_media_info?.height,
					i + 1,
				); //first_mediaがnullなら一つ目のテンプレートが返る

				if (selectUnit) {
					const $template = selectUnit.parent().clone(true); // イベント付きでコピー
					// ラップ解除：hide-wrapper 直下の本体だけを対象にする
					$template
						.find(
							".hide-wrapper > .wp-block-itmar-design-title,.wp-block-itmar-design-button,.itmar_ex_block",
						)
						.each(function () {
							$(this).css("visibility", ""); // 念のため可視化を戻す
							$(this).unwrap(); // 親の .title-wrapper を外す
						});
					//テンプレートを要素として追加
					target_block.append($template);

					// variantId や quantity などもここで追加可能
					const lineId = product.lineId;
					const variantId = product.variants?.edges?.[0]?.node.id;
					const quantity = product.quantity ? product.quantity : 1;

					// テンプレートの親にフォームがある場合
					const $parentForm = $template.closest("form");
					if ($parentForm) {
						$template.find('[type="submit"]').attr({
							"data-line-id": product.lineId,
							"data-variant-id": variantId,
							"data-quantity": quantity,
						});
					}

					//テンプレートの子にフォームがある場合
					const $childForm = $template.find("form").first();
					if (typeof itmar_option !== "undefined" && $childForm.length) {
						// フォームの action をセット
						$childForm
							.off("submit.uniqueChildForm")
							.on("submit.uniqueChildForm", function (e) {
								e.preventDefault();

								//クリックされたボタンを取得
								const submitter = e.originalEvent?.submitter;

								if (submitter) {
									cartControl(
										submitter,
										$childForm,
										cartId,
										cart_icon_id,
										lineId,
										wp_user_id,
										variantId,
										quantity,
									);
								}
							});
					}
					//テンプレート内の各フィールドにshopifyデータを埋め込み
					$template.find("[class*='sp_field_']").each(function () {
						const $el = $(this);

						const classes = $el.attr("class").split(/\s+/);
						const fieldClass = classes.find((cls) =>
							cls.startsWith("sp_field_"),
						);

						if (!fieldClass) return;

						const fieldKey = fieldClass.replace("sp_field_", "");
						//プロダクトデータの第一層にデータがあるフィールドをセット
						let fieldData = product[fieldKey];

						// 第一階層に見つからなかった場合
						if (fieldData === undefined) {
							if (fieldKey === "image") {
								fieldData = product.media?.edges?.[0]?.node;
							} else if (fieldKey === "images") {
								fieldData = product.media?.edges;
							} else {
								//variants 内の node を探す
								const variantNode = product?.variants?.edges?.[0]?.node;
								if (variantNode && fieldKey in variantNode) {
									fieldData = variantNode[fieldKey];
								}
							}
						}

						// それで見つからなければスキップ
						if (fieldData === undefined) return;

						// edges[] を自動展開する
						const value = Array.isArray(fieldData?.edges)
							? fieldData.edges.map((e) => e.node)
							: fieldData;

						//すべてのクラス名
						const allClassNames = $el.attr("class");

						// 🔸 wp-block-itmar-design-title の場合
						if (allClassNames.includes("wp-block-itmar-design-title")) {
							const heading = $el.find("h1,h2,h3,h4,h5,h6").first();
							const targetDiv = heading.find("div").first();
							if (targetDiv.length) {
								const text =
									value == null
										? "" // null または undefined の場合は空文字
										: typeof value === "object" &&
										  fieldKey === "price" &&
										  value.amount //priceに当てはめる値
										? value.amount
										: typeof value === "object" &&
										  fieldKey === "compareAtPrice" &&
										  value.amount //compareAtPriceに当てはめる値
										? value.amount
										: value;
								//フォーマットされたテキストに変換
								const displayText =
									value != null
										? displayFormated(
												text,
												$el.data("user_format"),
												$el.data("free_format"),
												$el.data("decimal"),
										  )
										: null;
								targetDiv.text(displayText);
							}
						}

						// 🔸 wp-block-itmar-design-title の場合
						if (allClassNames.includes("wp-block-itmar-design-text-ctrl")) {
							const input_text = $el.find("input").first();

							if (input_text.length) {
								input_text.val(value);
							}
						}

						// 🔸 wp-block-image の場合
						else if (allClassNames.includes("wp-block-image")) {
							const img = $el.find("img").first();
							if (img.length) {
								if (value.mediaContentType === "IMAGE") {
									img.attr("src", value.image.url);
									img.attr("alt", value.image.altText || "");
								} else if (value.mediaContentType === "VIDEO") {
									const $video = $("<video>", {
										src: value.sources[0].url,
										controls: true,
										autoplay: false,
										muted: true,
										loop: false,
									});

									// 元のimgからクラス・スタイル・データ属性などを引き継ぐ
									$video.attr("class", img.attr("class") || "");
									$video.attr("style", img.attr("style") || "");
									$.each(img.data(), function (key, val) {
										$video.attr("data-" + key, val);
									});
									//スタイル設定
									$video.css({
										width: "100%",
										height: "auto",
										display: "block",
										objectFit: "cover", // 必要に応じて contain などに変更
									});

									// 🔁 imgをvideoに差し替え
									img.replaceWith($video);
								} else if (fieldKey === "featuredImage") {
									//カートの商品イメージ
									img.attr("src", value.url);
									img.attr("alt", value.altText || "");
								}
							}
						}

						// 🔸 <p> 要素の場合
						else if ($el.is("p")) {
							const text =
								typeof value === "object" && value.amount
									? value.amount
									: value;
							$el.text(text);
						}

						// 🔸 <wp-block-itmar-slide-mv> 要素の場合
						else if (allClassNames.includes("wp-block-itmar-slide-mv")) {
							//swiper独自ID
							const swiperId = `slide-${i}`;
							const clone_swiper = $el.find(".swiper");
							//IDの付け直し
							clone_swiper.removeData("swiper-id");
							clone_swiper.attr("data-swiper-id", swiperId);
							const classPrefixMap = {
								prev: "swiper-button-prev",
								next: "swiper-button-next",
								pagination: "swiper-pagination",
								scrollbar: "swiper-scrollbar",
							};

							Object.entries(classPrefixMap).forEach(([suffix, baseClass]) => {
								const $target = clone_swiper.parent().find(`.${baseClass}`);
								$target.each(function () {
									const currentClasses = $(this).attr("class").split(/\s+/);
									const filteredClasses = currentClasses.filter(
										(cls) => cls === baseClass,
									);
									// 新しい `${swiperId}-${suffix}` を追加
									filteredClasses.push(`${swiperId}-${suffix}`);
									$(this).attr("class", filteredClasses.join(" "));
								});
							});
							//swiper-wrapper内からswiper-slideを抽出
							const wrapper = clone_swiper.find(".swiper-wrapper");
							const templateSlide = wrapper.find(".swiper-slide").first();
							//swiper-wrapperオブジェクトをクリア
							clone_swiper.empty();
							// 新しい swiper-wrapper を作成
							const newWrapper = $('<div class="swiper-wrapper"></div>');
							// valueの件数にあわせて、ひな型を複製

							value.forEach((imgNode) => {
								const newSlide = templateSlide.clone(true); // trueでイベントもコピー
								// ✅ 不要な属性を削除
								// ライブ属性の配列を静的にコピー
								Array.from(newSlide[0].attributes).forEach((attr) => {
									if (attr.name !== "class") {
										newSlide.removeAttr(attr.name);
									}
								});
								//img要素を取り出し画像を差し替え
								const $img = newSlide.find("img").first();
								const imgData = imgNode.node;
								if ($img.length) {
									if (imgData.mediaContentType === "IMAGE") {
										$img.attr("src", imgData.image.url);
										$img.attr("alt", imgData.image.altText || "");
										newWrapper.append(newSlide);
									}
								}
							});
							//新しいswiper-wrapperを追加
							clone_swiper.append(newWrapper);
							//swiper初期化
							slideBlockSwiperInit(clone_swiper);
						}
					});
				}
			}
		} catch (error) {
			console.error("Shopify 商品情報の取得に失敗しました:", error);
		}
	}

	//テンプレートを選択する関数
	function selectTemplateUnit(targetBlock, aspectRatio, itmNum) {
		const templateUnits = targetBlock.find("*").filter(function () {
			const classes = $(this).attr("class");
			if (!classes) return false;
			return classes.split(/\s+/).some((cls) => cls.startsWith("unit_design_"));
		});

		let retTemplate;
		if (aspectRatio > 1.2 && itmNum % 2 !== 0) {
			retTemplate = templateUnits.eq(0);
		} else if (aspectRatio > 1.2 && itmNum % 2 === 0) {
			retTemplate = templateUnits.eq(1);
		} else if (aspectRatio < 0.8 && itmNum % 2 !== 0) {
			retTemplate = templateUnits.eq(2);
		} else if (aspectRatio < 0.8 && itmNum % 2 === 0) {
			retTemplate = templateUnits.eq(3);
		} else {
			retTemplate = templateUnits.eq(0);
		}
		return retTemplate;
	}
});
