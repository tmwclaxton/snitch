<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Snitch - Brand pitch</title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=caveat:500,600|figtree:400,500,600,700|young-serif:400" rel="stylesheet">
    <style>
        :root {
            --paper: #efe6d8;
            --fog: #e4d9c8;
            --ink: #1c1b1a;
            --spot: #f0c400;
            --teal: #3a5f6b;
            --lift: #fffdf8;
            --press: #1c1b1a;
            --display: "Young Serif", Georgia, serif;
            --sans: "Figtree", ui-sans-serif, system-ui, sans-serif;
            --note: "Caveat", "Segoe Print", cursive;
            --halftone: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='8' height='8' viewBox='0 0 8 8'%3E%3Ccircle cx='2' cy='2' r='1.1' fill='%231c1b1a' fill-opacity='0.18'/%3E%3C/svg%3E");
            --grain: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.95' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.55'/%3E%3C/svg%3E");
            --ticket: polygon(0% 10%, 4% 0, 14% 6%, 26% 0, 40% 5%, 54% 0, 68% 6%, 82% 0, 94% 5%, 100% 12%, 98% 50%, 100% 88%, 94% 100%, 80% 95%, 66% 100%, 50% 94%, 36% 100%, 20% 95%, 8% 100%, 0 88%);
        }

        * { box-sizing: border-box; }
        html, body { margin: 0; height: 100%; background: #1c1b1a; color: var(--ink); font-family: var(--sans); }
        body { overflow: hidden; }

        .room { min-height: 100dvh; display: grid; place-items: center; background: #141311; }

        .stage {
            position: relative;
            display: grid;
            grid-template-rows: minmax(0, 1fr) auto;
            width: min(100vw, calc(100dvh * 16 / 9));
            height: min(100dvh, calc(100vw * 9 / 16));
            overflow: hidden;
            background: var(--paper);
            isolation: isolate;
            container-type: size;
            container-name: deck;
            box-shadow: 0 28px 90px rgba(0, 0, 0, 0.55);
        }

        .stage::before {
            content: "";
            position: absolute;
            inset: 0;
            z-index: 2;
            pointer-events: none;
            background-image: var(--halftone);
            background-size: 7px 7px;
            opacity: 0.42;
            mix-blend-mode: multiply;
        }

        .stage::after {
            content: "";
            position: absolute;
            inset: -18%;
            z-index: 1;
            pointer-events: none;
            background-image: var(--grain);
            opacity: 0.11;
        }

        .deck { position: relative; z-index: 3; min-height: 0; overflow: hidden; }

        .slide {
            position: absolute;
            inset: 0;
            display: none;
            padding: clamp(0.8rem, 3cqi, 1.65rem);
            min-height: 0;
            overflow: hidden;
        }

        .slide.is-on { display: grid; gap: clamp(0.5rem, 1.5cqh, 0.9rem); animation: settle 280ms ease both; }
        @keyframes settle { from { opacity: 0; } to { opacity: 1; } }

        .wordmark { position: relative; font-family: var(--display); letter-spacing: -0.03em; line-height: 0.9; }
        .wordmark .ghost {
            position: absolute; inset: 0; color: var(--spot);
            transform: translate(1px, 1px); opacity: 0.32; mix-blend-mode: multiply; pointer-events: none;
        }

        .kicker {
            font-size: clamp(0.6rem, 1.35cqi, 0.72rem);
            letter-spacing: 0.18em;
            text-transform: uppercase;
            font-weight: 700;
            color: color-mix(in oklab, var(--ink) 55%, var(--paper));
        }

        h1, h2, h3, p { margin: 0; }
        h1, h2 { font-family: var(--display); font-weight: 400; line-height: 0.95; letter-spacing: -0.03em; }
        .title { font-size: clamp(1.45rem, 4.3cqi, 2.75rem); max-width: 20ch; }
        .lede { font-size: clamp(0.88rem, 1.65cqi, 1.08rem); line-height: 1.35; max-width: 36ch; color: color-mix(in oklab, var(--ink) 76%, var(--paper)); }
        .note { font-family: var(--note); line-height: 1.08; }

        .chrome {
            z-index: 6;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.55rem;
            padding: 0.5rem 0.8rem 0.65rem;
            background: color-mix(in oklab, var(--paper) 86%, var(--fog));
            border-top: 1px solid color-mix(in oklab, var(--ink) 10%, transparent);
        }

        .chrome button, .dots button { font: inherit; cursor: pointer; }
        .brand-slot { display: flex; align-items: center; gap: 0.4rem; min-width: 6.2rem; }
        .brand-slot img { width: 1.65rem; height: 1.65rem; object-fit: contain; }
        .folio { font-family: var(--note); font-size: 1rem; min-width: 2.6rem; }

        .ticket {
            border: 0;
            color: var(--press);
            background: var(--spot);
            padding: 0.38rem 0.72rem;
            clip-path: var(--ticket);
            box-shadow: 2px 2px 0 color-mix(in oklab, var(--ink) 18%, transparent);
            font-weight: 700;
            font-size: 0.7rem;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }
        .ticket:disabled { opacity: 0.32; cursor: default; box-shadow: none; }

        .dots { display: flex; gap: 0.26rem; justify-content: center; flex-wrap: wrap; }
        .dots button { width: 0.46rem; height: 0.46rem; border: 0; padding: 0; background: color-mix(in oklab, var(--ink) 20%, var(--paper)); }
        .dots button.is-on { background: var(--spot); box-shadow: 1px 1px 0 var(--ink); }

        .stack { grid-template-rows: auto minmax(0, 1fr); }
        .cards-2, .cards-3, .cards-4, .chips, .why-grid, .meter, .outcomes {
            display: grid; gap: 0.6rem; min-height: 0; align-items: stretch;
        }
        .cards-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .cards-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .cards-4 { grid-template-columns: repeat(4, minmax(0, 1fr)); }

        .tape {
            height: 0.7rem;
            width: 38%;
            margin: -0.85rem auto 0.45rem;
            background:
                repeating-linear-gradient(90deg, color-mix(in oklab, var(--spot) 55%, transparent) 0 6px, transparent 6px 8px),
                color-mix(in oklab, var(--spot) 48%, var(--paper));
            box-shadow: 0 1px 0 color-mix(in oklab, var(--ink) 12%, transparent);
            opacity: 0.9;
        }

        .film {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 0.18rem;
            height: 3.15rem;
            padding: 0.28rem;
            background: var(--ink);
            flex: 0 0 auto;
        }

        .film i {
            display: block;
            height: 100%;
            background:
                linear-gradient(180deg, color-mix(in oklab, var(--spot) 16%, #2c2823), #141210);
            box-shadow: inset 0 0 0 1px color-mix(in oklab, var(--spot) 20%, transparent);
        }

        .film i.win {
            background:
                linear-gradient(180deg, var(--spot), color-mix(in oklab, var(--spot) 58%, #2f2a08));
        }

        .film.thin { grid-template-columns: repeat(3, minmax(0, 1fr)); height: 2.35rem; }

        .mark {
            display: inline-grid;
            place-items: center;
            width: 1.15rem;
            height: 1.15rem;
            font-family: var(--note);
            font-size: 0.95rem;
            line-height: 1;
            flex: 0 0 auto;
        }
        .mark.ok { background: var(--spot); color: var(--press); }
        .mark.no { background: color-mix(in oklab, var(--ink) 12%, var(--paper)); color: color-mix(in oklab, var(--ink) 55%, var(--paper)); }

        .cover {
            grid-template-columns: minmax(0, 1.12fr) minmax(0, 0.88fr);
        }
        .cover-copy { display: grid; align-content: center; gap: 0.7rem; min-width: 0; }
        .cover h1 { font-size: clamp(3.3rem, 12cqi, 6.8rem); }
        .cover .line { font-size: clamp(1.05rem, 2.35cqi, 1.55rem); max-width: 16ch; }
        .cover .lede { max-width: 28ch; }

        .platforms { display: flex; gap: 0.38rem; align-items: center; }
        .platforms span {
            display: grid; place-items: center;
            width: 2rem; height: 2rem;
            background: var(--lift);
            box-shadow: 2px 2px 0 color-mix(in oklab, var(--ink) 14%, transparent);
        }
        .platforms img { width: 1.15rem; height: 1.15rem; }

        .cover-art {
            min-width: 0;
            min-height: 0;
            display: grid;
            align-items: end;
            background: linear-gradient(180deg, transparent 62%, var(--spot) 62%);
        }
        .cover-art img { width: 100%; height: 100%; object-fit: contain; object-position: bottom center; }

        .quote {
            display: grid;
            place-items: center;
            min-height: 0;
            padding: 0.7rem 1rem;
            background: color-mix(in oklab, var(--spot) 55%, var(--paper));
            box-shadow: 6px 6px 0 var(--ink);
            font-family: var(--note);
            font-size: clamp(1.55rem, 4.2cqi, 2.55rem);
            text-align: center;
        }

        .chips { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        .chip {
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
            min-height: 0;
            padding: 0.5rem 0.45rem 0.6rem;
            background: var(--lift);
            box-shadow: 3px 4px 0 color-mix(in oklab, var(--ink) 14%, transparent);
            text-align: center;
        }
        .chip b { font-family: var(--note); font-size: clamp(1.05rem, 2.1cqi, 1.35rem); font-weight: 600; }
        .chip small { font-size: 0.68rem; letter-spacing: 0.04em; text-transform: uppercase; color: color-mix(in oklab, var(--ink) 50%, var(--paper)); }

        .scrap, .cutout, .col, .stat, .poster, .outcome, .bar {
            min-width: 0; min-height: 0;
        }

        .scrap {
            display: flex;
            flex-direction: column;
            gap: 0.28rem;
            padding: 0.7rem 0.65rem 0.75rem;
            background: color-mix(in oklab, var(--lift) 80%, var(--paper));
            box-shadow: 4px 5px 0 color-mix(in oklab, var(--spot) 40%, transparent);
        }
        .scrap h3 { font-family: var(--display); font-size: clamp(1.2rem, 2.3cqi, 1.6rem); margin: 0.25rem 0 0.2rem; }
        .scrap .num { font-family: var(--note); color: var(--teal); font-size: 1.25rem; }
        .scrap p { font-size: clamp(0.82rem, 1.45cqi, 0.98rem); }
        .press {
            flex: 1;
            min-height: 1.6rem;
            margin-top: 0.35rem;
            background:
                repeating-linear-gradient(
                    90deg,
                    color-mix(in oklab, var(--ink) 9%, var(--paper)) 0 7px,
                    transparent 7px 14px
                ),
                color-mix(in oklab, var(--spot) 14%, var(--paper));
        }

        .polaroid {
            display: grid;
            grid-template-rows: auto minmax(0, 1fr) auto;
            height: 100%;
            min-height: 0;
            padding: 0.35rem 0.4rem 0.55rem;
            background: var(--lift);
            box-shadow: 6px 7px 0 color-mix(in oklab, var(--spot) 52%, transparent);
        }
        .polaroid img { width: 100%; height: 100%; min-height: 0; object-fit: contain; background: color-mix(in oklab, var(--spot) 22%, var(--paper)); }
        .polaroid figcaption { font-family: var(--note); text-align: center; font-size: clamp(1.05rem, 2cqi, 1.3rem); padding-top: 0.3rem; }

        .stat {
            display: grid;
            grid-template-rows: auto 1fr auto;
            padding: 0.5rem 0.55rem 0.65rem;
            background: var(--lift);
            box-shadow: 4px 5px 0 var(--spot);
        }
        .stat strong { display: block; font-family: var(--display); font-size: clamp(2rem, 5.6cqi, 3.4rem); line-height: 0.88; align-self: end; }
        .stat span { font-size: clamp(0.74rem, 1.4cqi, 0.9rem); }

        .why-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .poster {
            display: grid;
            grid-template-rows: auto 1fr auto;
            padding: 0.65rem 0.7rem;
            background: color-mix(in oklab, var(--spot) 16%, var(--paper));
            box-shadow: 5px 6px 0 color-mix(in oklab, var(--ink) 16%, transparent);
        }
        .poster:nth-child(2) { background: color-mix(in oklab, var(--spot) 48%, var(--paper)); }
        .poster:nth-child(3) { background: color-mix(in oklab, var(--teal) 18%, var(--paper)); }
        .poster h3 { font-family: var(--display); font-size: clamp(1.15rem, 2.4cqi, 1.55rem); }
        .poster p { font-size: clamp(0.8rem, 1.45cqi, 0.95rem); align-self: end; }

        .cutout {
            display: grid;
            grid-template-rows: auto auto 1fr auto;
            padding: 0.7rem;
            background: color-mix(in oklab, var(--spot) 18%, var(--paper));
            box-shadow: 6px 6px 0 color-mix(in oklab, var(--ink) 15%, transparent);
        }
        .cutout h3 { font-family: var(--display); font-size: clamp(1.3rem, 2.7cqi, 1.85rem); }
        .cutout .lede { max-width: 26ch; }
        .sticker { display: inline-block; background: var(--spot); color: var(--press); font-family: var(--note); padding: 0.08rem 0.45rem; width: fit-content; }

        .people { display: flex; gap: 0.35rem; margin: 0.55rem 0 auto; }
        .people i {
            width: 1.7rem; height: 1.7rem;
            background: var(--lift);
            box-shadow: 2px 2px 0 color-mix(in oklab, var(--ink) 12%, transparent);
            background-image: url("/images/brand/mascot-mark.png");
            background-size: 80%;
            background-repeat: no-repeat;
            background-position: center;
        }
        .people.agency i:nth-child(2),
        .people.agency i:nth-child(3) { opacity: 0.55; }

        .col {
            display: flex;
            flex-direction: column;
            gap: 0.45rem;
            padding: 0.65rem 0.65rem 0.7rem;
            background: var(--lift);
            box-shadow: 4px 4px 0 color-mix(in oklab, var(--ink) 12%, transparent);
        }
        .col.win { background: color-mix(in oklab, var(--spot) 46%, var(--paper)); box-shadow: 4px 5px 0 var(--ink); }
        .col h3 { font-family: var(--display); font-size: clamp(1.05rem, 2.1cqi, 1.4rem); margin-bottom: 0.45rem; }
        .col ul { margin: 0; padding: 0; list-style: none; display: grid; gap: 0.38rem; font-size: clamp(0.78rem, 1.4cqi, 0.92rem); }
        .col li { display: flex; align-items: center; gap: 0.4rem; }
        .price { margin-top: 0.7rem; font-family: var(--note); font-size: clamp(1.15rem, 2.1cqi, 1.4rem); }

        .money { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); align-items: stretch; }
        .ticket-xl {
            display: grid;
            place-content: center;
            text-align: center;
            min-height: 0;
            background: var(--spot);
            color: var(--press);
            clip-path: var(--ticket);
            box-shadow: 8px 8px 0 var(--ink);
            padding: 1rem;
        }
        .giant { font-family: var(--display); font-size: clamp(3.8rem, 12cqi, 7.2rem); line-height: 0.85; }
        .econ { display: flex; gap: 0.5rem; margin-top: 0.75rem; }
        .econ b {
            display: grid;
            padding: 0.45rem 0.55rem;
            background: var(--lift);
            box-shadow: 3px 3px 0 color-mix(in oklab, var(--spot) 40%, transparent);
            font-family: var(--display);
            font-size: clamp(1.05rem, 2.2cqi, 1.4rem);
            font-weight: 400;
        }
        .econ span { display: block; font-family: var(--sans); font-size: 0.68rem; letter-spacing: 0.08em; text-transform: uppercase; font-weight: 700; color: color-mix(in oklab, var(--ink) 55%, var(--paper)); }

        .bar {
            display: grid;
            grid-template-columns: 4.8rem minmax(0, 1fr);
            gap: 0.7rem;
            align-items: center;
            padding: 0.65rem 0.8rem;
        }
        .bar.a { background: color-mix(in oklab, var(--spot) 42%, var(--paper)); }
        .bar.b { background: color-mix(in oklab, var(--teal) 24%, var(--paper)); }
        .bar.c { background: color-mix(in oklab, var(--ink) 9%, var(--paper)); }
        .bar strong { font-family: var(--display); font-size: clamp(1.55rem, 3.4cqi, 2.2rem); }
        .track { height: 0.72rem; margin-top: 0.35rem; background: color-mix(in oklab, var(--ink) 10%, var(--paper)); overflow: hidden; }
        .track i { display: block; height: 100%; background: var(--press); }
        .bar.a .track i { background: var(--press); }
        .bar.b .track i { background: var(--teal); }
        .bar.c .track i { background: color-mix(in oklab, var(--ink) 45%, var(--paper)); }

        .ask { grid-template-columns: minmax(0, 0.95fr) minmax(0, 1.05fr); }
        .outcomes { margin: 0; padding: 0; }
        .outcome {
            display: grid;
            grid-template-columns: 1.7rem minmax(0, 1fr);
            gap: 0.55rem;
            align-items: center;
            padding: 0.55rem 0.65rem;
            background: var(--lift);
            box-shadow: 3px 3px 0 color-mix(in oklab, var(--spot) 50%, transparent);
            list-style: none;
            font-size: clamp(0.85rem, 1.55cqi, 1.02rem);
        }
        .outcome i {
            display: grid; place-items: center;
            width: 1.55rem; height: 1.55rem;
            background: var(--spot);
            font-family: var(--note);
            font-size: 1.1rem;
            font-style: normal;
        }

        .close {
            place-items: center;
            text-align: center;
        }
        .close-inner {
            display: grid;
            justify-items: center;
            gap: 0.4rem;
            padding: 1rem 1.4rem 1.1rem;
            background: color-mix(in oklab, var(--spot) 55%, var(--paper));
            box-shadow: 8px 8px 0 var(--ink);
        }
        .close h2 { font-size: clamp(3.1rem, 10cqi, 5.6rem); }
        .close img { width: min(20cqi, 8.8rem); height: auto; }
        .close .note { font-size: clamp(1.2rem, 2.5cqi, 1.7rem); }

        @container deck (max-width: 760px) {
            .cover, .money, .ask { grid-template-columns: 1fr; }
            .cover-art { max-height: 36%; }
            .chips, .cards-4, .cards-3, .why-grid { grid-template-columns: 1fr 1fr; }
        }

        @media print {
            body, .room { background: var(--paper); }
            .stage { width: 100%; height: auto; box-shadow: none; overflow: visible; grid-template-rows: auto; }
            .deck { overflow: visible; }
            .slide { position: relative; display: grid !important; height: 100vh; page-break-after: always; animation: none; }
            .chrome { display: none; }
        }
    </style>
</head>
<body>
    <div class="room">
        <div class="stage" id="stage">
            <div class="deck">
                <section class="slide cover is-on" data-slide>
                    <div class="cover-copy">
                        <p class="kicker">Brand pitch</p>
                        <h1 class="wordmark"><span class="ghost" aria-hidden="true">Snitch</span><span>Snitch</span></h1>
                        <p class="line">Outperform your market by knowing exactly what works.</p>
                        <p class="lede">The winning posts are already public. We assemble the formula.</p>
                        <div class="platforms" aria-hidden="true">
                            <span><img src="/images/platforms/instagram.svg" alt=""></span>
                            <span><img src="/images/platforms/tiktok.svg" alt=""></span>
                            <span><img src="/images/platforms/facebook.svg" alt=""></span>
                            <span><img src="/images/platforms/linkedin.svg" alt=""></span>
                        </div>
                    </div>
                    <div class="cover-art">
                        <img src="/images/marketing/hero/mascot-character.png" alt="">
                    </div>
                </section>

                <section class="slide stack" data-slide>
                    <div>
                        <p class="kicker">The problem</p>
                        <h2 class="title">They ship into the void every week.</h2>
                        <p class="lede" style="margin-top:0.4rem">Businesses do not know how to get the most from social. They post, they boost, they still cannot say what worked.</p>
                    </div>
                    <div class="stack" style="gap:0.55rem;min-height:0">
                        <p class="quote">“What do we post this week?”</p>
                        <div class="chips">
                            <article class="chip">
                                <div class="film thin" aria-hidden="true"><i></i><i></i><i></i></div>
                                <b>What</b>
                                <small>The idea</small>
                            </article>
                            <article class="chip">
                                <div class="film thin" aria-hidden="true"><i></i><i class="win"></i><i></i></div>
                                <b>When</b>
                                <small>The timing</small>
                            </article>
                            <article class="chip">
                                <div class="film thin" aria-hidden="true"><i></i><i></i><i class="win"></i></div>
                                <b>Captions</b>
                                <small>The words</small>
                            </article>
                            <article class="chip">
                                <div class="film thin" aria-hidden="true"><i class="win"></i><i></i><i></i></div>
                                <b>Ads or organic?</b>
                                <small>The spend</small>
                            </article>
                        </div>
                    </div>
                </section>

                <section class="slide stack" data-slide>
                    <div>
                        <p class="kicker">Solution</p>
                        <h2 class="title">Real posts. Real winners. No guesswork.</h2>
                        <p class="lede" style="margin-top:0.4rem">Snitch tracks what competitors publish, then shows the formula that is actually working. Head-to-head gap, then the next post.</p>
                    </div>
                    <div class="cards-3">
                        <article class="scrap">
                            <div class="tape" aria-hidden="true"></div>
                            <div class="film" aria-hidden="true"><i></i><i></i><i></i><i></i></div>
                            <p class="num">01 Track</p>
                            <h3>One sheet</h3>
                            <p>Their public posts, pulled into one contact board.</p>
                            <div class="press" aria-hidden="true"></div>
                        </article>
                        <article class="scrap">
                            <div class="tape" aria-hidden="true"></div>
                            <div class="film" aria-hidden="true"><i></i><i class="win"></i><i></i><i class="win"></i></div>
                            <p class="num">02 Reveal</p>
                            <h3>The winners</h3>
                            <p>What is performing best in your market, not a vibe.</p>
                            <div class="press" aria-hidden="true"></div>
                        </article>
                        <article class="scrap">
                            <div class="tape" aria-hidden="true"></div>
                            <div class="film" aria-hidden="true"><i class="win"></i><i class="win"></i><i></i><i></i></div>
                            <p class="num">03 Remake</p>
                            <h3>Your move</h3>
                            <p>Copy the move in your voice. That is the product.</p>
                            <div class="press" aria-hidden="true"></div>
                        </article>
                    </div>
                </section>

                <section class="slide stack" data-slide>
                    <div>
                        <p class="kicker">Founders</p>
                        <h2 class="title">We felt this pain first.</h2>
                        <p class="lede" style="margin-top:0.4rem">Two operators who could not tell what to post, until we built the board we needed. We are raising to prove it, not to pose as finished.</p>
                    </div>
                    <div class="cards-2">
                        <figure class="polaroid">
                            <div class="tape" aria-hidden="true"></div>
                            <img src="/images/marketing/hero/mascot-character.png" alt="">
                            <figcaption>Dan Smyth</figcaption>
                        </figure>
                        <figure class="polaroid">
                            <div class="tape" aria-hidden="true"></div>
                            <img src="/images/marketing/hero/mascot-binos.png" alt="">
                            <figcaption>Toby Claxton</figcaption>
                        </figure>
                    </div>
                </section>

                <section class="slide stack" data-slide>
                    <div>
                        <p class="kicker">Early traction</p>
                        <h2 class="title">Proof of interest. Not a finished machine.</h2>
                        <p class="lede" style="margin-top:0.35rem">Signal before scale. The raise is to turn this into paying habit.</p>
                    </div>
                    <div class="cards-4">
                        <article class="stat">
                            <div class="film thin" aria-hidden="true"><i class="win"></i><i></i><i></i></div>
                            <strong>214</strong>
                            <span>cold emails sent</span>
                        </article>
                        <article class="stat">
                            <div class="film thin" aria-hidden="true"><i class="win"></i><i class="win"></i><i></i></div>
                            <strong>69%</strong>
                            <span>open rate</span>
                        </article>
                        <article class="stat">
                            <div class="film thin" aria-hidden="true"><i></i><i class="win"></i><i></i></div>
                            <strong>14%</strong>
                            <span>click through</span>
                        </article>
                        <article class="stat">
                            <div class="film thin" aria-hidden="true"><i></i><i></i><i class="win"></i></div>
                            <strong>15</strong>
                            <span>private beta seats</span>
                        </article>
                    </div>
                </section>

                <section class="slide stack" data-slide>
                    <div>
                        <p class="kicker">Why now</p>
                        <h2 class="title">The channel is social. The gap is what to post.</h2>
                    </div>
                    <div class="why-grid">
                        <article class="poster">
                            <p class="sticker">01</p>
                            <h3>Social is the bet</h3>
                            <p>Small businesses put growth on social. They cannot staff a research desk.</p>
                        </article>
                        <article class="poster">
                            <p class="sticker">02</p>
                            <h3>Posting is the jam</h3>
                            <p>The bottleneck is not tools. It is knowing what to post this week.</p>
                        </article>
                        <article class="poster">
                            <p class="sticker">03</p>
                            <h3>Seats got expensive</h3>
                            <p>Incumbents sell dashboards to teams. AI made the answer cheap enough for £10.</p>
                        </article>
                    </div>
                </section>

                <section class="slide stack" data-slide>
                    <div>
                        <p class="kicker">Who it is for</p>
                        <h2 class="title">Start where the incumbents cannot sell.</h2>
                        <p class="lede" style="margin-top:0.35rem">One product. Two nearby buyers. Beachhead first, not a TAM slide.</p>
                    </div>
                    <div class="cards-2">
                        <article class="cutout">
                            <p class="sticker">A</p>
                            <h3>Solo marketers</h3>
                            <p class="lede">In-house people who have to post, and do not have a research team.</p>
                            <div class="people" aria-hidden="true"><i></i></div>
                        </article>
                        <article class="cutout">
                            <p class="sticker">B</p>
                            <h3>Micro-agencies</h3>
                            <p class="lede">Small shops priced out of Rival IQ and Socialinsider.</p>
                            <div class="people agency" aria-hidden="true"><i></i><i></i><i></i></div>
                        </article>
                    </div>
                </section>

                <section class="slide stack" data-slide>
                    <div>
                        <p class="kicker">Why Snitch</p>
                        <h2 class="title">They show numbers. We show the gap.</h2>
                        <p class="lede" style="margin-top:0.35rem">Wedge: tell a solo what to change, at a price they can actually pay.</p>
                    </div>
                    <div class="cards-3">
                        <article class="col">
                            <h3>Socialinsider</h3>
                            <ul>
                                <li><span class="mark ok">+</span> Competitor data</li>
                                <li><span class="mark no">x</span> Built for teams</li>
                                <li><span class="mark no">x</span> No “what to change”</li>
                            </ul>
                            <p class="price">£66+</p>
                        </article>
                        <article class="col">
                            <h3>Rival IQ</h3>
                            <ul>
                                <li><span class="mark ok">+</span> You vs them</li>
                                <li><span class="mark no">x</span> Built for agencies</li>
                                <li><span class="mark no">x</span> Still a dashboard</li>
                            </ul>
                            <p class="price">£177+</p>
                        </article>
                        <article class="col win">
                            <h3>Snitch</h3>
                            <ul>
                                <li><span class="mark ok">+</span> Solo + micro-agency</li>
                                <li><span class="mark ok">+</span> Head-to-head gap</li>
                                <li><span class="mark ok">+</span> What to post next</li>
                            </ul>
                            <p class="price">£10 to £30</p>
                        </article>
                    </div>
                </section>

                <section class="slide money" data-slide>
                    <div style="align-self:center">
                        <p class="kicker">How we make money</p>
                        <h2 class="title">One model. Subscription.</h2>
                        <p class="lede" style="margin-top:0.55rem">Validated at £10+ a month. Costs under £1 a month per user at scale. That is the whole story.</p>
                        <div class="econ">
                            <b><span>Charge</span>£10</b>
                            <b><span>Run</span>&lt; £1</b>
                        </div>
                    </div>
                    <div class="ticket-xl">
                        <p class="giant">£10</p>
                        <p class="note" style="font-size:1.55rem;margin-top:0.25rem">per month. Nothing else.</p>
                    </div>
                </section>

                <section class="slide stack" data-slide>
                    <div>
                        <p class="kicker">Use of funds</p>
                        <h2 class="title">Buy the answers. Keep the unit economics honest.</h2>
                    </div>
                    <div class="meter">
                        <article class="bar a">
                            <strong>60%</strong>
                            <div>
                                <p><b>Find the channel.</b> Outreach, content, ads, affiliates.</p>
                                <div class="track" aria-hidden="true"><i style="width:60%"></i></div>
                            </div>
                        </article>
                        <article class="bar b">
                            <strong>30%</strong>
                            <div>
                                <p><b>Keep it running.</b> Hosting, scrape, AI, admin.</p>
                                <div class="track" aria-hidden="true"><i style="width:30%"></i></div>
                            </div>
                        </article>
                        <article class="bar c">
                            <strong>10%</strong>
                            <div>
                                <p><b>Show up.</b> Marketing rooms where buyers already are.</p>
                                <div class="track" aria-hidden="true"><i style="width:10%"></i></div>
                            </div>
                        </article>
                    </div>
                </section>

                <section class="slide ask" data-slide>
                    <div style="align-self:center">
                        <p class="kicker">The ask</p>
                        <h2 class="title">Fund the proof.</h2>
                        <p class="lede" style="margin-top:0.55rem">This is a POC. You are not buying a finished, profitable company. You are funding the work that makes it possible.</p>
                    </div>
                    <ul class="outcomes">
                        <li class="outcome"><i>1</i> Which buyer sticks: solo or agency</li>
                        <li class="outcome"><i>2</i> Which channel actually signs people up</li>
                        <li class="outcome"><i>3</i> Paying customers by the end of the grant</li>
                        <li class="outcome"><i>4</i> A GTM we can take to the next conversation</li>
                    </ul>
                </section>

                <section class="slide close" data-slide>
                    <div class="close-inner">
                        <img src="/images/marketing/hero/mascot-binos.png" alt="">
                        <h2 class="wordmark"><span class="ghost" aria-hidden="true">Snitch</span><span>Snitch</span></h2>
                        <p class="note">Know what works. Then post it.</p>
                    </div>
                </section>
            </div>

            <nav class="chrome" aria-label="Deck controls">
                <div class="brand-slot">
                    <img src="/images/brand/mascot-mark.png" alt="">
                    <button class="ticket" type="button" id="prev">Back</button>
                </div>
                <p class="folio" id="folio">01 / 12</p>
                <div class="dots" id="dots"></div>
                <button class="ticket" type="button" id="next">Next</button>
            </nav>
        </div>
    </div>

    <script>
        (function () {
            const slides = Array.from(document.querySelectorAll('[data-slide]'));
            const dots = document.getElementById('dots');
            const prev = document.getElementById('prev');
            const next = document.getElementById('next');
            const folio = document.getElementById('folio');
            let index = 0;

            slides.forEach((_, i) => {
                const dot = document.createElement('button');
                dot.type = 'button';
                dot.setAttribute('aria-label', 'Slide ' + (i + 1));
                dot.addEventListener('click', () => go(i));
                dots.appendChild(dot);
            });

            function go(nextIndex) {
                index = Math.max(0, Math.min(slides.length - 1, nextIndex));
                slides.forEach((slide, i) => slide.classList.toggle('is-on', i === index));
                Array.from(dots.children).forEach((dot, i) => dot.classList.toggle('is-on', i === index));
                prev.disabled = index === 0;
                next.disabled = index === slides.length - 1;
                folio.textContent = String(index + 1).padStart(2, '0') + ' / ' + String(slides.length).padStart(2, '0');
                history.replaceState(null, '', '#' + (index + 1));
            }

            prev.addEventListener('click', () => go(index - 1));
            next.addEventListener('click', () => go(index + 1));

            document.addEventListener('keydown', (event) => {
                if (['ArrowRight', 'ArrowDown', 'PageDown', ' '].includes(event.key)) {
                    event.preventDefault();
                    go(index + 1);
                }
                if (['ArrowLeft', 'ArrowUp', 'PageUp', 'Backspace'].includes(event.key)) {
                    event.preventDefault();
                    go(index - 1);
                }
                if (event.key === 'Home') go(0);
                if (event.key === 'End') go(slides.length - 1);
            });

            let startX = null;
            document.addEventListener('touchstart', (event) => {
                startX = event.changedTouches[0].clientX;
            }, { passive: true });
            document.addEventListener('touchend', (event) => {
                if (startX === null) return;
                const delta = event.changedTouches[0].clientX - startX;
                if (Math.abs(delta) > 40) go(index + (delta < 0 ? 1 : -1));
                startX = null;
            });

            const fromHash = Number.parseInt(window.location.hash.replace('#', ''), 10);
            go(Number.isFinite(fromHash) ? fromHash - 1 : 0);
        })();
    </script>
</body>
</html>
