import { useBlockProps, InnerBlocks } from "@wordpress/block-editor";

export default function save({ attributes }) {
	const { numberOfItems, selectedFields } = attributes;
	return (
		<div
			{...useBlockProps.save()}
			data-number_of_items={numberOfItems}
			data-selected_fields={JSON.stringify(selectedFields)}
		>
			<div className="template_unit unit_hide">
				<InnerBlocks.Content />
			</div>
		</div>
	);
}
