const defaultConfig = require("@wordpress/scripts/config/webpack.config");

const mode = "production";

//フロントエンドモジュールのトランスパイル
const path = require("path");
const newEntryConfig = async () => {
	const originalEntry = await defaultConfig.entry();

	return {
		...originalEntry,
		"ec-front-module": path.resolve(__dirname, "./assets/ec-front-module.js"),
	};
};

module.exports = {
	...defaultConfig,
	mode: mode,
	entry: newEntryConfig,
};
