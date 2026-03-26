export function buildUrl(page, { includePassword, includeTable, tableName }) {
	const params = [];

	if (includePassword && page.password) {
		params.push('key=' + encodeURIComponent(page.password));
	}

	if (includeTable && tableName) {
		params.push('table=' + encodeURIComponent(tableName));
	}

	if (!params.length) {
		return page.permalink;
	}

	const separator = page.permalink.includes('?') ? '&' : '?';
	return page.permalink + separator + params.join('&');
}
