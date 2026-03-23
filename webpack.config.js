const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

const adminEntry = { admin: path.resolve( __dirname, 'src/admin/index.js' ) };

// With --experimental-modules, defaultConfig is an array [scriptConfig, moduleConfig].
// We only add the admin entry to the script config (index 0).
if ( Array.isArray( defaultConfig ) ) {
	module.exports = [
		{
			...defaultConfig[ 0 ],
			entry: { ...defaultConfig[ 0 ].entry(), ...adminEntry },
		},
		defaultConfig[ 1 ],
	];
} else {
	module.exports = {
		...defaultConfig,
		entry: { ...defaultConfig.entry(), ...adminEntry },
	};
}
