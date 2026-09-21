@once
    <style>
        .pr { --pr-line: #e2e8f0; --pr-soft: #f8fafc; --pr-ink: #0f172a; --pr-muted: #64748b; --pr-brand: #1e2761; --pr-accent: #b45309; }
        .dark .pr { --pr-line: #334155; --pr-soft: #1e293b; --pr-ink: #e2e8f0; --pr-muted: #94a3b8; --pr-brand: #a5b4fc; --pr-accent: #fbbf24; }
        .pr { color: var(--pr-ink); }
        .pr-note { display: flex; gap: .6rem; align-items: flex-start; border: 1px dashed var(--pr-accent); background: color-mix(in srgb, var(--pr-accent) 8%, transparent); border-radius: .6rem; padding: .7rem .9rem; font-size: .8rem; line-height: 1.45; margin-bottom: 1rem; }
        .pr-note b { color: var(--pr-accent); }
        .pr-grid { display: grid; gap: .85rem; }
        .pr-grid--4 { grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); }
        .pr-grid--3 { grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); }
        .pr-grid--2 { grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); }
        .pr-card { border: 1px solid var(--pr-line); border-radius: .75rem; padding: .9rem 1rem; background: var(--pr-soft); }
        .pr-card h4 { font-size: .78rem; font-weight: 600; color: var(--pr-muted); margin: 0 0 .35rem; text-transform: uppercase; letter-spacing: .03em; }
        .pr-card .pr-big { font-size: 1.35rem; font-weight: 700; line-height: 1.2; }
        .pr-card .pr-sub { font-size: .75rem; color: var(--pr-muted); margin-top: .3rem; }
        .pr-section { margin-top: 1.4rem; }
        .pr-section > h3 { font-size: .95rem; font-weight: 700; margin: 0 0 .15rem; }
        .pr-section > p.pr-desc { font-size: .78rem; color: var(--pr-muted); margin: 0 0 .7rem; }
        .pr-scroll { overflow-x: auto; border: 1px solid var(--pr-line); border-radius: .75rem; }
        table.pr-table { width: 100%; border-collapse: collapse; font-size: .8rem; min-width: 640px; }
        table.pr-table th, table.pr-table td { padding: .5rem .7rem; text-align: left; border-bottom: 1px solid var(--pr-line); white-space: nowrap; }
        table.pr-table thead th { background: var(--pr-soft); font-weight: 600; font-size: .72rem; text-transform: uppercase; letter-spacing: .03em; color: var(--pr-muted); position: sticky; top: 0; }
        table.pr-table td.pr-num, table.pr-table th.pr-num { text-align: right; font-variant-numeric: tabular-nums; }
        table.pr-table tr.pr-total td { font-weight: 700; background: var(--pr-soft); }
        table.pr-table tr.pr-sub td { color: var(--pr-muted); }
        table.pr-table td.pr-wrap { white-space: normal; min-width: 220px; }
        table.pr-kk td:first-child, table.pr-kk th:first-child { white-space: normal; min-width: 170px; }
        table.pr-tight th, table.pr-tight td { padding: .45rem .5rem; font-size: .75rem; }
        .pr-chip { display: inline-block; padding: .1rem .5rem; border-radius: 999px; font-size: .68rem; font-weight: 600; border: 1px solid transparent; }
        .pr-chip--ok { background: #dcfce7; color: #166534; }
        .pr-chip--wait { background: #fef3c7; color: #92400e; }
        .pr-chip--info { background: #dbeafe; color: #1e40af; }
        .pr-chip--bad { background: #fee2e2; color: #991b1b; }
        .pr-chip--mute { background: #e2e8f0; color: #334155; }
        .dark .pr-chip--ok { background: #14532d; color: #bbf7d0; }
        .dark .pr-chip--wait { background: #78350f; color: #fde68a; }
        .dark .pr-chip--info { background: #1e3a8a; color: #bfdbfe; }
        .dark .pr-chip--bad { background: #7f1d1d; color: #fecaca; }
        .dark .pr-chip--mute { background: #334155; color: #e2e8f0; }
        .pr-lock { font-size: .68rem; color: var(--pr-muted); }
        .pr-code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .76rem; }
        .pr-indent-1 { padding-left: 1.2rem !important; }
        .pr-indent-2 { padding-left: 2.4rem !important; }
        .pr-head0 { font-weight: 700; background: var(--pr-soft); }
        .pr-trail { list-style: none; margin: 0; padding: 0; }
        .pr-trail li { display: flex; gap: .7rem; padding: .55rem 0; border-bottom: 1px dashed var(--pr-line); font-size: .8rem; }
        .pr-trail li:last-child { border-bottom: 0; }
        .pr-trail .pr-dot { flex: 0 0 auto; width: .6rem; height: .6rem; border-radius: 999px; background: var(--pr-brand); margin-top: .35rem; }
        .pr-trail .pr-dot--wait { background: var(--pr-line); }
        .pr-trail time { color: var(--pr-muted); font-size: .72rem; display: block; }
        .pr-tabs { display: flex; gap: .4rem; flex-wrap: wrap; margin-bottom: .8rem; }
        .pr-tab { padding: .32rem .7rem; border-radius: 999px; border: 1px solid var(--pr-line); font-size: .76rem; text-decoration: none; color: var(--pr-ink); }
        .pr-tab--on { background: var(--pr-brand); color: #fff; border-color: var(--pr-brand); }
        .dark .pr-tab--on { color: #0f172a; }
        .pr-flow { display: flex; gap: .5rem; flex-wrap: wrap; align-items: stretch; }
        .pr-flow > div { flex: 1 1 150px; border: 1px solid var(--pr-line); border-radius: .6rem; padding: .6rem .7rem; background: var(--pr-soft); font-size: .76rem; }
        .pr-flow b { display: block; font-size: .82rem; margin-bottom: .2rem; }
    </style>
@endonce
