// Project ESLint flat config.
//
// Extends the default config from @wordpress/scripts and registers the
// @wordpress/* packages as core modules so the import plugin doesn't try
// to resolve them on disk — they are externalized by webpack at build
// time and provided by WordPress core at runtime.
const defaultConfig = require('@wordpress/scripts/config/eslint.config.cjs');

const wordpressCoreModules = [
	'@wordpress/api-fetch',
	'@wordpress/block-editor',
	'@wordpress/block-serialization-default-parser',
	'@wordpress/blocks',
	'@wordpress/components',
	'@wordpress/data',
	'@wordpress/element',
	'@wordpress/html-entities',
	'@wordpress/i18n',
	'@wordpress/interactivity',
];

module.exports = [
	...defaultConfig,
	{
		settings: {
			'import/core-modules': wordpressCoreModules,
		},
	},
];
