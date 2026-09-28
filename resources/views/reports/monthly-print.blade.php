<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Snitch report - {{ $report['month_label'] ?? 'Monthly' }}</title>
    <style>
        :root {
            --paper: #EFE6D8;
            --ink: #1C1B1A;
            --spot: #F0C400;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 24px;
            background: var(--paper);
            color: var(--ink);
            font-family: Figtree, system-ui, sans-serif;
            font-size: 13px;
            line-height: 1.45;
        }
        h1, h2 { font-family: "Young Serif", Georgia, serif; font-weight: 400; margin: 0 0 8px; }
        h1 { font-size: 28px; }
        h2 { font-size: 18px; margin-top: 20px; }
        .meta { color: #5c5346; margin-bottom: 16px; }
        .muted { color: #8a8072; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .card {
            border: 1px solid rgba(28, 27, 26, 0.18);
            background: #f7f0e4;
            padding: 12px;
        }
        .label { text-transform: uppercase; letter-spacing: 0.06em; font-size: 10px; color: #5c5346; }
        .value { font-size: 22px; margin-top: 4px; }
        ul { padding-left: 0; margin: 8px 0; list-style: none; }
        li { margin-bottom: 10px; }
        .post-row { display: flex; gap: 10px; align-items: flex-start; }
        .thumb {
            width: 48px;
            height: 48px;
            object-fit: cover;
            background: rgba(28, 27, 26, 0.08);
            flex-shrink: 0;
        }
        .thumb-fallback {
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(28, 27, 26, 0.08);
            color: #8a8072;
            font-size: 9px;
            flex-shrink: 0;
        }
        .actions { margin-bottom: 16px; }
        .actions button {
            background: var(--ink);
            color: var(--paper);
            border: 0;
            padding: 8px 12px;
            cursor: pointer;
        }
        @media print {
            .actions { display: none; }
            body { padding: 0; }
            .card, .post-row { break-inside: avoid; }
        }
    </style>
</head>
<body>
    <div class="actions">
        <button type="button" onclick="window.print()">Download PDF / Print</button>
    </div>

    <p class="label">Snitch monthly report</p>
    <h1>{{ $report['month_label'] ?? 'Monthly report' }}</h1>
    <p class="meta">Read-only summary. Charts fill in as weekly snapshots land.</p>

    <div class="grid">
        @foreach ([
            'followers' => 'Followers',
            'posts' => 'Posts',
            'engagement_rate' => 'Engagement rate',
            'avg_multiplier' => 'Avg X usual',
        ] as $key => $label)
            @php $kpi = $report['kpis'][$key] ?? []; @endphp
            <div class="card">
                <div class="label">{{ $label }}</div>
                <div class="value">{{ $kpi['you_display'] ?? ($kpi['you'] ?? 'no data') }}</div>
                <div class="meta">
                    vs last month:
                    <span @class(['muted' => ! isset($kpi['you_change']) || $kpi['you_change'] === null])>
                        {{ $kpi['you_change_label'] ?? ('no data for '.($report['prev_month_label'] ?? 'prior month')) }}
                    </span>
                    · peer {{ $kpi['peer_display'] ?? ($kpi['peer'] ?? 'no data') }}
                    (<span @class(['muted' => ! isset($kpi['peer_change']) || $kpi['peer_change'] === null])>
                        {{ $kpi['peer_change_label'] ?? ('no data for '.($report['prev_month_label'] ?? 'prior month')) }}
                    </span>)
                </div>
            </div>
        @endforeach
    </div>

    <h2>Your top 3</h2>
    <ul>
        @forelse (($report['own_top_posts'] ?? []) as $post)
            <li class="post-row">
                @if (! empty($post['thumbnail_url']))
                    <img class="thumb" src="{{ $post['thumbnail_url'] }}" alt="">
                @else
                    <span class="thumb-fallback">Post</span>
                @endif
                <div>
                    <div class="label">
                        @if (! empty($post['multiplier']))
                            {{ number_format((float) $post['multiplier'], 1) }}×
                        @else
                            No X× yet
                        @endif
                    </div>
                    <div>{{ $post['caption'] ?? ($post['caption_preview'] ?? 'Untitled post') }}</div>
                </div>
            </li>
        @empty
            <li>No own posts in this month yet.</li>
        @endforelse
    </ul>

    <h2>Competitor winners</h2>
    <ul>
        @forelse (($report['competitor_winners'] ?? []) as $winner)
            <li class="post-row">
                @if (! empty($winner['thumbnail_url']))
                    <img class="thumb" src="{{ $winner['thumbnail_url'] }}" alt="">
                @else
                    <span class="thumb-fallback">Post</span>
                @endif
                <div>
                    <div class="label">
                        {{ '@'.($winner['handle'] ?? 'rival') }}
                        @if (! empty($winner['multiplier']))
                            · {{ number_format((float) $winner['multiplier'], 1) }}×
                        @endif
                    </div>
                    <div>{{ $winner['caption'] ?? ($winner['caption_preview'] ?? ($winner['why'] ?? 'Winner')) }}</div>
                </div>
            </li>
        @empty
            <li>No scored rival winners this month.</li>
        @endforelse
    </ul>

    <h2>What changed</h2>
    <ul style="padding-left: 18px; list-style: disc;">
        @foreach (($report['what_changed'] ?? []) as $line)
            <li>{{ $line }}</li>
        @endforeach
    </ul>

    <h2>Next month focus</h2>
    <ul style="padding-left: 18px; list-style: disc;">
        @forelse (($report['next_focus'] ?? []) as $idea)
            <li>
                <strong>{{ $idea['format'] ?? 'Post' }}:</strong>
                {{ $idea['hook'] ?? '' }}
                @if (!empty($idea['why']))
                    <div class="meta">{{ $idea['why'] }}</div>
                @endif
            </li>
        @empty
            <li>Generate a weekly brief to pull focus ideas here.</li>
        @endforelse
    </ul>

    <script>
        window.addEventListener('load', function () {
            if (new URLSearchParams(window.location.search).get('autoprint') === '1') {
                window.print();
            }
        });
    </script>
</body>
</html>
