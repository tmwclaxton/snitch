/**
 * Escape HTML, then apply a small safe Markdown subset for analysis copy.
 * Supports paragraphs, line breaks, **bold**, *italic*, `code`, links, and lists.
 */
export function renderMarkdown(source: string | null | undefined): string {
    if (source == null) {
        return '';
    }

    const trimmed = source.replace(/\r\n?/g, '\n').trim();

    if (trimmed === '') {
        return '';
    }

    const blocks = trimmed.split(/\n{2,}/);

    return blocks
        .map((block) => renderBlock(block.trim()))
        .filter(Boolean)
        .join('');
}

function renderBlock(block: string): string {
    if (block === '') {
        return '';
    }

    const lines = block.split('\n');
    const listKind = detectListKind(lines);

    if (listKind === 'ul') {
        const items = lines.map((line) =>
            renderInline(line.replace(/^[-*+]\s+/, '')),
        );

        return `<ul>${items.map((item) => `<li>${item}</li>`).join('')}</ul>`;
    }

    if (listKind === 'ol') {
        const items = lines.map((line) =>
            renderInline(line.replace(/^\d+\.\s+/, '')),
        );

        return `<ol>${items.map((item) => `<li>${item}</li>`).join('')}</ol>`;
    }

    return `<p>${renderInline(lines.join('\n'))}</p>`;
}

function detectListKind(lines: string[]): 'ul' | 'ol' | null {
    if (lines.length === 0 || lines.some((line) => line.trim() === '')) {
        return null;
    }

    if (lines.every((line) => /^[-*+]\s+\S/.test(line))) {
        return 'ul';
    }

    if (lines.every((line) => /^\d+\.\s+\S/.test(line))) {
        return 'ol';
    }

    return null;
}

function renderInline(text: string): string {
    const escaped = escapeHtml(text).replace(/\n/g, '<br>\n');

    return escaped
        .replace(/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>')
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        .replace(/(^|[^*])\*(?!\s)(.+?)(?<!\s)\*(?!\*)/g, '$1<em>$2</em>')
        .replace(/`([^`]+)`/g, '<code>$1</code>');
}

function escapeHtml(text: string): string {
    return text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}
