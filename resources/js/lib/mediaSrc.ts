/**
 * URLs safe to put in <img> for product media: local public-disk paths
 * (/storage/...), same-origin storage URLs, marketing /images/, and durable
 * YouTube thumbs. Signed CDN URLs expire and must not be rendered.
 */
export function isDurableMediaSrc(src: string | null | undefined): boolean {
    if (!src || typeof src !== 'string') {
        return false;
    }

    const trimmed = src.trim();

    if (trimmed === '') {
        return false;
    }

    if (
        trimmed.startsWith('/storage/')
        || trimmed.startsWith('/images/')
        || trimmed.startsWith('data:')
        || trimmed.startsWith('blob:')
    ) {
        return true;
    }

    try {
        const url = new URL(trimmed, typeof window !== 'undefined' ? window.location.origin : 'https://www.snitchsocial.net');
        const host = url.hostname.toLowerCase();

        if (host.endsWith('ytimg.com')) {
            return true;
        }

        if (typeof window !== 'undefined' && url.origin === window.location.origin) {
            return url.pathname.startsWith('/storage/') || url.pathname.startsWith('/images/');
        }

        // Absolute APP_URL storage links from the API.
        if (url.pathname.startsWith('/storage/') || url.pathname.startsWith('/images/')) {
            return true;
        }
    } catch {
        return false;
    }

    return false;
}

/** Still image (not mp4 / extensionless video) suitable for an <img> tag. */
export function isDisplayableImageSrc(src: string | null | undefined): boolean {
    if (!src || typeof src !== 'string') {
        return false;
    }

    const path = src.split('?')[0]?.toLowerCase() ?? '';

    if (/\.(mp4|webm|ogg|m4v|mov|m3u8)$/i.test(path)) {
        return false;
    }

    if (/\.(jpe?g|png|webp|gif|avif)$/i.test(path)) {
        return true;
    }

    // Local post-covers / avatars without relying on extension alone.
    return path.includes('/storage/post-covers/') || path.includes('/storage/avatars/');
}
