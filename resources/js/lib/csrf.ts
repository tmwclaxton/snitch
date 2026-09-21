export function csrfHeaders(doc: Document = document): Record<string, string> {
    const headers: Record<string, string> = {};
    const meta = doc.querySelector('meta[name="csrf-token"]')?.getAttribute('content')?.trim();

    if (meta) {
        headers['X-CSRF-TOKEN'] = meta;
    }

    const match = doc.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    if (match?.[1]) {
        headers['X-XSRF-TOKEN'] = decodeURIComponent(match[1]);
    }

    return headers;
}
