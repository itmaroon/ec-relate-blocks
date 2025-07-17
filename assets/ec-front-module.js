import apiFetch from "@wordpress/api-fetch";
import { __ } from "@wordpress/i18n";
import { displayFormated } from "itmar-block-packages";

jQuery(function ($) {
	//クッキーの取得関数
	function getCookie(name) {
		const match = document.cookie.match(
			new RegExp("(^| )" + name + "=([^;]+)"),
		);
		if (match) return match[2];
		return null;
	}
	// Ajax 送信用 共通関数
	function sendRegistrationAjax(url, postData, isRest = false) {
		const ajaxOptions = {
			url: url,
			type: "POST",
			data: isRest ? JSON.stringify(postData) : postData,
			contentType: isRest ? "application/json" : undefined,
			dataType: "json",
		};

		if (isRest) {
			ajaxOptions.headers = {
				"X-WP-Nonce": postData.nonce,
			};
		}

		return $.ajax(ajaxOptions);
	}
	//カート情報の更新
	function updateCartInfo(uniqueId, itemCount, checkoutUrl) {
		const $target = $(
			`.wp-block-itmar-design-title[data-unique_id="${uniqueId}"]`,
		);

		if ($target.length === 0) return;

		// h1〜h6 を探して、その中の div に itemCount を設定
		$target.find("h1,h2,h3,h4,h5,h6").each(function () {
			const $div = $(this).find("div");
			if ($div.length > 0) {
				$div.text(itemCount);
			}
		});

		// a要素のhrefを書き換える
		$target.find("a").each(function () {
			$(this).attr("href", checkoutUrl);
		});
	}

	//jquery読み込み後のイベントハンドラ
	$(document).ready(function ($) {
		//メインブロックの取得
		const main_block = $(".wp-block-itmar-ec-relate-bloks");
		//メインの
		if (main_block.length < 1) return;

		//ユーザー情報の確認
		const userCheckUrl =
			"/wp-json/itmar-ec-relate/v1/shopify-validate-customer";
		const postData = {
			nonce: itmar_option.nonce,
		};
		sendRegistrationAjax(userCheckUrl, postData, true)
			.done(function (res) {
				//会員登録メニューがあれば表示・非表示の切り替え
				const $regist_menu = $(
					`.wp-block-itmar-design-title[data-unique_id="shopping_register"]`,
				);
				if (res.valid) {
					console.log("有効なShopify顧客です:", res.customer);
					$regist_menu?.hide();
				} else if (res.valid) {
					console.error(res.message);
				} else {
					console.error(res.message);

					$regist_menu?.show();
					$regist_menu.on("click", function () {
						console.log("click!!!");
					});
				}
			})
			.fail(function (xhr, status, error) {
				alert("サーバーエラー: " + error);
				console.error(xhr.responseText);
			});

		//カート情報の取得とレンダリング
		const rawCartId = getCookie("shopify_cart_id");

		if (rawCartId) {
			const targetUrl = "/wp-json/itmar-ec-relate/v1/shopify-create-checkout";
			const cartId = decodeURIComponent(rawCartId);
			const postData = {
				cartId: cartId,
				mode: "get_cart",
				nonce: itmar_option.nonce,
			};

			sendRegistrationAjax(targetUrl, postData, true)
				.done(function (res) {
					if (res.success) {
						//カート情報の更新
						updateCartInfo("swiper_cart_info", res.itemCount, res.checkoutUrl);
					} else {
						alert("カート追加に失敗しました。");
					}
				})
				.fail(function (xhr, status, error) {
					alert("サーバー通信エラーが発生しました。");
					console.error("AJAX Error:", status, error, xhr.responseText);
				});
		}

		//ひな型部分は非表示
		main_block.find(".unit_hide").hide();
		if (!main_block.length) return;
		//取得するフィールド
		const selected_fields = main_block.data("selected_fields"); // [{ key, label, block }]
		if (!selected_fields) return;
		const field_keys = selected_fields.map((f) => f.key);

		//取得する商品数
		const itemNum = main_block.data("number_of_items");

		(async () => {
			try {
				const productData = await apiFetch({
					path: "/itmar-ec-relate/v1/get-product-info",
					method: "POST",
					data: {
						fields: field_keys,
						itemNum: itemNum,
					},
				});

				//productDataの内容によってレンダリングされたDOM要素からデザインの要素を選択

				for (const [i, product] of productData.entries()) {
					const first_media = product.media?.edges?.[0]?.node;
					const first_media_info =
						first_media.mediaContentType === "VIDEO"
							? first_media.sources[0]
							: first_media.mediaContentType === "IMAGE"
							? first_media.image
							: null;

					//productDataの内容によってデザインのテンプレートを選定

					const selectUnit = selectTemplateUnit(
						first_media_info.width / first_media_info.height,
						i + 1,
					);
					if (selectUnit) {
						const $template = selectUnit.parent().clone(true); // イベント付きでコピー
						//テンプレートを要素として追加
						main_block.append($template);
						//フォームを取得して送信のためのオプションをセット
						const $sendForm = $template.find("form");
						if (typeof itmar_option !== "undefined" && $sendForm.length) {
							// variantId や quantity などもここで追加可能
							const variantId = product.variants?.edges?.[0]?.node.id;
							const quantity = 1;

							// フォームの action をセット
							$sendForm.on("submit", function (e) {
								e.preventDefault();
								//クリックされたボタンを取得
								const submitter = e.originalEvent?.submitter;

								if (submitter) {
									//クリックされたボタンのkeyを取得
									const $button = $(submitter);
									const key = $button.data("key");

									const targetUrl =
										"/wp-json/itmar-ec-relate/v1/shopify-create-checkout";
									//フォーム内のインプットデータ(現時点は未使用)
									const formDataObj = {};
									$sendForm.serializeArray().forEach((item) => {
										formDataObj[item.name] = item.value;
									});
									const postData = {
										form_data: formDataObj,
										productId: variantId,
										quantity: quantity,
										mode: key,
										nonce: itmar_option.nonce,
									};
									// REST APIへPOST送信

									sendRegistrationAjax(targetUrl, postData, true)
										.done(function (res) {
											//すぐに購入の処理
											if (key === "soon_buy") {
												if (res.checkoutUrl) {
													window.open(res.checkoutUrl, "_blank");
												} else {
													alert("チェックアウトURLの取得に失敗しました。");
													console.error("Unexpected response:", res);
												}
											} else if (key === "into_cart") {
												if (res.success) {
													//カート情報の更新
													updateCartInfo(
														"swiper_cart_info",
														res.itemCount,
														res.checkoutUrl,
													);
												} else {
													alert("カート追加に失敗しました。");
												}
											}
										})
										.fail(function (xhr, status, error) {
											alert("サーバー通信エラーが発生しました。");
											console.error(
												"AJAX Error:",
												status,
												error,
												xhr.responseText,
											);
										});
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
											: typeof value === "object" && value.amount
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

								Object.entries(classPrefixMap).forEach(
									([suffix, baseClass]) => {
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
									},
								);
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
		})();

		function selectTemplateUnit(aspectRatio, itmNum) {
			const templateUnits = main_block.find("*").filter(function () {
				const classes = $(this).attr("class");
				if (!classes) return false;
				return classes
					.split(/\s+/)
					.some((cls) => cls.startsWith("unit_design_"));
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
});
