import { __ } from "@wordpress/i18n";
import WebhookSettingsPanel from "./WebhookSettingsPanel";
import ShopifyFieldSelector from "./ShopifyFieldSelector";

import {
	PanelBody,
	PanelRow,
	CheckboxControl,
	Notice,
	TextControl,
	RangeControl,
} from "@wordpress/components";
import {
	useBlockProps,
	useInnerBlocksProps,
	InspectorControls,
} from "@wordpress/block-editor";

import { useState, useEffect } from "@wordpress/element";
import { useSelect, dispatch, useDispatch } from "@wordpress/data";
import { createBlock, getBlockType } from "@wordpress/blocks";
import {
	ArchiveSelectControl,
	isValidUrlWithUrlApi,
	serializeBlockTree,
	createBlockTree,
	flattenBlocks,
} from "itmar-block-packages";

import "./editor.scss";

export default function Edit({ attributes, setAttributes, clientId }) {
	const {
		productPost,
		storeUrl,
		adminToken,
		storefrontToken,
		callbackUrl,
		stripeKey,
		selectedFields,
		numberOfItems,
		blocksAttributesArray,
	} = attributes;

	// dispatch関数を取得
	const { replaceInnerBlocks } = useDispatch("core/block-editor");

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
	//スタイルの説明
	const style_disp = [
		__("For landscape images, odd numbers", "ec-relate-bloks"),
		__("For landscape images, even numbers", "ec-relate-bloks"),
		__("For portrait images, odd numbers", "ec-relate-bloks"),
		__("For portrait images, even numbers", "ec-relate-bloks"),
	];

	//インナーブロックのひな型を用意
	const TEMPLATE = [];
	const blockProps = useBlockProps();
	const innerBlocksProps = useInnerBlocksProps(blockProps, {
		allowedBlocks: [
			"itmar/design-group",
			"itmar/design-title",
			"core/image",
			"core/paragraph",
			"itmar/design-button",
		],
		template: TEMPLATE,
		templateLock: false,
	});

	//インナーブロックの取得
	const { innerBlocks, parentBlock } = useSelect(
		(select) => {
			const { getBlocks, getBlockParents, getBlock } =
				select("core/block-editor");
			const parentIds = getBlockParents(clientId);

			return {
				innerBlocks: getBlocks(clientId),
				parentBlock:
					parentIds.length > 0
						? getBlock(parentIds[parentIds.length - 1])
						: null,
			};
		},
		[clientId],
	);

	//トークンをサーバに格納
	async function saveTokens() {
		const body_obj = {
			productPost: productPost,
			shop_domain: storeUrl,
			admin_token: adminToken,
			storefront_token: storefrontToken,
			stripe_key: stripeKey,
		};

		const res = await fetch("/wp-json/itmar-ec-relate/v1/save-tokens", {
			method: "POST",
			headers: {
				"Content-Type": "application/json",
				"X-WP-Nonce": itmar_option.nonce, // ローカルスクリプトで渡す
			},
			credentials: "include",
			body: JSON.stringify(body_obj),
		});

		const json = await res.json();
		if (json.status === "ok") {
			console.log("保存成功");
		} else {
			console.error("保存失敗", json);
		}
	}

	//Shopifyフィールド生成用関数
	const createShopifyBlockAttr = (selectedField) => {
		let blockAttributes = {};

		switch (selectedField.block) {
			case "itmar/design-title":
				blockAttributes = {
					className: `sp_field_${selectedField.key}`,
					headingContent: `[${selectedField.label}]`,
				};

				break;
			case "core/paragraph":
				blockAttributes = {
					className: `itmar_ex_block sp_field_${selectedField.key}`,
					content: `[${selectedField.label}]`,
				};
				break;
			case "core/image":
				blockAttributes = {
					className: `itmar_ex_block sp_field_${selectedField.key}`,
					url: `${ec_relate_blocks.plugin_url}/assets/image/main_sample.png`,
				};
				break;
			case "itmar/slide-mv":
				const spaceAttributes = {
					margin_val: {
						type: "object",
						default: {
							top: "0em",
							left: "0em",
							bottom: "0em",
							right: "0em",
						},
					},
					padding_val: {
						type: "object",
						default: {
							top: "0em",
							left: "0em",
							bottom: "0em",
							right: "0em",
						},
					},
				};

				const imageBlock = createBlock("core/image", {
					className: "itmar_ex_block",
					url: `${ec_relate_blocks.plugin_url}/assets/image/slide_sample.png`,
					...spaceAttributes,
				});
				//Design Blockの初期設定を取得
				const blockType = getBlockType("itmar/design-group");
				const defaultValBase =
					blockType?.attributes?.default_val?.default ?? {};
				const mobileValBase = blockType?.attributes?.mobile_val?.default ?? {};
				const slideBlock = createBlock(
					"itmar/design-group",
					{
						default_val: {
							...defaultValBase,
							width_val: "fit",
						},
						mobile_val: {
							...mobileValBase,
							width_val: "fit",
						},
					},
					[imageBlock],
				);
				//slideBlock をシリアライズ
				const serializedSlide = serializeBlockTree(slideBlock);
				// 同じスライドブロックを5つ複製（独立したブロックとして）
				const slideBlocks = Array.from({ length: 5 }, () =>
					createBlockTree(serializedSlide),
				);
				//子ブロック付きで返す
				blockAttributes = {
					attributes: { className: `sp_field_${selectedField.key}` },
					slideBlocks: slideBlocks,
				};
				break;
			default:
				blockAttributes = {
					className: `sp_field_${selectedField.key}`,
					headingContent: `[${selectedField.label}]`,
				};
		}
		return blockAttributes;
	};

	//トークン、キー、商品情報ポストタイプの変更があればサーバーに格納
	useEffect(() => {
		saveTokens();
	}, [storeUrl, adminToken, storefrontToken, stripeKey, productPost]);

	//表示フィールド変更によるインナーブロックの再構成
	useEffect(() => {
		//blocksAttributesArrayを複製する
		const dispAttributeArray = [...blocksAttributesArray];

		//dispAttributeArray の個数は４に固定
		const blocksLength = dispAttributeArray.length;

		if (blocksLength < 4) {
			// dispAttributeArrayの長さが短い場合、{}を追加する
			const diff = 4 - blocksLength;
			for (let i = 0; i < diff; i++) {
				dispAttributeArray.push({});
			}
		} else {
			// dispAttributeArrayの長さが長い場合、余分な要素を削除する
			dispAttributeArray.splice(4);
		}

		// インナーブロックに差し込むブロック配列を生成
		const blocksArray = dispAttributeArray.map((dispAttribute, unit_index) => {
			// blocksAttributesArray属性で登録されたブロックのclassName一覧（sp_field_xxx を拾う）
			const allBlocks = Array.isArray(dispAttribute.innerBlocks)
				? flattenBlocks(dispAttribute.innerBlocks) //階層になったブロックを平坦化
				: [];
			const existingKeys = allBlocks
				.map((block) => block.attributes?.className)
				.filter(Boolean)
				.map((cls) => {
					const match = cls.match(/sp_field_([\w-]+)/);
					return match?.[1];
				})
				.filter(Boolean);

			// selectedFields のうち未挿入のものだけ追加
			const autoGeneratedBlocks = selectedFields
				.filter((field) => !existingKeys.includes(field.key))
				.map((field) => {
					const attr = createShopifyBlockAttr(field);
					const blockName = field.block;
					const blockAttributes = attr?.attributes ?? attr;
					const innerBlocks = Array.isArray(attr?.slideBlocks)
						? attr.slideBlocks
						: [];

					return createBlock(blockName, blockAttributes, innerBlocks);
				});

			// blocksAttributesArray属性で登録されたブロックの再構築
			const selectedKeys = selectedFields.map((f) => f.key);

			const filterBlocksRecursively = (blocks) => {
				return blocks
					.map((block) => {
						const className = block.attributes?.className || "";
						const match = className.match(/sp_field_([a-zA-Z0-9_]+)/);

						// 再帰的に innerBlocks をフィルタ
						const filteredInner = block.innerBlocks
							? filterBlocksRecursively(block.innerBlocks)
							: [];

						const isAutoGenerated = !!match;
						const keep = !isAutoGenerated || selectedKeys.includes(match[1]);

						if (!keep) return null;

						// 構造を復元して返す
						return {
							...block,
							innerBlocks: filteredInner,
						};
					})
					.filter(Boolean); // null を除去
			};

			const userBlocks = Array.isArray(dispAttribute.innerBlocks)
				? filterBlocksRecursively(dispAttribute.innerBlocks).map(
						createBlockTree,
				  )
				: [];

			// autoGenerated（selectedFields） + userBlocks を合成
			const innerBlocks = [...userBlocks, ...autoGeneratedBlocks];

			return createBlock(
				"itmar/design-group",
				{
					...dispAttribute.attributes,
					className: `unit_design_${unit_index}`,
					domType: "form", //DOM要素はformにする。
				} || {},
				innerBlocks,
			);
		});
		// 既存のインナーブロックを一括置換
		replaceInnerBlocks(clientId, blocksArray, false);
	}, [selectedFields]);

	//ブロック属性の更新処理
	useEffect(() => {
		if (innerBlocks.length > 0) {
			const serialized = innerBlocks.map(serializeBlockTree);
			setAttributes({ blocksAttributesArray: serialized });
		}
	}, [innerBlocks]);

	//編集中の値を確保するための状態変数
	const [url_editing, setUrlValue] = useState(storeUrl);
	const [store_editing, setStoreValue] = useState(storefrontToken);
	const [admin_editing, setAdminValue] = useState(adminToken);
	const [callback_editing, setCallbackValue] = useState(callbackUrl);
	const [stripe_key_editing, setStripeKeyValue] = useState(stripeKey);
	//Noticeのインデックス保持
	const [noticeClickedIndex, setNoticeClickedIndex] = useState(null);
	//貼付け中のフラグ保持
	const [isPastWait, setIsPastWait] = useState(false);
	//ペースト対象のチェック配列
	const [isCopyChecked, setIsCopyChecked] = useState([]);
	//CheckBoxのイベントハンドラ
	const handleCheckboxChange = (index, newCheckedValue) => {
		const updatedIsChecked = [...isCopyChecked];
		updatedIsChecked[index] = newCheckedValue;
		setIsCopyChecked(updatedIsChecked);
	};

	return (
		<>
			<InspectorControls>
				<PanelBody title={__("EC setting", "ec-relate-bloks")}>
					<ArchiveSelectControl
						selectedSlug={productPost}
						label={__("Select Product Post Type", "ec-relate-bloks")}
						homeUrl={ec_relate_blocks.home_url}
						onChange={(postInfo) => {
							if (postInfo) {
								setAttributes({
									productPost: postInfo.slug,
								});
							}
						}}
					/>

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

					<PanelBody
						title={__("WebHook Setting", "ec-relate-bloks")}
						initialOpen={true}
					>
						<TextControl
							label={__("WebHook Callback Url", "ec-relate-bloks")}
							value={callback_editing}
							onChange={(newVal) => setCallbackValue(newVal)} // 一時的な編集値として保存する
							onBlur={() => {
								if (!isValidUrlWithUrlApi(callback_editing)) {
									dispatch("core/notices").createNotice(
										"error",
										__(
											"The input string is not in URL format.",
											"ec-relate-bloks",
										),
										{ type: "snackbar", isDismissible: true },
									);
									// バリデーションエラーがある場合、表示を元の値に戻す
									setCallbackValue(callbackUrl);
								} else {
									//URLの形式を確認してリンク先をセット
									setAttributes({ callbackUrl: callback_editing });
								}
							}}
						/>
						<WebhookSettingsPanel callbackUrl={callbackUrl} />
					</PanelBody>

					<TextControl
						label={__("Stripe API Key", "ec-relate-bloks")}
						value={stripe_key_editing}
						onChange={(newVal) => setStripeKeyValue(newVal)} // 一時的な編集値として保存する
						onBlur={() => {
							setAttributes({ stripeKey: stripe_key_editing });
						}}
					/>

					<ShopifyFieldSelector
						selectedFields={selectedFields}
						setSelectedFields={(fields) =>
							setAttributes({ selectedFields: fields })
						}
					/>
					<PanelRow className="itmar_post_blocks_pannel">
						<RangeControl
							value={numberOfItems}
							label={__("Display Num", "query-blocks")}
							max={30}
							min={1}
							onChange={(val) => setAttributes({ numberOfItems: val })}
						/>
					</PanelRow>
				</PanelBody>
			</InspectorControls>
			<InspectorControls group="styles">
				<PanelBody title={__("Unit Style Copy&Past", "query-blocks")}>
					<div className="itmar_post_block_notice">
						{blocksAttributesArray.map((styleObj, index) => {
							const copyBtn = {
								label: __("Copy", "query-blocks"),
								onClick: () => {
									//CopyがクリックされたNoticeの順番を記録
									setNoticeClickedIndex(index);
								},
							};
							const pastBtn = {
								label: isPastWait ? (
									<img
										src={`${query_blocks.plugin_url}/assets/past-wait.gif`}
										alt={__("wait", "query-blocks")}
										style={{ width: "36px", height: "36px" }} // サイズ調整
									/>
								) : (
									__("Paste", "query-blocks")
								),
								onClick: () => {
									//貼付け中フラグをオン
									setIsPastWait(true);

									//記録された順番の書式をコピー
									if (noticeClickedIndex !== null) {
										//blocksAttributesArrayのクローンを作成
										const updatedBlocksAttributes = [...blocksAttributesArray];
										//ペースト対象配列にチェックが入った順番のものにペースト
										const newInnerBlocks = [...innerBlocks];
										isCopyChecked.forEach((checked, index) => {
											if (checked) {
												const replaceBlock = createBlockTree(
													blocksAttributesArray[noticeClickedIndex],
												);
												newInnerBlocks[index] = replaceBlock;
												//ブロック属性に格納した配列の要素を入れ替える
												updatedBlocksAttributes[index] =
													blocksAttributesArray[noticeClickedIndex];
											}
										});
										//元のブロックと入れ替え
										replaceInnerBlocks(clientId, newInnerBlocks, false);

										//属性を変更
										setAttributes({
											blocksAttributesArray: updatedBlocksAttributes,
										});

										//貼付け中フラグをオフ
										setIsPastWait(false);
										setNoticeClickedIndex(null); //保持を解除
										//ペースト対象配列の初期化
										setIsCopyChecked(
											Array(blocksAttributesArray.length).fill(false),
										);
									}
								},
							};
							const actions =
								noticeClickedIndex === index ? [pastBtn] : [copyBtn];
							const checkInfo = __(
								"Check the unit to which you want to paste and press the Paste button.",
								"query-blocks",
							);
							const checkContent =
								noticeClickedIndex != index ? (
									<CheckboxControl
										label={__("Paste to", "query-blocks")}
										checked={isCopyChecked[index]}
										onChange={(newVal) => {
											handleCheckboxChange(index, newVal);
										}}
									/>
								) : (
									<p>{checkInfo}</p>
								);

							return (
								<div className="style_unit">
									<Notice
										key={index}
										actions={actions}
										status={
											noticeClickedIndex === index ? "success" : "default"
										}
										isDismissible={false}
									>
										<div>
											<p>{`Unit ${index + 1} Style`}</p>
											<p>{style_disp[index]}</p>
										</div>
									</Notice>
									<div className="past_state">{checkContent}</div>
								</div>
							);
						})}
					</div>
				</PanelBody>
			</InspectorControls>

			<div {...innerBlocksProps} />
		</>
	);
}
