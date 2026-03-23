export function buildUrl(page, { includePassword, includeTable, tableName }) {
	const params = [];

	if (includePassword && page.password) {
		params.push('key=' + encodeURIComponent(page.password));
	}

	if (includeTable && tableName) {
		params.push('table=' + encodeURIComponent(tableName));
	}

	return params.length ? page.permalink + '?' + params.join('&') : page.permalink;
}
