/**
 * Escape HTML, then apply a small safe Markdown subset.
 * Supports paragraphs, line breaks, **bold**, *italic*, `code`, links, tables, and lists.
 */
export type RenderMarkdownOptions = {
    /** Turn unnumbered multi-step remake copy into an ordered list (analysis copy). */
    coerceSteps?: boolean;
    /** Link @handles (and bare Instagram-looking handles after bullets) to Instagram. */
    linkHandles?: boolean;
};

export function renderMarkdown(
    source: string | null | undefined,
    options: RenderMarkdownOptions = {},
): string {
    if (source == null) {
        return '';
    }

    const coerceSteps = options.coerceSteps ?? false;
    const linkHandles = options.linkHandles ?? false;

    let next = source.replace(/\r\n?/g, '\n');
    next = coerceSteps ? coerceRemakeSteps(next) : normalizeListBreaks(next);
    const trimmed = next.trim();

    if (trimmed === '') {
        return '';
    }

    const blocks = trimmed.split(/\n{2,}/);

    return blocks
        .map((block) => renderBlock(block.trim(), { linkHandles }))
        .filter(Boolean)
        .join('');
}

/**
 * Models often return "1. Step. 2. Step." on one line.
 * Split those inline markers onto their own lines before list detection.
 */
export function normalizeListBreaks(text: string): string {
    let next = text.replace(/\r\n?/g, '\n');

    // Numbered markers: "2. Next" / "2) Next" after non-newline content.
    next = next.replace(/(?<=\S)[ \t]+(?=\d{1,2}[.)]\s+\S)/gu, '\n');

    // Bullet markers (dot / middle-dot) mid-line.
    next = next.replace(/(?<=\S)[ \t]+(?=[•·]\s+\S)/gu, '\n');

    // Hyphen / asterisk bullets after sentence punctuation or at line starts after text.
    next = next.replace(/(?<=[.;:!?\]])[ \t]+(?=[-*+]\s+\S)/gu, '\n');

    // "Label:\n- item" style: ensure a blank-line-free intro still splits before bullets.
    next = next.replace(/(?<=\S)\n(?=[-*+•·]\s+\S)/gu, '\n');

    return next;
}

/**
 * Turn unnumbered multi-step remake copy into a Markdown ordered list.
 */
export function coerceRemakeSteps(text: string): string {
    const next = normalizeListBreaks(text);

    if (hasStepMarkers(next)) {
        return next;
    }

    const paragraphs = next
        .trim()
        .split(/\n{2,}/)
        .map((part) => part.trim())
        .filter(Boolean);

    if (paragraphs.length >= 2) {
        return numberSteps(paragraphs);
    }

    const lines = next
        .trim()
        .split('\n')
        .map((line) => line.trim())
        .filter(Boolean);

    if (lines.length >= 2) {
        return numberSteps(lines);
    }

    if (lines.length === 1) {
        const sentences = splitSentences(lines[0]);

        if (sentences.length >= 2) {
            return numberSteps(sentences);
        }
    }

    return next;
}

function numberSteps(steps: string[]): string {
    return steps.map((step, index) => `${index + 1}. ${step.trim()}`).join('\n');
}

