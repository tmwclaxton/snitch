export type StippleVariant = 'dots' | 'hexes';

export type StippleCircle = {
    kind: 'circle';
    cx: number;
    cy: number;
    r: number;
};

export type StippleHex = {
    kind: 'hex';
    points: string;
};

export type StippleLogo = {
    kind: 'logo';
    cx: number;
    cy: number;
    size: number;
    /** Degrees; small print wobble for risograph feel. */
    rotate: number;
};

export type StippleMark = StippleCircle | StippleHex | StippleLogo;

export type StippleOptions = {
    x: number;
    y: number;
    width: number;
    height: number;
    variant?: StippleVariant;
    /** Lattice spacing in SVG units. */
    step?: number;
    /** Dot / hex radius in SVG units. */
    radius?: number;
    /** Deterministic jitter seed (same inputs → same marks). */
    seed?: number;
};

export type LogoStippleOptions = {
    x: number;
    y: number;
    width: number;
    height: number;
    /** Logo edge length in SVG units. */
    size?: number;
    /** Lattice spacing in SVG units. */
    step?: number;
    /** Deterministic jitter seed (same inputs → same marks). */
    seed?: number;
};

/**
 * Build a lattice of tiny marks that fill a bar rectangle.
 * Value is still encoded by bar height/width; marks are the printed fill.
 */
export function buildStippleMarks(options: StippleOptions): StippleMark[] {
    const {
        x,
        y,
        width,
        height,
        variant = 'dots',
        seed = 0,
    } = options;

    if (width <= 0 || height <= 0) {
        return [];
    }

    if (variant === 'hexes') {
        const radius = options.radius ?? 1.55;
        const step = options.step ?? radius * 2.05;

        return buildHexMarks(x, y, width, height, radius, step, seed);
    }

    const radius = options.radius ?? 1.05;
    const step = options.step ?? 3.6;

    return buildDotMarks(x, y, width, height, radius, step, seed);
}

/**
 * Dense lattice of mini logo placements for vendor-identity bar fills
 * (billing spend chart). Same height encoding as stipple dots; marks are logos.
 */
export function buildLogoMarks(options: LogoStippleOptions): StippleLogo[] {
    const { x, y, width, height, seed = 0 } = options;

    if (width <= 0 || height <= 0) {
        return [];
    }

    const size = options.size ?? Math.min(8.5, Math.max(5.5, width * 0.55));
    const step = options.step ?? size * 1.12;
    const half = size / 2;
    const inset = half * 0.2;
    const left = x + inset;
    const right = x + width - inset;
    const top = y + inset;
    const bottom = y + height - inset;
    const marks: StippleLogo[] = [];

    if (right <= left || bottom <= top) {
        return [
            {
                kind: 'logo',
                cx: x + width / 2,
                cy: y + height / 2,
                size: Math.min(size, width, height),
                rotate: jitter(seed, 0, 0, 5) * 6,
            },
        ];
    }

    let row = 0;

    for (let cy = top + step / 2; cy <= bottom; cy += step, row += 1) {
        const rowOffset = row % 2 === 0 ? 0 : step / 2;
        let col = 0;

        for (let cx = left + step / 2 + rowOffset; cx <= right; cx += step, col += 1) {
            const jx = jitter(seed, row, col, 1) * half * 0.22;
            const jy = jitter(seed, row, col, 2) * half * 0.22;
            const px = clamp(cx + jx, left, right);
            const py = clamp(cy + jy, top, bottom);

            marks.push({
                kind: 'logo',
                cx: px,
                cy: py,
                size,
                rotate: jitter(seed, row, col, 5) * 8,
            });
        }
    }

    return marks.length > 0
        ? marks
        : [
              {
                  kind: 'logo',
                  cx: x + width / 2,
                  cy: y + height / 2,
                  size: Math.min(size, width, height),
                  rotate: jitter(seed, 0, 0, 5) * 6,
              },
          ];
}

