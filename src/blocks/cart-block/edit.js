import { __ } from "@wordpress/i18n";
import ShopifyFieldSelector from "../../ShopifyFieldSelector";
import { useRebuildChangeField } from "../../BrockInserter";

import { PanelBody, PanelRow, RangeControl } from "@wordpress/components";
import {
	useBlockProps,
	useInnerBlocksProps,
	InspectorControls,
} from "@wordpress/block-editor";

import { useEffect } from "@wordpress/element";
import { useSelect } from "@wordpress/data";

import { serializeBlockTree } from "itmar-block-packages";

import "./editor.scss";

export default function Edit({ attributes, setAttributes, clientId }) {
	const { selectedFields, numberOfItems, blocksAttributesArray } = attributes;

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
			"itmar/design-text-ctrl",
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

	//表示フィールド変更によるインナーブロックの再構成
	const sectionCount = 1;
	const domType = "div";
	useRebuildChangeField(
		blocksAttributesArray,
		selectedFields,
		sectionCount,
		domType,
		"cart",
		clientId,
	);
	//ブロック属性の更新処理
	useEffect(() => {
		if (innerBlocks.length > 0) {
			const serialized = innerBlocks.map(serializeBlockTree);
			setAttributes({ blocksAttributesArray: serialized });
		}
	}, [innerBlocks]);

	return (
		<>
			<InspectorControls>
				<PanelBody title={__("Cart setting", "ec-relate-bloks")}>
					<ShopifyFieldSelector
						fieldType="cart"
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

			<div {...innerBlocksProps} />
		</>
	);
}
