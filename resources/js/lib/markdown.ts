/**
 * Escape HTML, then apply a small safe Markdown subset for analysis copy.
 * Supports paragraphs, line breaks, **bold**, *italic*, `code`, links, and lists.
 */
export function renderMarkdown(source: string | null | undefined): string {
    if (source == null) {
        return '';
    }

    const trimmed = coerceRemakeSteps(source.replace(/\r\n?/g, '\n')).trim();

    if (trimmed === '') {
        return '';
    }

    const blocks = trimmed.split(/\n{2,}/);

    return blocks
        .map((block) => renderBlock(block.trim()))
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

    // Hyphen / asterisk bullets only after sentence punctuation.
    next = next.replace(/(?<=[.;:!?\]])[ \t]+(?=[-*+]\s+\S)/gu, '\n');

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

function renderBlock(block: string): string {
    if (block === '') {
        return '';
    }

    const lines = block.split('\n');
    const listKind = detectListKind(lines);

    if (listKind === 'ul') {
        const items = lines.map((line) =>
            renderInline(line.replace(/^[-*+•·]\s+/, '')),
        );

        return `<ul>${items.map((item) => `<li>${item}</li>`).join('')}</ul>`;
    }

    if (listKind === 'ol') {
        const items = lines.map((line) =>
            renderInline(line.replace(/^\d+[.)]\s+/, '')),
        );

        return `<ol>${items.map((item) => `<li>${item}</li>`).join('')}</ol>`;
    }

    return `<p>${renderInline(lines.join('\n'))}</p>`;
}

function detectListKind(lines: string[]): 'ul' | 'ol' | null {
    if (lines.length === 0 || lines.some((line) => line.trim() === '')) {
        return null;
    }

    if (lines.every((line) => /^[-*+•·]\s+\S/.test(line))) {
        return 'ul';
    }

    if (lines.every((line) => /^\d+[.)]\s+\S/.test(line))) {
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
