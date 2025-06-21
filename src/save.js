import { useBlockProps, InnerBlocks } from "@wordpress/block-editor";

export default function save({ attributes }) {
	const { backgroundColor, backgroundGradient, default_val, mobile_val } =
		attributes;

	//単色かグラデーションかの選択
	const bgColor = backgroundColor || backgroundGradient;

	return <div {...useBlockProps.save()}></div>;
}
