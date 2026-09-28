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
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .card {
            border: 1px solid rgba(28, 27, 26, 0.18);
            background: #f7f0e4;
            padding: 12px;
        }
        .label { text-transform: uppercase; letter-spacing: 0.06em; font-size: 10px; color: #5c5346; }
        .value { font-size: 22px; margin-top: 4px; }
        ul { padding-left: 18px; margin: 8px 0; }
        li { margin-bottom: 6px; }
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
            .card { break-inside: avoid; }
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
                <div class="value">{{ $kpi['you'] ?? '-' }}</div>
                <div class="meta">
                    vs last month:
                    @if (isset($kpi['you_change']))
                        {{ $kpi['you_change'] > 0 ? '+' : '' }}{{ $kpi['you_change'] }}%
                    @else
                        -
                    @endif
                    · peer {{ $kpi['peer'] ?? '-' }}
                </div>
            </div>
        @endforeach
    </div>

    <h2>Your top 3</h2>
    <ul>
        @forelse (($report['own_top_posts'] ?? []) as $post)
            <li>
                @if (!empty($post['multiplier'])){{ $post['multiplier'] }}x · @endif
                {{ $post['caption'] ?? 'Untitled post' }}
            </li>
        @empty
            <li>No own posts in this month yet.</li>
        @endforelse
    </ul>

    <h2>Competitor winners</h2>
    <ul>
        @forelse (($report['competitor_winners'] ?? []) as $winner)
            <li>
                {{ '@'.($winner['handle'] ?? 'rival') }}
                @if (!empty($winner['multiplier'])) · {{ $winner['multiplier'] }}x @endif
                - {{ $winner['caption'] ?? ($winner['why'] ?? 'Winner') }}
            </li>
        @empty
            <li>No scored rival winners this month.</li>
        @endforelse
    </ul>

    <h2>What changed</h2>
    <ul>
        @foreach (($report['what_changed'] ?? []) as $line)
            <li>{{ $line }}</li>
        @endforeach
    </ul>

    <h2>Next month focus</h2>
    <ul>
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
