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
            --stock: #efe6d8;
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

        body {
            overflow: hidden;
        }

        .room {
            min-height: 100dvh;
            display: grid;
            place-items: center;
            background: #1c1b1a;
        }

        .stage {
            position: relative;
            width: min(100vw, calc(100dvh * 16 / 9));
            height: min(100dvh, calc(100vw * 9 / 16));
            overflow: hidden;
            background: var(--paper);
            color: var(--ink);
            isolation: isolate;
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

        .slide {
            position: absolute;
            inset: 0;
            display: none;
            padding: clamp(1.1rem, 3.6vw, 2.6rem);
            z-index: 3;
        }

        .slide.is-on {
            display: grid;
            animation: settle 420ms ease both;
        }

        @keyframes settle {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: none; }
        }

        .wordmark {
            position: relative;
            font-family: var(--display);
            font-weight: 400;
            letter-spacing: -0.03em;
            line-height: 0.9;
        }

        .wordmark .ghost {
            position: absolute;
            inset: 0;
            color: var(--spot);
            transform: translate(3px, 2px);
            opacity: 0.62;
            mix-blend-mode: multiply;
            pointer-events: none;
        }

        .kicker {
            font-size: 0.72rem;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            font-weight: 600;
            color: color-mix(in oklab, var(--ink) 62%, var(--paper));
        }

        h1, h2 {
            margin: 0;
            font-family: var(--display);
            font-weight: 400;
            line-height: 0.95;
            letter-spacing: -0.03em;
        }

        p { margin: 0; }

        .note {
            font-family: var(--note);
            line-height: 1.05;
        }

        .chrome {
            position: absolute;
            z-index: 6;
            left: 0;
            right: 0;
            bottom: 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            padding: 0.7rem 1rem 0.85rem;
            pointer-events: none;
        }

        .chrome button,
        .dots button {
            pointer-events: auto;
            font: inherit;
            cursor: pointer;
        }

        .ticket {
            border: 0;
            color: var(--press);
            background: var(--spot);
            padding: 0.45rem 0.85rem;
            clip-path: polygon(0% 12%, 6% 0, 18% 8%, 32% 0, 50% 7%, 68% 0, 82% 8%, 94% 0, 100% 14%, 98% 50%, 100% 88%, 94% 100%, 80% 94%, 64% 100%, 48% 93%, 32% 100%, 16% 94%, 4% 100%, 0 86%);
            box-shadow: 3px 3px 0 color-mix(in oklab, var(--ink) 22%, transparent);
            font-weight: 700;
            font-size: 0.78rem;
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
            gap: 0.35rem;
            flex-wrap: wrap;
            justify-content: center;
        }

        .dots button {
            width: 0.55rem;
            height: 0.55rem;
            border: 0;
            border-radius: 1px;
            background: color-mix(in oklab, var(--ink) 22%, var(--paper));
            padding: 0;
        }

        .dots button.is-on {
            background: var(--spot);
            box-shadow: 1px 1px 0 var(--ink);
        }

        .hint {
            font-size: 0.68rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: color-mix(in oklab, var(--ink) 45%, var(--paper));
        }

        .mark {
            position: absolute;
            top: 1rem;
            right: 1.1rem;
            z-index: 6;
            width: 2.4rem;
            height: 2.4rem;
            object-fit: contain;
            pointer-events: none;
        }

        .mascot {
            display: block;
            width: 100%;
            height: 100%;
            object-fit: contain;
            filter: contrast(1.05) saturate(0.92);
        }

        .polaroid {
            background: var(--lift);
            padding: 0.55rem 0.55rem 1.4rem;
            box-shadow: 7px 8px 0 color-mix(in oklab, var(--spot) 55%, transparent);
            rotate: var(--tilt, -2deg);
        }

        .polaroid img,
        .polaroid .face {
            display: block;
            width: 100%;
            aspect-ratio: 1;
            object-fit: contain;
            background: color-mix(in oklab, var(--spot) 28%, var(--paper));
        }

        .polaroid figcaption {
            margin-top: 0.55rem;
            font-family: var(--note);
            font-size: 1.15rem;
            text-align: center;
        }

        .scrap {
            background: color-mix(in oklab, var(--lift) 70%, var(--paper));
            border: 1px solid color-mix(in oklab, var(--ink) 12%, transparent);
            box-shadow: 4px 5px 0 color-mix(in oklab, var(--spot) 35%, transparent);
            padding: 1rem 1.1rem;
        }

        .sticker {
            display: inline-block;
            background: var(--spot);
            color: var(--press);
            font-family: var(--note);
            padding: 0.2rem 0.55rem;
            rotate: var(--tilt, -3deg);
            box-shadow: 2px 2px 0 color-mix(in oklab, var(--ink) 18%, transparent);
        }

        .chip {
            display: grid;
            place-items: center;
            min-height: 4.4rem;
            padding: 0.7rem;
            background: color-mix(in oklab, var(--spot) 22%, var(--paper));
            box-shadow: 3px 3px 0 color-mix(in oklab, var(--ink) 16%, transparent);
            font-family: var(--note);
            font-size: clamp(1.1rem, 2.2vw, 1.55rem);
            text-align: center;
        }

        .platforms {
            display: flex;
            gap: 0.55rem;
            align-items: center;
        }

        .platforms img {
            width: clamp(1.6rem, 3vw, 2.3rem);
            height: clamp(1.6rem, 3vw, 2.3rem);
            object-fit: contain;
            filter: grayscale(0.15);
        }

        .cover {
            grid-template-columns: 1.1fr 0.9fr;
            align-items: end;
            background:
                radial-gradient(ellipse 70% 80% at 100% 100%, color-mix(in oklab, var(--spot) 55%, transparent), transparent 55%),
                linear-gradient(180deg, color-mix(in oklab, var(--fog) 50%, transparent), transparent 40%);
        }

        .cover-copy {
            display: grid;
            gap: 1.1rem;
            align-content: end;
            padding-bottom: 2.6rem;
        }

        .cover h1 {
            font-size: clamp(4.2rem, 11vw, 8.2rem);
        }

        .cover .line {
            max-width: 16ch;
            font-size: clamp(1.35rem, 2.6vw, 2rem);
            color: color-mix(in oklab, var(--ink) 82%, var(--paper));
        }

        .cover-art {
            position: relative;
            height: 100%;
        }

        .cover-art img {
            position: absolute;
            inset: auto 0 0;
            height: 108%;
            width: 100%;
            object-fit: contain;
            object-position: bottom center;
        }

        .problem {
            grid-template-rows: auto 1fr auto;
            gap: 0.8rem;
        }

        .problem h2 {
            font-size: clamp(2.4rem, 6vw, 4.4rem);
            max-width: 16ch;
        }

        .problem .big-q {
            font-family: var(--note);
            font-size: clamp(2.6rem, 7vw, 5.2rem);
            color: var(--ink);
        }

        .grid-4 {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0.75rem;
        }

        .solution {
            grid-template-columns: 0.9fr 1.1fr;
            gap: 1.4rem;
            align-items: center;
        }

        .solution h2 {
            font-size: clamp(2.6rem, 5.5vw, 4.2rem);
        }

        .steps {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.85rem;
            align-items: end;
        }

        .step .num {
            font-family: var(--note);
            font-size: 1.7rem;
            color: var(--teal);
        }

        .step h3 {
            margin: 0.15rem 0 0;
            font-family: var(--display);
            font-size: clamp(1.3rem, 2.4vw, 1.8rem);
        }

        .founders {
            grid-template-columns: 1fr 1fr 0.9fr;
            gap: 1.2rem;
            align-items: center;
        }

        .founders h2 {
            font-size: clamp(2.4rem, 5vw, 3.6rem);
        }

        .founders .line {
            font-size: 1.15rem;
            max-width: 22ch;
        }

        .traction {
            grid-template-rows: auto 1fr;
            gap: 0.8rem;
        }

        .traction h2 { font-size: clamp(2rem, 4vw, 3rem); }

        .stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0.8rem;
            align-items: stretch;
        }

        .stat {
            background: var(--lift);
            padding: 1rem 0.85rem 1.1rem;
            box-shadow: 5px 6px 0 var(--spot);
        }

        .stat strong {
            display: block;
            font-family: var(--display);
            font-size: clamp(2.4rem, 5.4vw, 4.2rem);
            line-height: 0.9;
        }

        .stat span {
            display: block;
            margin-top: 0.45rem;
            font-size: 0.92rem;
            color: color-mix(in oklab, var(--ink) 70%, var(--paper));
        }

        .why {
            place-items: center;
            text-align: center;
            background:
                radial-gradient(ellipse at 50% 80%, color-mix(in oklab, var(--spot) 38%, transparent), transparent 50%);
        }

        .why blockquote {
            margin: 0;
            max-width: 16ch;
            font-family: var(--display);
            font-size: clamp(2.2rem, 5.6vw, 4.4rem);
        }

        .market {
            grid-template-rows: auto 1fr;
            gap: 1rem;
        }

        .market h2 { font-size: clamp(2.2rem, 4.6vw, 3.4rem); }

        .split {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .cutout {
            min-height: 0;
            padding: 1.3rem;
            background: color-mix(in oklab, var(--spot) 18%, var(--paper));
            box-shadow: 8px 8px 0 color-mix(in oklab, var(--ink) 18%, transparent);
        }

        .cutout h3 {
            margin: 0;
            font-family: var(--display);
            font-size: clamp(1.6rem, 3vw, 2.2rem);
        }

        .cutout p {
            margin-top: 0.55rem;
            font-size: 1.05rem;
            max-width: 22ch;
        }

        .vs {
            grid-template-rows: auto 1fr;
            gap: 0.9rem;
        }

        .vs h2 { font-size: clamp(2rem, 4.2vw, 3.1rem); }

        .cols {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.75rem;
        }

        .col {
            padding: 1rem 0.85rem 1.1rem;
            background: var(--lift);
            box-shadow: 4px 5px 0 color-mix(in oklab, var(--ink) 12%, transparent);
        }

        .col.win {
            background: color-mix(in oklab, var(--spot) 42%, var(--paper));
            box-shadow: 5px 6px 0 var(--ink);
        }

        .col h3 {
            margin: 0 0 0.7rem;
            font-family: var(--display);
            font-size: clamp(1.2rem, 2.2vw, 1.6rem);
        }

        .col ul {
            margin: 0;
            padding: 0;
            list-style: none;
            display: grid;
            gap: 0.45rem;
            font-size: 0.92rem;
        }

        .price {
            margin-top: 0.8rem;
            font-family: var(--note);
            font-size: 1.35rem;
        }

        .money {
            grid-template-columns: 1.1fr 0.9fr;
            align-items: center;
            gap: 1.2rem;
        }

        .money h2 { font-size: clamp(2.2rem, 5vw, 3.6rem); }

        .giant {
            font-family: var(--display);
            font-size: clamp(5rem, 14vw, 9rem);
            line-height: 0.85;
        }

        .funds {
            grid-template-rows: auto 1fr;
            gap: 0.8rem;
        }

        .funds h2 { font-size: clamp(2rem, 4vw, 3rem); }

        .bars {
            display: grid;
            grid-template-columns: 6fr 3fr 1fr;
            gap: 0.7rem;
            align-items: stretch;
        }

        .bar {
            display: grid;
            align-content: end;
            min-height: 0;
            padding: 1rem;
            color: var(--press);
        }

        .bar.a { background: var(--spot); }
        .bar.b { background: color-mix(in oklab, var(--teal) 45%, var(--paper)); }
        .bar.c { background: color-mix(in oklab, var(--ink) 12%, var(--paper)); }

        .bar strong {
            font-family: var(--display);
            font-size: clamp(2.4rem, 5vw, 4rem);
            line-height: 0.9;
        }

        .ask {
            grid-template-columns: 1fr 1fr;
            gap: 1.2rem;
            align-items: center;
        }

        .ask h2 { font-size: clamp(2.4rem, 5vw, 3.8rem); }

        .outcomes {
            display: grid;
            gap: 0.65rem;
        }

        .outcomes li {
            list-style: none;
            padding: 0.7rem 0.85rem;
            background: var(--lift);
            box-shadow: 3px 3px 0 color-mix(in oklab, var(--spot) 50%, transparent);
            font-size: 1.02rem;
        }

        .close {
            place-items: center;
            text-align: center;
            background:
                radial-gradient(ellipse at 50% 100%, color-mix(in oklab, var(--spot) 50%, transparent), transparent 52%);
        }

        .close h2 {
            font-size: clamp(3.4rem, 9vw, 6.4rem);
        }

        .close .mascot-end {
            width: min(28vw, 220px);
            height: auto;
        }

        @media (max-width: 820px) {
            .cover,
            .solution,
            .founders,
            .money,
            .ask {
                grid-template-columns: 1fr;
            }

            .cover-art {
                min-height: 38%;
            }

            .grid-4,
            .stats,
            .steps,
            .cols,
            .split,
            .bars {
                grid-template-columns: 1fr 1fr;
            }

            .hint { display: none; }
        }

        @media print {
            body, .room { background: var(--paper); }
            .stage {
                width: 100%;
                height: auto;
                box-shadow: none;
                overflow: visible;
            }
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
            <img class="mark" src="/images/brand/mascot-mark.png" alt="">
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
                    <img class="mascot" src="/images/marketing/hero/mascot-character.png" alt="">
                </div>
            </section>

            <section class="slide problem" data-slide>
                <p class="kicker">The problem</p>
                <h2>Businesses do not know how to get the most from social.</h2>
                <p class="big-q">“What do we post this week?”</p>
                <div class="grid-4">
                    <div class="chip" style="--tilt:-4deg">What</div>
                    <div class="chip">When</div>
                    <div class="chip" style="--tilt:3deg">Captions</div>
                    <div class="chip">Ads or organic?</div>
                </div>
            </section>

            <section class="slide solution" data-slide>
                <div>
                    <p class="kicker">Solution</p>
                    <h2>Real posts. Real winners. No guesswork.</h2>
                    <p style="margin-top:1rem;max-width:22ch;font-size:1.15rem">
                        Snitch tracks what competitors publish, then shows the formula that is actually working.
                    </p>
                </div>
                <div class="steps">
                    <article class="scrap step">
                        <p class="num">01</p>
                        <h3>Track</h3>
                        <p>Their public posts, in one sheet.</p>
                    </article>
                    <article class="scrap step" style="translate:0 -10px">
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

            <section class="slide founders" data-slide>
                <figure class="polaroid" style="--tilt:-3deg">
                    <img src="/images/marketing/hero/mascot-character.png" alt="">
                    <figcaption>Dan Smyth</figcaption>
                </figure>
                <figure class="polaroid" style="--tilt:3deg">
                    <img src="/images/marketing/hero/mascot-binos.png" alt="">
                    <figcaption>Toby Claxton</figcaption>
                </figure>
                <div>
                    <p class="kicker">Founders</p>
                    <h2>We felt this pain first.</h2>
                    <p class="line" style="margin-top:0.8rem">Two operators who could not tell what to post, until we built the board we needed.</p>
                </div>
            </section>

            <section class="slide traction" data-slide>
                <div>
                    <p class="kicker">Early traction</p>
                    <h2>Proof of interest. Not a finished machine.</h2>
                </div>
                <div class="stats">
                    <div class="stat">
                        <strong>214</strong>
                        <span>cold emails sent</span>
                    </div>
                    <div class="stat">
                        <strong>69%</strong>
                        <span>open rate</span>
                    </div>
                    <div class="stat">
                        <strong>14%</strong>
                        <span>click through</span>
                    </div>
                    <div class="stat">
                        <strong>15</strong>
                        <span>private beta seats</span>
                    </div>
                </div>
            </section>

            <section class="slide why" data-slide>
                <div>
                    <p class="kicker">Why now</p>
                    <blockquote>Social is the growth channel. Knowing what to post is the bottleneck.</blockquote>
                    <p class="note" style="margin-top:1.2rem;font-size:1.7rem">The market is fragmented. Nobody owns the simple version.</p>
                </div>
            </section>

            <section class="slide market" data-slide>
                <div>
                    <p class="kicker">Who it is for</p>
                    <h2>One product. Two nearby buyers.</h2>
                </div>
                <div class="split">
                    <article class="cutout">
                        <p class="sticker">A</p>
                        <h3>Solo marketers</h3>
                        <p>In-house people who have to post, and do not have a research team.</p>
                    </article>
                    <article class="cutout">
                        <p class="sticker" style="--tilt:4deg">B</p>
                        <h3>Micro-agencies</h3>
                        <p>Small shops priced out of Rival IQ and Socialinsider.</p>
                    </article>
                </div>
            </section>

            <section class="slide vs" data-slide>
                <div>
                    <p class="kicker">Why Snitch</p>
                    <h2>They show numbers. We show the gap.</h2>
                </div>
                <div class="cols">
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
                <div>
                    <p class="kicker">How we make money</p>
                    <h2>One model. Subscription.</h2>
                    <p style="margin-top:0.9rem;max-width:24ch;font-size:1.15rem">
                        Validated at £10+ a month. Costs under £1 a month per user at scale. That is the whole story.
                    </p>
                </div>
                <div>
                    <p class="giant">£10</p>
                    <p class="note" style="font-size:1.8rem;margin-top:0.4rem">per month. Nothing else.</p>
                </div>
            </section>

            <section class="slide funds" data-slide>
                <div>
                    <p class="kicker">Use of funds</p>
                    <h2>Find the channel. Keep the lights on. Show up.</h2>
                </div>
                <div class="bars">
                    <article class="bar a">
                        <strong>60%</strong>
                        <p>Acquisition. Outreach, content, ads, affiliates.</p>
                    </article>
                    <article class="bar b">
                        <strong>30%</strong>
                        <p>Run costs. Hosting, scrape, AI, admin.</p>
                    </article>
                    <article class="bar c">
                        <strong>10%</strong>
                        <p>Showcases. Meet the market.</p>
                    </article>
                </div>
            </section>

            <section class="slide ask" data-slide>
                <div>
                    <p class="kicker">The ask</p>
                    <h2>Fund the proof.</h2>
                    <p style="margin-top:0.85rem;max-width:22ch;font-size:1.15rem">
                        This is a POC. You are not buying a finished, profitable company. You are funding the work that makes it possible.
                    </p>
                </div>
                <ul class="outcomes">
                    <li>Which buyer sticks: solo or agency</li>
                    <li>Which channel actually signs people up</li>
                    <li>Paying customers by the end of the grant</li>
                    <li>A GTM we can take to the next conversation</li>
                </ul>
            </section>

            <section class="slide close" data-slide>
                <div>
                    <img class="mascot-end" src="/images/marketing/hero/mascot-binos.png" alt="">
                    <h2 class="wordmark">
                        <span class="ghost" aria-hidden="true">Snitch</span>
                        <span>Snitch</span>
                    </h2>
                    <p class="note" style="margin-top:0.8rem;font-size:2rem">Know what works. Then post it.</p>
                </div>
            </section>

            <nav class="chrome" aria-label="Deck controls">
                <button class="ticket" type="button" id="prev" data-prev>Back</button>
                <div class="dots" id="dots"></div>
                <div style="display:flex;align-items:center;gap:0.7rem">
                    <span class="hint">Arrows</span>
                    <button class="ticket" type="button" id="next" data-next>Next</button>
                </div>
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