function buildDotMarks(
    x: number,
    y: number,
    width: number,
    height: number,
    radius: number,
    step: number,
    seed: number,
): StippleCircle[] {
    const marks: StippleCircle[] = [];
    const inset = radius * 0.35;
    const left = x + inset;
    const right = x + width - inset;
    const top = y + inset;
    const bottom = y + height - inset;

    if (right <= left || bottom <= top) {
        return [
            {
                kind: 'circle',
                cx: x + width / 2,
                cy: y + height / 2,
                r: Math.min(radius, width / 2, height / 2),
            },
        ];
    }

    let row = 0;

    for (let cy = top + step / 2; cy <= bottom; cy += step, row += 1) {
        const rowOffset = row % 2 === 0 ? 0 : step / 2;
        let col = 0;

        for (let cx = left + step / 2 + rowOffset; cx <= right; cx += step, col += 1) {
            const jx = jitter(seed, row, col, 1) * radius * 0.28;
            const jy = jitter(seed, row, col, 2) * radius * 0.28;
            const px = clamp(cx + jx, left, right);
            const py = clamp(cy + jy, top, bottom);

            marks.push({
                kind: 'circle',
                cx: px,
                cy: py,
                r: radius,
            });
        }
    }

    return marks.length > 0
        ? marks
        : [
              {
                  kind: 'circle',
                  cx: x + width / 2,
                  cy: y + height / 2,
                  r: Math.min(radius, width / 2, height / 2),
              },
          ];
}

function buildHexMarks(
    x: number,
    y: number,
    width: number,
    height: number,
    radius: number,
    step: number,
    seed: number,
): StippleHex[] {
    const marks: StippleHex[] = [];
    const inset = radius * 0.2;
    const left = x + inset;
    const right = x + width - inset;
    const top = y + inset;
    const bottom = y + height - inset;
    const colStep = step * 0.75;
    const rowStep = step * Math.sqrt(3) * 0.5;

    if (right <= left || bottom <= top) {
        return [
            {
                kind: 'hex',
                points: hexPoints(x + width / 2, y + height / 2, Math.min(radius, width / 2, height / 2)),
            },
        ];
    }

    let row = 0;

    for (let cy = top + rowStep / 2; cy <= bottom; cy += rowStep, row += 1) {
        const rowOffset = row % 2 === 0 ? 0 : colStep / 2;
        let col = 0;

        for (let cx = left + colStep / 2 + rowOffset; cx <= right; cx += colStep, col += 1) {
            const jx = jitter(seed, row, col, 3) * radius * 0.18;
            const jy = jitter(seed, row, col, 4) * radius * 0.18;
            const px = clamp(cx + jx, left, right);
            const py = clamp(cy + jy, top, bottom);

            marks.push({
                kind: 'hex',
                points: hexPoints(px, py, radius),
            });
        }
    }

    return marks.length > 0
        ? marks
        : [
              {
                  kind: 'hex',
                  points: hexPoints(x + width / 2, y + height / 2, Math.min(radius, width / 2, height / 2)),
              },
          ];
}

function hexPoints(cx: number, cy: number, radius: number): string {
    const points: string[] = [];

    for (let i = 0; i < 6; i += 1) {
        const angle = (Math.PI / 180) * (60 * i - 30);
        const px = cx + radius * Math.cos(angle);
        const py = cy + radius * Math.sin(angle);
        points.push(`${round(px)},${round(py)}`);
    }

    return points.join(' ');
}

/** Deterministic pseudo-random in [-1, 1]. */
function jitter(seed: number, row: number, col: number, channel: number): number {
    const n = Math.sin(seed * 12.9898 + row * 78.233 + col * 37.719 + channel * 4.141) * 43758.5453;

    return (n - Math.floor(n)) * 2 - 1;
}

function clamp(value: number, min: number, max: number): number {
    return Math.min(max, Math.max(min, value));
}

function round(value: number): number {
    return Math.round(value * 100) / 100;
}
