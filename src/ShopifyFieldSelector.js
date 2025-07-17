import { PanelBody, ToggleControl } from "@wordpress/components";
import { __ } from "@wordpress/i18n";

const ShopifyFieldSelector = ({ selectedFields, setSelectedFields }) => {
	const choices = [
		{
			key: "title",
			label: __("Title", "ec-relate-bloks"),
			block: "itmar/design-title",
		},
		{
			key: "description",
			label: __("Description (plain)", "ec-relate-bloks"),
			block: "core/paragraph",
		},
		{
			key: "descriptionHtml",
			label: __("Description (HTML)", "ec-relate-bloks"),
			block: "core/paragraph",
		},
		{
			key: "vendor",
			label: __("Vendor", "ec-relate-bloks"),
			block: "itmar/design-title",
		},
		{
			key: "productType",
			label: __("Product Type", "ec-relate-bloks"),
			block: "itmar/design-title",
		},
		{
			key: "handle",
			label: __("Handle (Slug)", "ec-relate-bloks"),
			block: "itmar/design-title",
		},
		{
			key: "tags",
			label: __("Tags", "ec-relate-bloks"),
			block: "itmar/design-title",
		},
		{
			key: "availableForSale",
			label: __("Available for Sale", "ec-relate-bloks"),
			block: "itmar/design-title",
		},
		{
			key: "price",
			label: __("Price", "ec-relate-bloks"),
			block: "itmar/design-title",
		},
		{
			key: "compareAtPrice",
			label: __("Compare at Price (Original)", "ec-relate-bloks"),
			block: "itmar/design-title",
		},
		{
			key: "currencyCode",
			label: __("Currency Code", "ec-relate-bloks"),
			block: "itmar/design-title",
		},
		{
			key: "image",
			label: __("Main Image", "ec-relate-bloks"),
			block: "core/image",
		},
		{
			key: "images",
			label: __("All Images", "ec-relate-bloks"),
			block: "itmar/slide-mv",
		},
		{
			key: "variants",
			label: __("Variants (Options)", "ec-relate-bloks"),
			block: "itmar/design-title",
		},
		{
			key: "sku",
			label: __("SKU", "ec-relate-bloks"),
			block: "itmar/design-title",
		},
		{
			key: "onlineStoreUrl",
			label: __("Online Store URL", "ec-relate-bloks"),
			block: "itmar/design-title",
		},
		{
			key: "createdAt",
			label: __("Created At", "ec-relate-bloks"),
			block: "itmar/design-title",
		},
		{
			key: "updatedAt",
			label: __("Updated At", "ec-relate-bloks"),
			block: "itmar/design-title",
		},
	];

	const handleToggle = (fieldKey, checked, label, block) => {
		if (checked) {
			if (!selectedFields.some((item) => item.key === fieldKey)) {
				setSelectedFields([...selectedFields, { key: fieldKey, label, block }]);
			}
		} else {
			setSelectedFields(selectedFields.filter((item) => item.key !== fieldKey));
		}
	};

	const isChecked = (fieldKey) => {
		return selectedFields.some((item) => item.key === fieldKey);
	};

	return (
		<PanelBody
			title={__("Display Shopify Fields", "ec-relate-bloks")}
			initialOpen={true}
		>
			{choices.map((choice) => (
				<div key={choice.key} className="field_section">
					<ToggleControl
						className="field_choice"
						label={choice.label}
						checked={isChecked(choice.key)}
						onChange={(checked) =>
							handleToggle(choice.key, checked, choice.label, choice.block)
						}
					/>
				</div>
			))}
		</PanelBody>
	);
};

export default ShopifyFieldSelector;
