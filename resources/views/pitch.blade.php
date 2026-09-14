<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Snitch - Pitch</title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=caveat:500,600|figtree:500,700|young-serif:400" rel="stylesheet">
    <style>
        :root {
            --paper: #efe6d8;
            --ink: #1c1b1a;
            --spot: #f0c400;
            --display: "Young Serif", Georgia, serif;
            --sans: "Figtree", ui-sans-serif, sans-serif;
            --note: "Caveat", cursive;
            --halftone: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='8' height='8'%3E%3Ccircle cx='2' cy='2' r='1.1' fill='%231c1b1a' fill-opacity='0.16'/%3E%3C/svg%3E");
        }

        * { box-sizing: border-box; }
        html, body { margin: 0; height: 100%; background: #111; color: var(--ink); font-family: var(--sans); }
        body { overflow: hidden; }

        .room { min-height: 100dvh; display: grid; place-items: center; }

        .stage {
            position: relative;
            display: grid;
            grid-template-rows: minmax(0, 1fr) auto;
            width: min(100vw, calc(100dvh * 16 / 9));
            height: min(100dvh, calc(100vw * 9 / 16));
            overflow: hidden;
            background: var(--paper);
            container-type: size;
            container-name: deck;
            isolation: isolate;
        }

        .stage::before {
            content: "";
            position: absolute;
            inset: 0;
            z-index: 1;
            pointer-events: none;
            background-image: var(--halftone);
            background-size: 8px 8px;
            opacity: 0.4;
            mix-blend-mode: multiply;
        }

        .deck { position: relative; z-index: 2; min-height: 0; }
        .slide {
            position: absolute;
            inset: 0;
            display: none;
            padding: clamp(1.2rem, 4.5cqi, 2.6rem);
            min-height: 0;
        }
        .slide.is-on { display: grid; }

        .wm { position: relative; font-family: var(--display); line-height: 0.86; letter-spacing: -0.04em; }
        .wm b {
            position: absolute;
            inset: 0;
            color: var(--spot);
            transform: translate(2px, 1px);
            opacity: 0.38;
            mix-blend-mode: multiply;
            pointer-events: none;
            font-weight: 400;
        }

        h1, h2, p { margin: 0; }
        .huge {
            font-family: var(--display);
            font-weight: 400;
            line-height: 0.86;
            letter-spacing: -0.045em;
            font-size: clamp(3.8rem, 12cqi, 8.2rem);
            max-width: 11ch;
        }
        .giant {
            font-family: var(--display);
            line-height: 0.78;
            letter-spacing: -0.06em;
            font-size: clamp(7.5rem, 28cqi, 18rem);
        }
        .line {
            font-size: clamp(1.15rem, 2.4cqi, 1.7rem);
            max-width: 22ch;
            line-height: 1.2;
        }
        .meta {
            font-size: 0.72rem;
            letter-spacing: 0.2em;
            text-transform: uppercase;
            font-weight: 700;
            opacity: 0.5;
        }

        .fill { place-content: center start; align-content: center; }
        .center { place-items: center; text-align: center; }
        .center .line { max-width: 18ch; }
        .spot { background: var(--spot); }
        .ink { background: var(--ink); color: var(--paper); }
        .ink .wm b { mix-blend-mode: plus-lighter; opacity: 0.55; }

        .split { grid-template-columns: minmax(0, 1.15fr) minmax(0, 0.85fr); align-items: stretch; }
        .art { min-width: 0; min-height: 0; display: grid; align-items: end; justify-items: center; }
        .art img { width: 100%; height: 100%; object-fit: contain; object-position: bottom center; }

        .cols { grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0; padding: 0; }
        .cols article {
            display: grid;
            align-content: space-between;
            min-width: 0;
            padding: clamp(1.1rem, 3.2cqi, 2rem);
            border-right: 1px solid color-mix(in oklab, var(--ink) 14%, transparent);
        }
        .cols article:last-child { border-right: 0; background: var(--spot); }
        .cols h2 { font-family: var(--display); font-size: clamp(1.5rem, 3.4cqi, 2.4rem); line-height: 0.95; }
        .cols p { font-size: clamp(1.05rem, 2.1cqi, 1.45rem); max-width: 12ch; }
        .cols strong { font-family: var(--display); font-size: clamp(2rem, 5cqi, 3.4rem); }

        .pair { grid-template-columns: 1fr 1fr; }
        .pair div { display: grid; align-content: end; min-width: 0; padding-right: 0.6rem; }
        .pair .who { font-family: var(--display); font-size: clamp(2.6rem, 7cqi, 5rem); line-height: 0.9; }

        .nums { grid-template-columns: 1.4fr 1fr; align-items: end; }
        .side { display: grid; gap: 1.1rem; align-content: end; }
        .side b { display: block; font-family: var(--display); font-size: clamp(2.4rem, 6cqi, 4.2rem); line-height: 0.9; }
        .side span { display: block; font-size: clamp(0.95rem, 1.8cqi, 1.2rem); opacity: 0.7; }

        .steps { align-content: center; gap: 0.15em; }
        .steps h2 { font-size: clamp(3.2rem, 9.5cqi, 6.6rem); }

        .chrome {
            z-index: 4;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.45rem 0.9rem 0.55rem;
            font-size: 0.78rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .chrome button {
            font: inherit;
            letter-spacing: inherit;
            text-transform: inherit;
            border: 0;
            background: transparent;
            color: inherit;
            cursor: pointer;
            padding: 0.2rem 0;
        }
        .chrome button:disabled { opacity: 0.25; cursor: default; }
        .folio { font-family: var(--note); text-transform: none; letter-spacing: 0; font-size: 1.05rem; }

        @container deck (max-width: 720px) {
            .split, .pair, .nums, .cols { grid-template-columns: 1fr; }
            .art { max-height: 40%; }
            .cols article { border-right: 0; border-top: 1px solid color-mix(in oklab, var(--ink) 14%, transparent); }
        }

        @media print {
            .stage { width: 100%; height: auto; grid-template-rows: auto; }
            .slide { position: relative; display: grid !important; height: 100vh; page-break-after: always; }
            .chrome { display: none; }
        }
    </style>
</head>
<body>
    <div class="room">
        <div class="stage">
            <div class="deck">
                <section class="slide split is-on" data-slide>
                    <div style="align-self:center;display:grid;gap:1.1rem">
                        <p class="meta">Snitch</p>
                        <h1 class="wm huge"><b>Know what works.</b>Know what works.</h1>
                        <p class="line">Outperform your market. Stop guessing what to post.</p>
                    </div>
                    <div class="art">
                        <img src="/images/marketing/hero/mascot-character.png" alt="">
                    </div>
                </section>

                <section class="slide fill spot" data-slide>
                    <div>
                        <p class="meta">Problem</p>
                        <h2 class="huge" style="margin-top:0.4rem">Nobody knows what to post this week.</h2>
                    </div>
                </section>

                <section class="slide steps" data-slide>
                    <p class="meta">Solution</p>
                    <h2 class="wm"><b>Track it.</b>Track it.</h2>
                    <h2 class="wm"><b>See what won.</b>See what won.</h2>
                    <h2 class="wm"><b>Post that.</b>Post that.</h2>
                </section>

                <section class="slide fill ink" data-slide>
                    <div>
                        <p class="meta">Why now</p>
                        <h2 class="huge" style="margin-top:0.4rem">Social is the channel. Guessing is the tax.</h2>
                    </div>
                </section>

                <section class="slide pair" data-slide>
                    <div>
                        <p class="meta">Who</p>
                        <p class="who">Solos</p>
                        <p class="line">In-house. No research team. Still have to post.</p>
                    </div>
                    <div>
                        <p class="meta">&nbsp;</p>
                        <p class="who">Micro-agencies</p>
                        <p class="line">Priced out of Socialinsider and Rival IQ.</p>
                    </div>
                </section>

                <section class="slide cols" data-slide>
                    <article>
                        <h2>Socialinsider</h2>
                        <p>Data for teams.</p>
                        <strong>£66+</strong>
                    </article>
                    <article>
                        <h2>Rival IQ</h2>
                        <p>Dashboards for agencies.</p>
                        <strong>£177+</strong>
                    </article>
                    <article>
                        <h2>Snitch</h2>
                        <p>The next post.</p>
                        <strong>£10</strong>
                    </article>
                </section>

                <section class="slide center spot" data-slide>
                    <div>
                        <p class="giant">£10</p>
                        <p class="line" style="margin-top:0.5rem">A month. Under £1 to run.</p>
                    </div>
                </section>

                <section class="slide nums" data-slide>
                    <div>
                        <p class="meta">Traction</p>
                        <p class="giant">69%</p>
                        <p class="line">opened the cold email.</p>
                    </div>
                    <div class="side">
                        <p><b>214</b><span>emails sent</span></p>
                        <p><b>14%</b><span>clicked</span></p>
                        <p><b>15</b><span>in private beta</span></p>
                    </div>
                </section>

                <section class="slide pair" data-slide>
                    <div>
                        <p class="meta">Team</p>
                        <p class="who">Dan Smyth</p>
                        <p class="line">Felt the pain. Runs the hustle.</p>
                    </div>
                    <div>
                        <img src="/images/marketing/hero/mascot-binos.png" alt="" style="width:min(38cqi,11rem);justify-self:end">
                        <p class="who">Toby Claxton</p>
                        <p class="line">Felt the pain. Builds the board.</p>
                    </div>
                </section>

                <section class="slide fill" data-slide>
                    <div>
                        <p class="meta">The ask</p>
                        <h2 class="huge" style="margin-top:0.35rem">Fund the proof.</h2>
                        <p class="line" style="margin-top:1rem">Not a finished, profitable company. Money to find the buyer, the channel, and the first paying customers.</p>
                    </div>
                </section>

                <section class="slide split spot" data-slide>
                    <div style="align-self:center">
                        <h1 class="wm huge"><b>Know what works.</b>Know what works.</h1>
                    </div>
                    <div class="art">
                        <img src="/images/marketing/hero/mascot-binos.png" alt="">
                    </div>
                </section>
            </div>

            <nav class="chrome">
                <button type="button" id="prev">Back</button>
                <span class="folio" id="folio">01 / 11</span>
                <span style="display:flex;align-items:center;gap:0.7rem">
                    <img src="/images/brand/mascot-mark.png" alt="" width="26" height="26">
                    <button type="button" id="next">Next</button>
                </span>
            </nav>
        </div>
    </div>
    <script>
        (function () {
            const slides = Array.from(document.querySelectorAll('[data-slide]'));
            const prev = document.getElementById('prev');
            const next = document.getElementById('next');
            const folio = document.getElementById('folio');
            let index = 0;

            function go(n) {
                index = Math.max(0, Math.min(slides.length - 1, n));
                slides.forEach((s, i) => s.classList.toggle('is-on', i === index));
                prev.disabled = index === 0;
                next.disabled = index === slides.length - 1;
                folio.textContent = String(index + 1).padStart(2, '0') + ' / ' + String(slides.length).padStart(2, '0');
                history.replaceState(null, '', '#' + (index + 1));
            }

            prev.onclick = () => go(index - 1);
            next.onclick = () => go(index + 1);
            document.addEventListener('keydown', (e) => {
                if (['ArrowRight', 'ArrowDown', 'PageDown', ' '].includes(e.key)) { e.preventDefault(); go(index + 1); }
                if (['ArrowLeft', 'ArrowUp', 'PageUp', 'Backspace'].includes(e.key)) { e.preventDefault(); go(index - 1); }
                if (e.key === 'Home') go(0);
                if (e.key === 'End') go(slides.length - 1);
            });
            let x = null;
            document.addEventListener('touchstart', (e) => { x = e.changedTouches[0].clientX; }, { passive: true });
            document.addEventListener('touchend', (e) => {
                if (x === null) return;
                const d = e.changedTouches[0].clientX - x;
                if (Math.abs(d) > 40) go(index + (d < 0 ? 1 : -1));
                x = null;
            });
            const h = Number.parseInt(location.hash.replace('#', ''), 10);
            go(Number.isFinite(h) ? h - 1 : 0);
        })();
    </script>
</body>
</html>