function splitSentences(text: string): string[] {
    return text
        .trim()
        .split(/(?<=[.!?])\s+(?=[A-Z0-9"'(])/)
        .map((part) => part.trim())
        .filter(Boolean);
}

function hasStepMarkers(text: string): boolean {
    return text
        .trim()
        .split(/\n+/)
        .some((line) => {
            const trimmed = line.trim();

            if (trimmed === '') {
                return false;
            }

            return (
                /^(\*\*)?\d+[.)]\s+\S/u.test(trimmed) ||
                /^[-*+•·]\s+\S/u.test(trimmed)
            );
        });
}

function renderBlock(block: string, options: { linkHandles: boolean }): string {
    if (block === '') {
        return '';
    }

    const lines = block.split('\n');
    const tableHtml = renderTable(lines, options);

    if (tableHtml !== null) {
        return tableHtml;
    }

    return renderMixedLines(lines, options);
}

/**
 * A single Markdown block may mix a prose intro with a following list.
 * Render consecutive list runs as real <ul>/<ol>, and prose as paragraphs.
 */
function renderMixedLines(lines: string[], options: { linkHandles: boolean }): string {
    const parts: string[] = [];
    let index = 0;

    while (index < lines.length) {
        const line = lines[index] ?? '';
        const bullet = matchBullet(line);
        const ordered = matchOrdered(line);

        if (bullet || ordered) {
            const kind = bullet ? 'ul' : 'ol';
            const items: string[] = [];

            while (index < lines.length) {
                const current = lines[index] ?? '';
                const b = matchBullet(current);
                const o = matchOrdered(current);

                if (kind === 'ul' && b) {
                    items.push(renderInline(b, options));
                    index += 1;
                    continue;
                }

                if (kind === 'ol' && o) {
                    items.push(renderInline(o, options));
                    index += 1;
                    continue;
                }

                break;
            }

            const tag = kind;
            parts.push(
                `<${tag}>${items.map((item) => `<li>${item}</li>`).join('')}</${tag}>`,
            );
            continue;
        }

        const prose: string[] = [];

        while (index < lines.length) {
            const current = lines[index] ?? '';

            if (matchBullet(current) || matchOrdered(current)) {
                break;
            }

            prose.push(current);
            index += 1;
        }

        const joined = prose.join('\n').trim();

        if (joined !== '') {
            parts.push(`<p>${renderInline(joined, options)}</p>`);
        }
    }

    return parts.join('');
}

function matchBullet(line: string): string | null {
    const match = line.match(/^[-*+•·]\s+(\S.*)$/u);

    return match?.[1] ?? null;
}

function matchOrdered(line: string): string | null {
    const match = line.match(/^\d+[.)]\s+(\S.*)$/u);

    return match?.[1] ?? null;
}

function renderTable(lines: string[], options: { linkHandles: boolean }): string | null {
    if (lines.length < 2) {
        return null;
    }

    if (! lines.every((line) => line.includes('|'))) {
        return null;
    }

    const rows = lines.map((line) =>
        line
            .trim()
            .replace(/^\|/, '')
            .replace(/\|$/, '')
            .split('|')
            .map((cell) => cell.trim()),
    );

    if (rows.some((row) => row.length < 2)) {
        return null;
    }

    const isDivider = (row: string[]): boolean =>
        row.every((cell) => /^:?-{3,}:?$/.test(cell));

    let bodyStart = 1;

    if (rows[1] && isDivider(rows[1])) {
        bodyStart = 2;
    } else if (! isDivider(rows[0]!)) {
        bodyStart = 1;
    } else {
        return null;
    }

    const header = rows[0]!;
    const body = rows.slice(bodyStart).filter((row) => ! isDivider(row));

    if (body.length === 0) {
        return null;
    }

    const thead = `<thead><tr>${header.map((cell) => `<th>${renderInline(cell, options)}</th>`).join('')}</tr></thead>`;
    const tbody = `<tbody>${body
        .map((row) => `<tr>${row.map((cell) => `<td>${renderInline(cell, options)}</td>`).join('')}</tr>`)
        .join('')}</tbody>`;

    return `<div class="snitch-md-table-wrap"><table>${thead}${tbody}</table></div>`;
}

function renderInline(text: string, options: { linkHandles: boolean }): string {
    let escaped = escapeHtml(text).replace(/\n/g, '<br>\n');

    escaped = escaped
        .replace(/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>')
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        .replace(/(^|[^*])\*(?!\s)(.+?)(?<!\s)\*(?!\*)/g, '$1<em>$2</em>')
        .replace(/`([^`]+)`/g, '<code>$1</code>');

    if (options.linkHandles) {
        escaped = linkInstagramHandles(escaped);
    }

    return escaped;
}

/**
 * Link @handles (and bare handles that look like Instagram usernames) without
 * touching text already inside an <a> or HTML tag attribute.
 */
function linkInstagramHandles(html: string): string {
    const parts = html.split(/(<[^>]+>)/g);

    return parts
        .map((part) => {
            if (part.startsWith('<')) {
                return part;
            }

            let next = part.replace(
                /(^|[\s([{])@([A-Za-z0-9._]{1,30})\b/g,
                '$1<a href="https://www.instagram.com/$2/" target="_blank" rel="noopener noreferrer">@$2</a>',
            );

            // Bare handle at the start of a list item / line: "letsgosocialuk: 104 followers"
            next = next.replace(
                /(^|<br>\n?)([A-Za-z0-9._]{2,30})(?=\s*[:,]\s*\d)/gm,
                '$1<a href="https://www.instagram.com/$2/" target="_blank" rel="noopener noreferrer">$2</a>',
            );

            return next;
        })
        .join('');
}

function escapeHtml(text: string): string {
    return text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}
