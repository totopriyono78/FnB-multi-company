@once
    <style>
        /* Gaya Vuexy: seluruh warna diambil dari token tema back-office (theme.css). */
        .pr { --pr-line: rgb(var(--gray-200)); --pr-soft: rgb(var(--gray-100)); --pr-surface: rgb(var(--fnb-surface)); --pr-ink: rgb(var(--fnb-heading)); --pr-text: rgb(var(--fnb-text)); --pr-muted: rgb(var(--gray-500)); --pr-brand: rgb(var(--primary-600)); --pr-brand-rgb: var(--primary-600); --pr-accent: rgb(var(--warning-600)); --pr-shadow: var(--fnb-shadow-card); }
        .dark .pr { --pr-line: rgb(var(--gray-800)); --pr-soft: rgb(52, 61, 85); --pr-muted: rgb(var(--gray-400)); --pr-brand: rgb(var(--primary-400)); --pr-brand-rgb: var(--primary-400); --pr-accent: rgb(var(--warning-400)); }
        .pr { color: var(--pr-text); }
        .pr-note { display: flex; gap: .6rem; align-items: flex-start; border: 1px dashed var(--pr-accent); background: color-mix(in srgb, var(--pr-accent) 8%, transparent); border-radius: var(--fnb-radius-lg); padding: .7rem .9rem; font-size: .8rem; line-height: 1.45; margin-bottom: 1rem; }
        .pr-note b { color: var(--pr-accent); }
        .pr-grid { display: grid; gap: 1.5rem; }
        .pr-grid--4 { grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); }
        .pr-grid--3 { grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); }
        .pr-grid--2 { grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); }
        .pr-card { border: 0; border-radius: var(--fnb-radius-lg); padding: 1.3rem 1.5rem; background: var(--pr-surface); box-shadow: var(--pr-shadow); transition: box-shadow .25s ease, transform .25s ease; }
        .pr-card:hover { box-shadow: var(--fnb-shadow-card-hover); transform: translateY(-2px); }
        .pr-card h4 { font-size: .78rem; font-weight: 600; color: var(--pr-text); margin: 0 0 .35rem; text-transform: uppercase; letter-spacing: .03em; }
        .pr-card .pr-big { font-size: 1.5rem; font-weight: 600; line-height: 1.2; color: var(--pr-ink); font-variant-numeric: tabular-nums; }
        .pr-card .pr-sub { font-size: .75rem; color: var(--pr-muted); margin-top: .3rem; }
        .pr-section { margin-top: 1.4rem; }
        .pr-section > h3 { font-size: 1.1rem; font-weight: 500; margin: 0 0 .15rem; color: var(--pr-ink); }
        .pr-section > p.pr-desc { font-size: .78rem; color: var(--pr-muted); margin: 0 0 .7rem; }
        .pr-scroll { overflow-x: auto; border: 0; border-radius: var(--fnb-radius-lg); background: var(--pr-surface); box-shadow: var(--pr-shadow); }
        table.pr-table { width: 100%; border-collapse: collapse; font-size: .8rem; min-width: 640px; }
        table.pr-table th, table.pr-table td { padding: .7rem 1.5rem; text-align: left; border-bottom: 1px solid var(--pr-line); white-space: nowrap; }
        table.pr-table thead th { background: var(--pr-soft); font-weight: 700; font-size: .72rem; text-transform: uppercase; letter-spacing: .5px; color: var(--pr-text); position: sticky; top: 0; }
        table.pr-table td.pr-num, table.pr-table th.pr-num { text-align: right; font-variant-numeric: tabular-nums; }
        table.pr-table tr.pr-total td { font-weight: 700; background: var(--pr-soft); }
        table.pr-table tr.pr-sub td { color: var(--pr-muted); }
        table.pr-table td.pr-wrap { white-space: normal; min-width: 220px; }
        table.pr-kk td:first-child, table.pr-kk th:first-child { white-space: normal; min-width: 170px; }
        table.pr-tight th, table.pr-tight td { padding: .45rem .5rem; font-size: .75rem; }
        .pr-chip { display: inline-block; padding: .2rem .55rem; border-radius: 999px; font-size: .7rem; font-weight: 600; border: 0; }
        .pr-chip--ok { background: rgba(var(--success-500), .12); color: rgb(var(--success-700)); }
        .pr-chip--wait { background: rgba(var(--warning-500), .12); color: rgb(var(--warning-700)); }
        .pr-chip--info { background: rgba(var(--info-500), .12); color: rgb(var(--info-700)); }
        .pr-chip--bad { background: rgba(var(--danger-500), .12); color: rgb(var(--danger-700)); }
        .pr-chip--mute { background: rgba(var(--gray-500), .12); color: rgb(var(--gray-700)); }
        .dark .pr-chip--ok { background: rgba(var(--success-500), .15); color: rgb(var(--success-400)); }
        .dark .pr-chip--wait { background: rgba(var(--warning-500), .15); color: rgb(var(--warning-400)); }
        .dark .pr-chip--info { background: rgba(var(--info-500), .15); color: rgb(var(--info-400)); }
        .dark .pr-chip--bad { background: rgba(var(--danger-500), .15); color: rgb(var(--danger-400)); }
        .dark .pr-chip--mute { background: rgba(var(--gray-400), .15); color: rgb(var(--gray-300)); }
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
        .pr-tab { padding: .55rem 1.1rem; border-radius: var(--fnb-radius-md); border: 0; font-size: .8rem; font-weight: 500; text-decoration: none; color: var(--pr-text); }
        .pr-tab:hover { color: var(--pr-brand); }
        .pr-tab--on, .pr-tab--on:hover { background: var(--pr-brand); color: #fff; box-shadow: 0 4px 18px -4px rgba(var(--pr-brand-rgb), .65); }
        .dark .pr-tab--on { color: rgb(var(--gray-950)); }
        .pr-flow { display: flex; gap: .5rem; flex-wrap: wrap; align-items: stretch; }
        .pr-flow > div { flex: 1 1 150px; border: 0; border-radius: var(--fnb-radius-lg); padding: .9rem 1rem; background: var(--pr-surface); box-shadow: var(--pr-shadow); font-size: .76rem; }
        .pr-flow b { display: block; font-size: .82rem; margin-bottom: .2rem; color: var(--pr-ink); }
    </style>
@endonce
