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
        }

        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            height: 100%;
            background: #1c1b1a;
            color: var(--ink);
            font-family: var(--sans);
        }

        body { overflow: hidden; }

        .room {
            min-height: 100dvh;
            display: grid;
            place-items: center;
            background: #1c1b1a;
        }

        .stage {
            position: relative;
            display: grid;
            grid-template-rows: minmax(0, 1fr) auto;
            width: min(100vw, calc(100dvh * 16 / 9));
            height: min(100dvh, calc(100vw * 9 / 16));
            overflow: hidden;
            background: var(--paper);
            color: var(--ink);
            isolation: isolate;
            container-type: size;
            container-name: deck;
            box-shadow: 0 24px 80px rgba(0, 0, 0, 0.45);
        }

        .stage::before {
            content: "";
            position: absolute;
            inset: 0;
            z-index: 2;
            pointer-events: none;
            background-image: var(--halftone);
            background-size: 7px 7px;
            opacity: 0.45;
            mix-blend-mode: multiply;
        }

        .stage::after {
            content: "";
            position: absolute;
            inset: -20%;
            z-index: 1;
            pointer-events: none;
            background-image: var(--grain);
            opacity: 0.12;
        }

        .deck {
            position: relative;
            z-index: 3;
            min-height: 0;
            overflow: hidden;
        }

        .slide {
            position: absolute;
            inset: 0;
            display: none;
            padding: clamp(0.85rem, 3.2cqi, 1.8rem);
            min-height: 0;
            overflow: hidden;
        }

        .slide.is-on {
            display: grid;
            gap: clamp(0.55rem, 1.6cqh, 1rem);
            animation: settle 360ms ease both;
        }

        @keyframes settle {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .wordmark {
            position: relative;
            font-family: var(--display);
            font-weight: 400;
            letter-spacing: -0.03em;
            line-height: 0.92;
        }

        .wordmark .ghost {
            position: absolute;
            inset: 0;
            color: var(--spot);
            transform: translate(1px, 1px);
            opacity: 0.35;
            mix-blend-mode: multiply;
            pointer-events: none;
        }

        .kicker {
            font-size: clamp(0.62rem, 1.4cqi, 0.75rem);
            letter-spacing: 0.16em;
            text-transform: uppercase;
            font-weight: 600;
            color: color-mix(in oklab, var(--ink) 62%, var(--paper));
        }

        h1, h2, h3 { margin: 0; }
        p { margin: 0; }

        h1, h2 {
            font-family: var(--display);
            font-weight: 400;
            line-height: 0.96;
            letter-spacing: -0.03em;
        }

        .title {
            font-size: clamp(1.55rem, 4.6cqi, 3rem);
            max-width: 22ch;
        }

        .lede {
            font-size: clamp(0.92rem, 1.7cqi, 1.12rem);
            line-height: 1.35;
            max-width: 34ch;
            color: color-mix(in oklab, var(--ink) 78%, var(--paper));
        }

        .note { font-family: var(--note); line-height: 1.1; }

        .chrome {
            position: relative;
            z-index: 6;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.6rem;
            padding: 0.55rem 0.85rem 0.7rem;
            background: color-mix(in oklab, var(--paper) 88%, var(--fog));
            border-top: 1px solid color-mix(in oklab, var(--ink) 10%, transparent);
        }

        .chrome button,
        .dots button {
            font: inherit;
            cursor: pointer;
        }

        .brand-slot {
            display: flex;
            align-items: center;
            gap: 0.45rem;
            min-width: 5.5rem;
        }

        .brand-slot img {
            width: 1.7rem;
            height: 1.7rem;
            object-fit: contain;
        }

        .ticket {
            border: 0;
            color: var(--press);
            background: var(--spot);
            padding: 0.4rem 0.75rem;
            clip-path: polygon(0% 12%, 6% 0, 18% 8%, 32% 0, 50% 7%, 68% 0, 82% 8%, 94% 0, 100% 14%, 98% 50%, 100% 88%, 94% 100%, 80% 94%, 64% 100%, 48% 93%, 32% 100%, 16% 94%, 4% 100%, 0 86%);
            box-shadow: 2px 2px 0 color-mix(in oklab, var(--ink) 18%, transparent);
            font-weight: 700;
            font-size: 0.72rem;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .ticket:disabled {
            opacity: 0.35;
            cursor: default;
            box-shadow: none;
        }

        .dots {
            display: flex;
            gap: 0.28rem;
            flex-wrap: wrap;
            justify-content: center;
        }

        .dots button {
            width: 0.48rem;
            height: 0.48rem;
            border: 0;
            border-radius: 1px;
            background: color-mix(in oklab, var(--ink) 22%, var(--paper));
            padding: 0;
        }

        .dots button.is-on {
            background: var(--spot);
            box-shadow: 1px 1px 0 var(--ink);
        }

        .scrap {
            min-width: 0;
            background: color-mix(in oklab, var(--lift) 72%, var(--paper));
            border: 1px solid color-mix(in oklab, var(--ink) 12%, transparent);
            box-shadow: 3px 4px 0 color-mix(in oklab, var(--spot) 32%, transparent);
            padding: clamp(0.7rem, 1.8cqi, 1rem);
        }

        .sticker {
            display: inline-block;
            background: var(--spot);
            color: var(--press);
            font-family: var(--note);
            padding: 0.12rem 0.5rem;
            box-shadow: 2px 2px 0 color-mix(in oklab, var(--ink) 16%, transparent);
        }

        .cover {
            grid-template-columns: minmax(0, 1.15fr) minmax(0, 0.85fr);
            align-items: stretch;
            background:
                radial-gradient(ellipse 70% 80% at 100% 100%, color-mix(in oklab, var(--spot) 50%, transparent), transparent 55%);
        }

        .cover-copy {
            display: grid;
            align-content: center;
            gap: clamp(0.55rem, 1.8cqh, 1rem);
            min-width: 0;
        }

        .cover h1 { font-size: clamp(3.2rem, 11cqi, 6.4rem); }

        .cover .line {
            font-size: clamp(1.05rem, 2.4cqi, 1.55rem);
            max-width: 18ch;
            color: color-mix(in oklab, var(--ink) 82%, var(--paper));
        }

        .platforms {
            display: flex;
            gap: 0.45rem;
            align-items: center;
        }

        .platforms img {
            width: clamp(1.35rem, 2.6cqi, 2rem);
            height: clamp(1.35rem, 2.6cqi, 2rem);
            object-fit: contain;
        }

        .cover-art {
            min-width: 0;
            min-height: 0;
            display: grid;
            align-items: end;
        }

        .cover-art img {
            width: 100%;
            height: 100%;
            max-height: 100%;
            object-fit: contain;
            object-position: bottom center;
        }

        .stack {
            grid-template-rows: auto minmax(0, 1fr);
            align-content: stretch;
        }

        .fill {
            min-height: 0;
            display: grid;
            gap: clamp(0.55rem, 1.5cqi, 0.85rem);
        }

        .problem .quote {
            display: grid;
            place-items: center;
            min-height: 0;
            padding: clamp(0.8rem, 2cqi, 1.2rem);
            background: color-mix(in oklab, var(--spot) 38%, var(--paper));
            box-shadow: 5px 5px 0 color-mix(in oklab, var(--ink) 14%, transparent);
            font-family: var(--note);
            font-size: clamp(1.6rem, 4.4cqi, 2.8rem);
            text-align: center;
        }

        .chips {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 0.55rem;
        }

        .chip {
            display: grid;
            place-items: center;
            min-height: clamp(3.2rem, 10cqh, 4.6rem);
            padding: 0.45rem;
            background: color-mix(in oklab, var(--lift) 70%, var(--paper));
            box-shadow: 3px 3px 0 color-mix(in oklab, var(--ink) 14%, transparent);
            font-family: var(--note);
            font-size: clamp(1rem, 2.1cqi, 1.4rem);
            text-align: center;
        }

        .cards-3,
        .cards-4,
        .cards-2 {
            display: grid;
            gap: 0.65rem;
            min-height: 0;
            align-items: stretch;
        }

        .cards-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .cards-4 { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        .cards-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }

        .step h3,
        .cutout h3,
        .col h3,
        .stat strong,
        .bar strong {
            font-family: var(--display);
            font-weight: 400;
        }

        .step h3 { font-size: clamp(1.2rem, 2.4cqi, 1.7rem); margin: 0.15rem 0 0.35rem; }
        .step .num { font-family: var(--note); font-size: 1.45rem; color: var(--teal); }
        .step p { font-size: clamp(0.85rem, 1.5cqi, 1rem); }

        .founders .cards-2 { align-items: stretch; }

        .polaroid {
            display: grid;
            grid-template-rows: minmax(0, 1fr) auto;
            min-height: 0;
            height: 100%;
            background: var(--lift);
            padding: 0.45rem 0.45rem 0.7rem;
            box-shadow: 5px 6px 0 color-mix(in oklab, var(--spot) 50%, transparent);
        }

        .polaroid img {
            width: 100%;
            height: 100%;
            min-height: 0;
            object-fit: contain;
            background: color-mix(in oklab, var(--spot) 26%, var(--paper));
        }

        .polaroid figcaption {
            margin-top: 0.4rem;
            font-family: var(--note);
            font-size: clamp(1.05rem, 2cqi, 1.35rem);
            text-align: center;
        }

        .stat,
        .cutout,
        .col,
        .bar,
        .outcome {
            min-width: 0;
            min-height: 0;
        }

        .stat {
            display: grid;
            align-content: center;
            padding: clamp(0.7rem, 1.8cqi, 1rem);
            background: var(--lift);
            box-shadow: 4px 5px 0 var(--spot);
        }

        .stat strong {
            display: block;
            font-size: clamp(2rem, 6cqi, 3.6rem);
            line-height: 0.9;
        }

        .stat span {
            display: block;
            margin-top: 0.4rem;
            font-size: clamp(0.78rem, 1.5cqi, 0.95rem);
        }

        .why .quote-block {
            display: grid;
            place-content: center;
            min-height: 0;
            padding: clamp(0.9rem, 2.4cqi, 1.4rem);
            text-align: center;
            background:
                radial-gradient(ellipse at 50% 80%, color-mix(in oklab, var(--spot) 42%, transparent), transparent 58%);
        }

        .why blockquote {
            margin: 0 auto;
            max-width: 18ch;
            font-family: var(--display);
            font-size: clamp(1.55rem, 4.4cqi, 2.7rem);
        }

        .why .note {
            margin: 0.7rem auto 0;
            max-width: 28ch;
            font-size: clamp(1.15rem, 2.4cqi, 1.55rem);
        }

        .cutout {
            display: grid;
            align-content: start;
            gap: 0.45rem;
            padding: clamp(0.85rem, 2cqi, 1.2rem);
            background: color-mix(in oklab, var(--spot) 18%, var(--paper));
            box-shadow: 6px 6px 0 color-mix(in oklab, var(--ink) 16%, transparent);
        }

        .cutout h3 { font-size: clamp(1.3rem, 2.8cqi, 1.9rem); }
        .cutout p { font-size: clamp(0.88rem, 1.6cqi, 1.05rem); max-width: 28ch; }

        .mini {
            display: flex;
            flex-wrap: wrap;
            gap: 0.4rem;
            margin-top: 0.55rem;
        }

        .mini span {
            background: var(--lift);
            padding: 0.2rem 0.45rem;
            font-size: clamp(0.72rem, 1.3cqi, 0.85rem);
            box-shadow: 2px 2px 0 color-mix(in oklab, var(--ink) 10%, transparent);
        }

        .col {
            display: grid;
            align-content: start;
            padding: clamp(0.75rem, 1.8cqi, 1rem);
            background: var(--lift);
            box-shadow: 4px 4px 0 color-mix(in oklab, var(--ink) 12%, transparent);
        }

        .col.win {
            background: color-mix(in oklab, var(--spot) 42%, var(--paper));
            box-shadow: 4px 5px 0 var(--ink);
        }

        .col h3 { font-size: clamp(1.05rem, 2.2cqi, 1.45rem); margin-bottom: 0.5rem; }

        .col ul {
            margin: 0;
            padding: 0;
            list-style: none;
            display: grid;
            gap: 0.35rem;
            font-size: clamp(0.8rem, 1.5cqi, 0.95rem);
        }

        .price {
            margin-top: auto;
            padding-top: 0.7rem;
            font-family: var(--note);
            font-size: clamp(1.15rem, 2.2cqi, 1.45rem);
        }

        .money {
            grid-template-columns: minmax(0, 1.1fr) minmax(0, 0.9fr);
            align-items: stretch;
        }

        .price-card {
            display: grid;
            place-content: center;
            text-align: center;
            background: color-mix(in oklab, var(--spot) 40%, var(--paper));
            box-shadow: 6px 6px 0 var(--ink);
            min-height: 0;
        }

        .giant {
            font-family: var(--display);
            font-size: clamp(3.6rem, 12cqi, 7rem);
            line-height: 0.88;
        }

        .price-card .note { font-size: clamp(1.2rem, 2.4cqi, 1.6rem); margin-top: 0.35rem; }

        .meter {
            display: grid;
            gap: 0.55rem;
            min-height: 0;
            align-content: stretch;
        }

        .bar {
            display: grid;
            grid-template-columns: 5.2rem minmax(0, 1fr);
            gap: 0.75rem;
            align-items: center;
            padding: 0.75rem 0.9rem;
            box-shadow: 3px 3px 0 color-mix(in oklab, var(--ink) 10%, transparent);
        }

        .bar.a { background: color-mix(in oklab, var(--spot) 38%, var(--paper)); }
        .bar.b { background: color-mix(in oklab, var(--teal) 22%, var(--paper)); }
        .bar.c { background: color-mix(in oklab, var(--ink) 8%, var(--paper)); }

        .bar strong { font-size: clamp(1.6rem, 3.6cqi, 2.3rem); }

        .track {
            height: 0.85rem;
            background: color-mix(in oklab, var(--ink) 8%, var(--paper));
            overflow: hidden;
        }

        .track i {
            display: block;
            height: 100%;
            background: var(--spot);
        }

        .bar.b .track i { background: color-mix(in oklab, var(--teal) 70%, var(--spot)); }
        .bar.c .track i { background: color-mix(in oklab, var(--ink) 45%, var(--paper)); }

        .ask {
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            align-items: stretch;
        }

        .outcomes {
            display: grid;
            gap: 0.5rem;
            min-height: 0;
            margin: 0;
            padding: 0;
            align-content: stretch;
        }

        .outcome {
            display: grid;
            align-content: center;
            padding: 0.65rem 0.75rem;
            background: var(--lift);
            box-shadow: 3px 3px 0 color-mix(in oklab, var(--spot) 48%, transparent);
            font-size: clamp(0.88rem, 1.6cqi, 1.05rem);
            list-style: none;
        }

        .close {
            place-items: center;
            text-align: center;
            background: radial-gradient(ellipse at 50% 100%, color-mix(in oklab, var(--spot) 48%, transparent), transparent 55%);
        }

        .close-inner {
            display: grid;
            justify-items: center;
            gap: 0.45rem;
            min-width: 0;
        }

        .close h2 { font-size: clamp(3rem, 10cqi, 5.4rem); }

        .close img {
            width: min(22cqi, 9.5rem);
            height: auto;
        }

        .close .note { font-size: clamp(1.2rem, 2.6cqi, 1.7rem); }

        @container deck (max-width: 760px) {
            .cover,
            .money,
            .ask {
                grid-template-columns: 1fr;
            }

            .cover-art { max-height: 38%; }

            .chips,
            .cards-4,
            .cards-3 {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media print {
            body, .room { background: var(--paper); }
            .stage {
                width: 100%;
                height: auto;
                box-shadow: none;
                overflow: visible;
                grid-template-rows: auto;
            }
            .deck { overflow: visible; }
            .slide {
                position: relative;
                display: grid !important;
                height: 100vh;
                page-break-after: always;
                animation: none;
            }
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
                        <h1 class="wordmark">
                            <span class="ghost" aria-hidden="true">Snitch</span>
                            <span>Snitch</span>
                        </h1>
                        <p class="line">Outperform your market by knowing exactly what works.</p>
                        <div class="platforms" aria-hidden="true">
                            <img src="/images/platforms/instagram.svg" alt="">
                            <img src="/images/platforms/tiktok.svg" alt="">
                            <img src="/images/platforms/facebook.svg" alt="">
                            <img src="/images/platforms/linkedin.svg" alt="">
                        </div>
                    </div>
                    <div class="cover-art">
                        <img src="/images/marketing/hero/mascot-character.png" alt="">
                    </div>
                </section>

                <section class="slide stack problem" data-slide>
                    <div>
                        <p class="kicker">The problem</p>
                        <h2 class="title">Businesses do not know how to get the most from social.</h2>
                    </div>
                    <div class="fill">
                        <p class="quote">“What do we post this week?”</p>
                        <div class="chips">
                            <div class="chip">What</div>
                            <div class="chip">When</div>
                            <div class="chip">Captions</div>
                            <div class="chip">Ads or organic?</div>
                        </div>
                    </div>
                </section>

                <section class="slide stack" data-slide>
                    <div>
                        <p class="kicker">Solution</p>
                        <h2 class="title">Real posts. Real winners. No guesswork.</h2>
                        <p class="lede" style="margin-top:0.45rem">Snitch tracks what competitors publish, then shows the formula that is actually working.</p>
                    </div>
                    <div class="cards-3">
                        <article class="scrap step">
                            <p class="num">01</p>
                            <h3>Track</h3>
                            <p>Their public posts, in one sheet.</p>
                        </article>
                        <article class="scrap step">
                            <p class="num">02</p>
                            <h3>Reveal</h3>
                            <p>What is winning in your market.</p>
                        </article>
                        <article class="scrap step">
                            <p class="num">03</p>
                            <h3>Remake</h3>
                            <p>Copy the move in your voice.</p>
                        </article>
                    </div>
                </section>

                <section class="slide stack founders" data-slide>
                    <div>
                        <p class="kicker">Founders</p>
                        <h2 class="title">We felt this pain first.</h2>
                        <p class="lede" style="margin-top:0.45rem">Two operators who could not tell what to post, until we built the board we needed.</p>
                    </div>
                    <div class="cards-2">
                        <figure class="polaroid">
                            <img src="/images/marketing/hero/mascot-character.png" alt="">
                            <figcaption>Dan Smyth</figcaption>
                        </figure>
                        <figure class="polaroid">
                            <img src="/images/marketing/hero/mascot-binos.png" alt="">
                            <figcaption>Toby Claxton</figcaption>
                        </figure>
                    </div>
                </section>

                <section class="slide stack" data-slide>
                    <div>
                        <p class="kicker">Early traction</p>
                        <h2 class="title">Proof of interest. Not a finished machine.</h2>
                    </div>
                    <div class="cards-4">
                        <div class="stat"><strong>214</strong><span>cold emails sent</span></div>
                        <div class="stat"><strong>69%</strong><span>open rate</span></div>
                        <div class="stat"><strong>14%</strong><span>click through</span></div>
                        <div class="stat"><strong>15</strong><span>private beta seats</span></div>
                    </div>
                </section>

                <section class="slide stack why" data-slide>
                    <div>
                        <p class="kicker">Why now</p>
                        <h2 class="title">The channel is social. The gap is what to post.</h2>
                    </div>
                    <div class="quote-block">
                        <blockquote>Social is the growth channel. Knowing what to post is the bottleneck.</blockquote>
                        <p class="note">The market is fragmented. Nobody owns the simple version.</p>
                    </div>
                </section>

                <section class="slide stack" data-slide>
                    <div>
                        <p class="kicker">Who it is for</p>
                        <h2 class="title">One product. Two nearby buyers.</h2>
                    </div>
                    <div class="cards-2">
                        <article class="cutout">
                            <p class="sticker">A</p>
                            <h3>Solo marketers</h3>
                            <p>In-house people who have to post, and do not have a research team.</p>
                            <p class="mini"><span>No research team</span><span>Has to post anyway</span></p>
                        </article>
                        <article class="cutout">
                            <p class="sticker">B</p>
                            <h3>Micro-agencies</h3>
                            <p>Small shops priced out of Rival IQ and Socialinsider.</p>
                            <p class="mini"><span>Priced out of £66+</span><span>Need a simple gap view</span></p>
                        </article>
                    </div>
                </section>

                <section class="slide stack" data-slide>
                    <div>
                        <p class="kicker">Why Snitch</p>
                        <h2 class="title">They show numbers. We show the gap.</h2>
                    </div>
                    <div class="cards-3">
                        <article class="col">
                            <h3>Socialinsider</h3>
                            <ul>
                                <li>Teams</li>
                                <li>Competitor data</li>
                                <li>No “what to change”</li>
                            </ul>
                            <p class="price">£66+</p>
                        </article>
                        <article class="col">
                            <h3>Rival IQ</h3>
                            <ul>
                                <li>Agencies</li>
                                <li>You vs them</li>
                                <li>Still just dashboards</li>
                            </ul>
                            <p class="price">£177+</p>
                        </article>
                        <article class="col win">
                            <h3>Snitch</h3>
                            <ul>
                                <li>Solo + micro-agency</li>
                                <li>Head-to-head gap</li>
                                <li>What to post next</li>
                            </ul>
                            <p class="price">£10 to £30</p>
                        </article>
                    </div>
                </section>

                <section class="slide money" data-slide>
                    <div style="align-self:center">
                        <p class="kicker">How we make money</p>
                        <h2 class="title">One model. Subscription.</h2>
                        <p class="lede" style="margin-top:0.6rem">Validated at £10+ a month. Costs under £1 a month per user at scale. That is the whole story.</p>
                    </div>
                    <div class="price-card">
                        <p class="giant">£10</p>
                        <p class="note">per month. Nothing else.</p>
                    </div>
                </section>

                <section class="slide stack" data-slide>
                    <div>
                        <p class="kicker">Use of funds</p>
                        <h2 class="title">Find the channel. Keep the lights on. Show up.</h2>
                    </div>
                    <div class="meter">
                        <article class="bar a">
                            <strong>60%</strong>
                            <div>
                                <p>Acquisition. Outreach, content, ads, affiliates.</p>
                                <div class="track" aria-hidden="true"><i style="width:60%"></i></div>
                            </div>
                        </article>
                        <article class="bar b">
                            <strong>30%</strong>
                            <div>
                                <p>Run costs. Hosting, scrape, AI, admin.</p>
                                <div class="track" aria-hidden="true"><i style="width:30%"></i></div>
                            </div>
                        </article>
                        <article class="bar c">
                            <strong>10%</strong>
                            <div>
                                <p>Showcases. Meet the market.</p>
                                <div class="track" aria-hidden="true"><i style="width:10%"></i></div>
                            </div>
                        </article>
                    </div>
                </section>

                <section class="slide ask" data-slide>
                    <div style="align-self:center">
                        <p class="kicker">The ask</p>
                        <h2 class="title">Fund the proof.</h2>
                        <p class="lede" style="margin-top:0.6rem">This is a POC. You are not buying a finished, profitable company. You are funding the work that makes it possible.</p>
                    </div>
                    <ul class="outcomes">
                        <li class="outcome">Which buyer sticks: solo or agency</li>
                        <li class="outcome">Which channel actually signs people up</li>
                        <li class="outcome">Paying customers by the end of the grant</li>
                        <li class="outcome">A GTM we can take to the next conversation</li>
                    </ul>
                </section>

                <section class="slide close" data-slide>
                    <div class="close-inner">
                        <img src="/images/marketing/hero/mascot-binos.png" alt="">
                        <h2 class="wordmark">
                            <span class="ghost" aria-hidden="true">Snitch</span>
                            <span>Snitch</span>
                        </h2>
                        <p class="note">Know what works. Then post it.</p>
                    </div>
                </section>
            </div>

            <nav class="chrome" aria-label="Deck controls">
                <div class="brand-slot">
                    <img src="/images/brand/mascot-mark.png" alt="">
                    <button class="ticket" type="button" id="prev">Back</button>
                </div>
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
                if (event.key === 'Home') {
                    go(0);
                }
                if (event.key === 'End') {
                    go(slides.length - 1);
                }
            });

            let startX = null;
            document.addEventListener('touchstart', (event) => {
                startX = event.changedTouches[0].clientX;
            }, { passive: true });
            document.addEventListener('touchend', (event) => {
                if (startX === null) {
                    return;
                }
                const delta = event.changedTouches[0].clientX - startX;
                if (Math.abs(delta) > 40) {
                    go(index + (delta < 0 ? 1 : -1));
                }
                startX = null;
            });

            const fromHash = Number.parseInt(window.location.hash.replace('#', ''), 10);
            go(Number.isFinite(fromHash) ? fromHash - 1 : 0);
        })();
    </script>
</body>
</html>
