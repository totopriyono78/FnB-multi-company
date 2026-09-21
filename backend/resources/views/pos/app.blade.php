<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title>Kasir — FnB Cloud</title>
<style>
  :root{
    --rail:#171b21; --rail-2:#232932; --rail-ink:#9aa4b2;
    --bg:#eef0f3; --surface:#fff; --line:#d7dbe1; --line-soft:#e8eaee;
    --ink:#1a1d23; --ink-2:#4b5563; --muted:#787f8a;
    --accent:#0b6b3a; --sel:#1f3d63; --warn:#9a6207; --danger:#9b2c2c; --r:4px;
  }
  *{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
  html,body{margin:0;height:100%;overflow:hidden;background:var(--bg);color:var(--ink);
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;font-size:14px;line-height:1.4}
  .num{font-variant-numeric:tabular-nums}
  svg.i{width:20px;height:20px;stroke:currentColor;fill:none;stroke-width:1.6;stroke-linecap:round;stroke-linejoin:round;flex:0 0 auto}
  svg.i.sm{width:16px;height:16px}
  button{font-family:inherit}

  /* ---- layar penuh (pairing / login / shift) ---- */
  .full{position:fixed;inset:0;display:none;align-items:center;justify-content:center;background:var(--bg);z-index:20;padding:24px}
  .full.on{display:flex}
  .panel{background:var(--surface);border:1px solid var(--line);border-radius:6px;width:100%;max-width:440px;padding:26px}
  .panel.wide{max-width:760px}
  .panel h2{margin:0 0 4px;font-size:18px;font-weight:650}
  .panel p.s{margin:0 0 18px;font-size:12.5px;color:var(--muted);line-height:1.6}
  .fld{margin-bottom:14px}
  .fld label{display:block;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:5px}
  .fld input{width:100%;padding:11px 12px;border:1px solid var(--line);border-radius:var(--r);font-size:15px;font-family:inherit}
  .fld input.code{letter-spacing:.35em;text-transform:uppercase;font-size:20px;text-align:center;font-weight:600}
  .btn{border:1px solid var(--line);background:#fff;color:var(--ink);border-radius:var(--r);padding:10px 14px;
    font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:7px}
  .btn:hover{background:#f5f6f8}
  .btn.main{background:var(--accent);border-color:var(--accent);color:#fff}
  .btn.main:hover{background:#0a5f34}
  .btn.block{width:100%;padding:13px;font-size:15px}
  .btn:disabled{opacity:.45;cursor:not-allowed}
  .err{background:#fdf1f1;border:1px solid #e9c3c3;color:#7f1d1d;font-size:12.5px;padding:9px 11px;border-radius:var(--r);margin-bottom:14px;line-height:1.5}
  .ok{background:#f1f9f4;border:1px solid #bfe3cd;color:#14532d;font-size:12.5px;padding:9px 11px;border-radius:var(--r);margin-bottom:14px;line-height:1.5}
  .staffgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:9px;margin-bottom:16px}
  .staffgrid button{border:1px solid var(--line);background:#fff;border-radius:var(--r);padding:12px 10px;text-align:left;cursor:pointer}
  .staffgrid button.on{border-color:var(--sel);background:#eef2f8}
  .staffgrid button b{display:block;font-size:13px;font-weight:600}
  .staffgrid button span{font-size:11px;color:var(--muted)}
  .staffgrid button .pin{display:inline-block;margin-top:5px;font-size:11px;font-weight:600;letter-spacing:.08em;
    background:#fdf6e6;border:1px solid #edd9a8;color:#7c5306;border-radius:3px;padding:1px 6px;font-variant-numeric:tabular-nums}
  .demohint{font-size:11.5px;color:var(--warn);background:#fdf6e6;border:1px solid #edd9a8;border-radius:var(--r);
    padding:7px 10px;margin-bottom:12px;line-height:1.5}
  .pinrow{display:flex;justify-content:center;gap:9px;margin:4px 0 16px}
  .pinrow i{width:14px;height:14px;border-radius:50%;border:1.5px solid var(--line);display:block}
  .pinrow i.f{background:var(--sel);border-color:var(--sel)}
  .pad{display:grid;grid-template-columns:repeat(3,1fr);gap:9px}
  .pad button{padding:16px 0;font-size:19px;font-weight:600;border:1px solid var(--line);background:#fff;border-radius:var(--r);cursor:pointer}
  .pad button:hover{background:#f2f4f7}

  /* ---- layar kasir ---- */
  .app{display:none;height:100vh}
  .app.on{display:flex}
  .rail{width:76px;background:var(--rail);display:flex;flex-direction:column;flex:0 0 auto;padding-bottom:8px}
  .rail .brand{height:52px;display:grid;place-items:center;border-bottom:1px solid #2a313b;color:#fff;font-size:11px;font-weight:600;letter-spacing:.14em}
  .rail button{border:0;background:transparent;color:var(--rail-ink);height:62px;display:flex;flex-direction:column;
    align-items:center;justify-content:center;gap:5px;font-size:10.5px;cursor:pointer;position:relative}
  .rail button:hover{color:#dfe4ea;background:#1d222a}
  .rail button.on{color:#fff;background:var(--rail-2)}
  .rail button.on::before{content:"";position:absolute;left:0;top:10px;bottom:10px;width:2px;background:#e4b363}
  .rail .grow{flex:1}
  .main{flex:1;display:flex;flex-direction:column;min-width:0}
  .top{height:52px;background:var(--surface);border-bottom:1px solid var(--line);display:flex;align-items:center;gap:16px;padding:0 16px;flex:0 0 auto}
  .top h1{margin:0;font-size:15px;font-weight:650}
  .top .meta{font-size:11.5px;color:var(--muted)}
  .sep{width:1px;height:26px;background:var(--line)}
  .state{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--ink-2)}
  .dot{width:7px;height:7px;border-radius:50%;background:#1a8a4d}
  .dot.amber{background:#c68209}.dot.red{background:#9b2c2c}
  .top .right{margin-left:auto;display:flex;align-items:center;gap:12px}
  .who{text-align:right;line-height:1.25}.who b{font-size:12.5px;font-weight:600}.who span{display:block;font-size:11px;color:var(--muted)}
  .clock{font-size:15px;font-weight:600}
  .ico{width:32px;height:32px;border:1px solid var(--line);border-radius:var(--r);background:#fff;color:var(--ink-2);display:grid;place-items:center;cursor:pointer;padding:0}
  .ico:hover{background:#f3f4f6;color:var(--ink)}
  .body{flex:1;display:flex;min-height:0}
  .left{flex:1;display:flex;flex-direction:column;min-width:0}
  .bar{background:var(--surface);border-bottom:1px solid var(--line);padding:0 16px;display:flex;align-items:center;gap:20px;flex:0 0 auto}
  .tabs{display:flex;gap:22px;overflow-x:auto}
  .tabs button{border:0;background:none;padding:12px 0 10px;font-size:13px;font-weight:550;color:var(--muted);cursor:pointer;border-bottom:2px solid transparent;white-space:nowrap}
  .tabs button.on{color:var(--ink);border-bottom-color:var(--sel);font-weight:650}
  .tabs button em{font-style:normal;color:var(--muted);font-weight:400;margin-left:5px;font-size:11.5px}
  .find{margin-left:auto;position:relative;padding:8px 0}
  .find svg{position:absolute;left:9px;top:50%;transform:translateY(-50%);color:var(--muted)}
  .find input{width:230px;padding:7px 10px 7px 31px;border:1px solid var(--line);border-radius:var(--r);font-size:12.5px;font-family:inherit;background:#fbfbfc}
  .grid{flex:1;overflow-y:auto;padding:14px 16px 18px;display:grid;grid-template-columns:repeat(auto-fill,minmax(152px,1fr));gap:12px;align-content:start}
  .card{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);overflow:hidden;cursor:pointer;display:flex;flex-direction:column;text-align:left;padding:0}
  .card:hover{border-color:#a9b4c2}
  .ph{position:relative;aspect-ratio:4/3;background:#e9ebef;overflow:hidden}
  .ph img{width:100%;height:100%;object-fit:cover;display:block}
  .ph .mono{position:absolute;inset:0;display:grid;place-items:center;font-size:26px;font-weight:600;color:#aeb5bf}
  .ph .flag{position:absolute;left:0;top:8px;background:#40454d;color:#fff;font-size:9.5px;letter-spacing:.06em;text-transform:uppercase;padding:2px 7px}
  .card .txt{padding:8px 9px 10px}
  .card .nm{font-size:12.5px;font-weight:550;line-height:1.3;min-height:33px}
  .card .row{display:flex;align-items:baseline;justify-content:space-between;margin-top:5px;gap:6px}
  .card .pc{font-size:13px;font-weight:650}
  .card .lbl{font-size:10px;color:var(--warn);text-transform:uppercase;letter-spacing:.05em}
  .card.off{cursor:default}.card.off .ph img,.card.off .ph .mono{filter:grayscale(1);opacity:.5}.card.off .nm,.card.off .pc{color:var(--muted)}
  .cart{width:352px;flex:0 0 auto;background:var(--surface);border-left:1px solid var(--line);display:flex;flex-direction:column}
  .cart .head{padding:10px 14px;border-bottom:1px solid var(--line);display:flex;align-items:baseline;justify-content:space-between}
  .cart .head b{font-size:13px;font-weight:650}.cart .head span{font-size:11.5px;color:var(--muted)}
  .seg{display:flex;margin:12px 14px 4px;border:1px solid var(--line);border-radius:var(--r);overflow:hidden}
  .seg button{flex:1;border:0;border-right:1px solid var(--line);background:#fff;padding:7px 4px;font-size:11.5px;color:var(--ink-2);cursor:pointer}
  .seg button:last-child{border-right:0}
  .seg button.on{background:var(--sel);color:#fff;font-weight:600}
  .lines{flex:1;overflow-y:auto;padding:6px 14px}
  .ln{display:flex;gap:10px;padding:10px 0;border-bottom:1px solid var(--line-soft)}
  .ln .info{flex:1;min-width:0}.ln .nm{font-size:12.5px;font-weight:550}
  .ln .mod{font-size:11px;color:var(--muted);margin-top:1px}
  .ln .amt{font-size:12.5px;font-weight:650;white-space:nowrap}
  .stp{display:inline-flex;align-items:center;border:1px solid var(--line);border-radius:var(--r);margin-top:7px}
  .stp button{width:26px;height:24px;border:0;background:#fff;color:var(--ink-2);display:grid;place-items:center;cursor:pointer}
  .stp span{min-width:26px;text-align:center;font-size:12.5px;font-weight:600;border-left:1px solid var(--line);border-right:1px solid var(--line);height:24px;line-height:24px}
  .ln .del{border:0;background:none;color:#9aa1ab;cursor:pointer;padding:0;height:18px}
  .blank{padding:44px 16px;text-align:center;color:var(--muted);font-size:12.5px;line-height:1.7}
  .sum{border-top:1px solid var(--line);padding:10px 14px 4px;font-size:12.5px}
  .sum .r{display:flex;justify-content:space-between;padding:2.5px 0;color:var(--ink-2)}
  .sum .r.big{border-top:1px solid var(--line);margin-top:7px;padding-top:9px;color:var(--ink);font-size:17px;font-weight:700}
  .acts{padding:10px 14px 14px;display:grid;grid-template-columns:1fr 1fr;gap:8px}
  .acts .btn.main{grid-column:1/-1;font-size:15px;padding:13px}
  .scr{display:none;flex:1;overflow:auto;padding:18px}.scr.on{display:block}
  .scr h2{margin:0 0 3px;font-size:16px;font-weight:650}.scr p.d{margin:0 0 14px;font-size:12.5px;color:var(--muted)}
  table.t{width:100%;border-collapse:collapse;font-size:12.5px;background:var(--surface);border:1px solid var(--line);border-radius:var(--r)}
  table.t th,table.t td{padding:9px 12px;text-align:left;border-bottom:1px solid var(--line-soft)}
  table.t tr:last-child td{border-bottom:0}
  table.t th{font-size:10.5px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);font-weight:600;border-bottom:1px solid var(--line)}
  table.t td.n,table.t th.n{text-align:right;font-variant-numeric:tabular-nums}
  .kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(178px,1fr));gap:10px;margin-bottom:16px}
  .kpi{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:11px 13px}
  .kpi h4{margin:0 0 5px;font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)}
  .kpi .v{font-size:19px;font-weight:700}.kpi .s{font-size:11px;color:var(--muted);margin-top:2px}

  .ov{position:fixed;inset:0;background:rgba(16,20,26,.5);display:none;align-items:center;justify-content:center;padding:22px;z-index:50}
  .ov.on{display:flex}
  .box{background:var(--surface);border-radius:6px;width:100%;max-width:520px;max-height:92vh;overflow:auto;box-shadow:0 18px 48px rgba(12,16,22,.28)}
  .box header{padding:14px 18px;border-bottom:1px solid var(--line)}
  .box header h3{margin:0;font-size:15px;font-weight:650}
  .box header p{margin:3px 0 0;font-size:12px;color:var(--muted)}
  .box .in{padding:16px 18px}
  .box footer{padding:12px 18px 16px;display:grid;grid-template-columns:1fr 1fr;gap:9px}
  .ways{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:14px}
  .ways button{border:1px solid var(--line);background:#fff;border-radius:var(--r);padding:12px 6px;font-size:12px;font-weight:600;cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:6px;color:var(--ink-2)}
  .ways button.on{border-color:var(--accent);color:var(--accent);background:#f2f8f4;box-shadow:inset 0 0 0 1px var(--accent)}
  .quick{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:12px}
  .quick button{border:1px solid var(--line);background:#fff;border-radius:var(--r);padding:10px 4px;font-size:12px;font-weight:600;cursor:pointer;font-variant-numeric:tabular-nums}
  .quick button.on{border-color:var(--sel);background:#eef2f8}
  .paid{display:flex;justify-content:space-between;font-size:13px;padding:9px 11px;background:#f5f6f8;border-radius:var(--r)}
  .slip{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:11.5px;background:#f7f8f9;border:1px solid var(--line);border-radius:var(--r);padding:14px;white-space:pre;line-height:1.55;overflow-x:auto}
  .qr{font-family:ui-monospace,Menlo,monospace;font-size:10.5px;word-break:break-all;background:#f7f8f9;border:1px solid var(--line);padding:10px;border-radius:var(--r);margin-bottom:10px}
  .opt{display:flex;flex-wrap:wrap;gap:7px;margin-bottom:14px}
  .opt button{border:1px solid var(--line);background:#fff;border-radius:var(--r);padding:8px 12px;font-size:12.5px;cursor:pointer}
  .opt button.on{border-color:var(--sel);background:#eef2f8;font-weight:600}
  .gh{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin:0 0 6px}
  @media (max-width:1080px){.cart{width:310px}.find input{width:150px}}
</style>
</head>
<body>

<svg width="0" height="0" style="position:absolute" aria-hidden="true">
  <symbol id="ic-kasir" viewBox="0 0 24 24"><path d="M6 2.8h12v18.4l-2.4-1.6-2.4 1.6-2.4-1.6-2.4 1.6L6 19.6z"/><path d="M9 7.5h6M9 11h6M9 14.5h4"/></symbol>
  <symbol id="ic-pesanan" viewBox="0 0 24 24"><rect x="5" y="4.2" width="14" height="16.6" rx="1.6"/><path d="M9.2 4.2V2.9h5.6v1.3"/><path d="M8.6 9.4h6.8M8.6 13h6.8M8.6 16.6h4.2"/></symbol>
  <symbol id="ic-shift" viewBox="0 0 24 24"><rect x="2.8" y="6" width="18.4" height="12.6" rx="2"/><path d="M2.8 10.2h18.4"/><circle cx="16.6" cy="14.6" r="1.5"/></symbol>
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
  <symbol id="ic-cetak" viewBox="0 0 24 24"><path d="M7 9.4V4h10v5.4"/><rect x="3.6" y="9.4" width="16.8" height="6.6" rx="1.6"/><path d="M7 14h10v6H7z"/></symbol>
  <symbol id="ic-info" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.6"/><path d="M12 11.2v5M12 7.9h.01"/></symbol>
  <symbol id="ic-keluar" viewBox="0 0 24 24"><path d="M14.4 7.4V5.2a1.6 1.6 0 0 0-1.6-1.6H5.6A1.6 1.6 0 0 0 4 5.2v13.6a1.6 1.6 0 0 0 1.6 1.6h7.2a1.6 1.6 0 0 0 1.6-1.6v-2.2"/><path d="M9.2 12h11M17 8.6l3.4 3.4-3.4 3.4"/></symbol>
</svg>

<!-- ============ 1. PAIRING PERANGKAT ============ -->
<div class="full on" id="scr-pair">
  <div class="panel">
    <h2>Pasangkan perangkat</h2>
    <p class="s">Buka back-office → <b>Organisasi → Perangkat</b>, pilih perangkat kasir, lalu tekan
      <b>Buat Kode Pairing</b>. Masukkan kodenya di sini. Perangkat akan menerima token dan terikat pada outletnya.</p>
    <div id="pairErr"></div>
    <div class="fld">
      <label for="pairCode">Kode pairing</label>
      <input id="pairCode" class="code" maxlength="12" autocomplete="off" placeholder="XXXXXXXX">
    </div>
    <button class="btn main block" id="pairBtn">Pasangkan</button>
  </div>
</div>

<!-- ============ 2. LOGIN KASIR ============ -->
<div class="full" id="scr-login">
  <div class="panel wide">
    <h2>Masuk kasir</h2>
    <p class="s" id="loginOutlet">Pilih nama Anda lalu masukkan PIN.</p>
    <div id="loginErr"></div>
    <div class="demohint" id="demoHint" style="display:none"></div>
    <div class="staffgrid" id="staffList"></div>
    <div class="pinrow" id="pinDots"></div>
    <div class="pad" id="pinPad"></div>
    <div style="display:flex;justify-content:space-between;margin-top:14px">
      <button class="btn" id="unpairBtn">Lepas perangkat</button>
      <span style="font-size:11.5px;color:var(--muted)" id="deviceInfo"></span>
    </div>
  </div>
</div>

<!-- ============ 3. BUKA SHIFT ============ -->
<div class="full" id="scr-shift">
  <div class="panel">
    <h2>Buka shift</h2>
    <p class="s">Masukkan jumlah uang di laci saat shift dibuka. Angka ini menjadi dasar perhitungan
      selisih kas saat tutup shift.</p>
    <div id="shiftErr"></div>
    <div class="fld">
      <label for="openingCash">Modal awal (Rp)</label>
      <input id="openingCash" class="num" inputmode="numeric" value="500000">
    </div>
    <button class="btn main block" id="openShiftBtn">Buka shift</button>
    <button class="btn block" style="margin-top:9px" id="backLoginBtn">Ganti kasir</button>
  </div>
</div>

<!-- ============ 4. LAYAR KASIR ============ -->
<div class="app" id="scr-sale">
  <aside class="rail">
    <div class="brand">FNB</div>
    <nav>
      <button class="on" data-scr="kasir"><svg class="i"><use href="#ic-kasir"/></svg>Kasir</button>
      <button data-scr="pesanan"><svg class="i"><use href="#ic-pesanan"/></svg>Pesanan</button>
      <button data-scr="shift"><svg class="i"><use href="#ic-shift"/></svg>Shift</button>
    </nav>
    <div class="grow"></div>
    <button data-scr="atur"><svg class="i"><use href="#ic-atur"/></svg>Atur</button>
  </aside>

  <div class="main">
    <header class="top">
      <div>
        <h1 id="hOutlet">—</h1>
        <div class="meta" id="hMeta">—</div>
      </div>
      <div class="sep"></div>
      <div class="state"><span class="dot" id="hDot"></span><span id="hNet">Terhubung</span></div>
      <div class="state" id="hShift">—</div>
      <div class="right">
        <button class="ico" id="infoBtn" title="Keterangan layar"><svg class="i sm"><use href="#ic-info"/></svg></button>
        <div class="clock num" id="clock">--.--</div>
        <div class="sep"></div>
        <div class="who"><b id="hCashier">—</b><span>Kasir</span></div>
        <button class="ico" id="logoutBtn" title="Keluar kasir"><svg class="i sm"><use href="#ic-keluar"/></svg></button>
      </div>
    </header>

    <div class="body">
      <div class="left" id="view-kasir">
        <div class="bar">
          <div class="tabs" id="tabs"></div>
          <div class="find"><svg class="i sm"><use href="#ic-cari"/></svg><input id="q" placeholder="Cari menu"></div>
        </div>
        <div class="grid" id="grid"></div>
      </div>

      <div class="scr" id="view-pesanan">
        <h2>Transaksi perangkat ini</h2>
        <p class="d">Transaksi yang dibuat dari perangkat ini pada sesi ini. Void memerlukan otorisasi supervisor.</p>
        <table class="t"><thead><tr><th>No. struk</th><th>Waktu</th><th>Tipe</th><th class="n">Total</th><th>Status</th><th></th></tr></thead>
          <tbody id="orderRows"><tr><td colspan="6" style="color:var(--muted)">Belum ada transaksi.</td></tr></tbody></table>
      </div>

      <div class="scr" id="view-shift">
        <h2>Shift kasir</h2>
        <p class="d">Angka diambil langsung dari server (laporan shift), sama dengan yang dilihat back-office.</p>
        <div id="shiftState"></div>
        <div class="kpis" id="shiftKpis"></div>
        <div style="display:flex;gap:9px;flex-wrap:wrap">
          <button class="btn" id="refreshShift">Muat ulang</button>
          <button class="btn" id="openShiftHere" style="display:none">Buka shift</button>
          <button class="btn" id="closeShiftHere" style="display:none">Tutup shift</button>
        </div>
      </div>

      <div class="scr" id="view-atur">
        <h2>Perangkat</h2>
        <p class="d">Informasi perangkat dan katalog yang sedang dipakai.</p>
        <table class="t"><tbody id="deviceRows"></tbody></table>
        <div style="margin-top:14px;display:flex;gap:9px">
          <button class="btn" id="reloadCatalog">Tarik ulang katalog</button>
          <button class="btn" id="unpairBtn2">Lepas perangkat</button>
        </div>
      </div>

      <aside class="cart" id="cartPanel">
        <div class="head"><b>Pesanan baru</b><span id="cartMeta">nomor struk otomatis</span></div>
        <div class="seg" id="seg"></div>
        <div class="lines" id="lines"></div>
        <div class="sum">
          <div class="r"><span>Subtotal</span><span class="num" id="sSub">0</span></div>
          <div class="r"><span>Diskon</span><span class="num" id="sDisc">0</span></div>
          <div class="r" id="rServ"><span>Service charge</span><span class="num" id="sServ">0</span></div>
          <div class="r"><span id="lTax">Pajak</span><span class="num" id="sTax">0</span></div>
          <div class="r"><span>Pembulatan</span><span class="num" id="sRound">0</span></div>
          <div class="r big"><span>Total</span><span class="num" id="sTotal">Rp 0</span></div>
        </div>
        <div class="acts">
          <button class="btn" id="clearBtn"><svg class="i sm"><use href="#ic-hapus"/></svg>Kosongkan</button>
          <button class="btn" id="kitchenBtn"><svg class="i sm"><use href="#ic-dapur"/></svg>Ke dapur</button>
          <button class="btn main" id="payBtn" disabled>Bayar</button>
        </div>
      </aside>
    </div>
  </div>
</div>

<!-- modal: pilihan varian & modifier -->
<div class="ov" id="optModal"><div class="box" style="max-width:460px">
  <header><h3 id="optTitle">Pilihan</h3><p id="optSub"></p></header>
  <div class="in" id="optBody"></div>
  <footer><button class="btn" data-close>Batal</button><button class="btn main" style="grid-column:auto" id="optAdd">Tambahkan</button></footer>
</div></div>

<!-- modal: pembayaran -->
<div class="ov" id="payModal"><div class="box">
  <header><h3>Pembayaran</h3><p>Total tagihan <b class="num" id="payTotal">Rp 0</b> · <span id="payCount">0</span> item</p></header>
  <div class="in">
    <div id="payErr"></div>
    <div class="ways" id="ways">
      <button class="on" data-code="cash"><svg class="i"><use href="#ic-tunai"/></svg>Tunai</button>
      <button data-code="qris"><svg class="i"><use href="#ic-qris"/></svg>QRIS</button>
      <button data-code="debit"><svg class="i"><use href="#ic-kartu"/></svg>Kartu debit</button>
    </div>
    <div id="cashBox">
      <div class="quick" id="quick"></div>
      <div class="paid"><span>Uang diterima <b class="num" id="cashGiven">Rp 0</b></span><span>Kembalian <b class="num" id="cashBack">Rp 0</b></span></div>
    </div>
    <div id="qrisBox" style="display:none">
      <p class="gh">Kode QR dari payment gateway (sandbox)</p>
      <div class="qr" id="qrString">—</div>
      <div style="display:flex;gap:9px;align-items:center">
        <button class="btn" id="qrisCreate">Buat kode QR</button>
        <button class="btn" id="qrisSimulate" disabled>Simulasikan pembayaran masuk</button>
        <span id="qrisState" style="font-size:12px;color:var(--muted)"></span>
      </div>
    </div>
  </div>
  <footer>
    <button class="btn" data-close>Batal</button>
    <button class="btn main" style="grid-column:auto" id="payDone"><svg class="i sm"><use href="#ic-cetak"/></svg>Selesaikan</button>
  </footer>
</div></div>

<!-- modal: struk -->
<div class="ov" id="rcptModal"><div class="box">
  <header><h3>Struk</h3><p>Struk 80 mm — transaksi sudah tersimpan di server.</p></header>
  <div class="in"><div id="rcptNote" class="ok"></div><div class="slip" id="rcpt"></div></div>
  <footer><button class="btn" data-close>Tutup</button><button class="btn main" style="grid-column:auto" id="newOrderBtn">Transaksi baru</button></footer>
</div></div>

<!-- modal: tutup shift -->
<div class="ov" id="closeModal"><div class="box" style="max-width:460px">
  <header><h3>Tutup shift</h3><p id="closeSub">Hitung uang di laci, lalu masukkan jumlahnya.</p></header>
  <div class="in">
    <div id="closeErr"></div>
    <div class="fld"><label>Kas seharusnya</label><input id="closeExpected" disabled></div>
    <div class="fld"><label>Kas terhitung (Rp)</label><input id="closeCounted" class="num" inputmode="numeric"></div>
    <div class="fld"><label>Keterangan selisih (bila ada)</label><input id="closeNote" maxlength="120" placeholder="mis. kembalian kurang"></div>
  </div>
  <footer><button class="btn" data-close>Batal</button><button class="btn main" style="grid-column:auto" id="closeGo">Tutup shift</button></footer>
</div></div>

<!-- modal: otorisasi supervisor -->
<div class="ov" id="authModal"><div class="box" style="max-width:420px">
  <header><h3>Otorisasi supervisor</h3><p id="authSub">Tindakan ini memerlukan persetujuan supervisor.</p></header>
  <div class="in">
    <div id="authErr"></div>
    <div class="fld"><label>Supervisor</label><select id="authWho" style="width:100%;padding:10px;border:1px solid var(--line);border-radius:4px;font-family:inherit"></select></div>
    <div class="fld"><label>PIN supervisor</label><input id="authPin" type="password" inputmode="numeric" maxlength="8" autocomplete="off"></div>
    <div class="fld"><label>Alasan</label><input id="authReason" maxlength="120" placeholder="mis. salah input"></div>
  </div>
  <footer><button class="btn" data-close>Batal</button><button class="btn main" style="grid-column:auto" id="authGo">Setujui</button></footer>
</div></div>

<!-- modal: keterangan -->
<div class="ov" id="infoModal"><div class="box" style="max-width:480px">
  <header><h3>Keterangan layar</h3></header>
  <div class="in" style="font-size:13px;line-height:1.65;display:flex;flex-direction:column;gap:9px">
    <p style="margin:0">Ini <b>aplikasi kasir versi web</b> yang memakai API POS yang sesungguhnya: pemasangan perangkat,
      login PIN, shift, perhitungan harga di server, penyimpanan transaksi, dan otorisasi supervisor.</p>
    <p style="margin:0">Setiap transaksi yang diselesaikan tersimpan di server dan langsung terlihat di back-office —
      Penjualan, Laporan, dan Tutup Hari. Stok bahan ikut terpotong sesuai resep.</p>
    <p style="margin:0">Aplikasi kasir Flutter nanti memakai jalur API yang sama persis; layar ini sekaligus menjadi
      alat uji kontrak API tersebut.</p>
  </div>
  <footer style="grid-template-columns:1fr"><button class="btn" data-close>Tutup</button></footer>
</div></div>

<script>
const API = '{{ url('/api/v1') }}';
const LS = { dev: 'fnb.pos.device', pos: 'fnb.pos.session', seq: 'fnb.pos.seq' };
const DEMO_PINS = @json($demoPins ?? []);
const S = { device: null, pos: null, catalog: null, shift: null, cart: [], channel: 'dine_in',
            quote: null, orders: [], pay: 'cash', given: 0, intent: null, pendingItem: null };

const nf = n => Math.round(Number(n) || 0).toLocaleString('id-ID');
const rp = n => 'Rp ' + nf(n);
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const uuid = () => (crypto.randomUUID ? crypto.randomUUID()
  : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
      const r = Math.random()*16|0; return (c === 'x' ? r : (r&0x3|0x8)).toString(16); }));
const el = id => document.getElementById(id);
const show = id => ['scr-pair','scr-login','scr-shift'].forEach(s => el(s).classList.toggle('on', s === id))
  || el('scr-sale').classList.toggle('on', id === 'scr-sale');

function fail(box, msg){ el(box).innerHTML = '<div class="err">' + esc(msg) + '</div>'; }
function clearFail(box){ el(box).innerHTML = ''; }

async function api(path, { method = 'GET', body = null, token = null } = {}) {
  const res = await fetch(API + path, {
    method,
    headers: Object.assign({ 'Accept': 'application/json', 'Content-Type': 'application/json' },
      token ? { 'Authorization': 'Bearer ' + token } : {}),
    body: body === null ? undefined : JSON.stringify(body),
  });
  let data = {};
  try { data = await res.json(); } catch (e) {}
  if (!res.ok || data.success === false) {
    const err = (data.errors || [])[0] || {};
    const e = new Error(err.message || ('Gagal (' + res.status + ')'));
    e.code = err.code; e.status = res.status; e.field = err.field;
    throw e;
  }
  return data.data;
}
const dev = (p, o = {}) => api(p, Object.assign({ token: S.device.token }, o));
const pos = (p, o = {}) => api(p, Object.assign({ token: S.pos.token }, o));

/* ---------------- pairing ---------------- */
el('pairBtn').onclick = async () => {
  const code = el('pairCode').value.trim().toUpperCase();
  if (code.length < 8) { fail('pairErr', 'Kode pairing minimal 8 karakter.'); return; }
  clearFail('pairErr');
  el('pairBtn').disabled = true;
  try {
    const d = await api('/devices/pair', { method: 'POST', body: { code, platform: 'web', app_version: '0.1.0' } });
    S.device = { token: d.token, company_id: d.company_id, device: d.device, outlet: d.outlet };
    localStorage.setItem(LS.dev, JSON.stringify(S.device));
    await startLogin();
  } catch (e) { fail('pairErr', e.message); }
  finally { el('pairBtn').disabled = false; }
};
function unpair(){ localStorage.removeItem(LS.dev); localStorage.removeItem(LS.pos); location.reload(); }
el('unpairBtn').onclick = unpair;
el('unpairBtn2').onclick = unpair;

/* ---------------- login kasir ---------------- */
let pinBuf = '', chosen = null;
async function startLogin(){
  show('scr-login');
  el('deviceInfo').textContent = S.device.device.code + ' · ' + S.device.outlet.name;
  el('loginOutlet').textContent = 'Outlet ' + S.device.outlet.name + '. Pilih nama Anda lalu masukkan PIN.';
  renderPad();
  try {
    const staff = await dev('/pos/staff');
    el('staffList').innerHTML = staff.map(s => {
      const pin = DEMO_PINS[s.name];
      return `<button data-id="${s.id}" ${s.locked ? 'disabled' : ''}><b>${esc(s.name)}</b>` +
        `<span>${esc(s.employee_code || '')}${s.locked ? ' · terkunci' : ''}</span>` +
        (pin ? `<span class="pin">PIN ${esc(pin)}</span>` : '') + `</button>`;
    }).join('');
    el('demoHint').innerHTML = Object.keys(DEMO_PINS).length
      ? 'Mode demo aktif: PIN ditampilkan di bawah nama agar peragaan lancar. Matikan dengan <b>FNB_DEMO_LOGIN=false</b> di .env.'
      : '';
    el('demoHint').style.display = Object.keys(DEMO_PINS).length ? 'block' : 'none';
    document.querySelectorAll('#staffList button').forEach(b => b.onclick = () => {
      document.querySelectorAll('#staffList button').forEach(x => x.classList.remove('on'));
      b.classList.add('on'); chosen = b.dataset.id; pinBuf = ''; drawPin();
    });
  } catch (e) {
    if (e.status === 401) { unpair(); return; }
    fail('loginErr', e.message);
  }
}
function renderPad(){
  el('pinPad').innerHTML = ['1','2','3','4','5','6','7','8','9','⌫','0','OK']
    .map(k => `<button data-k="${k}">${k}</button>`).join('');
  document.querySelectorAll('#pinPad button').forEach(b => b.onclick = () => tapPin(b.dataset.k));
  drawPin();
}
function drawPin(){ el('pinDots').innerHTML = Array.from({length: Math.max(6, pinBuf.length)},
  (_, i) => `<i class="${i < pinBuf.length ? 'f' : ''}"></i>`).join(''); }
async function tapPin(k){
  if (k === '⌫') { pinBuf = pinBuf.slice(0, -1); drawPin(); return; }
  if (k === 'OK') { return doLogin(); }
  if (pinBuf.length >= 8) return;
  pinBuf += k; drawPin();
  if (pinBuf.length >= 6) doLogin();
}
async function doLogin(){
  if (!chosen) { fail('loginErr', 'Pilih nama kasir dulu.'); return; }
  if (pinBuf.length < 4) return;
  clearFail('loginErr');
  try {
    const d = await dev('/pos/auth/pin', { method: 'POST', body: { staff_id: chosen, pin: pinBuf } });
    S.pos = { token: d.token, expires_at: d.expires_at, staff: d.staff };
    localStorage.setItem(LS.pos, JSON.stringify(S.pos));
    pinBuf = ''; drawPin();
    await afterLogin();
  } catch (e) { pinBuf = ''; drawPin(); fail('loginErr', e.message); }
}
el('logoutBtn').onclick = async () => {
  try { await pos('/pos/auth/logout', { method: 'POST' }); } catch (e) {}
  localStorage.removeItem(LS.pos); S.pos = null; S.cart = []; startLogin();
};

/* ---------------- shift ---------------- */
async function afterLogin(){
  try {
    S.shift = await pos('/pos/shifts/current');
  } catch (e) {
    if (e.status === 401) { localStorage.removeItem(LS.pos); return startLogin(); }
    throw e;
  }
  if (!S.shift) { show('scr-shift'); return; }
  await enterSale();
}
el('openShiftBtn').onclick = async () => {
  clearFail('shiftErr');
  el('openShiftBtn').disabled = true;
  try {
    await openShift(el('openingCash').value);
    await enterSale();
    await syncSequence();
  } catch (e) { fail('shiftErr', e.message); }
  finally { el('openShiftBtn').disabled = false; }
};

/** Buka shift lalu ambil ulang dari server (agar tidak bergantung pada bentuk balasan). */
async function openShift(cashInput){
  const cash = String(cashInput || '0').replace(/[^\d]/g, '') || '0';
  await pos('/pos/shifts', { method: 'POST', body: { id: uuid(), opening_cash: cash + '.00' } });
  S.shift = await pos('/pos/shifts/current');
  if (!S.shift || !S.shift.id) { throw new Error('Shift terbuka tidak terbaca dari server.'); }
  return S.shift;
}
el('backLoginBtn').onclick = () => { localStorage.removeItem(LS.pos); S.pos = null; startLogin(); };

/* ---------------- katalog & layar kasir ---------------- */
async function enterSale(){
  show('scr-sale');
  el('hOutlet').textContent = S.device.outlet.name;
  el('hCashier').textContent = S.pos.staff.name;
  updateHeader();
  if (!S.catalog) { await loadCatalog(); }
  await syncSequence();
  renderChannels(); renderTabs(); renderGrid(); renderCart(); renderTotals(null); renderDevice();
  goView('kasir');
}
async function loadCatalog(){
  S.catalog = await pos('/pos/catalog');
  S.cats = S.catalog.categories;
  S.cat = (S.cats[0] || {}).id;
  S.groups = Object.fromEntries((S.catalog.modifier_groups || []).map(g => [g.id, g]));
}
el('reloadCatalog').onclick = async () => { await loadCatalog(); renderTabs(); renderGrid(); renderDevice(); };

function channelObj(){ return (S.catalog.channels || []).find(c => c.code === S.channel) || {}; }
function renderChannels(){
  const list = (S.catalog.channels || []).filter(c => ['dine_in','take_away','delivery'].includes(c.code));
  el('seg').innerHTML = list.map(c =>
    `<button data-code="${c.code}" class="${c.code === S.channel ? 'on' : ''}">${esc(c.name)}</button>`).join('');
  document.querySelectorAll('#seg button').forEach(b => b.onclick = () => {
    S.channel = b.dataset.code; renderChannels(); renderGrid(); refreshQuote();
  });
}
function priceOf(item){
  const ch = S.channel;
  if (item.prices) return Number(item.prices[ch] ?? Object.values(item.prices)[0] ?? 0);
  const v = (item.variants || []).find(v => v.is_default) || (item.variants || [])[0];
  return v ? Number(v.prices[ch] ?? Object.values(v.prices)[0] ?? 0) : 0;
}
function available(item){
  if (item.sold_out) return false;
  if (item.channel_codes && !item.channel_codes.includes(S.channel)) return false;
  return true;
}
function renderTabs(){
  el('tabs').innerHTML = S.cats.map(c => {
    const n = S.catalog.items.filter(i => i.category_id === c.id).length;
    return `<button data-id="${c.id}" class="${c.id === S.cat ? 'on' : ''}">${esc(c.name)}<em>${n}</em></button>`;
  }).join('');
  document.querySelectorAll('#tabs button').forEach(b => b.onclick = () => { S.cat = b.dataset.id; renderTabs(); renderGrid(); });
}
function renderGrid(){
  const q = el('q').value.toLowerCase().trim();
  const items = S.catalog.items.filter(i => (q ? i.name.toLowerCase().includes(q) : i.category_id === S.cat));
  el('grid').innerHTML = items.map(i => {
    const ok = available(i);
    const initials = i.name.split(/\s+/).filter(w => /[A-Za-z]/.test(w[0])).slice(0,2).map(w => w[0].toUpperCase()).join('');
    const img = i.image_path ? `<img src="${esc(i.image_path)}" alt="">` : `<span class="mono">${initials || '#'}</span>`;
    return `<button type="button" class="card ${ok ? '' : 'off'}" data-id="${i.id}" ${ok ? '' : 'disabled'}>
      <div class="ph">${img}${i.sold_out ? '<span class="flag">Habis</span>' : ''}</div>
      <div class="txt"><div class="nm">${esc(i.name)}</div>
        <div class="row"><span class="pc num">${nf(priceOf(i))}</span>${i.type === 'bundle' ? '<span class="lbl">Paket</span>' : ''}</div>
      </div></button>`;
  }).join('') || '<div class="blank" style="grid-column:1/-1">Tidak ada menu.</div>';
  document.querySelectorAll('#grid .card').forEach(b => b.onclick = () => pickItem(b.dataset.id));
}
el('q').addEventListener('input', renderGrid);

/* ---- tambah item: varian, modifier wajib, paket ---- */
function pickItem(id){
  const item = S.catalog.items.find(i => i.id === id);
  const needs = (item.variants && item.variants.length > 1)
    || (item.modifier_group_ids || []).some(g => S.groups[g])
    || item.type === 'bundle';
  if (!needs) return addLine(item, defaults(item));
  S.pendingItem = { item, sel: defaults(item) };
  openOpt();
}
function defaults(item){
  const sel = { variant: null, mods: {}, bundle: {} };
  if (item.variants && item.variants.length) sel.variant = (item.variants.find(v => v.is_default) || item.variants[0]).id;
  (item.modifier_group_ids || []).forEach(gid => {
    const g = S.groups[gid]; if (!g) return;
    const d = g.modifiers.filter(m => m.is_default);
    sel.mods[gid] = d.length ? d.map(m => m.id) : (g.min_select > 0 ? [g.modifiers[0].id] : []);
  });
  (item.bundle_groups || []).forEach(bg => { sel.bundle[bg.id] = [bg.options[0].id]; });
  return sel;
}
function openOpt(){
  const { item, sel } = S.pendingItem;
  el('optTitle').textContent = item.name;
  el('optSub').textContent = 'Pilih varian dan tambahan sebelum masuk keranjang.';
  let h = '';
  if (item.variants && item.variants.length) {
    h += '<p class="gh">Varian</p><div class="opt">' + item.variants.map(v =>
      `<button data-t="v" data-id="${v.id}" class="${sel.variant === v.id ? 'on' : ''}">${esc(v.name)} · ${nf(v.prices[S.channel] || 0)}</button>`).join('') + '</div>';
  }
  (item.modifier_group_ids || []).forEach(gid => {
    const g = S.groups[gid]; if (!g) return;
    h += `<p class="gh">${esc(g.name)}${g.min_select > 0 ? ' (wajib)' : ''}</p><div class="opt">` + g.modifiers.map(m =>
      `<button data-t="m" data-g="${gid}" data-id="${m.id}" class="${(sel.mods[gid]||[]).includes(m.id) ? 'on' : ''}">${esc(m.name)}${Number(m.price) ? ' +' + nf(m.price) : ''}</button>`).join('') + '</div>';
  });
  (item.bundle_groups || []).forEach(bg => {
    h += `<p class="gh">${esc(bg.name || 'Pilihan paket')}</p><div class="opt">` + bg.options.map(o =>
      `<button data-t="b" data-g="${bg.id}" data-id="${o.id}" class="${(sel.bundle[bg.id]||[]).includes(o.id) ? 'on' : ''}">${esc(o.name || 'Pilihan')}${Number(o.extra_price) ? ' +' + nf(o.extra_price) : ''}</button>`).join('') + '</div>';
  });
  el('optBody').innerHTML = h || '<p style="font-size:13px;color:var(--muted)">Tidak ada pilihan tambahan.</p>';
  el('optBody').querySelectorAll('button').forEach(b => b.onclick = () => {
    const { t, g, id } = b.dataset;
    if (t === 'v') S.pendingItem.sel.variant = id;
    if (t === 'm') {
      const grp = S.groups[g], cur = S.pendingItem.sel.mods[g] || [];
      if (grp.max_select === 1) S.pendingItem.sel.mods[g] = [id];
      else S.pendingItem.sel.mods[g] = cur.includes(id) ? cur.filter(x => x !== id) : cur.concat(id).slice(0, grp.max_select || 99);
    }
    if (t === 'b') S.pendingItem.sel.bundle[g] = [id];
    openOpt();
  });
  el('optModal').classList.add('on');
}
el('optAdd').onclick = () => {
  const { item, sel } = S.pendingItem;
  el('optModal').classList.remove('on');
  addLine(item, sel);
};
function addLine(item, sel){
  const mods = [];
  Object.entries(sel.mods || {}).forEach(([gid, ids]) => ids.forEach(id => mods.push({ id, qty: 1 })));
  const bundle = Object.entries(sel.bundle || {}).map(([gid, ids]) => ({ group_id: gid, options: ids.map(o => ({ option_id: o })) }));
  S.cart.push({ lineId: uuid(), item_id: item.id, name: item.name, variant_id: sel.variant || null,
                modifiers: mods, bundle, qty: 1 });
  refreshQuote();
}
function chgQty(i, d){ S.cart[i].qty += d; if (S.cart[i].qty <= 0) S.cart.splice(i, 1); refreshQuote(); }
function delLine(i){ S.cart.splice(i, 1); refreshQuote(); }
el('clearBtn').onclick = () => { S.cart = []; refreshQuote(); };

/* ---- harga dihitung server ---- */
let quoteTimer = null;
function refreshQuote(){
  renderCart();
  clearTimeout(quoteTimer);
  if (!S.cart.length) { S.quote = null; renderTotals(null); return; }
  quoteTimer = setTimeout(async () => {
    try {
      S.quote = await pos('/pos/quotes', { method: 'POST', body: {
        channel_code: S.channel,
        lines: S.cart.map(l => {
          const o = { id: l.lineId, item_id: l.item_id, qty: String(l.qty) };
          if (l.variant_id) o.variant_id = l.variant_id;
          if (l.modifiers.length) o.modifiers = l.modifiers;
          if (l.bundle.length) o.bundle = l.bundle;
          return o;
        }),
      }});
      renderTotals(S.quote.totals);
      renderCart();
    } catch (e) { renderTotals(null); el('cartMeta').textContent = e.message; }
  }, 180);
}
function renderTotals(t){
  const g = id => el(id);
  g('sSub').textContent = t ? nf(t.subtotal) : '0';
  g('sDisc').textContent = t && Number(t.discount) ? '−' + nf(t.discount) : '0';
  g('sServ').textContent = t ? nf(t.service_charge) : '0';
  g('sTax').textContent = t ? nf(t.tax) : '0';
  g('sRound').textContent = t ? (Number(t.rounding) < 0 ? '−' : '') + nf(Math.abs(Number(t.rounding))) : '0';
  g('sTotal').textContent = t ? rp(t.total) : 'Rp 0';
  el('payBtn').disabled = !t;
  const p = S.catalog.outlet.pricing;
  el('lTax').textContent = p.tax_name + ' ' + Number(p.tax_rate) + '%';
  el('rServ').style.display = Number(p.service_charge_rate) > 0 ? 'flex' : 'none';
}
function renderCart(){
  const ql = (S.quote && S.quote.lines) || [];
  el('lines').innerHTML = S.cart.length ? S.cart.map((l, i) => {
    const q = ql.find(x => x.id === l.lineId);
    const sub = q ? q.gross : '';
    const mods = (q ? q.modifiers.map(m => m.name) : []).concat(q && q.variant ? [q.variant.name] : []);
    return `<div class="ln"><div class="info">
        <div class="nm">${esc(l.name)}</div>
        ${mods.length ? `<div class="mod">${esc(mods.join(' · '))}</div>` : ''}
        <div class="stp">
          <button onclick="chgQty(${i},-1)"><svg class="i sm"><use href="#ic-minus"/></svg></button>
          <span class="num">${l.qty}</span>
          <button onclick="chgQty(${i},1)"><svg class="i sm"><use href="#ic-plus"/></svg></button>
        </div></div>
      <div style="text-align:right;display:flex;flex-direction:column;align-items:flex-end;gap:10px">
        <span class="amt num">${sub ? nf(sub) : '…'}</span>
        <button class="del" onclick="delLine(${i})"><svg class="i sm"><use href="#ic-hapus"/></svg></button>
      </div></div>`;
  }).join('') : '<div class="blank">Belum ada item.<br>Pilih menu di sebelah kiri.</div>';
}

/* ---- kirim ke dapur ---- */
el('kitchenBtn').onclick = async () => {
  if (!S.cart.length) return;
  S.orderId = S.orderId || uuid();
  try {
    await pos('/pos/kitchen-tickets', { method: 'POST', body: {
      id: uuid(), order_id: S.orderId, shift_id: S.shift.id,
      lines: S.cart.map(l => ({ id: l.lineId, item_id: l.item_id, name: l.name, qty: String(l.qty),
        modifiers: l.modifiers.map(m => ({ id: m.id, qty: m.qty })) })),
    }});
    el('cartMeta').textContent = 'tiket dapur terkirim';
  } catch (e) { el('cartMeta').textContent = 'tiket dapur gagal: ' + e.message; }
};

/* ---------------- pembayaran ---------------- */
el('payBtn').onclick = () => {
  if (!S.quote) return;
  if (!S.shift || !S.shift.id) {
    alert('Belum ada shift terbuka. Buka menu Shift di kiri, lalu tekan "Buka shift".');
    return;
  }
  clearFail('payErr');
  S.intent = null;
  el('payTotal').textContent = rp(S.quote.totals.total);
  el('payCount').textContent = S.cart.reduce((s, l) => s + l.qty, 0);
  el('qrString').textContent = '—'; el('qrisState').textContent = ''; el('qrisSimulate').disabled = true;
  const t = Number(S.quote.totals.total);
  const opts = [...new Set([t, Math.ceil(t/50000)*50000, Math.ceil(t/100000)*100000, Math.ceil(t/100000)*100000 + 100000])];
  el('quick').innerHTML = opts.map((v, i) => `<button class="${i === 0 ? 'on' : ''}" data-v="${v}">${nf(v)}</button>`).join('');
  el('quick').querySelectorAll('button').forEach(b => b.onclick = () => {
    el('quick').querySelectorAll('button').forEach(x => x.classList.remove('on'));
    b.classList.add('on'); setGiven(Number(b.dataset.v));
  });
  setGiven(t);
  el('payModal').classList.add('on');
};
function setGiven(v){
  S.given = v;
  const t = Number(S.quote.totals.total);
  el('cashGiven').textContent = rp(v);
  el('cashBack').textContent = rp(Math.max(0, v - t));
}
document.querySelectorAll('#ways button').forEach(b => b.onclick = () => {
  document.querySelectorAll('#ways button').forEach(x => x.classList.remove('on'));
  b.classList.add('on'); S.pay = b.dataset.code;
  el('cashBox').style.display = S.pay === 'cash' ? 'block' : 'none';
  el('qrisBox').style.display = S.pay === 'qris' ? 'block' : 'none';
});
el('qrisCreate').onclick = async () => {
  clearFail('payErr');
  try {
    S.orderId = S.orderId || uuid();
    S.intent = await pos('/payments/qris', { method: 'POST', body: {
      order_ref: S.orderId, method: 'qris', amount: S.quote.totals.total } });
    el('qrString').textContent = S.intent.qr_string || '(kode QR diterima)';
    el('qrisState').textContent = 'menunggu pembayaran';
    el('qrisSimulate').disabled = false;
  } catch (e) { fail('payErr', e.message); }
};
el('qrisSimulate').onclick = async () => {
  try {
    S.intent = await pos('/payments/' + S.intent.id + '/simulate', { method: 'POST', body: { status: 'paid' } });
    el('qrisState').textContent = 'pembayaran diterima (' + S.intent.status + ')';
  } catch (e) { fail('payErr', e.message); }
};

/** Selaraskan penomoran struk dengan jumlah transaksi yang sudah ada di server. */
async function syncSequence(){
  if (!S.shift || !S.shift.id) return;
  try {
    const d = await pos('/pos/shifts/' + S.shift.id + '/report');
    const n = Number((d.report || {}).orders || 0);
    const k = LS.seq + '.' + S.shift.business_date;
    if (n > (Number(localStorage.getItem(k)) || 0)) localStorage.setItem(k, String(n));
  } catch (e) { /* biarkan memakai penghitung lokal */ }
}

function nextSeq(bd){
  const k = LS.seq + '.' + bd;
  const n = (Number(localStorage.getItem(k)) || 0) + 1;
  localStorage.setItem(k, String(n));
  return n;
}
function receiptNo(seq){
  const bd = S.shift.business_date.replaceAll('-', '').slice(2);
  return `${S.device.outlet.code}-${S.device.device.code}-${bd}-${String(seq).padStart(4, '0')}`;
}

el('payDone').onclick = async () => {
  if (!S.quote) return;
  if (S.pay === 'qris' && (!S.intent || S.intent.status !== 'paid')) {
    fail('payErr', 'Pembayaran QRIS belum masuk. Buat kode QR lalu tunggu/simulasikan pembayarannya.');
    return;
  }
  const btn = el('payDone'); btn.disabled = true; btn.textContent = 'Menyimpan…';
  const t = S.quote.totals;
  const pricing = Object.assign({}, S.catalog.outlet.pricing, { service_charge_applies: !!channelObj().service_charge_applies });
  const lines = S.quote.lines.map(l => ({
    id: l.id, item_id: l.item_id, variant_id: l.variant ? l.variant.id : null, name: l.name,
    qty: l.qty, unit_price: l.unit_price, note: l.note || null,
    modifiers: l.modifiers.map(m => ({ id: m.id, name: m.name, price: m.price, qty: m.qty })),
    bundle: (l.bundle || []).map(b => ({ option_id: b.option_id, name: b.name || null, extra_price: b.extra_price || '0.00' })),
    discounts: [],
  }));
  const TK = ['subtotal','item_discount','order_discount','service_charge','tax','rounding','total'];
  const totals = Object.fromEntries(TK.map(k => [k, t[k]]));
  const method = S.pay;
  const now = new Date().toISOString();
  S.orderId = S.orderId || uuid();
  let seq = nextSeq(S.shift.business_date), saved = null, lastErr = null;
  for (let i = 0; i < 40; i++) {
    const body = {
      id: S.orderId, shift_id: S.shift.id, receipt_no: receiptNo(seq), channel_code: S.channel,
      status: 'paid', created_at: now, completed_at: now, pricing, lines, totals,
      payments: [Object.assign({ id: uuid(), method, amount: t.total, created_at: now },
        method === 'cash' ? { tendered: Number(S.given).toFixed(2) } : {},
        method === 'qris' ? { payment_intent_id: S.intent.id } : {})],
    };
    try { saved = await pos('/pos/orders', { method: 'POST', body }); break; }
    catch (e) {
      lastErr = e;
      if (e.code === 'DUPLICATE_RECEIPT_NO') { seq = nextSeq(S.shift.business_date); continue; }
      break;
    }
  }
  btn.disabled = false; btn.innerHTML = '<svg class="i sm"><use href="#ic-cetak"/></svg>Selesaikan';
  if (!saved) { fail('payErr', lastErr ? lastErr.message : 'Transaksi gagal disimpan.'); return; }
  S.orders.unshift(saved);
  el('payModal').classList.remove('on');
  printReceipt(saved, method);
  renderOrders();
};

function printReceipt(o, method){
  const W = 40;
  const mid = s => ' '.repeat(Math.max(0, Math.floor((W - s.length) / 2))) + s;
  const row = (a, b) => a + ' '.repeat(Math.max(1, W - a.length - b.length)) + b;
  const rule = '-'.repeat(W);
  const label = { cash: 'Tunai', qris: 'QRIS', debit: 'Kartu Debit', credit: 'Kartu Kredit' }[method] || method;
  const tot = o.totals || {};
  let s = mid(S.device.outlet.name.toUpperCase()) + '\n';
  if (S.device.outlet.address) s += mid(String(S.device.outlet.address).slice(0, W)) + '\n';
  s += rule + '\n' + row(o.receipt_no, (S.catalog.channels.find(c => c.code === S.channel) || {}).name || '') + '\n';
  s += row(new Date().toLocaleString('id-ID', { dateStyle: 'short', timeStyle: 'short' }), 'Kasir: ' + S.pos.staff.name.split(' ')[0]) + '\n' + rule + '\n';
  (o.items || S.quote.lines).forEach(l => {
    s += (l.name || '') + '\n' + row('  ' + Number(l.qty) + ' x ' + nf(l.unit_price), nf(l.net || l.gross || (Number(l.qty) * Number(l.unit_price)))) + '\n';
  });
  s += rule + '\n' + row('Subtotal', nf(tot.subtotal ?? S.quote.totals.subtotal)) + '\n';
  if (Number(tot.service_charge ?? S.quote.totals.service_charge)) s += row('Service charge', nf(tot.service_charge ?? S.quote.totals.service_charge)) + '\n';
  s += row(S.catalog.outlet.pricing.tax_name, nf(tot.tax ?? S.quote.totals.tax)) + '\n';
  const rnd = Number(tot.rounding ?? S.quote.totals.rounding);
  if (rnd) s += row('Pembulatan', (rnd < 0 ? '-' : '') + nf(Math.abs(rnd))) + '\n';
  s += rule + '\n' + row('TOTAL', nf(tot.total ?? S.quote.totals.total)) + '\n';
  s += row(label, nf(method === 'cash' ? S.given : (tot.total ?? S.quote.totals.total))) + '\n';
  if (method === 'cash') s += row('Kembalian', nf(Math.max(0, S.given - Number(tot.total ?? S.quote.totals.total)))) + '\n';
  s += rule + '\n' + mid('Terima kasih atas kunjungan Anda') + '\n';
  el('rcpt').textContent = s;
  el('rcptNote').innerHTML = 'Tersimpan di server sebagai <b>' + esc(o.receipt_no) + '</b> — hari bisnis ' +
    esc(S.shift.business_date) + '. Sudah tampil di back-office (Penjualan &amp; Laporan).';
  el('rcptModal').classList.add('on');
}
el('newOrderBtn').onclick = () => {
  S.cart = []; S.quote = null; S.orderId = null; S.intent = null;
  el('rcptModal').classList.remove('on');
  el('cartMeta').textContent = 'nomor struk otomatis';
  refreshQuote();
};

/* ---------------- daftar transaksi + void ---------------- */
function renderOrders(){
  el('orderRows').innerHTML = S.orders.length ? S.orders.map((o, i) => `
    <tr><td>${esc(o.receipt_no)}</td><td>${fmtTime(o.completed_at || o.created_at)}</td>
      <td>${esc((S.catalog.channels.find(c => c.code === o.channel_code) || {}).name || o.channel_code || '')}</td>
      <td class="n">${nf((o.totals || {}).total || o.total)}</td>
      <td>${esc(o.status)}</td>
      <td>${o.status === 'paid' ? `<button class="btn" onclick="askVoid(${i})">Void</button>` : ''}</td></tr>`).join('')
    : '<tr><td colspan="6" style="color:var(--muted)">Belum ada transaksi.</td></tr>';
}
let voidTarget = null;
async function askVoid(i){
  voidTarget = S.orders[i];
  clearFail('authErr');
  el('authSub').textContent = 'Void struk ' + voidTarget.receipt_no + ' memerlukan persetujuan supervisor.';
  el('authPin').value = ''; el('authReason').value = '';
  try {
    const sup = (await pos('/pos/supervisors')).filter(s => !s.locked && (s.actions || []).includes('void'));
    el('authWho').innerHTML = sup.length
      ? sup.map(s => `<option value="${s.id}">${esc(s.name)}${DEMO_PINS[s.name] ? ' — PIN ' + DEMO_PINS[s.name] : ''}</option>`).join('')
      : '<option value="">(tidak ada supervisor berwenang)</option>';
  } catch (e) { fail('authErr', e.message); }
  el('authModal').classList.add('on');
}
el('authGo').onclick = async () => {
  clearFail('authErr');
  const reason = el('authReason').value.trim() || 'Void kasir';
  try {
    const auth = await pos('/pos/authorize', { method: 'POST', body: {
      action: 'void', supervisor_id: el('authWho').value, pin: el('authPin').value,
      reason, reference_type: 'order', reference_id: voidTarget.id } });
    const res = await pos('/pos/orders/' + voidTarget.id + '/void', { method: 'POST', body: {
      id: uuid(), reason, stock_action: 'waste',
      authorization: { mode: 'online', authorization_id: auth.authorization_id } } });
    const i = S.orders.findIndex(o => o.id === voidTarget.id);
    if (i >= 0) S.orders[i] = res;
    el('authModal').classList.remove('on');
    renderOrders();
  } catch (e) { fail('authErr', e.message); }
};

/* ---------------- shift & perangkat ---------------- */
async function renderShift(){
  const state = el('shiftState'), kpi = el('shiftKpis');
  el('openShiftHere').style.display = 'none';
  el('closeShiftHere').style.display = 'none';
  // Selalu ambil keadaan terbaru dari server, jangan percaya keadaan di layar.
  try {
    S.shift = await pos('/pos/shifts/current');
  } catch (e) {
    if (e.status === 401) { localStorage.removeItem(LS.pos); return startLogin(); }
    state.innerHTML = '<div class="err">Tidak dapat membaca shift: ' + esc(e.message) + '</div>';
    kpi.innerHTML = ''; return;
  }
  if (!S.shift || !S.shift.id) {
    state.innerHTML = '<div class="err">Belum ada shift terbuka di perangkat ini. Buka shift dulu sebelum menerima pembayaran.</div>';
    kpi.innerHTML = '';
    el('openShiftHere').style.display = 'inline-flex';
    return;
  }
  const today = todayHint();
  const bd = String(S.shift.business_date || '').slice(0, 10);
  const owner = S.shift.cashier_name || null;
  const notes = [];
  if (owner && S.pos.staff.name && owner !== S.pos.staff.name) {
    notes.push('Shift ini dibuka oleh <b>' + esc(owner) + '</b>, bukan oleh Anda. Transaksi tetap tercatat atas nama Anda sebagai kasir.');
  }
  if (today && bd && bd !== today) {
    notes.push('Shift ini untuk hari bisnis <b>' + esc(bd) + '</b>, bukan hari ini (' + esc(today) + '). Tutup shift lama dan buka yang baru agar penjualan masuk ke hari ini.');
  }
  state.innerHTML = notes.length ? '<div class="err">' + notes.join('<br>') + '</div>'
    : '<div class="ok">Shift terbuka · hari bisnis ' + esc(bd) + ' · dibuka ' + esc(fmtTime(S.shift.opened_at)) + '</div>';
  el('closeShiftHere').style.display = 'inline-flex';
  try {
    const d = await pos('/pos/shifts/' + S.shift.id + '/report');
    const r = d.report || {};
    const c = r.cash || {};
    el('shiftKpis').innerHTML = [
      ['Modal awal', rp(d.opening_cash), 'Dibuka ' + fmtTime(d.opened_at)],
      ['Penjualan tunai', rp(c.sales ?? 0), (r.orders ?? 0) + ' transaksi'],
      ['Kas seharusnya', rp(c.expected ?? 0), 'Modal + tunai − pengeluaran'],
      ['Total penjualan', rp((r.totals || {}).total ?? 0), 'Termasuk non-tunai'],
    ].map(([h, v, s]) => `<div class="kpi"><h4>${h}</h4><div class="v num">${v}</div><div class="s">${esc(s)}</div></div>`).join('');
  } catch (e) {
    kpi.innerHTML = '<div class="err">Laporan shift tidak dapat dibaca (' + esc(e.message) + ').<br>'
      + 'Shift ' + esc(S.shift.id) + ' mungkin milik perangkat lain. Tutup shift ini dari back-office, lalu buka shift baru di sini.</div>';
  }
}
el('refreshShift').onclick = renderShift;
el('openShiftHere').onclick = () => { clearFail('shiftErr'); show('scr-shift'); el('openingCash').focus(); };
el('closeShiftHere').onclick = async () => {
  clearFail('closeErr');
  let expected = '0';
  try { const d = await pos('/pos/shifts/' + S.shift.id + '/report'); expected = String(((d.report || {}).cash || {}).expected || '0'); }
  catch (e) {}
  el('closeExpected').value = rp(expected);
  el('closeCounted').value = String(Math.round(Number(expected)));
  el('closeNote').value = '';
  el('closeSub').textContent = 'Shift ' + (S.shift.business_date || '').slice(0, 10) + ' · hitung uang di laci, lalu masukkan jumlahnya.';
  el('closeModal').classList.add('on');
};
el('closeGo').onclick = async () => {
  clearFail('closeErr');
  const counted = (el('closeCounted').value || '0').replace(/[^\d]/g, '') + '.00';
  try {
    await pos('/pos/shifts/' + S.shift.id + '/close', { method: 'POST', body: {
      id: uuid(), counted_cash: counted, variance_note: el('closeNote').value || null } });
    el('closeModal').classList.remove('on');
    S.shift = null; S.cart = []; S.quote = null;
    await renderShift(); updateHeader(); renderCart(); renderTotals(null);
  } catch (e) { fail('closeErr', e.message); }
};
function todayHint(){
  try { return new Date().toISOString().slice(0, 10); } catch (e) { return null; }
}
function updateHeader(){
  el('hShift').textContent = S.shift && S.shift.id
    ? 'Shift dibuka ' + fmtTime(S.shift.opened_at)
    : 'Belum ada shift terbuka';
  el('hMeta').textContent = S.device.device.code + ' · hari bisnis ' + ((S.shift && S.shift.business_date) ? String(S.shift.business_date).slice(0, 10) : '-');
}
function renderDevice(){
  el('deviceRows').innerHTML = [
    ['Outlet', S.device.outlet.name + ' (' + S.device.outlet.code + ')'],
    ['Perangkat', S.device.device.code + ' · ' + (S.device.device.name || '')],
    ['Kasir', S.pos.staff.name + ' · ' + (S.pos.staff.roles || []).join(', ')],
    ['Sesi kasir berlaku sampai', fmtTime(S.pos.expires_at)],
    ['Hari bisnis', S.shift.business_date],
    ['Katalog ditarik', fmtTime(S.catalog.generated_at)],
    ['Pajak', S.catalog.outlet.pricing.tax_name + ' ' + Number(S.catalog.outlet.pricing.tax_rate) + '%'],
    ['Pembulatan', 'Rp ' + S.catalog.outlet.pricing.rounding_unit],
    ['Menu aktif', S.catalog.items.length + ' item'],
  ].map(([k, v]) => `<tr><td style="width:240px;color:var(--muted)">${esc(k)}</td><td>${esc(v)}</td></tr>`).join('');
}

/* ---------------- navigasi & utilitas ---------------- */
function goView(t){
  document.querySelectorAll('.rail button').forEach(x => x.classList.toggle('on', x.dataset.scr === t));
  el('view-kasir').style.display = t === 'kasir' ? 'flex' : 'none';
  el('cartPanel').style.display = t === 'kasir' ? 'flex' : 'none';
  ['pesanan','shift','atur'].forEach(s => el('view-' + s).classList.toggle('on', s === t));
  if (t === 'shift') renderShift();
  if (t === 'pesanan') renderOrders();
  if (t === 'atur') renderDevice();
}
document.querySelectorAll('.rail button').forEach(b => b.onclick = () => goView(b.dataset.scr));
document.querySelectorAll('[data-close]').forEach(b => b.onclick = () => b.closest('.ov').classList.remove('on'));
document.querySelectorAll('.ov').forEach(o => o.addEventListener('click', e => { if (e.target === o) o.classList.remove('on'); }));
document.addEventListener('keydown', e => { if (e.key === 'Escape') document.querySelectorAll('.ov.on').forEach(o => o.classList.remove('on')); });
el('infoBtn').onclick = () => el('infoModal').classList.add('on');

function fmtTime(v){ if (!v) return '-'; const d = new Date(v);
  return isNaN(d) ? String(v) : d.toLocaleString('id-ID', { dateStyle: 'short', timeStyle: 'short' }); }
const tick = () => el('clock').textContent = new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }).replace(':', '.');
tick(); setInterval(tick, 10000);
window.addEventListener('online', () => { el('hDot').className = 'dot'; el('hNet').textContent = 'Terhubung'; });
window.addEventListener('offline', () => { el('hDot').className = 'dot red'; el('hNet').textContent = 'Tidak terhubung'; });

/* ---------------- mulai ---------------- */
(async function boot(){
  const d = localStorage.getItem(LS.dev);
  if (!d) { show('scr-pair'); el('pairCode').focus(); return; }
  S.device = JSON.parse(d);
  const p = localStorage.getItem(LS.pos);
  if (p) {
    S.pos = JSON.parse(p);
    if (!S.pos.expires_at || new Date(S.pos.expires_at) > new Date()) {
      try { await afterLogin(); return; } catch (e) { localStorage.removeItem(LS.pos); }
    }
  }
  await startLogin();
})();
</script>
</body>
</html>
