<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title>{{ $outlet }} — Kasir</title>
<style>
  :root{
    --rail:#171b21; --rail-2:#232932; --rail-ink:#9aa4b2;
    --bg:#eef0f3; --surface:#ffffff; --line:#d7dbe1; --line-soft:#e8eaee;
    --ink:#1a1d23; --ink-2:#4b5563; --muted:#787f8a;
    --accent:#0b6b3a; --accent-ink:#ffffff; --sel:#1f3d63; --warn:#9a6207; --danger:#9b2c2c;
    --r:4px;
  }
  *{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
  html,body{margin:0;height:100%;overflow:hidden;background:var(--bg);color:var(--ink);
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;
    font-size:14px;line-height:1.4;-webkit-font-smoothing:antialiased}
  .num{font-variant-numeric:tabular-nums}
  svg.i{width:20px;height:20px;stroke:currentColor;fill:none;stroke-width:1.6;stroke-linecap:round;stroke-linejoin:round;flex:0 0 auto}
  svg.i.sm{width:16px;height:16px}
  .app{display:flex;height:100vh}

  /* ---------- rail ---------- */
  .rail{width:76px;background:var(--rail);display:flex;flex-direction:column;flex:0 0 auto;padding-bottom:8px}
  .rail .brand{height:52px;display:flex;align-items:center;justify-content:center;border-bottom:1px solid #2a313b;
    color:#fff;font-size:11px;font-weight:600;letter-spacing:.14em}
  .rail nav{display:flex;flex-direction:column;padding-top:6px}
  .rail button{border:0;background:transparent;color:var(--rail-ink);height:62px;display:flex;flex-direction:column;
    align-items:center;justify-content:center;gap:5px;font-size:10.5px;cursor:pointer;position:relative}
  .rail button:hover{color:#dfe4ea;background:#1d222a}
  .rail button.on{color:#fff;background:var(--rail-2)}
  .rail button.on::before{content:"";position:absolute;left:0;top:10px;bottom:10px;width:2px;background:#e4b363}
  .rail .grow{flex:1}

  /* ---------- top ---------- */
  .main{flex:1;display:flex;flex-direction:column;min-width:0}
  .top{height:52px;background:var(--surface);border-bottom:1px solid var(--line);display:flex;align-items:center;
    gap:16px;padding:0 16px;flex:0 0 auto}
  .top h1{margin:0;font-size:15px;font-weight:650;letter-spacing:-.01em}
  .top .meta{font-size:11.5px;color:var(--muted)}
  .sep{width:1px;height:26px;background:var(--line)}
  .state{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--ink-2)}
  .dot{width:7px;height:7px;border-radius:50%;background:#1a8a4d}
  .dot.amber{background:#c68209}
  .top .right{margin-left:auto;display:flex;align-items:center;gap:12px}
  .who{text-align:right;line-height:1.25}
  .who b{font-size:12.5px;font-weight:600}
  .who span{display:block;font-size:11px;color:var(--muted)}
  .clock{font-size:15px;font-weight:600;letter-spacing:.01em}
  .ico{width:32px;height:32px;border:1px solid var(--line);border-radius:var(--r);background:#fff;color:var(--ink-2);
    display:grid;place-items:center;cursor:pointer;padding:0}
  .ico:hover{background:#f3f4f6;color:var(--ink)}

  .body{flex:1;display:flex;min-height:0}
  .left{flex:1;display:flex;flex-direction:column;min-width:0}

  /* ---------- tabs + search ---------- */
  .bar{background:var(--surface);border-bottom:1px solid var(--line);padding:0 16px;display:flex;align-items:center;
    gap:20px;flex:0 0 auto}
  .tabs{display:flex;gap:22px;overflow-x:auto}
  .tabs button{border:0;background:none;padding:12px 0 10px;font-size:13px;font-weight:550;color:var(--muted);
    cursor:pointer;border-bottom:2px solid transparent;white-space:nowrap}
  .tabs button:hover{color:var(--ink)}
  .tabs button.on{color:var(--ink);border-bottom-color:var(--sel);font-weight:650}
  .tabs button em{font-style:normal;color:var(--muted);font-weight:400;margin-left:5px;font-size:11.5px}
  .find{margin-left:auto;position:relative;padding:8px 0}
  .find svg{position:absolute;left:9px;top:50%;transform:translateY(-50%);color:var(--muted)}
  .find input{width:250px;padding:7px 10px 7px 31px;border:1px solid var(--line);border-radius:var(--r);
    font-size:12.5px;font-family:inherit;background:#fbfbfc}
  .find input:focus{outline:2px solid #c7d3e3;outline-offset:-1px;border-color:#9fb2cb;background:#fff}

  /* ---------- grid ---------- */
  .grid{flex:1;overflow-y:auto;padding:14px 16px 18px;display:grid;
    grid-template-columns:repeat(auto-fill,minmax(152px,1fr));gap:12px;align-content:start}
  .card{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);overflow:hidden;
    cursor:pointer;display:flex;flex-direction:column;text-align:left;padding:0;font-family:inherit}
  .card:hover{border-color:#a9b4c2}
  .card:active{background:#f7f8fa}
  .ph{position:relative;aspect-ratio:4/3;background:#e9ebef;overflow:hidden}
  .ph img{width:100%;height:100%;object-fit:cover;display:block}
  .ph .mono{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;
    font-size:26px;font-weight:600;color:#aeb5bf;letter-spacing:.04em}
  .card .txt{padding:8px 9px 10px}
  .card .nm{font-size:12.5px;font-weight:550;line-height:1.3;min-height:33px;color:var(--ink)}
  .card .row{display:flex;align-items:baseline;justify-content:space-between;margin-top:5px;gap:6px}
  .card .pc{font-size:13px;font-weight:650}
  .card .lbl{font-size:10px;color:var(--warn);text-transform:uppercase;letter-spacing:.05em}
  .card.off{cursor:default}
  .card.off .ph img,.card.off .ph .mono{filter:grayscale(1);opacity:.5}
  .card.off .nm,.card.off .pc{color:var(--muted)}
  .ph .flag{position:absolute;left:0;top:8px;background:#40454d;color:#fff;font-size:9.5px;letter-spacing:.06em;
    text-transform:uppercase;padding:2px 7px}

  /* ---------- cart ---------- */
  .cart{width:348px;flex:0 0 auto;background:var(--surface);border-left:1px solid var(--line);display:flex;flex-direction:column}
  .cart .head{padding:10px 14px;border-bottom:1px solid var(--line);display:flex;align-items:baseline;justify-content:space-between}
  .cart .head b{font-size:13px;font-weight:650}
  .cart .head span{font-size:11.5px;color:var(--muted)}
  .seg{display:flex;margin:12px 14px 4px;border:1px solid var(--line);border-radius:var(--r);overflow:hidden}
  .seg button{flex:1;border:0;border-right:1px solid var(--line);background:#fff;padding:7px 4px;font-size:11.5px;
    font-family:inherit;color:var(--ink-2);cursor:pointer}
  .seg button:last-child{border-right:0}
  .seg button.on{background:var(--sel);color:#fff;font-weight:600}
  .lines{flex:1;overflow-y:auto;padding:6px 14px}
  .ln{display:flex;gap:10px;padding:10px 0;border-bottom:1px solid var(--line-soft)}
  .ln .info{flex:1;min-width:0}
  .ln .nm{font-size:12.5px;font-weight:550}
  .ln .mod{font-size:11px;color:var(--muted);margin-top:1px}
  .ln .amt{font-size:12.5px;font-weight:650;white-space:nowrap}
  .stp{display:inline-flex;align-items:center;border:1px solid var(--line);border-radius:var(--r);margin-top:7px}
  .stp button{width:26px;height:24px;border:0;background:#fff;color:var(--ink-2);display:grid;place-items:center;cursor:pointer}
  .stp button:hover{background:#f2f4f7}
  .stp span{min-width:26px;text-align:center;font-size:12.5px;font-weight:600;border-left:1px solid var(--line);
    border-right:1px solid var(--line);height:24px;line-height:24px}
  .ln .del{border:0;background:none;color:#9aa1ab;cursor:pointer;padding:0;height:18px}
  .ln .del:hover{color:var(--danger)}
  .blank{padding:44px 16px;text-align:center;color:var(--muted);font-size:12.5px;line-height:1.7}
  .sum{border-top:1px solid var(--line);padding:10px 14px 4px;font-size:12.5px}
  .sum .r{display:flex;justify-content:space-between;padding:2.5px 0;color:var(--ink-2)}
  .sum .r.big{border-top:1px solid var(--line);margin-top:7px;padding-top:9px;color:var(--ink);font-size:17px;font-weight:700}
  .acts{padding:10px 14px 14px;display:grid;grid-template-columns:1fr 1fr;gap:8px}
  .btn{font-family:inherit;border-radius:var(--r);border:1px solid var(--line);background:#fff;color:var(--ink);
    padding:10px 8px;font-size:12.5px;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:7px}
  .btn:hover{background:#f5f6f8}
  .btn.main{grid-column:1/-1;background:var(--accent);border-color:var(--accent);color:var(--accent-ink);
    font-size:15px;padding:13px;font-weight:650}
  .btn.main:hover{background:#0a5f34}
  .btn:disabled{opacity:.45;cursor:not-allowed}

  /* ---------- layar lain ---------- */
  .scr{display:none;flex:1;overflow:auto;padding:18px}
  .scr.on{display:block}
  .scr h2{margin:0 0 3px;font-size:16px;font-weight:650}
  .scr p.d{margin:0 0 14px;font-size:12.5px;color:var(--muted)}
  .kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(178px,1fr));gap:10px;margin-bottom:16px}
  .kpi{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:11px 13px}
  .kpi h4{margin:0 0 5px;font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)}
  .kpi .v{font-size:19px;font-weight:700}
  .kpi .s{font-size:11px;color:var(--muted);margin-top:2px}
  table.t{width:100%;border-collapse:collapse;font-size:12.5px;background:var(--surface);
    border:1px solid var(--line);border-radius:var(--r)}
  table.t th,table.t td{padding:9px 12px;text-align:left;border-bottom:1px solid var(--line-soft)}
  table.t tr:last-child td{border-bottom:0}
  table.t th{font-size:10.5px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);font-weight:600;
    border-bottom:1px solid var(--line)}
  table.t td.n,table.t th.n{text-align:right;font-variant-numeric:tabular-nums}
  .tag{font-size:11px;color:var(--ink-2);display:inline-flex;align-items:center;gap:6px}

  /* ---------- modal ---------- */
  .ov{position:fixed;inset:0;background:rgba(16,20,26,.5);display:none;align-items:center;justify-content:center;
    padding:22px;z-index:50}
  .ov.on{display:flex}
  .box{background:var(--surface);border-radius:6px;width:100%;max-width:500px;max-height:92vh;overflow:auto;
    box-shadow:0 18px 48px rgba(12,16,22,.28)}
  .box header{padding:14px 18px;border-bottom:1px solid var(--line)}
  .box header h3{margin:0;font-size:15px;font-weight:650}
  .box header p{margin:3px 0 0;font-size:12px;color:var(--muted)}
  .box .in{padding:16px 18px}
  .box footer{padding:12px 18px 16px;display:grid;grid-template-columns:1fr 1fr;gap:9px}
  .ways{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:14px}
  .ways button{border:1px solid var(--line);background:#fff;border-radius:var(--r);padding:12px 6px;font-size:12px;
    font-weight:600;font-family:inherit;cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:6px;color:var(--ink-2)}
  .ways button.on{border-color:var(--accent);color:var(--accent);background:#f2f8f4;box-shadow:inset 0 0 0 1px var(--accent)}
  .quick{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:12px}
  .quick button{border:1px solid var(--line);background:#fff;border-radius:var(--r);padding:10px 4px;font-size:12px;
    font-weight:600;font-family:inherit;cursor:pointer;font-variant-numeric:tabular-nums}
  .quick button.on{border-color:var(--sel);background:#eef2f8}
  .paid{display:flex;justify-content:space-between;font-size:13px;padding:9px 11px;background:#f5f6f8;border-radius:var(--r)}
  .paid b{font-variant-numeric:tabular-nums}
  .slip{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:11.5px;background:#f7f8f9;
    border:1px solid var(--line);border-radius:var(--r);padding:14px;white-space:pre;line-height:1.55;overflow-x:auto}
  @media (max-width:1080px){.cart{width:310px}.find input{width:170px}}
</style>
</head>
<body>

<svg width="0" height="0" style="position:absolute" aria-hidden="true">
  <symbol id="ic-kasir" viewBox="0 0 24 24"><path d="M6 2.8h12v18.4l-2.4-1.6-2.4 1.6-2.4-1.6-2.4 1.6L6 19.6z"/><path d="M9 7.5h6M9 11h6M9 14.5h4"/></symbol>
  <symbol id="ic-pesanan" viewBox="0 0 24 24"><rect x="5" y="4.2" width="14" height="16.6" rx="1.6"/><path d="M9.2 4.2V2.9h5.6v1.3"/><path d="M8.6 9.4h6.8M8.6 13h6.8M8.6 16.6h4.2"/></symbol>
  <symbol id="ic-shift" viewBox="0 0 24 24"><rect x="2.8" y="6" width="18.4" height="12.6" rx="2"/><path d="M2.8 10.2h18.4"/><circle cx="16.6" cy="14.6" r="1.5"/></symbol>
  <symbol id="ic-stok" viewBox="0 0 24 24"><path d="M3.6 7.4 12 3.2l8.4 4.2v9.2L12 20.8l-8.4-4.2z"/><path d="m3.6 7.4 8.4 4.3 8.4-4.3M12 11.7v9.1"/></symbol>
  <symbol id="ic-laporan" viewBox="0 0 24 24"><path d="M3.4 20.2h17.2"/><path d="M6.6 17.4v-5.2M12 17.4V6.8M17.4 17.4v-7.6"/></symbol>
  <symbol id="ic-atur" viewBox="0 0 24 24"><path d="M3.6 7.4h8.2M15.4 7.4h5M3.6 16.6h5M12.2 16.6h8.2M3.6 12h3M10 12h10.4"/><circle cx="13.6" cy="7.4" r="2"/><circle cx="10.2" cy="16.6" r="2"/><circle cx="8.2" cy="12" r="2"/></symbol>
  <symbol id="ic-cari" viewBox="0 0 24 24"><circle cx="10.8" cy="10.8" r="6.4"/><path d="m15.6 15.6 4.4 4.4"/></symbol>
  <symbol id="ic-plus" viewBox="0 0 24 24"><path d="M12 6.4v11.2M6.4 12h11.2"/></symbol>
  <symbol id="ic-minus" viewBox="0 0 24 24"><path d="M6.4 12h11.2"/></symbol>
  <symbol id="ic-hapus" viewBox="0 0 24 24"><path d="M4.4 6.8h15.2M9.8 6.8V4.6h4.4v2.2"/><path d="m6.8 6.8.8 12.2a1.6 1.6 0 0 0 1.6 1.5h5.6a1.6 1.6 0 0 0 1.6-1.5l.8-12.2"/></symbol>
  <symbol id="ic-dapur" viewBox="0 0 24 24"><path d="M4 13.6h16a8 8 0 0 0-16 0Z"/><path d="M3 17.2h18M6.5 9.6V4.4M9.5 9.6V4.4"/></symbol>
  <symbol id="ic-tahan" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.4"/><path d="M12 7.4V12l3 2"/></symbol>
  <symbol id="ic-tunai" viewBox="0 0 24 24"><rect x="2.6" y="6.6" width="18.8" height="10.8" rx="1.6"/><circle cx="12" cy="12" r="2.6"/><path d="M6 12h.01M18 12h.01"/></symbol>
  <symbol id="ic-qris" viewBox="0 0 24 24"><rect x="3.6" y="3.6" width="6.4" height="6.4"/><rect x="14" y="3.6" width="6.4" height="6.4"/><rect x="3.6" y="14" width="6.4" height="6.4"/><path d="M14 14h3.2v3.2H14zM19.2 14h1.2M14 19.2h1.2M17.6 19.2h2.8"/></symbol>
  <symbol id="ic-kartu" viewBox="0 0 24 24"><rect x="2.6" y="5.6" width="18.8" height="12.8" rx="2"/><path d="M2.6 10h18.8M6 14.6h3.4"/></symbol>
  <symbol id="ic-info" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.6"/><path d="M12 11.2v5M12 7.9h.01"/></symbol>
  <symbol id="ic-cetak" viewBox="0 0 24 24"><path d="M7 9.4V4h10v5.4"/><rect x="3.6" y="9.4" width="16.8" height="6.6" rx="1.6"/><path d="M7 14h10v6H7z"/></symbol>
</svg>

<div class="app">
  <aside class="rail">
    <div class="brand">FNB</div>
    <nav>
      <button class="on" data-scr="kasir"><svg class="i"><use href="#ic-kasir"/></svg>Kasir</button>
      <button data-scr="pesanan"><svg class="i"><use href="#ic-pesanan"/></svg>Pesanan</button>
      <button data-scr="shift"><svg class="i"><use href="#ic-shift"/></svg>Shift</button>
      <button data-scr="stok"><svg class="i"><use href="#ic-stok"/></svg>Stok</button>
      <button data-scr="laporan"><svg class="i"><use href="#ic-laporan"/></svg>Laporan</button>
    </nav>
    <div class="grow"></div>
    <button data-scr="pengaturan"><svg class="i"><use href="#ic-atur"/></svg>Atur</button>
  </aside>

  <div class="main">
    <header class="top">
      <div>
        <h1>{{ $outlet }}</h1>
        <div class="meta">{{ $device }} · hari bisnis {{ $businessDate }}</div>
      </div>
      <div class="sep"></div>
      <div class="state"><span class="dot"></span>Terhubung</div>
      @if ($live)
        <div class="state" title="Transaksi disimpan ke sistem">Tersambung ke sistem</div>
      @endif
      <div class="state">Shift 07.00 · Dewi</div>
      <div class="right">
        <button class="ico" onclick="document.getElementById('infoModal').classList.add('on')"
                title="Keterangan layar" aria-label="Keterangan layar">
          <svg class="i sm"><use href="#ic-info"/></svg>
        </button>
        <div class="clock num" id="clock">--.--</div>
        <div class="sep"></div>
        <div class="who"><b>{{ $cashier }}</b><span>Kasir</span></div>
      </div>
    </header>

    <div class="body">
      <div class="left" id="scr-kasir">
        <div class="bar">
          <div class="tabs" id="tabs"></div>
          <div class="find">
            <svg class="i sm"><use href="#ic-cari"/></svg>
            <input id="q" placeholder="Cari menu atau pindai barcode">
          </div>
        </div>
        <div class="grid" id="grid"></div>
      </div>

      <div class="scr" id="scr-pesanan">
        <h2>Pesanan berjalan</h2>
        <p class="d">Pesanan yang sedang diproses dapur dan yang ditahan kasir.</p>
        <table class="t">
          <thead><tr><th>No. struk</th><th>Meja / tipe</th><th>Item</th><th>Status</th><th>Dibuka</th><th class="n">Nilai</th></tr></thead>
          <tbody>
            <tr><td>KMG-01-{{ $receiptDate }}-0042</td><td>Meja 12 · Dine-in</td><td>4 item</td><td><span class="tag"><span class="dot amber"></span>Diproses dapur</span></td><td>19.42</td><td class="n">248.000</td></tr>
            <tr><td>KMG-01-{{ $receiptDate }}-0041</td><td>Bawa pulang</td><td>2 item</td><td><span class="tag"><span class="dot"></span>Siap diambil</span></td><td>19.35</td><td class="n">96.000</td></tr>
            <tr><td>KMG-01-{{ $receiptDate }}-0040</td><td>Meja 5 · Dine-in</td><td>7 item</td><td><span class="tag"><span class="dot" style="background:#6b7280"></span>Ditahan</span></td><td>19.20</td><td class="n">412.000</td></tr>
            <tr><td>KMG-01-{{ $receiptDate }}-0039</td><td>Delivery</td><td>3 item</td><td><span class="tag"><span class="dot amber"></span>Diproses dapur</span></td><td>19.11</td><td class="n">154.000</td></tr>
          </tbody>
        </table>
      </div>

      <div class="scr" id="scr-shift">
        <h2>Shift kasir</h2>
        <p class="d">Buka kas, setoran, dan selisih kas — dasar jurnal penjualan harian.</p>
        <div class="kpis">
          <div class="kpi"><h4>Modal awal</h4><div class="v num">Rp 500.000</div><div class="s">Dibuka 07.00 · {{ $cashier }}</div></div>
          <div class="kpi"><h4>Penjualan tunai</h4><div class="v num">Rp 4.860.000</div><div class="s">38 transaksi</div></div>
          <div class="kpi"><h4>Non-tunai</h4><div class="v num">Rp 7.240.000</div><div class="s">QRIS 24 · kartu 9</div></div>
          <div class="kpi"><h4>Kas seharusnya</h4><div class="v num">Rp 5.360.000</div><div class="s">Modal + tunai − pengeluaran</div></div>
        </div>
        <table class="t">
          <thead><tr><th>Waktu</th><th>Kejadian</th><th>Oleh</th><th class="n">Nilai</th></tr></thead>
          <tbody>
            <tr><td>07.00</td><td>Buka shift — modal awal</td><td>{{ $cashier }}</td><td class="n">500.000</td></tr>
            <tr><td>12.15</td><td>Pengeluaran kas kecil — es batu</td><td>{{ $cashier }}</td><td class="n">120.000</td></tr>
            <tr><td>15.40</td><td>Void transaksi (otorisasi supervisor)</td><td>Dimas — Supervisor</td><td class="n">84.000</td></tr>
            <tr><td>18.02</td><td>Setoran tunai sementara ke brankas</td><td>{{ $cashier }}</td><td class="n">2.000.000</td></tr>
          </tbody>
        </table>
      </div>

      <div class="scr" id="scr-stok">
        <h2>Stok cepat</h2>
        <p class="d">Menu yang stoknya menipis atau dimatikan sementara dari POS.</p>
        <table class="t">
          <thead><tr><th>Menu</th><th>Status</th><th class="n">Sisa</th><th>Tindakan</th></tr></thead>
          <tbody>
            <tr><td>Iga Bakar Madu</td><td><span class="tag"><span class="dot amber"></span>Menipis</span></td><td class="n">6 porsi</td><td>Tandai habis</td></tr>
            <tr><td>Es Kopi Susu Gula Aren</td><td><span class="tag"><span class="dot"></span>Aman</span></td><td class="n">48 porsi</td><td>—</td></tr>
            <tr><td>Nasi Goreng Kampung</td><td><span class="tag"><span class="dot amber"></span>Menipis</span></td><td class="n">9 porsi</td><td>Tandai habis</td></tr>
            <tr><td>Sop Buntut</td><td><span class="tag"><span class="dot" style="background:#9b2c2c"></span>Habis hari ini</span></td><td class="n">0</td><td>Aktifkan lagi</td></tr>
          </tbody>
        </table>
      </div>

      <div class="scr" id="scr-laporan">
        <h2>Ringkasan hari ini</h2>
        <p class="d">Angka ini yang akan mengalir ke pembukuan sebagai jurnal penjualan saat tutup hari.</p>
        <div class="kpis">
          <div class="kpi"><h4>Penjualan bersih</h4><div class="v num">Rp 11.180.000</div><div class="s">71 transaksi · rata-rata 157.000</div></div>
          <div class="kpi"><h4>Pajak PB1 10%</h4><div class="v num">Rp 1.118.000</div><div class="s">Dipisah per transaksi</div></div>
          <div class="kpi"><h4>Diskon &amp; promo</h4><div class="v num">Rp 386.000</div><div class="s">12 transaksi</div></div>
          <div class="kpi"><h4>Void</h4><div class="v num">2</div><div class="s">Seluruhnya dengan otorisasi</div></div>
        </div>
        <table class="t">
          <thead><tr><th>Menu terlaris</th><th class="n">Terjual</th><th class="n">Penjualan</th></tr></thead>
          <tbody>
            <tr><td>Es Kopi Susu Gula Aren</td><td class="n">64</td><td class="n">1.472.000</td></tr>
            <tr><td>Nasi Goreng Kampung</td><td class="n">41</td><td class="n">1.804.000</td></tr>
            <tr><td>Ayam Bakar Taliwang</td><td class="n">33</td><td class="n">2.079.000</td></tr>
            <tr><td>Iga Bakar Madu</td><td class="n">22</td><td class="n">2.068.000</td></tr>
          </tbody>
        </table>
      </div>

      <div class="scr" id="scr-pengaturan">
        <h2>Pengaturan perangkat</h2>
        <p class="d">Pengaturan yang dipakai kasir di lapangan.</p>
        <table class="t">
          <thead><tr><th>Pengaturan</th><th>Nilai</th></tr></thead>
          <tbody>
            <tr><td>Printer struk</td><td>EPSON TM-T82 (80 mm) — LAN 192.168.1.50</td></tr>
            <tr><td>Printer dapur</td><td>Stasiun dapur &amp; bar (2 printer)</td></tr>
            <tr><td>Pembulatan</td><td>Rp 100 — berlaku juga untuk non-tunai</td></tr>
            <tr><td>Pajak &amp; service</td><td>PB1 10% · service charge 5%</td></tr>
            <tr><td>Mode offline</td><td>Aktif — transaksi tersimpan lokal dan dikirim otomatis</td></tr>
            <tr><td>Antrian belum terkirim</td><td>0 transaksi</td></tr>
            <tr><td>Folder foto menu</td><td><code>public/img/pos/</code></td></tr>
          </tbody>
        </table>
      </div>

      <aside class="cart" id="cartPanel">
        <div class="head"><b>Pesanan baru</b><span class="num" id="orderNo">@if ($live)Nomor struk otomatis @else{{ $outletCode }}-{{ $device }}-{{ $receiptDate }}-0043 @endif</span></div>
        <div class="seg" id="seg">
          <button class="on" data-type="Dine-in" data-ch="dine_in">Dine-in</button>
          <button data-type="Bawa pulang" data-ch="take_away">Bawa pulang</button>
          <button data-type="Delivery" data-ch="delivery">Delivery</button>
        </div>
        <div class="lines" id="lines"></div>
        <div class="sum">
          <div class="r"><span>Subtotal</span><span class="num" id="sSub">0</span></div>
          <div class="r"><span>Diskon</span><span class="num" id="sDisc">0</span></div>
          <div class="r"><span>Service charge {{ (float) $pricing['service_charge_rate'] }}%</span><span class="num" id="sServ">0</span></div>
          <div class="r"><span>{{ $pricing['tax_name'] }} {{ (float) $pricing['tax_rate'] }}%</span><span class="num" id="sTax">0</span></div>
          <div class="r"><span>Pembulatan</span><span class="num" id="sRound">0</span></div>
          <div class="r big"><span>Total</span><span class="num" id="sTotal">Rp 0</span></div>
        </div>
        <div class="acts">
          <button class="btn" onclick="hold()"><svg class="i sm"><use href="#ic-tahan"/></svg>Tahan</button>
          <button class="btn" onclick="kitchen()"><svg class="i sm"><use href="#ic-dapur"/></svg>Ke dapur</button>
          <button class="btn main" id="payBtn" onclick="openPay()" disabled>Bayar</button>
        </div>
      </aside>
    </div>
  </div>
</div>

<div class="ov" id="infoModal">
  <div class="box" style="max-width:460px">
    <header><h3>Keterangan layar</h3></header>
    <div class="in" style="font-size:13px;line-height:1.65;display:flex;flex-direction:column;gap:9px">
      @if ($live)
        <p style="margin:0">Layar ini <b>prototipe tampilan yang sudah tersambung ke sistem</b>: menu dan harganya diambil dari katalog outlet, dan transaksi yang diselesaikan benar-benar tersimpan — langsung tampil di back-office (Penjualan, Laporan, Tutup Hari).</p>
      @else
        <p style="margin:0">Layar ini masih <b>prototipe tampilan</b>: menu, harga, dan transaksinya data contoh, dan tidak disimpan ke basis data.</p>
      @endif
      <p style="margin:0">Alur kasir: pilih menu, kirim ke dapur, bayar, lalu struk tercetak. Pajak {{ $pricing['tax_name'] }} {{ (float) $pricing['tax_rate'] }}%, service charge {{ (float) $pricing['service_charge_rate'] }}%, dan pembulatan Rp{{ (int) $pricing['rounding_unit'] }} mengikuti pengaturan outlet.</p>
      @if ($live)
        <p style="margin:0">Pembayaran QRIS memerlukan payment gateway berizin, jadi di lingkungan demo hanya Tunai dan Kartu yang dapat diselesaikan.</p>
      @endif
      <p style="margin:0">Foto menu dibaca dari folder <code>public/img/pos</code>; menu tanpa foto tampil sebagai kotak berinisial.</p>
    </div>
    <footer style="grid-template-columns:1fr">
      <button class="btn" onclick="document.getElementById('infoModal').classList.remove('on')">Tutup</button>
    </footer>
  </div>
</div>

<div class="ov" id="payModal">
  <div class="box">
    <header>
      <h3>Pembayaran</h3>
      <p>Total tagihan <b class="num" id="payTotal">Rp 0</b> · <span id="payCount">0</span> item</p>
    </header>
    <div class="in">
      <div class="ways" id="ways">
        <button class="on" data-pay="Tunai"><svg class="i"><use href="#ic-tunai"/></svg>Tunai</button>
        <button data-pay="QRIS"><svg class="i"><use href="#ic-qris"/></svg>QRIS</button>
        <button data-pay="Kartu / EDC"><svg class="i"><use href="#ic-kartu"/></svg>Kartu / EDC</button>
      </div>
      <div id="cashBox">
        <div class="quick" id="quick"></div>
        <div class="paid"><span>Uang diterima <b class="num" id="cashGiven">Rp 0</b></span><span>Kembalian <b class="num" id="cashBack">Rp 0</b></span></div>
      </div>
    </div>
    <footer>
      <button class="btn" onclick="closePay()">Batal</button>
      <button class="btn main" style="grid-column:auto" onclick="finish()"><svg class="i sm"><use href="#ic-cetak"/></svg>Selesaikan</button>
    </footer>
  </div>
</div>

<div class="ov" id="rcptModal">
  <div class="box">
    <header><h3>Struk</h3><p>Pratinjau struk 80 mm — dapat dicetak atau dikirim sebagai struk digital.</p></header>
    <div class="in">
      <div id="savedNote" style="display:none;gap:.5rem;align-items:flex-start;margin-bottom:12px;padding:9px 11px;
           border:1px solid #bfe3cd;background:#f1f9f4;border-radius:4px;font-size:12px;line-height:1.5;color:#14532d"></div>
      <div class="slip" id="rcpt"></div>
    </div>
    <footer>
      <button class="btn" onclick="document.getElementById('rcptModal').classList.remove('on')">Tutup</button>
      <button class="btn main" style="grid-column:auto" onclick="newOrder()">Transaksi baru</button>
    </footer>
  </div>
</div>

<script>
const MENU = @json($menu);
const OUTLET = @json($outlet);
const ADDRESS = @json($address);
const CASHIER = @json($cashier);
const RDATE = @json($receiptDate);
const LIVE = @json($live);
const PRICING = @json($pricing);
const ORDER_URL = @json(route('prototype.pos.order'));
const OUTLET_CODE = @json($outletCode);
const DEVICE = @json($device);
const CSRF = document.querySelector('meta[name=csrf-token]').content;
let channel = 'dine_in', payCode = 'cash', lastSaved = null;
let cat = MENU[0].name, cart = [], orderType = 'Dine-in', payMethod = 'Tunai', given = 0, seq = 43;

const nf = n => Math.round(n).toLocaleString('id-ID');
const rp = n => 'Rp ' + nf(n);
const esc = s => String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));

function renderTabs(){
  document.getElementById('tabs').innerHTML = MENU.map(c =>
    `<button class="${c.name===cat?'on':''}" onclick="setCat('${c.name}')">${c.name}<em>${c.items.length}</em></button>`).join('');
}
function setCat(c){ cat = c; renderTabs(); renderGrid(); }

function renderGrid(){
  const q = document.getElementById('q').value.toLowerCase().trim();
  const group = MENU.find(c => c.name === cat);
  const items = group.items.filter(i => !q || i.name.toLowerCase().includes(q));
  document.getElementById('grid').innerHTML = items.map((i, idx) => `
    <button type="button" class="card ${i.out?'off':''}" ${i.out?'disabled':`onclick="add('${cat}',${idx})"`}>
      <div class="ph">
        ${i.image ? `<img src="${i.image}" alt="${esc(i.name)}" loading="lazy">` : `<span class="mono">${i.initials}</span>`}
        ${i.out ? '<span class="flag">Habis</span>' : ''}
      </div>
      <div class="txt">
        <div class="nm">${esc(i.name)}</div>
        <div class="row"><span class="pc num">${nf(i.price)}</span>${i.note ? `<span class="lbl">${esc(i.note)}</span>` : ''}</div>
      </div>
    </button>`).join('') || '<div class="blank" style="grid-column:1/-1">Menu tidak ditemukan.</div>';
}

function add(catName, idx){
  const item = MENU.find(c => c.name === catName).items[idx];
  const f = cart.find(l => l.name === item.name);
  if (f) { f.qty++; } else { cart.push({ id: item.id || null, name: item.name, price: item.price, qty: 1, note: item.mod || '' }); }
  renderCart();
}
function chg(i, d){ cart[i].qty += d; if (cart[i].qty <= 0) cart.splice(i, 1); renderCart(); }
function del(i){ cart.splice(i, 1); renderCart(); }

function totals(){
  const unit = PRICING.rounding_unit || 0;
  const sub = cart.reduce((s, l) => s + l.price * l.qty, 0);
  const disc = 0;
  const base = sub - disc;
  const serv = PRICING.service_charge_applies ? Math.round(base * (PRICING.service_charge_rate / 100)) : 0;
  const tax = PRICING.tax_inclusive ? 0 : Math.round((base + serv) * (PRICING.tax_rate / 100));
  const raw = base + serv + tax;
  const total = unit > 0 ? Math.round(raw / unit) * unit : raw;
  return { sub, disc, serv, tax, round: total - raw, total };
}

function renderCart(){
  const t = totals();
  document.getElementById('lines').innerHTML = cart.length ? cart.map((l, i) => `
    <div class="ln">
      <div class="info">
        <div class="nm">${esc(l.name)}</div>
        ${l.note ? `<div class="mod">${esc(l.note)}</div>` : ''}
        <div class="stp">
          <button onclick="chg(${i},-1)" aria-label="Kurangi"><svg class="i sm"><use href="#ic-minus"/></svg></button>
          <span class="num">${l.qty}</span>
          <button onclick="chg(${i},1)" aria-label="Tambah"><svg class="i sm"><use href="#ic-plus"/></svg></button>
        </div>
      </div>
      <div style="text-align:right;display:flex;flex-direction:column;align-items:flex-end;gap:10px">
        <span class="amt num">${nf(l.price * l.qty)}</span>
        <button class="del" onclick="del(${i})" aria-label="Hapus baris"><svg class="i sm"><use href="#ic-hapus"/></svg></button>
      </div>
    </div>`).join('')
    : '<div class="blank">Belum ada item.<br>Pilih menu di sebelah kiri.</div>';
  document.getElementById('sSub').textContent = nf(t.sub);
  document.getElementById('sDisc').textContent = t.disc ? '−' + nf(t.disc) : '0';
  document.getElementById('sServ').textContent = nf(t.serv);
  document.getElementById('sTax').textContent = nf(t.tax);
  document.getElementById('sRound').textContent = (t.round < 0 ? '−' : '') + nf(Math.abs(t.round));
  document.getElementById('sTotal').textContent = rp(t.total);
  document.getElementById('payBtn').disabled = cart.length === 0;
}

function openPay(){
  const t = totals();
  document.getElementById('payTotal').textContent = rp(t.total);
  document.getElementById('payCount').textContent = cart.reduce((s, l) => s + l.qty, 0);
  const opts = [...new Set([t.total, Math.ceil(t.total/50000)*50000, Math.ceil(t.total/100000)*100000, Math.ceil(t.total/100000)*100000 + 100000])];
  document.getElementById('quick').innerHTML = opts.map((v, i) =>
    `<button class="${i===0?'on':''}" onclick="setGiven(${v},this)">${nf(v)}</button>`).join('');
  setGiven(t.total);
  document.getElementById('payModal').classList.add('on');
}
function closePay(){ document.getElementById('payModal').classList.remove('on'); }
function setGiven(v, el){
  given = v;
  if (el) { document.querySelectorAll('#quick button').forEach(b => b.classList.remove('on')); el.classList.add('on'); }
  const t = totals();
  document.getElementById('cashGiven').textContent = rp(v);
  document.getElementById('cashBack').textContent = rp(Math.max(0, v - t.total));
}

async function finish(){
  if (LIVE) { await finishLive(); return; }
  const t = totals();
  const no = OUTLET_CODE + '-' + DEVICE + '-' + RDATE + '-' + String(seq).padStart(4, '0');
  const W = 40;
  const mid = s => ' '.repeat(Math.max(0, Math.floor((W - s.length) / 2))) + s;
  const row = (a, b) => a + ' '.repeat(Math.max(1, W - a.length - b.length)) + b;
  const rule = '-'.repeat(W);
  let s = '';
  s += mid(OUTLET.toUpperCase()) + '\n' + mid(ADDRESS) + '\n' + rule + '\n';
  s += row(no, orderType) + '\n';
  s += row(new Date().toLocaleString('id-ID', { dateStyle: 'short', timeStyle: 'short' }), 'Kasir: ' + CASHIER.split(' ')[0]) + '\n';
  s += rule + '\n';
  cart.forEach(l => {
    s += l.name + '\n';
    s += row('  ' + l.qty + ' x ' + nf(l.price), nf(l.qty * l.price)) + '\n';
    if (l.note) s += '  ' + l.note + '\n';
  });
  s += rule + '\n';
  s += row('Subtotal', nf(t.sub)) + '\n';
  if (t.disc) s += row('Diskon 5%', '-' + nf(t.disc)) + '\n';
  s += row('Service charge 5%', nf(t.serv)) + '\n';
  s += row('PB1 10%', nf(t.tax)) + '\n';
  s += row('Pembulatan', (t.round < 0 ? '-' : '') + nf(Math.abs(t.round))) + '\n';
  s += rule + '\n';
  s += row('TOTAL', nf(t.total)) + '\n';
  s += row(payMethod, nf(payMethod === 'Tunai' ? given : t.total)) + '\n';
  if (payMethod === 'Tunai') s += row('Kembalian', nf(Math.max(0, given - t.total))) + '\n';
  s += rule + '\n';
  s += mid('Terima kasih atas kunjungan Anda') + '\n';
  document.getElementById('rcpt').textContent = s;
  closePay();
  document.getElementById('rcptModal').classList.add('on');
  seq++;
}
async function finishLive(){
  const btn = document.querySelector('#payModal .btn.main');
  btn.disabled = true; btn.textContent = 'Menyimpan…';
  try {
    const res = await fetch(ORDER_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
      body: JSON.stringify({
        lines: cart.map(l => ({ item_id: l.id, qty: l.qty })),
        channel: channel,
        method: payCode,
        tendered: payCode === 'cash' ? given : null,
        table: null,
      }),
    });
    const data = await res.json();
    if (!res.ok) { alert('Transaksi tidak tersimpan: ' + (data.message || res.status)); return; }
    lastSaved = data;
    renderSavedReceipt(data);
    closePay();
    document.getElementById('rcptModal').classList.add('on');
  } catch (e) {
    alert('Tidak dapat menghubungi server: ' + e.message);
  } finally {
    btn.disabled = false; btn.innerHTML = '<svg class="i sm"><use href="#ic-cetak"/></svg>Selesaikan';
  }
}

function renderSavedReceipt(d){
  const W = 40;
  const mid = s => ' '.repeat(Math.max(0, Math.floor((W - s.length) / 2))) + s;
  const row = (a, b) => a + ' '.repeat(Math.max(1, W - a.length - b.length)) + b;
  const rule = '-'.repeat(W);
  const t = d.totals;
  const label = { cash: 'Tunai', debit: 'Kartu Debit', credit: 'Kartu Kredit' }[d.method] || d.method;
  let s = '';
  s += mid(d.outlet.toUpperCase()) + '\n';
  if (d.address) s += mid(d.address.slice(0, W)) + '\n';
  s += rule + '\n';
  s += row(d.receipt_no, d.channel) + '\n';
  s += row(new Date().toLocaleString('id-ID', { dateStyle: 'short', timeStyle: 'short' }), 'Kasir: ' + d.cashier.split(' ')[0]) + '\n';
  s += rule + '\n';
  cart.forEach(l => {
    s += l.name + '\n';
    s += row('  ' + l.qty + ' x ' + nf(l.price), nf(l.qty * l.price)) + '\n';
  });
  s += rule + '\n';
  s += row('Subtotal', nf(t.subtotal)) + '\n';
  if (Number(t.service_charge) > 0) s += row('Service charge', nf(t.service_charge)) + '\n';
  s += row(PRICING.tax_name, nf(t.tax)) + '\n';
  if (Number(t.rounding) !== 0) s += row('Pembulatan', nf(t.rounding)) + '\n';
  s += rule + '\n';
  s += row('TOTAL', nf(t.total)) + '\n';
  s += row(label, nf(d.method === 'cash' && d.tendered ? d.tendered : t.total)) + '\n';
  if (d.method === 'cash' && d.tendered) s += row('Kembalian', nf(Math.max(0, d.tendered - t.total))) + '\n';
  s += rule + '\n';
  s += mid('Terima kasih atas kunjungan Anda') + '\n';
  document.getElementById('rcpt').textContent = s;
  const note = document.getElementById('savedNote');
  note.style.display = 'flex';
  note.innerHTML = '<span>Tersimpan di sistem sebagai <b>' + d.receipt_no + '</b> — hari bisnis ' + d.business_date +
    '. Transaksi ini sudah tampil di back-office (Penjualan &amp; Laporan).</span>';
}

function newOrder(){
  cart = []; renderCart();
  const note = document.getElementById('savedNote'); if (note) note.style.display = 'none';
  document.getElementById('rcptModal').classList.remove('on');
  if (!LIVE) document.getElementById('orderNo').textContent = OUTLET_CODE + '-' + DEVICE + '-' + RDATE + '-' + String(seq).padStart(4, '0');
}
function hold(){ alert('Pesanan ditahan dan dapat dibuka kembali dari layar Pesanan.'); }
function kitchen(){
  if (!cart.length) { alert('Belum ada item.'); return; }
  alert('Tiket dikirim ke printer dapur dan bar.\n\nDi sistem sebenarnya, inilah saat stok bahan terpotong sesuai resep.');
}

document.querySelectorAll('#seg button').forEach(b => b.onclick = () => {
  document.querySelectorAll('#seg button').forEach(x => x.classList.remove('on'));
  b.classList.add('on'); orderType = b.dataset.type; channel = b.dataset.ch || 'dine_in';
});
document.querySelectorAll('#ways button').forEach(b => b.onclick = () => {
  if (b.disabled) return;
  document.querySelectorAll('#ways button').forEach(x => x.classList.remove('on'));
  b.classList.add('on'); payMethod = b.dataset.pay; payCode = b.dataset.code || 'cash';
  document.getElementById('cashBox').style.display = payCode === 'cash' ? 'block' : 'none';
});
document.querySelectorAll('.rail button').forEach(b => b.onclick = () => {
  document.querySelectorAll('.rail button').forEach(x => x.classList.remove('on'));
  b.classList.add('on');
  const target = b.dataset.scr;
  document.getElementById('scr-kasir').style.display = target === 'kasir' ? 'flex' : 'none';
  document.getElementById('cartPanel').style.display = target === 'kasir' ? 'flex' : 'none';
  ['pesanan', 'shift', 'stok', 'laporan', 'pengaturan'].forEach(s =>
    document.getElementById('scr-' + s).classList.toggle('on', s === target));
});
document.querySelectorAll('.ov').forEach(o => o.addEventListener('click', e => {
  if (e.target === o) o.classList.remove('on');
}));
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') document.querySelectorAll('.ov.on').forEach(o => o.classList.remove('on'));
});
document.getElementById('q').addEventListener('input', renderGrid);
const tick = () => document.getElementById('clock').textContent =
  new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }).replace(':', '.');
tick(); setInterval(tick, 10000);
renderTabs(); renderGrid(); renderCart();
</script>
</body>
</html>
