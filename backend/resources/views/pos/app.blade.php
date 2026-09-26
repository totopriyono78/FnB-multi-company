<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title>Kasir — FnB Cloud</title>
<link rel="preconnect" href="https://fonts.bunny.net">
<link rel="stylesheet" href="https://fonts.bunny.net/css?family=montserrat:400,500,600,700&display=swap">
<style>
  /* Token gaya Vuexy (sama dengan back-office, resources/css/filament/admin/theme.css):
     latar #f8f8f8, kartu putih tanpa garis + bayangan lembut, menu aktif bergradien + pendar,
     huruf Montserrat. Aksen hijau daun FnB Cloud. */
  :root{
    --accent-rgb:28,114,79;
    --rail:#fff; --rail-ink:#625f6e;
    --bg:#f8f8f8; --surface:#fff; --line:#ebe9f1; --line-2:#d8d6de; --line-soft:#f3f2f7; --head:#f3f2f7;
    --ink:#5e5873; --ink-2:#625f6e; --muted:#6e6b7b;
    --accent:rgb(var(--accent-rgb)); --accent-hover:#18633f; --accent-soft:rgba(var(--accent-rgb),.12);
    --sel:var(--accent); --sel-soft:var(--accent-soft);
    --warn:#9e5a0c; --warn-soft:#fff5ec; --danger:#c42f30; --danger-soft:#fdeeee; --ok:#168045; --ok-soft:#eaf9f1;
    --r:5px; --r-lg:6px;
    --shadow:0 4px 24px 0 rgba(34,41,47,.1); --shadow-hover:0 4px 25px 0 rgba(34,41,47,.25);
    --shadow-menu:0 0 15px 0 rgba(34,41,47,.05); --shadow-float:0 5px 25px rgba(34,41,47,.1);
    --grad:linear-gradient(118deg,rgb(var(--accent-rgb)),rgba(var(--accent-rgb),.7));
    --glow:0 0 10px 1px rgba(var(--accent-rgb),.7); --glow-btn:0 8px 25px -8px rgb(var(--accent-rgb));
    --pill-glow:0 4px 18px -4px rgba(var(--accent-rgb),.65);
  }
  *{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
  html,body{margin:0;height:100%;overflow:hidden;background:var(--bg);color:var(--ink);
    font-family:Montserrat,Helvetica,Arial,sans-serif;font-size:14px;line-height:1.45}
  .num{font-variant-numeric:tabular-nums}
  svg.i{width:20px;height:20px;stroke:currentColor;fill:none;stroke-width:1.6;stroke-linecap:round;stroke-linejoin:round;flex:0 0 auto}
  svg.i.sm{width:16px;height:16px}
  button{font-family:inherit}

  /* ---- layar penuh (pairing / login / shift) ---- */
  .full{position:fixed;inset:0;display:none;align-items:center;justify-content:center;background:var(--bg);z-index:20;padding:24px}
  .full.on{display:flex}
  .panel{background:var(--surface);border:0;border-radius:var(--r-lg);box-shadow:var(--shadow);width:100%;max-width:440px;padding:28px}
  .panel.wide{max-width:760px}
  .panel h2{margin:0 0 4px;font-size:18px;font-weight:600;color:var(--ink)}
  .panel p.s{margin:0 0 18px;font-size:12.5px;color:var(--muted);line-height:1.6}
  .fld{margin-bottom:14px}
  .fld label{display:block;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:5px}
  .fld input{width:100%;padding:11px 12px;border:1px solid var(--line-2);border-radius:var(--r);font-size:15px;font-family:inherit;color:var(--ink-2);transition:box-shadow .25s,border-color .25s}
  .fld input:focus{outline:0;border-color:var(--accent);box-shadow:0 3px 10px 0 rgba(34,41,47,.1)}
  .fld input.code{letter-spacing:.35em;text-transform:uppercase;font-size:20px;text-align:center;font-weight:600}
  .btn{border:1px solid var(--line-2);background:var(--surface);color:var(--ink-2);border-radius:var(--r);padding:10px 14px;
    font-size:13px;font-weight:500;letter-spacing:.02em;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:7px;
    transition:box-shadow .2s,background-color .2s,color .2s}
  .btn:hover{background:var(--line-soft);color:var(--ink)}
  .btn.main{background:var(--accent);border-color:var(--accent);color:#fff}
  .btn.main:hover{background:var(--accent);box-shadow:var(--glow-btn)}
  .btn:focus-visible,.rail button:focus-visible,.tabs button:focus-visible,.card:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
  .btn.block{width:100%;padding:13px;font-size:15px}
  .btn:disabled{opacity:.45;cursor:not-allowed;box-shadow:none}
  .err{background:var(--danger-soft);border:0;color:var(--danger);font-size:12.5px;padding:9px 11px;border-radius:var(--r);margin-bottom:14px;line-height:1.5}
  .ok{background:var(--ok-soft);border:0;color:var(--ok);font-size:12.5px;padding:9px 11px;border-radius:var(--r);margin-bottom:14px;line-height:1.5}
  .staffgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:9px;margin-bottom:16px}
  .staffgrid button{border:1px solid var(--line);background:var(--surface);border-radius:var(--r);padding:12px 10px;text-align:left;cursor:pointer;color:var(--ink-2);font-family:inherit;transition:box-shadow .2s,border-color .2s}
  .staffgrid button:hover{box-shadow:var(--shadow)}
  .staffgrid button.on{border-color:var(--sel);background:var(--sel-soft)}
  .staffgrid button b{display:block;font-size:13px;font-weight:600;color:var(--ink)}
  .staffgrid button span{font-size:11px;color:var(--muted)}
  .staffgrid button .pin{display:inline-block;margin-top:5px;font-size:11px;font-weight:600;letter-spacing:.08em;
    background:var(--warn-soft);border:0;color:var(--warn);border-radius:999px;padding:1px 8px;font-variant-numeric:tabular-nums}
  .demohint{font-size:11.5px;color:var(--warn);background:var(--warn-soft);border:0;border-radius:var(--r);
    padding:7px 10px;margin-bottom:12px;line-height:1.5}
  .pinrow{display:flex;justify-content:center;gap:9px;margin:4px 0 16px}
  .pinrow i{width:14px;height:14px;border-radius:50%;border:1.5px solid var(--line-2);display:block}
  .pinrow i.f{background:var(--accent);border-color:var(--accent);box-shadow:0 0 6px rgba(var(--accent-rgb),.5)}
  .pad{display:grid;grid-template-columns:repeat(3,1fr);gap:9px}
  .pad button{padding:16px 0;font-size:19px;font-weight:600;border:1px solid var(--line);background:var(--surface);color:var(--ink);border-radius:var(--r);cursor:pointer;font-family:inherit}
  .pad button:hover{background:var(--accent-soft);border-color:var(--accent);color:var(--accent)}

  /* ---- layar kasir ---- */
  .app{display:none;height:100vh}
  .app.on{display:flex}
  .rail{width:88px;background:var(--rail);display:flex;flex-direction:column;flex:0 0 auto;box-shadow:var(--shadow-menu);position:relative;z-index:2}
  .rail .brand{height:64px;display:grid;place-items:center;color:var(--accent);font-size:12px;font-weight:700;letter-spacing:.14em}
  /* Menu ditempatkan di tengah tinggi rail agar jarak atas dan bawah seimbang. */
  .rail nav{flex:1;display:flex;flex-direction:column;justify-content:center;gap:2px;padding:10px 0}
  .rail .sep{height:1px;background:var(--line);margin:10px 14px}
  .rail button{width:calc(100% - 20px);margin:0 10px;border:0;border-radius:4px;background:transparent;color:var(--rail-ink);height:64px;display:flex;flex-direction:column;
    align-items:center;justify-content:center;gap:5px;font-size:10.5px;font-weight:500;font-family:inherit;cursor:pointer;position:relative;
    transition:color .2s,transform .25s}
  .rail button:hover{color:var(--accent);transform:translateX(3px)}
  .rail button.on,.rail button.on:hover{color:#fff;background:var(--grad);box-shadow:var(--glow);transform:none}
  .main{flex:1;display:flex;flex-direction:column;min-width:0}
  .top{height:60px;background:var(--surface);border-radius:var(--r-lg);box-shadow:var(--shadow);margin:12px 16px 0;display:flex;align-items:center;gap:16px;padding:0 18px;flex:0 0 auto}
  .top h1{margin:0;font-size:15px;font-weight:600;color:var(--ink)}
  .top .meta{font-size:11.5px;color:var(--muted)}
  .sep{width:1px;height:26px;background:var(--line)}
  .state{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--ink-2)}
  .dot{width:8px;height:8px;border-radius:50%;background:#28c76f;box-shadow:0 0 0 2px #fff,0 0 0 3px rgba(40,199,111,.35)}
  .dot.amber{background:#ff9f43;box-shadow:0 0 0 2px #fff,0 0 0 3px rgba(255,159,67,.35)}.dot.red{background:#ea5455;box-shadow:0 0 0 2px #fff,0 0 0 3px rgba(234,84,85,.35)}
  .top .right{margin-left:auto;display:flex;align-items:center;gap:12px}
  .who{text-align:right;line-height:1.25}.who b{font-size:12.5px;font-weight:600;color:var(--ink)}.who span{display:block;font-size:11px;color:var(--muted)}
  .clock{font-size:15px;font-weight:600;color:var(--ink)}
  .ico{width:34px;height:34px;border:0;border-radius:50%;background:transparent;color:var(--ink-2);display:grid;place-items:center;cursor:pointer;padding:0;transition:background-color .2s,color .2s}
  .ico:hover{background:var(--accent-soft);color:var(--accent)}
  .body{flex:1;display:flex;min-height:0}
  .left{flex:1;display:flex;flex-direction:column;min-width:0}
  .bar{background:transparent;padding:14px 16px 2px;display:flex;align-items:center;gap:20px;flex:0 0 auto}
  .tabs{display:flex;gap:6px;overflow-x:auto;padding:4px 4px 10px}
  .tabs button{border:0;background:none;padding:8px 14px;border-radius:var(--r);font-size:13px;font-weight:500;font-family:inherit;color:var(--ink-2);cursor:pointer;white-space:nowrap;transition:color .2s}
  .tabs button:hover{color:var(--accent)}
  .tabs button.on{color:#fff;background:var(--accent);box-shadow:var(--pill-glow);font-weight:600}
  .tabs button em{font-style:normal;color:var(--muted);font-weight:400;margin-left:5px;font-size:11.5px}
  .tabs button.on em{color:rgba(255,255,255,.85)}
  .find{margin-left:auto;position:relative;padding:0 0 8px}
  .find svg{position:absolute;left:11px;top:calc(50% - 4px);transform:translateY(-50%);color:var(--muted)}
  .find input{width:240px;padding:9px 12px 9px 34px;border:0;border-radius:var(--r);font-size:12.5px;font-family:inherit;background:var(--surface);box-shadow:var(--shadow);color:var(--ink-2)}
  .find input:focus{outline:2px solid var(--accent);outline-offset:0}
  .grid{flex:1;overflow-y:auto;padding:8px 16px 20px;display:grid;grid-template-columns:repeat(auto-fill,minmax(156px,1fr));gap:16px;align-content:start}
  .card{background:var(--surface);border:0;border-radius:var(--r-lg);box-shadow:var(--shadow);overflow:hidden;cursor:pointer;display:flex;flex-direction:column;text-align:left;padding:0;font-family:inherit;color:var(--ink-2);transition:box-shadow .25s,transform .25s}
  .card:hover{box-shadow:var(--shadow-hover);transform:translateY(-4px)}
  .ph{position:relative;aspect-ratio:4/3;background:var(--line-soft);overflow:hidden}
  .ph img{width:100%;height:100%;object-fit:cover;display:block}
  .ph .mono{position:absolute;inset:0;display:grid;place-items:center;font-size:26px;font-weight:600;color:rgba(var(--accent-rgb),.55);background:var(--accent-soft)}
  .ph .flag{position:absolute;left:8px;top:8px;background:var(--danger);color:#fff;font-size:9.5px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;padding:2px 8px;border-radius:999px}
  .card .txt{padding:10px 12px 12px}
  .card .nm{font-size:12.5px;font-weight:500;line-height:1.3;min-height:33px;color:var(--ink)}
  .card .row{display:flex;align-items:baseline;justify-content:space-between;margin-top:5px;gap:6px}
  .card .pc{font-size:13.5px;font-weight:600;color:var(--accent)}
  .card .lbl{font-size:10px;color:var(--warn);text-transform:uppercase;letter-spacing:.05em}
  .card.off{cursor:default}.card.off .ph img,.card.off .ph .mono{filter:grayscale(1);opacity:.5}.card.off .nm,.card.off .pc{color:var(--muted)}
  .cart{width:352px;flex:0 0 auto;background:var(--surface);border-radius:var(--r-lg);box-shadow:var(--shadow);margin:16px 16px 16px 0;display:flex;flex-direction:column;overflow:hidden}
  .cart .head{padding:14px 16px;border-bottom:1px solid var(--line);display:flex;align-items:baseline;justify-content:space-between}
  .cart .head b{font-size:14px;font-weight:600;color:var(--ink)}.cart .head span{font-size:11.5px;color:var(--muted)}
  .seg{display:flex;gap:4px;margin:12px 14px 4px;padding:3px;background:var(--line-soft);border-radius:var(--r)}
  .seg button{flex:1;border:0;border-radius:4px;background:transparent;padding:7px 4px;font-size:11.5px;font-family:inherit;font-weight:500;color:var(--ink-2);cursor:pointer}
  .seg button.on{background:var(--accent);color:#fff;font-weight:600;box-shadow:var(--pill-glow)}
  .lines{flex:1;overflow-y:auto;padding:6px 14px}
  .ln{display:flex;gap:10px;padding:10px 0;border-bottom:1px solid var(--line-soft)}
  .ln .info{flex:1;min-width:0}.ln .nm{font-size:12.5px;font-weight:500;color:var(--ink)}
  .ln .mod{font-size:11px;color:var(--muted);margin-top:1px}
  .ln .amt{font-size:12.5px;font-weight:600;white-space:nowrap;color:var(--ink)}
  .stp{display:inline-flex;align-items:center;gap:2px;background:var(--line-soft);border-radius:var(--r);margin-top:7px;padding:2px}
  .stp button{width:26px;height:24px;border:0;border-radius:4px;background:var(--accent);color:#fff;display:grid;place-items:center;cursor:pointer}
  .stp span{min-width:28px;text-align:center;font-size:12.5px;font-weight:600;color:var(--ink);height:24px;line-height:24px}
  .ln .del{border:0;background:none;color:var(--muted);cursor:pointer;padding:0;height:18px}
  .ln .del:hover{color:var(--danger)}
  .blank{padding:44px 16px;text-align:center;color:var(--muted);font-size:12.5px;line-height:1.7}
  .sum{border-top:1px solid var(--line);padding:4px 14px 0;font-size:12.5px}
  .sum .r{display:flex;justify-content:space-between;padding:2.5px 0;color:var(--ink-2)}
  /* Rincian subtotal s.d. pembulatan dilipat bawaan agar daftar pesanan lebih lega. */
  .sum .detail{display:none;padding:6px 0 2px;border-bottom:1px dashed var(--line-2)}
  .sum.open .detail{display:block}
  .sum .tot{display:flex;align-items:center;gap:8px;width:100%;border:0;background:none;padding:9px 0 8px;cursor:pointer;
    font-family:inherit;color:var(--ink);text-align:left;border-radius:var(--r)}
  .sum .tot:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
  .sum .tot .lbl{font-size:17px;font-weight:700}
  .sum .tot .more{display:inline-flex;align-items:center;gap:3px;font-size:11.5px;font-weight:500;color:var(--muted)}
  .sum .tot .more svg{width:14px;height:14px;transition:transform .2s}
  .sum.open .tot .more svg{transform:rotate(180deg)}
  .sum .tot:hover .more{color:var(--accent)}
  .sum .tot .hint{font-size:11px;font-weight:500;color:var(--ok);background:var(--ok-soft);border-radius:999px;padding:1px 8px}
  .sum .tot .val{margin-left:auto;font-size:17px;font-weight:700}
  .acts{padding:4px 14px 14px;display:grid;grid-template-columns:repeat(3,1fr);gap:6px}
  .acts .btn{padding:9px 4px;font-size:12px;gap:5px;min-width:0;white-space:nowrap}
  .acts .btn.main{grid-column:1/-1;font-size:15px;padding:13px;margin-top:2px}
  .scr{display:none;flex:1;overflow:auto;padding:18px}.scr.on{display:block}
  .scr h2{margin:0 0 3px;font-size:17px;font-weight:600;color:var(--ink)}.scr p.d{margin:0 0 14px;font-size:12.5px;color:var(--muted)}
  table.t{width:100%;border-collapse:separate;border-spacing:0;font-size:12.5px;background:var(--surface);border:0;border-radius:var(--r-lg);box-shadow:var(--shadow);overflow:hidden}
  table.t th,table.t td{padding:11px 18px;text-align:left;border-bottom:1px solid var(--line)}
  table.t tr:last-child td{border-bottom:0}
  table.t th{font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:700;background:var(--head);border-bottom:0}
  table.t tbody tr:hover td{background:#fafafc}
  table.t td.n,table.t th.n{text-align:right;font-variant-numeric:tabular-nums}
  .kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:16px;margin-bottom:20px}
  .kpi{background:var(--surface);border:0;border-radius:var(--r-lg);box-shadow:var(--shadow);padding:18px 20px;border-left:3px solid var(--accent)}
  .kpi h4{margin:0 0 6px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)}
  .kpi .v{font-size:21px;font-weight:600;color:var(--ink)}.kpi .s{font-size:11px;color:var(--muted);margin-top:2px}

  .ov{position:fixed;inset:0;background:rgba(34,41,47,.5);display:none;align-items:center;justify-content:center;padding:22px;z-index:50}
  .ov.on{display:flex}
  .box{background:var(--surface);border-radius:var(--r-lg);width:100%;max-width:520px;max-height:92vh;overflow:auto;box-shadow:var(--shadow-float)}
  .box header{padding:16px 20px;background:var(--line-soft);border-bottom:0}
  .box header h3{margin:0;font-size:15px;font-weight:600;color:var(--ink)}
  .box header p{margin:3px 0 0;font-size:12px;color:var(--muted)}
  .box .in{padding:16px 18px}
  .box footer{padding:12px 18px 16px;display:grid;grid-template-columns:1fr 1fr;gap:9px}
  .ways{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:14px}
  .ways button{border:1px solid var(--line-2);background:var(--surface);font-family:inherit;border-radius:var(--r);padding:12px 6px;font-size:12px;font-weight:600;cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:6px;color:var(--ink-2)}
  .ways button.on{border-color:var(--accent);color:var(--accent);background:var(--accent-soft);box-shadow:inset 0 0 0 1px var(--accent)}
  .quick{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:12px}
  .quick button{border:1px solid var(--line-2);background:var(--surface);color:var(--ink-2);font-family:inherit;border-radius:var(--r);padding:10px 4px;font-size:12px;font-weight:600;cursor:pointer;font-variant-numeric:tabular-nums}
  .quick button.on{border-color:var(--sel);background:var(--sel-soft);color:var(--accent)}
  .paid{display:flex;justify-content:space-between;font-size:13px;padding:9px 11px;background:var(--line-soft);border-radius:var(--r)}
  .slip{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:11.5px;background:var(--bg);border:1px solid var(--line);border-radius:var(--r);padding:14px;white-space:pre;line-height:1.55;overflow-x:auto;color:#2f2b3d}
  .qr{font-family:ui-monospace,Menlo,monospace;font-size:10.5px;word-break:break-all;background:var(--bg);border:1px solid var(--line);padding:10px;border-radius:var(--r);margin-bottom:10px}
  .opt{display:flex;flex-wrap:wrap;gap:7px;margin-bottom:14px}
  .opt button{border:1px solid var(--line-2);background:var(--surface);color:var(--ink-2);border-radius:var(--r);padding:8px 12px;font-size:12.5px;font-family:inherit;cursor:pointer}
  .opt button.on{border-color:var(--sel);background:var(--sel-soft);color:var(--accent);font-weight:600}
  .gh{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin:0 0 6px}
  /* baris nomor meja di panel pesanan (hanya untuk makan di tempat) */
  .tablebar{display:flex;align-items:center;gap:8px;width:100%;margin:0 0 10px;padding:9px 11px;font-size:13px;
    border:1px solid var(--line-2);border-radius:var(--r);background:var(--surface);cursor:pointer;text-align:left;color:var(--ink);font-family:inherit}
  .tablebar:hover{border-color:var(--sel);background:var(--sel-soft)}
  .tablebar.empty{border-style:dashed;color:var(--muted)}
  .tablebar b{font-weight:600}
  .barrow{display:flex;gap:7px;margin:8px 14px 0}
  .barrow .tablebar{flex:1;min-width:0}
  .barrow .tablebar span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .stp .wgt{width:auto;padding:0 10px;font-size:12px;font-weight:600;font-variant-numeric:tabular-nums;white-space:nowrap}
  .tables{display:grid;grid-template-columns:repeat(6,1fr);gap:7px;margin-bottom:12px;max-height:240px;overflow:auto}
  .tables button{border:1px solid var(--line-2);background:var(--surface);color:var(--ink);border-radius:var(--r);padding:11px 4px;font-size:13.5px;
    font-weight:600;font-family:inherit;cursor:pointer;font-variant-numeric:tabular-nums}
  .tables button:hover{border-color:var(--sel);background:var(--sel-soft)}
  .tables button.on{border-color:var(--accent);background:var(--accent);color:#fff;box-shadow:var(--pill-glow)}
  @media (max-width:1080px){.cart{width:310px}.find input{width:150px}.acts .btn svg{display:none}}

  /* ---- cetak ke printer struk (58 mm / 80 mm) ----
     Halaman kasir disembunyikan saat mencetak; hanya #printSlip yang keluar.
     Lebar kertas diatur lewat variabel --paper dari menu Atur. */
  #printSlip{display:none}
  @media print{
    @page{margin:0}
    html,body{background:#fff;margin:0;padding:0}
    body>*{display:none !important}
    body>#printSlip{
      display:block !important;
      width:var(--paper,72mm);
      margin:0;padding:2mm 0 8mm;
      font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
      font-size:var(--paperFont,11pt);line-height:1.35;
      white-space:pre;color:#000;
    }
    /* Logo struk: dibatasi lebarnya agar muat di kertas 58 mm sekalipun. */
    body>#printSlip .slipLogo{
      display:block;margin:0 auto 2mm;max-width:60%;max-height:20mm;
      object-fit:contain;filter:grayscale(1) contrast(1.4);
    }
  }
</style>
</head>
<body>

<svg width="0" height="0" style="position:absolute" aria-hidden="true">
  <symbol id="ic-kasir" viewBox="0 0 24 24"><path d="M6 2.8h12v18.4l-2.4-1.6-2.4 1.6-2.4-1.6-2.4 1.6L6 19.6z"/><path d="M9 7.5h6M9 11h6M9 14.5h4"/></symbol>
  <symbol id="ic-pesanan" viewBox="0 0 24 24"><rect x="5" y="4.2" width="14" height="16.6" rx="1.6"/><path d="M9.2 4.2V2.9h5.6v1.3"/><path d="M8.6 9.4h6.8M8.6 13h6.8M8.6 16.6h4.2"/></symbol>
  <symbol id="ic-shift" viewBox="0 0 24 24"><rect x="2.8" y="6" width="18.4" height="12.6" rx="2"/><path d="M2.8 10.2h18.4"/><circle cx="16.6" cy="14.6" r="1.5"/></symbol>
  <symbol id="ic-atur" viewBox="0 0 24 24"><path d="M3.6 7.4h8.2M15.4 7.4h5M3.6 16.6h5M12.2 16.6h8.2M3.6 12h3M10 12h10.4"/><circle cx="13.6" cy="7.4" r="2"/><circle cx="10.2" cy="16.6" r="2"/><circle cx="8.2" cy="12" r="2"/></symbol>
  <symbol id="ic-cari" viewBox="0 0 24 24"><circle cx="10.8" cy="10.8" r="6.4"/><path d="m15.6 15.6 4.4 4.4"/></symbol>
  <symbol id="ic-bill" viewBox="0 0 24 24"><path d="M6 3.4h12v17.2l-2.4-1.4-2.4 1.4-2.4-1.4-2.4 1.4-2.4-1.4z"/><path d="M9.2 8.4h5.6M9.2 12.4h5.6"/></symbol>
  <symbol id="ic-tamu" viewBox="0 0 24 24"><circle cx="12" cy="8.4" r="3.6"/><path d="M4.8 20.2a7.2 7.2 0 0 1 14.4 0"/></symbol>
  <symbol id="ic-meja" viewBox="0 0 24 24"><path d="M3 8.6h18M6.4 8.6 5 19.4M17.6 8.6 19 19.4"/><path d="M7.2 4.6h9.6a1.4 1.4 0 0 1 1.4 1.4v2.6H5.8V6a1.4 1.4 0 0 1 1.4-1.4z"/></symbol>
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
      <button data-scr="bill"><svg class="i"><use href="#ic-bill"/></svg>Bill<em id="billBadge" style="display:none"></em></button>
      <button data-scr="shift"><svg class="i"><use href="#ic-shift"/></svg>Shift</button>
      <div class="sep"></div>
      <button data-scr="atur"><svg class="i"><use href="#ic-atur"/></svg>Atur</button>
    </nav>
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
          <button class="btn" id="cashInBtn">Kas masuk</button>
          <button class="btn" id="cashOutBtn">Kas keluar</button>
          <button class="btn" id="openShiftHere" style="display:none">Buka shift</button>
          <button class="btn" id="closeShiftHere" style="display:none">Tutup shift</button>
        </div>
      </div>

      <div class="scr" id="view-bill">
        <h2>Bill tersimpan</h2>
        <p class="d">Tagihan tamu yang belum membayar. Tersimpan di server, jadi bisa dibuka dari
          perangkat kasir mana pun di outlet ini.</p>
        <div id="billErr"></div>
        <div style="margin-bottom:12px"><button class="btn" id="refreshBills">Muat ulang</button></div>
        <table class="t"><thead><tr><th>Bill</th><th>Tamu</th><th class="n">Item</th><th class="n">Perkiraan</th><th>Dibuka</th><th></th></tr></thead>
          <tbody id="billRows"></tbody></table>
      </div>

      <div class="scr" id="view-atur">
        <h2>Perangkat</h2>
        <p class="d">Informasi perangkat dan katalog yang sedang dipakai.</p>
        <table class="t"><tbody id="deviceRows"></tbody></table>
        <div style="margin-top:14px;display:flex;gap:9px">
          <button class="btn" id="reloadCatalog">Tarik ulang katalog</button>
          <button class="btn" id="unpairBtn2">Lepas perangkat</button>
        </div>

        <h2 style="margin-top:22px">Menu habis</h2>
        <p class="d">Menandai menu habis membuatnya langsung tidak bisa dipesan di layar kasir dan di aplikasi
          pemesanan lain. Berlaku untuk outlet ini saja.</p>
        <div class="fld" style="max-width:280px"><label for="soldSearch">Cari menu</label>
          <input id="soldSearch" autocomplete="off" placeholder="ketik nama menu"></div>
        <table class="t"><tbody id="soldRows"></tbody></table>

        <h2 style="margin-top:22px">Printer struk</h2>
        <p class="d">Struk dicetak lewat printer yang terpasang di komputer ini. Pilih lebar kertas sesuai printer,
          lalu tekan Uji cetak. Di dialog cetak Windows, pilih printer struk dan atur margin ke <b>None</b>.</p>
        <p class="gh">Lebar kertas</p>
        <div class="opt" id="paperOpt">
          <button data-w="80">80 mm</button>
          <button data-w="58">58 mm</button>
        </div>
        <label class="chk" style="display:flex;align-items:center;gap:8px;font-size:13px;margin-bottom:12px">
          <input type="checkbox" id="autoPrint"> Langsung buka dialog cetak setelah transaksi selesai
        </label>
        <label class="chk" style="display:flex;align-items:center;gap:8px;font-size:13px;margin-bottom:14px">
          <input type="checkbox" id="autoKitchen"> Cetak tiket dapur saat menekan "Ke dapur"
        </label>
        <div style="display:flex;gap:9px">
          <button class="btn" id="testPrint">Uji cetak</button>
        </div>
      </div>

      <aside class="cart" id="cartPanel">
        <div class="head"><b>Pesanan baru</b><span id="cartMeta">nomor struk otomatis</span></div>
        <div class="seg" id="seg"></div>
        <div class="barrow">
          <button class="tablebar" id="tableBar" style="display:none">
            <svg class="i sm"><use href="#ic-meja"/></svg>
            <span id="tableText">Pilih meja</span>
          </button>
          <button class="tablebar empty" id="detailBar">
            <svg class="i sm"><use href="#ic-tamu"/></svg>
            <span id="detailText">Tamu &amp; catatan</span>
          </button>
        </div>
        <div class="lines" id="lines"></div>
        <div class="sum" id="sumBox">
          <div class="detail" id="sumDetail">
            <div class="r"><span>Subtotal</span><span class="num" id="sSub">0</span></div>
            <div class="r"><span>Diskon</span><span class="num" id="sDisc">0</span></div>
            <div class="r" id="rServ"><span>Service charge</span><span class="num" id="sServ">0</span></div>
            <div class="r"><span id="lTax">Pajak</span><span class="num" id="sTax">0</span></div>
            <div class="r"><span>Pembulatan</span><span class="num" id="sRound">0</span></div>
          </div>
          <button type="button" class="tot" id="sumToggle" aria-expanded="false" aria-controls="sumDetail">
            <span class="lbl">Total</span>
            <span class="more"><span id="sumMoreText">Rincian</span><svg class="i" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></svg></span>
            <span class="hint" id="sHint" hidden></span>
            <span class="val num" id="sTotal">Rp 0</span>
          </button>
        </div>
        <div class="acts">
          <button class="btn" id="clearBtn"><svg class="i sm"><use href="#ic-hapus"/></svg>Kosongkan</button>
          <button class="btn" id="kitchenBtn"><svg class="i sm"><use href="#ic-dapur"/></svg>Ke dapur</button>
          <button class="btn" id="parkBtn"><svg class="i sm"><use href="#ic-bill"/></svg>Simpan bill</button>
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
      <div class="fld"><label for="cashInput">Nominal diterima (Rp)</label>
        <input id="cashInput" class="num" inputmode="numeric" placeholder="0"></div>
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
    <div id="splitBox" style="display:none;margin-top:12px">
      <p class="gh">Pembayaran gabungan</p>
      <table class="t"><tbody id="splitRows"></tbody></table>
      <div class="paid" style="margin-top:8px"><span>Sisa tagihan</span><b class="num" id="splitLeft">Rp 0</b></div>
    </div>
  </div>
  <footer style="grid-template-columns:auto auto 1fr">
    <button class="btn" data-close>Batal</button>
    <button class="btn" id="paySplit">Bayar sebagian</button>
    <button class="btn main" style="grid-column:auto" id="payDone"><svg class="i sm"><use href="#ic-cetak"/></svg>Selesaikan</button>
  </footer>
</div></div>

<!-- modal: detail pesanan -->
<div class="ov" id="detailModal"><div class="box" style="max-width:460px">
  <header><h3>Tamu &amp; catatan</h3><p>Ikut tercetak di tiket dapur dan struk.</p></header>
  <div class="in">
    <div class="fld"><label for="custName">Nama tamu</label>
      <input id="custName" maxlength="80" autocomplete="off" placeholder="mis. Ibu Sari"></div>
    <div class="fld"><label for="queueNo">Nomor antrean</label>
      <input id="queueNo" class="num" inputmode="numeric" maxlength="5" placeholder="mis. 27"></div>
    <div class="fld"><label for="orderNote">Catatan pesanan</label>
      <input id="orderNote" maxlength="300" autocomplete="off" placeholder="mis. bungkus terpisah"></div>
  </div>
  <footer style="grid-template-columns:auto auto 1fr">
    <button class="btn" data-close>Batal</button>
    <button class="btn" id="detailClear">Kosongkan</button>
    <button class="btn main" style="grid-column:auto" id="detailSave">Simpan</button>
  </footer>
</div></div>

<!-- modal: retur / refund -->
<div class="ov" id="refundModal"><div class="box" style="max-width:560px">
  <header><h3>Retur transaksi</h3><p id="refundSub">Pilih barang yang dikembalikan.</p></header>
  <div class="in">
    <div id="refundErr"></div>
    <table class="t"><thead><tr><th>Item</th><th class="n">Dibeli</th><th class="n">Diretur</th></tr></thead>
      <tbody id="refundRows"></tbody></table>
    <div class="paid" style="margin:10px 0"><span>Perkiraan nilai retur</span><b class="num" id="refundAmount">Rp 0</b></div>
    <p class="gh">Barang yang diretur</p>
    <div class="opt" id="refundStock">
      <button class="on" data-v="return">Kembali ke stok</button>
      <button data-v="waste">Rusak / dibuang</button>
    </div>
    <p class="gh">Dikembalikan lewat</p>
    <div class="opt" id="refundMethod"></div>
    <div class="fld"><label for="refundReason">Alasan (wajib)</label>
      <input id="refundReason" maxlength="300" autocomplete="off" placeholder="mis. pesanan salah"></div>
  </div>
  <footer style="grid-template-columns:auto auto 1fr">
    <button class="btn" data-close>Batal</button>
    <button class="btn" id="refundAll">Retur seluruhnya</button>
    <button class="btn main" style="grid-column:auto" id="refundGo">Proses retur</button>
  </footer>
</div></div>

<!-- modal: kas masuk / keluar -->
<div class="ov" id="cashModal"><div class="box" style="max-width:440px">
  <header><h3 id="cashTitle">Kas masuk</h3><p id="cashSub">Dicatat pada shift yang sedang berjalan.</p></header>
  <div class="in">
    <div id="cashErr"></div>
    <div class="fld"><label for="cashAmount">Nominal (Rp)</label>
      <input id="cashAmount" class="num" inputmode="numeric" placeholder="0"></div>
    <div class="fld"><label for="cashReason">Keterangan</label>
      <input id="cashReason" maxlength="200" autocomplete="off" placeholder="mis. setoran ke brankas"></div>
  </div>
  <footer><button class="btn" data-close>Batal</button>
    <button class="btn main" style="grid-column:auto" id="cashGo">Simpan</button></footer>
</div></div>

<!-- modal: berat barang timbangan -->
<div class="ov" id="weightModal"><div class="box" style="max-width:420px">
  <header><h3 id="weightTitle">Berat</h3><p id="weightSub">Masukkan berat hasil timbangan.</p></header>
  <div class="in">
    <div id="weightErr"></div>
    <div class="fld"><label for="weightInput">Berat (<span id="weightUnit">kg</span>)</label>
      <input id="weightInput" class="num" inputmode="decimal" autocomplete="off" placeholder="mis. 1,35"></div>
    <div class="paid"><span>Harga satuan <b class="num" id="weightPrice">Rp 0</b></span><span>Perkiraan <b class="num" id="weightTotal">Rp 0</b></span></div>
  </div>
  <footer><button class="btn" data-close>Batal</button>
    <button class="btn main" style="grid-column:auto" id="weightSave">Simpan</button></footer>
</div></div>

<!-- modal: simpan bill -->
<div class="ov" id="parkModal"><div class="box" style="max-width:440px">
  <header><h3>Simpan bill</h3><p>Tagihan disimpan tanpa dibayar. Tamu bisa lanjut makan, kasir bisa melayani tamu lain.</p></header>
  <div class="in">
    <div id="parkErr"></div>
    <div class="fld"><label for="parkLabel">Nama bill / meja</label>
      <input id="parkLabel" maxlength="40" autocomplete="off" placeholder="mis. Meja 7 — Pak Budi"></div>
  </div>
  <footer><button class="btn" data-close>Batal</button>
    <button class="btn main" style="grid-column:auto" id="parkGo">Simpan bill</button></footer>
</div></div>

<!-- modal: nomor meja -->
<div class="ov" id="tableModal"><div class="box" style="max-width:470px">
  <header><h3>Nomor meja</h3><p id="tableSub">Pilih meja tempat tamu duduk.</p></header>
  <div class="in">
    <div class="tables" id="tableGrid"></div>
    <div class="fld"><label for="tableFree">Atau tulis sendiri</label>
      <input id="tableFree" maxlength="30" autocomplete="off" placeholder="mis. Teras 2"></div>
  </div>
  <footer style="grid-template-columns:auto auto 1fr">
    <button class="btn" data-close>Batal</button>
    <button class="btn" id="tableClear">Kosongkan</button>
    <button class="btn main" style="grid-column:auto" id="tableSave">Simpan</button>
  </footer>
</div></div>

<!-- modal: struk -->
<div class="ov" id="rcptModal"><div class="box">
  <header><h3>Struk</h3><p id="rcptHead">Transaksi sudah tersimpan di server.</p></header>
  <div class="in"><div id="rcptNote" class="ok"></div><div class="slip" id="rcpt"></div></div>
  <footer style="grid-template-columns:auto auto 1fr">
    <button class="btn" data-close>Tutup</button>
    <button class="btn" id="printBtn">Cetak struk</button>
    <button class="btn main" style="grid-column:auto" id="newOrderBtn">Transaksi baru</button>
  </footer>
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
    <div class="fld"><label>Supervisor</label><select id="authWho" style="width:100%;padding:10px;border:1px solid var(--line-2);border-radius:var(--r);font-family:inherit;color:var(--ink-2)"></select></div>
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
const LS = { dev: 'fnb.pos.device', pos: 'fnb.pos.session', seq: 'fnb.pos.seq', print: 'fnb.pos.print' };
const DEMO_PINS = @json($demoPins ?? []);
const S = { device: null, pos: null, catalog: null, shift: null, cart: [], channel: 'dine_in',
            quote: null, orders: [], pay: 'cash', given: 0, intent: null, pendingItem: null,
            lastOrder: null, table: '', guest: '', queue: '', orderNote: '',
            pays: [], refundTarget: null, cashType: 'in',
            billId: null, billLabel: '', bills: [], pendingWeight: null };

const nf = n => Math.round(Number(n) || 0).toLocaleString('id-ID');
/** Jumlah baris: bilangan bulat tanpa desimal, berat sampai 3 desimal tanpa nol ekor. */
const fmtQty = q => Number(q).toLocaleString('id-ID', { maximumFractionDigits: 3 });
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
    // Petunjuk hanya ditampilkan bila PIN benar-benar muncul di daftar ini. Sebelumnya cukup
    // "ada PIN demo di sistem", sehingga di outlet yang stafnya tidak terdaftar petunjuknya
    // tampil tanpa satu pun PIN — menyesatkan.
    const adaPin = staff.some(s => DEMO_PINS[s.name]);
    el('demoHint').innerHTML = adaPin
      ? 'Mode demo aktif: PIN ditampilkan di bawah nama agar peragaan lancar. Matikan dengan <b>FNB_DEMO_LOGIN=false</b> di .env.'
      : '';
    el('demoHint').style.display = adaPin ? 'block' : 'none';
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
  renderTableBar();
  renderDetailBar();
}

/* ---------------- nomor meja (FR-POS) ----------------
   Hanya untuk channel makan di tempat. Disimpan sebagai teks pada transaksi
   (kolom table_label), lalu tercetak di tiket dapur dan struk pelanggan.
   Tombol pintas 1..N dibuat dari pengaturan "Jumlah meja" milik outlet agar
   penulisannya seragam; kasir tetap boleh menulis sendiri (mis. "Teras 2"). */
function perluMeja(){ return S.channel === 'dine_in'; }

function renderTableBar(){
  const bar = el('tableBar');
  if (!perluMeja()) { bar.style.display = 'none'; return; }
  bar.style.display = 'flex';
  bar.classList.toggle('empty', !S.table);
  el('tableText').innerHTML = S.table ? 'Meja <b>' + esc(S.table) + '</b>' : 'Pilih meja';
}

function openTableModal(alasan){
  const n = Number((S.catalog.outlet || {}).table_count || 0);
  el('tableSub').textContent = alasan || 'Pilih meja tempat tamu duduk.';
  el('tableGrid').innerHTML = Array.from({ length: n }, (_, i) => i + 1)
    .map(i => `<button data-t="${i}" class="${String(i) === S.table ? 'on' : ''}">${i}</button>`).join('');
  document.querySelectorAll('#tableGrid button').forEach(b => b.onclick = () => simpanMeja(b.dataset.t));
  el('tableFree').value = /^\d+$/.test(S.table) ? '' : S.table;
  el('tableModal').classList.add('on');
  if (!n) el('tableFree').focus();
}
function simpanMeja(v){
  S.table = String(v || '').trim().slice(0, 30);
  el('tableModal').classList.remove('on');
  renderTableBar();
}
el('cashInBtn').onclick = () => askCash('in');
el('cashOutBtn').onclick = () => askCash('out');
el('tableBar').onclick = () => openTableModal();

/* ---------------- tamu, nomor antrean, catatan pesanan ---------------- */
function renderDetailBar(){
  const isi = [S.guest, S.queue ? 'antrean ' + S.queue : '', S.orderNote].filter(Boolean).join(' · ');
  el('detailBar').classList.toggle('empty', !isi);
  el('detailText').textContent = isi || 'Tamu & catatan';
}
el('detailBar').onclick = () => {
  el('custName').value = S.guest;
  el('queueNo').value = S.queue;
  el('orderNote').value = S.orderNote;
  el('detailModal').classList.add('on');
};
el('detailSave').onclick = () => {
  S.guest = el('custName').value.trim().slice(0, 80);
  S.queue = String(el('queueNo').value).replace(/[^\d]/g, '').slice(0, 5);
  S.orderNote = el('orderNote').value.trim().slice(0, 300);
  el('detailModal').classList.remove('on');
  renderDetailBar();
};
el('detailClear').onclick = () => {
  S.guest = ''; S.queue = ''; S.orderNote = '';
  el('detailModal').classList.remove('on');
  renderDetailBar();
};
el('tableSave').onclick = () => simpanMeja(el('tableFree').value);
el('tableClear').onclick = () => simpanMeja('');
el('tableFree').addEventListener('keydown', e => { if (e.key === 'Enter') simpanMeja(e.target.value); });
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
    // Server yang menyusun URL-nya (image_url); image_path hanya jalur internal.
    const img = i.image_url ? `<img src="${esc(i.image_url)}" alt="" loading="lazy">` : `<span class="mono">${initials || '#'}</span>`;
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
function addLine(item, sel, qty){
  const mods = [];
  Object.entries(sel.mods || {}).forEach(([gid, ids]) => ids.forEach(id => mods.push({ id, qty: 1 })));
  const bundle = Object.entries(sel.bundle || {}).map(([gid, ids]) => ({ group_id: gid, options: ids.map(o => ({ option_id: o })) }));
  const baris = { lineId: uuid(), item_id: item.id, name: item.name, variant_id: sel.variant || null,
                  modifiers: mods, bundle, qty: qty || 1,
                  byWeight: !!item.sold_by_weight, unit: item.unit || 'pcs' };
  if (baris.byWeight && !qty) { S.pendingWeight = { baris, tambah: true }; bukaBerat(); return; }
  S.cart.push(baris);
  refreshQuote();
}

/* ---------------- barang timbangan (FR-POS-05) ----------------
   Ikan dipilih lalu ditimbang di luar sistem; kasir memasukkan beratnya di sini.
   Harga tetap dihitung server: berat dikirim sebagai jumlah baris (3 desimal). */
/** Satuan tampilan (kg/pcs) diambil dari katalog; transaksi tidak menyimpannya. */
function satuanItem(itemId){
  const item = (S.catalog.items || []).find(i => i.id === itemId);
  return item && item.sold_by_weight ? (item.unit || 'kg') : '';
}
function hargaSatuan(baris){
  const item = (S.catalog.items || []).find(i => i.id === baris.item_id);
  return item ? priceOf(item) : 0;
}
function bukaBerat(){
  const w = S.pendingWeight;
  if (!w) return;
  clearFail('weightErr');
  el('weightTitle').textContent = w.baris.name;
  el('weightUnit').textContent = w.baris.unit;
  el('weightSub').textContent = 'Masukkan berat hasil timbangan. Harga = harga satuan x berat.';
  el('weightPrice').textContent = rp(hargaSatuan(w.baris));
  el('weightInput').value = w.tambah ? '' : String(w.baris.qty).replace('.', ',');
  hitungBerat();
  el('weightModal').classList.add('on');
  setTimeout(() => el('weightInput').focus(), 50);
}
function beratDiisi(){ return Number(String(el('weightInput').value).replace(',', '.')) || 0; }
function hitungBerat(){
  const w = S.pendingWeight;
  el('weightTotal').textContent = rp(beratDiisi() * (w ? hargaSatuan(w.baris) : 0));
}
el('weightInput').addEventListener('input', hitungBerat);
el('weightInput').addEventListener('keydown', e => { if (e.key === 'Enter') el('weightSave').click(); });
el('weightSave').onclick = () => {
  const w = S.pendingWeight;
  if (!w) return;
  const berat = Math.round(beratDiisi() * 1000) / 1000;
  if (!berat || berat <= 0) return fail('weightErr', 'Berat harus lebih dari nol.');
  if (berat > 9999) return fail('weightErr', 'Berat terlalu besar.');
  w.baris.qty = berat;
  if (w.tambah) S.cart.push(w.baris);
  S.pendingWeight = null;
  el('weightModal').classList.remove('on');
  refreshQuote();
};
function ubahBerat(i){
  const baris = S.cart[i];
  if (!baris) return;
  S.pendingWeight = { baris, tambah: false };
  bukaBerat();
}
function chgQty(i, d){
  if (S.cart[i].byWeight) return ubahBerat(i);
  S.cart[i].qty += d; if (S.cart[i].qty <= 0) S.cart.splice(i, 1); refreshQuote();
}
function delLine(i){ S.cart.splice(i, 1); refreshQuote(); }
el('clearBtn').onclick = () => { S.cart = []; refreshQuote(); };

// Rincian total (subtotal s.d. pembulatan) dapat dilipat; pilihan diingat per perangkat.
(function () {
  const box = el('sumBox'), btn = el('sumToggle');
  const set = (open) => {
    box.classList.toggle('open', open);
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    el('sumMoreText').textContent = open ? 'Tutup' : 'Rincian';
  };
  let open = false;
  try { open = localStorage.getItem('pos.sumOpen') === '1'; } catch (e) { /* penyimpanan tidak tersedia */ }
  set(open);
  btn.onclick = () => {
    const next = !box.classList.contains('open');
    set(next);
    try { localStorage.setItem('pos.sumOpen', next ? '1' : '0'); } catch (e) { /* abaikan */ }
  };
})();

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
          const o = { id: l.lineId, item_id: l.item_id, qty: Number(l.qty).toFixed(3) };
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
  // Saat rincian terlipat, diskon tetap terlihat sebagai penanda kecil di baris total.
  const hint = g('sHint');
  if (hint) {
    const disc = t ? Number(t.discount) : 0;
    hint.hidden = !disc;
    hint.textContent = disc ? 'Diskon ' + nf(t.discount) : '';
  }
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
        ${l.byWeight
          ? `<div class="stp"><button class="wgt" onclick="ubahBerat(${i})">${esc(fmtQty(l.qty))} ${esc(l.unit)} &times; ${nf(hargaSatuan(l))}</button></div>`
          : `<div class="stp">
          <button onclick="chgQty(${i},-1)"><svg class="i sm"><use href="#ic-minus"/></svg></button>
          <span class="num">${l.qty}</span>
          <button onclick="chgQty(${i},1)"><svg class="i sm"><use href="#ic-plus"/></svg></button>
        </div>`}</div>
      <div style="text-align:right;display:flex;flex-direction:column;align-items:flex-end;gap:10px">
        <span class="amt num">${sub ? nf(sub) : '…'}</span>
        <button class="del" onclick="delLine(${i})"><svg class="i sm"><use href="#ic-hapus"/></svg></button>
      </div></div>`;
  }).join('') : '<div class="blank">Belum ada item.<br>Pilih menu di sebelah kiri.</div>';
}

/* ---- kirim ke dapur ---- */
el('kitchenBtn').onclick = async () => {
  if (!S.cart.length) return;
  if (perluMeja() && !S.table) { openTableModal('Isi nomor meja dulu supaya dapur tahu pesanan ini untuk siapa.'); return; }
  S.orderId = S.orderId || uuid();
  const dikirim = S.cart.slice();
  try {
    await pos('/pos/kitchen-tickets', { method: 'POST', body: {
      id: uuid(), order_id: S.orderId, shift_id: S.shift.id,
      lines: S.cart.map(l => ({ id: l.lineId, item_id: l.item_id, name: l.name, qty: Number(l.qty).toFixed(3),
        modifiers: l.modifiers.map(m => ({ id: m.id, qty: m.qty })) })),
    }});
    el('cartMeta').textContent = 'tiket dapur terkirim';
    // Baris dari quote dipakai bila ada: nama varian dan modifier sudah diterjemahkan server.
    const utk = (S.quote && S.quote.lines && S.quote.lines.length) ? S.quote.lines : dikirim;
    if (prefs().kitchen) setTimeout(() => kirimKePrinter(kitchenText(utk), { logo: false }), 150);
  } catch (e) { el('cartMeta').textContent = 'tiket dapur gagal: ' + e.message; }
};

/* ---------------- pembayaran ---------------- */
el('payBtn').onclick = () => {
  if (!S.quote) return;
  if (!S.shift || !S.shift.id) {
    alert('Belum ada shift terbuka. Buka menu Shift di kiri, lalu tekan "Buka shift".');
    return;
  }
  if (perluMeja() && !S.table) { openTableModal('Isi nomor meja dulu sebelum menutup transaksi makan di tempat.'); return; }
  clearFail('payErr');
  S.intent = null;
  S.pays = [];
  renderSplit();
  el('payTotal').textContent = rp(S.quote.totals.total);
  el('payCount').textContent = S.cart.length;
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
function setGiven(v, dariInput){
  S.given = Math.max(0, Number(v) || 0);
  if (!dariInput) el('cashInput').value = S.given ? nf(S.given) : '';
  el('cashGiven').textContent = rp(S.given);
  el('cashBack').textContent = rp(Math.max(0, S.given - sisaTagihan()));
}
el('cashInput').addEventListener('input', e => {
  const v = Number(String(e.target.value).replace(/[^\d]/g, ''));
  el('quick').querySelectorAll('button').forEach(x => x.classList.remove('on'));
  setGiven(v, true);
});

/* ---------------- pembayaran gabungan (FR-PAY-04) ----------------
   Beberapa pembayaran untuk satu struk, mis. sebagian tunai lalu sisanya kartu.
   Kembalian hanya boleh dari pembayaran tunai terakhir, sesuai aturan server. */
function totalTagihan(){ return S.quote ? Number(S.quote.totals.total) : 0; }
function sudahDibayar(){ return S.pays.reduce((c, p) => c + Number(p.amount), 0); }
function sisaTagihan(){ return Math.max(0, Math.round((totalTagihan() - sudahDibayar()) * 100) / 100); }

function renderSplit(){
  const box = el('splitBox');
  box.style.display = S.pays.length ? 'block' : 'none';
  el('splitRows').innerHTML = S.pays.map((p, i) =>
    `<tr><td>${esc(METHOD_LABEL[p.method] || p.method)}</td><td class="n">${nf(p.amount)}</td>
     <td class="n"><button class="btn" onclick="hapusBayar(${i})">Hapus</button></td></tr>`).join('');
  el('splitLeft').textContent = rp(sisaTagihan());
  el('payTotal').textContent = rp(totalTagihan());
  if (S.quote) el('cashBack').textContent = rp(Math.max(0, S.given - sisaTagihan()));
}
function hapusBayar(i){ S.pays.splice(i, 1); renderSplit(); }

el('paySplit').onclick = () => {
  clearFail('payErr');
  if (!S.quote) return;
  if (S.pay === 'qris') return fail('payErr', 'QRIS untuk sementara hanya bisa dipakai sebagai pembayaran penuh.');
  const sisa = sisaTagihan();
  const nominal = Math.min(S.pay === 'cash' ? S.given : S.given || sisa, sisa);
  if (!nominal || nominal <= 0) return fail('payErr', 'Isi dulu nominal yang diterima untuk pembayaran ini.');
  if (nominal >= sisa) return fail('payErr', 'Nominal ini melunasi tagihan. Tekan Selesaikan, bukan Bayar sebagian.');
  S.pays.push({ id: uuid(), method: S.pay, amount: nominal.toFixed(2) });
  setGiven(0);
  renderSplit();
};
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
      order_ref: S.orderId, method: 'qris', amount: sisaTagihan().toFixed(2) } });
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

/**
 * Menyalin diskon dari quote ke bentuk yang diterima `POST /pos/orders`: hanya jenis, nilai,
 * sumber, dan alasan. Nominalnya tidak ikut — server menghitungnya sendiri.
 */
function rincianDiskon(daftar){
  return (daftar || []).map(d => ({
    type: d.type, value: String(d.value), source: d.source, reason: d.reason || null,
  }));
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
    // Diskon dari quote (promo otomatis maupun manual) dikirim ulang apa adanya; server
    // menghitung total dari daftar ini, jadi menghilangkannya berarti transaksi ditolak.
    discounts: rincianDiskon(l.discounts),
  }));
  const orderDiscounts = rincianDiskon(S.quote.order_discounts);
  const TK = ['subtotal','item_discount','order_discount','service_charge','tax','rounding','total'];
  const totals = Object.fromEntries(TK.map(k => [k, t[k]]));
  const method = S.pay;
  const now = new Date().toISOString();
  // Pembayaran penutup melunasi sisa tagihan; pembayaran sebagian sudah dicatat di S.pays.
  const sisa = sisaTagihan();
  const penutup = Object.assign({ id: uuid(), method, amount: sisa.toFixed(2), created_at: now },
    method === 'cash' ? { tendered: Number(Math.max(S.given, sisa)).toFixed(2) } : {},
    method === 'qris' ? { payment_intent_id: S.intent.id } : {});
  const daftarBayar = S.pays.map(p => Object.assign({}, p, { created_at: now })).concat(penutup);
  S.orderId = S.orderId || uuid();
  let seq = nextSeq(S.shift.business_date), saved = null, lastErr = null;
  for (let i = 0; i < 40; i++) {
    const body = {
      id: S.orderId, shift_id: S.shift.id, receipt_no: receiptNo(seq), channel_code: S.channel,
      table_label: S.table || null,
      open_bill_id: S.billId || null,
      customer_name: S.guest || null,
      queue_no: S.queue ? Number(S.queue) : null,
      note: S.orderNote || null,
      status: 'paid', created_at: now, completed_at: now, pricing, lines, totals,
      order_discounts: orderDiscounts,
      payments: daftarBayar,
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
  if (S.billId) { S.billId = null; S.billLabel = ''; muatBills(); }
  showReceipt(saved);
  renderOrders();
};

/* ---------------- cetak struk ----------------
   Struk dicetak lewat printer yang sudah terpasang di komputer kasir (driver Windows),
   jadi tidak perlu program tambahan di outlet. Saat mencetak, seluruh layar kasir
   disembunyikan oleh @media print dan hanya #printSlip yang keluar ke kertas.
   Lebar kertas dipilih di menu Atur dan tersimpan di perangkat ini. */
const PAPER = {
  80: { cols: 42, css: '72mm', font: '11pt' },
  58: { cols: 32, css: '50mm', font: '9.5pt' },
};
const METHOD_LABEL = { cash: 'Tunai', qris: 'QRIS', debit: 'Kartu Debit', credit: 'Kartu Kredit' };

function prefs(){
  const def = { w: 80, auto: true, kitchen: true };
  try { return Object.assign(def, JSON.parse(localStorage.getItem(LS.print) || '{}')); }
  catch (e) { return def; }
}
function savePrefs(patch){
  const p = Object.assign(prefs(), patch);
  try { localStorage.setItem(LS.print, JSON.stringify(p)); } catch (e) {}
  applyPaper();
  return p;
}
function applyPaper(){
  const p = PAPER[prefs().w] || PAPER[80];
  document.documentElement.style.setProperty('--paper', p.css);
  document.documentElement.style.setProperty('--paperFont', p.font);
}

/** Pembantu tata letak struk untuk lebar kolom yang sedang dipakai. */
function slip(){
  const W = (PAPER[prefs().w] || PAPER[80]).cols;
  const wrap = (s, indent) => {
    const pad = ' '.repeat(indent || 0);
    const words = String(s).split(/\s+/).filter(Boolean);
    const out = []; let line = pad;
    words.forEach(w => {
      if (line.trim() && (line + ' ' + w).length > W) { out.push(line); line = pad + w; }
      else { line = line.trim() ? line + ' ' + w : pad + w; }
    });
    if (line.trim()) out.push(line);
    return out.length ? out.join('\n') + '\n' : '';
  };
  return {
    W,
    rule: '-'.repeat(W) + '\n',
    mid: s => ' '.repeat(Math.max(0, Math.floor((W - String(s).length) / 2))) + s + '\n',
    row: (a, b) => {
      a = String(a); b = String(b);
      if (a.length + b.length + 1 > W) a = a.slice(0, Math.max(1, W - b.length - 2)) + '…';
      return a + ' '.repeat(Math.max(1, W - a.length - b.length)) + b + '\n';
    },
    wrap,
  };
}

function waktu(iso){
  const d = iso ? new Date(iso) : new Date();
  return d.toLocaleString('id-ID', { dateStyle: 'short', timeStyle: 'short' });
}

/** Teks struk dari objek order yang dikembalikan server (bukan dari keranjang). */
function slipText(o){
  const P = slip();
  const m = v => nf(Number(v || 0));
  const chan = (S.catalog.channels.find(c => c.code === o.channel_code) || {}).name || '';
  const rcpt = (((S.catalog || {}).outlet || {}).receipt) || {};
  let s = P.mid(String(S.device.outlet.name).toUpperCase());
  if (S.device.outlet.address) s += P.wrap(S.device.outlet.address);
  // Teks atas struk yang diatur per outlet (Outlet -> Struk).
  if (rcpt.header) s += P.wrap(rcpt.header);
  s += P.rule;
  // Di kertas 58 mm nomor struk dan nama channel tidak muat sebaris; nomor struk tidak boleh terpotong.
  if (String(o.receipt_no || '-').length + chan.length + 1 > P.W) {
    s += P.wrap(o.receipt_no || '-');
    if (chan) s += P.wrap(chan);
  } else {
    s += P.row(o.receipt_no || '-', chan);
  }
  s += P.row(waktu(o.completed_at || o.created_at), 'Kasir: ' + String(o.cashier_name || (S.pos.staff || {}).name || '').split(' ')[0]);
  if (o.table_label) s += P.wrap('Meja ' + o.table_label);
  if (o.customer_name) s += P.wrap('Tamu: ' + o.customer_name);
  if (o.queue_no) s += P.wrap('Antrean ' + o.queue_no);
  s += P.rule;
  (o.items || []).forEach(l => {
    s += P.wrap(l.name + (l.variant_name ? ' (' + l.variant_name + ')' : ''));
    (l.modifiers || []).forEach(x => { if (x && x.name) s += P.wrap('+ ' + x.name, 2); });
    const sat = satuanItem(l.item_id);
    s += P.row('  ' + fmtQty(l.qty) + (sat ? ' ' + sat : '') + ' x ' + m(l.unit_price), m(l.net != null ? l.net : l.gross));
    if (l.note) s += P.wrap('* ' + l.note, 2);
  });
  s += P.rule + P.row('Subtotal', m(o.subtotal));
  const disc = Number(o.item_discount || 0) + Number(o.order_discount || 0);
  if (disc) s += P.row('Diskon', '-' + m(disc));
  if (Number(o.service_charge || 0)) s += P.row('Service charge', m(o.service_charge));
  if (Number(o.tax || 0)) s += P.row(o.tax_name || 'Pajak', m(o.tax));
  const rnd = Number(o.rounding || 0);
  if (rnd) s += P.row('Pembulatan', (rnd < 0 ? '-' : '') + m(Math.abs(rnd)));
  s += P.rule + P.row('TOTAL', m(o.total));
  (o.payments || []).forEach(p => {
    s += P.row(METHOD_LABEL[p.method] || p.method, m(p.tendered != null ? p.tendered : p.amount));
    if (Number(p.change_amount || 0)) s += P.row('Kembalian', m(p.change_amount));
    if (p.reference) s += P.wrap('Ref: ' + p.reference);
  });
  if (o.note) s += P.rule + P.wrap(o.note);
  if (Number(o.refunded_total || 0)) s += P.row('Retur', '-' + m(o.refunded_total));
  if (o.status === 'voided') s += P.rule + P.mid('*** TRANSAKSI DIBATALKAN ***');
  // Teks bawah dari pengaturan outlet; kalimat bawaan hanya dipakai bila outlet belum mengisinya.
  s += P.rule + P.wrap(rcpt.footer || 'Terima kasih atas kunjungan Anda');
  return s;
}

/** Teks tiket dapur; tanpa harga, huruf besar agar terbaca cepat di dapur. */
function kitchenText(lines){
  const P = slip();
  const chan = (S.catalog.channels.find(c => c.code === S.channel) || {}).name || '';
  let s = P.mid('*** TIKET DAPUR ***') + P.rule;
  if (S.table) s += P.mid('MEJA ' + String(S.table).toUpperCase()) + P.rule;
  if (S.queue) s += P.mid('ANTREAN ' + S.queue) + P.rule;
  s += P.row(waktu(), chan);
  if (S.guest) s += P.row('Tamu', S.guest);
  s += P.row('Kasir', String((S.pos.staff || {}).name || '').split(' ')[0]);
  s += P.rule;
  lines.forEach(l => {
    const varian = l.variant_name || (l.variant && l.variant.name) || '';
    const sat = satuanItem(l.item_id);
    const satuan = sat ? ' ' + sat.toUpperCase() : 'x';
    s += P.wrap(fmtQty(l.qty) + satuan + ' ' + String(l.name).toUpperCase() + (varian ? ' (' + String(varian).toUpperCase() + ')' : ''));
    (l.modifiers || []).forEach(x => { if (x && x.name) s += P.wrap('+ ' + x.name, 3); });
    if (l.note) s += P.wrap('* ' + l.note, 3);
  });
  if (S.orderNote) s += P.rule + P.wrap('CATATAN: ' + String(S.orderNote).toUpperCase());
  return s + P.rule;
}

/**
 * Kirim satu teks ke printer. Dialog cetak Windows yang memilih printernya.
 *
 * Logo hanya ikut pada struk tamu, bukan tiket dapur, dan hanya bila outlet menyalakan
 * "Cetak logo". Pencetakan ditunda sampai gambarnya termuat — bila tidak, kertas keluar
 * dengan kotak kosong di kepala struk. Ada batas tunggu supaya printer tidak menggantung
 * ketika gambarnya gagal diambil.
 */
function kirimKePrinter(text, opsi){
  const pakaiLogo = !(opsi && opsi.logo === false);
  const area = el('printSlip');
  const rcpt = (((S.catalog || {}).outlet || {}).receipt) || {};
  const src = pakaiLogo && rcpt.show_logo ? rcpt.logo_url : null;

  area.innerHTML = '';
  const teks = document.createElement('span');
  teks.textContent = text;

  if (!src) { area.appendChild(teks); applyPaper(); window.print(); return; }

  const img = new Image();
  img.className = 'slipLogo';
  img.alt = '';
  area.appendChild(img);
  area.appendChild(teks);

  let sudah = false;
  const cetak = () => { if (sudah) return; sudah = true; applyPaper(); window.print(); };
  img.onload = cetak;
  img.onerror = () => { img.remove(); cetak(); };
  setTimeout(cetak, 1500);
  img.src = src;
}

function showReceipt(o){
  S.lastOrder = o;
  el('rcpt').textContent = slipText(o);
  el('rcptHead').textContent = 'Struk ' + prefs().w + ' mm — transaksi sudah tersimpan di server.';
  el('rcptNote').innerHTML = 'Tersimpan di server sebagai <b>' + esc(o.receipt_no) + '</b> — hari bisnis ' +
    esc(S.shift.business_date) + '. Sudah tampil di back-office (Penjualan &amp; Laporan).';
  el('rcptModal').classList.add('on');
  if (prefs().auto) setTimeout(() => kirimKePrinter(slipText(o)), 150);
}

el('printBtn').onclick = () => { if (S.lastOrder) kirimKePrinter(slipText(S.lastOrder)); };

/* ---------------- tandai menu habis (FR-POS-08) ----------------
   Perubahan langsung berlaku untuk outlet ini; katalog lokal ikut disegarkan
   agar kartu menu di layar kasir langsung berubah. */
function renderSoldOut(){
  const q = String(el('soldSearch').value || '').toLowerCase().trim();
  const items = ((S.catalog && S.catalog.items) || [])
    .filter(i => !q || i.name.toLowerCase().includes(q))
    .slice(0, q ? 60 : 30);
  el('soldRows').innerHTML = items.map(i =>
    `<tr><td>${esc(i.name)}</td>
      <td class="n" style="color:${i.sold_out ? 'var(--danger)' : 'var(--muted)'}">${i.sold_out ? 'Habis' : 'Tersedia'}</td>
      <td class="n"><button class="btn" onclick="ubahHabis('${i.id}', ${i.sold_out ? 'false' : 'true'})">
        ${i.sold_out ? 'Tandai tersedia' : 'Tandai habis'}</button></td></tr>`).join('')
    || '<tr><td colspan="3" style="color:var(--muted)">Tidak ada menu yang cocok.</td></tr>';
}
el('soldSearch').addEventListener('input', renderSoldOut);

async function ubahHabis(itemId, habis){
  try {
    const res = await pos('/pos/items/' + itemId + '/sold-out', { method: 'POST', body: { sold_out: habis } });
    const item = (S.catalog.items || []).find(i => i.id === itemId);
    if (item) item.sold_out = !!res.is_sold_out;
    renderSoldOut();
    renderGrid();
  } catch (e) { alert('Gagal mengubah status menu: ' + e.message); }
}

/* pengaturan printer di menu Atur */
function renderPrintPrefs(){
  const p = prefs();
  document.querySelectorAll('#paperOpt button').forEach(b => b.classList.toggle('on', Number(b.dataset.w) === p.w));
  el('autoPrint').checked = !!p.auto;
  el('autoKitchen').checked = !!p.kitchen;
}
document.querySelectorAll('#paperOpt button').forEach(b => b.onclick = () => { savePrefs({ w: Number(b.dataset.w) }); renderPrintPrefs(); });
el('autoPrint').onchange = e => savePrefs({ auto: e.target.checked });
el('autoKitchen').onchange = e => savePrefs({ kitchen: e.target.checked });
el('testPrint').onclick = () => {
  const P = slip();
  kirimKePrinter(
    P.mid(String((S.device && S.device.outlet ? S.device.outlet.name : 'FnB Cloud')).toUpperCase()) +
    P.rule + P.mid('UJI CETAK') + P.row('Lebar kertas', prefs().w + ' mm') +
    P.row('Waktu', waktu()) + P.rule +
    P.row('Contoh item', nf(25000)) + P.row('TOTAL', nf(25000)) + P.rule +
    P.mid('Bila garis di atas tidak terpotong,') + P.mid('lebar kertas sudah benar.')
  );
};
el('newOrderBtn').onclick = () => {
  bersihkanKeranjang();
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
      <td style="display:flex;gap:6px;flex-wrap:wrap">
        <button class="btn" onclick="cetakUlang(${i})">Cetak ulang</button>
        ${o.status === 'paid' ? `<button class="btn" onclick="askRefund(${i})">Retur</button>` : ''}
        ${o.status === 'paid' ? `<button class="btn" onclick="askVoid(${i})">Void</button>` : ''}
      </td></tr>`).join('')
    : '<tr><td colspan="6" style="color:var(--muted)">Belum ada transaksi.</td></tr>';
}
/** Cetak ulang struk. Data diambil ulang dari server agar status (void/retur) selalu terbaru. */
async function cetakUlang(i){
  const o = S.orders[i];
  if (!o) return;
  let penuh = o;
  try { penuh = await pos('/pos/orders/' + o.id); S.orders[i] = penuh; renderOrders(); }
  catch (e) { /* jaringan bermasalah: pakai salinan yang ada di layar */ }
  kirimKePrinter(slipText(penuh));
}

/* ---------------- otorisasi supervisor (FR-AUTH-07) ----------------
   Satu alur untuk semua aksi yang butuh PIN supervisor: void, retur, buka laci.
   Kasir yang memang berwenang (punya izinnya sendiri) tidak ditanyai PIN. */
let authCtx = null;

function punyaIzin(izin){ return ((S.pos && S.pos.staff && S.pos.staff.permissions) || []).includes(izin); }

/** @return Promise<{authorization_id, reason}|null> — null berarti kasir membatalkan. */
function mintaOtorisasi(opsi){
  return new Promise((resolve) => {
    authCtx = Object.assign({ resolve }, opsi);
    clearFail('authErr');
    el('authSub').textContent = opsi.judul;
    el('authPin').value = '';
    el('authReason').value = opsi.reasonDefault || '';
    el('authWho').innerHTML = '<option value="">memuat…</option>';
    // Cegah PIN dikirim sebelum daftar supervisor selesai dimuat.
    el('authGo').disabled = true;
    el('authModal').classList.add('on');
    pos('/pos/supervisors').then(list => {
      const sup = list.filter(x => !x.locked && (x.actions || []).includes(opsi.action));
      el('authWho').innerHTML = sup.length
        ? sup.map(x => `<option value="${x.id}">${esc(x.name)}${DEMO_PINS[x.name] ? ' — PIN ' + DEMO_PINS[x.name] : ''}</option>`).join('')
        : '<option value="">(tidak ada supervisor berwenang)</option>';
      el('authGo').disabled = ! sup.length;
    }).catch(e => { fail('authErr', e.message); el('authGo').disabled = true; });
  });
}
el('authGo').onclick = async () => {
  if (!authCtx) return;
  clearFail('authErr');
  const reason = el('authReason').value.trim() || authCtx.reasonDefault || '';
  const body = { action: authCtx.action, supervisor_id: el('authWho').value, pin: el('authPin').value, reason };
  if (authCtx.referenceType) body.reference_type = authCtx.referenceType;
  if (authCtx.referenceId) body.reference_id = authCtx.referenceId;
  if (authCtx.amount != null) body.amount = authCtx.amount;
  try {
    const auth = await pos('/pos/authorize', { method: 'POST', body });
    const ctx = authCtx; authCtx = null;
    el('authModal').classList.remove('on');
    ctx.resolve({ authorization_id: auth.authorization_id, reason });
  } catch (e) { fail('authErr', e.message); }
};
// Ditutup tanpa PIN: pemanggil menerima null dan membatalkan aksinya.
el('authModal').addEventListener('click', e => {
  if (e.target === el('authModal') && authCtx) { const c = authCtx; authCtx = null; c.resolve(null); }
});
document.querySelectorAll('#authModal [data-close]').forEach(b => b.addEventListener('click', () => {
  if (authCtx) { const c = authCtx; authCtx = null; c.resolve(null); }
}));

async function askVoid(i){
  const target = S.orders[i];
  if (!target) return;
  const izin = punyaIzin('pos.void')
    ? { authorization_id: null, reason: 'Void oleh kasir berwenang' }
    : await mintaOtorisasi({
        action: 'void',
        judul: 'Void struk ' + target.receipt_no + ' memerlukan persetujuan supervisor.',
        reasonDefault: 'Void kasir',
        referenceType: 'order',
        referenceId: target.id,
      });
  if (!izin) return;
  try {
    const body = { id: uuid(), reason: izin.reason || 'Void kasir', stock_action: 'waste' };
    if (izin.authorization_id) body.authorization = { mode: 'online', authorization_id: izin.authorization_id };
    const res = await pos('/pos/orders/' + target.id + '/void', { method: 'POST', body });
    const k = S.orders.findIndex(o => o.id === target.id);
    if (k >= 0) S.orders[k] = res;
    renderOrders();
  } catch (e) { alert('Void gagal: ' + e.message); }
}

/* ---------------- retur / refund (FR-POS-13) ----------------
   Nilai retur dihitung server. Angka di layar hanya perkiraan; bila berbeda,
   permintaan diulang sekali memakai nilai yang dihitung server. */
async function askRefund(i){
  const ringkas = S.orders[i];
  if (!ringkas) return;
  let order = ringkas;
  try { order = await pos('/pos/orders/' + ringkas.id); S.orders[i] = order; } catch (e) { /* pakai yang ada */ }
  S.refundTarget = { order, qty: {}, stock: 'return', method: (order.payments || [{}])[0].method || 'cash' };
  clearFail('refundErr');
  el('refundReason').value = '';
  el('refundSub').textContent = 'Struk ' + order.receipt_no + ' — total ' + rp(order.total) +
    (Number(order.refunded_total || 0) ? ', sudah diretur ' + rp(order.refunded_total) : '');
  const metode = [...new Set((order.payments || []).map(p => p.method).concat('cash'))];
  el('refundMethod').innerHTML = metode.map(m =>
    `<button data-v="${m}" class="${m === S.refundTarget.method ? 'on' : ''}">${METHOD_LABEL[m] || m}</button>`).join('');
  document.querySelectorAll('#refundMethod button').forEach(b => b.onclick = () => {
    S.refundTarget.method = b.dataset.v;
    document.querySelectorAll('#refundMethod button').forEach(x => x.classList.toggle('on', x === b));
  });
  document.querySelectorAll('#refundStock button').forEach(b => b.onclick = () => {
    S.refundTarget.stock = b.dataset.v;
    document.querySelectorAll('#refundStock button').forEach(x => x.classList.toggle('on', x === b));
  });
  renderRefundRows();
  el('refundModal').classList.add('on');
}

function renderRefundRows(){
  const t = S.refundTarget;
  el('refundRows').innerHTML = (t.order.items || []).map(it => {
    const diambil = Number(t.qty[it.id] || 0);
    return `<tr><td>${esc(it.name)}${it.variant_name ? ' (' + esc(it.variant_name) + ')' : ''}</td>
      <td class="n">${Number(it.qty)}</td>
      <td class="n" style="white-space:nowrap">
        <button class="btn" onclick="ubahRefund('${it.id}',-1)">−</button>
        <b style="display:inline-block;min-width:22px;text-align:center">${diambil}</b>
        <button class="btn" onclick="ubahRefund('${it.id}',1)">+</button>
      </td></tr>`;
  }).join('') || '<tr><td colspan="3" style="color:var(--muted)">Rincian item tidak tersedia.</td></tr>';
  el('refundAmount').textContent = rp(perkiraanRefund());
}

function ubahRefund(itemId, d){
  const t = S.refundTarget;
  const item = (t.order.items || []).find(x => x.id === itemId);
  if (!item) return;
  const max = Number(item.qty);
  t.qty[itemId] = Math.min(max, Math.max(0, Number(t.qty[itemId] || 0) + d));
  renderRefundRows();
}

/** Porsi baris terhadap total bayar — rumus yang sama dengan server. */
function perkiraanRefund(){
  const t = S.refundTarget;
  const items = t.order.items || [];
  const netTotal = items.reduce((c, i) => c + Number(i.net || 0), 0);
  if (!netTotal) return 0;
  let jumlah = 0, adaIsi = false;
  items.forEach(i => {
    const q = Number(t.qty[i.id] || 0);
    if (!q) return;
    adaIsi = true;
    jumlah += Number(i.net) * q * Number(t.order.total) / (Number(i.qty) * netTotal);
  });
  if (!adaIsi) return 0;
  const semua = items.every(i => Number(t.qty[i.id] || 0) === Number(i.qty));
  const sisa = Number(t.order.total) - Number(t.order.refunded_total || 0);
  return semua ? sisa : Math.round(jumlah * 100) / 100;
}

el('refundAll').onclick = () => {
  const t = S.refundTarget;
  (t.order.items || []).forEach(i => { t.qty[i.id] = Number(i.qty); });
  renderRefundRows();
};

el('refundGo').onclick = async () => {
  const t = S.refundTarget;
  clearFail('refundErr');
  const alasan = el('refundReason').value.trim();
  if (alasan.length < 3) return fail('refundErr', 'Alasan retur wajib diisi minimal 3 huruf.');
  const baris = (t.order.items || []).filter(i => Number(t.qty[i.id] || 0) > 0)
    .map(i => ({ order_item_id: i.id, qty: String(t.qty[i.id]) }));
  if (!baris.length) return fail('refundErr', 'Pilih dulu barang yang diretur.');
  if (!S.shift || !S.shift.id) return fail('refundErr', 'Belum ada shift terbuka.');

  const izin = punyaIzin('pos.void')
    ? { authorization_id: null, reason: alasan }
    : await mintaOtorisasi({
        action: 'refund',
        judul: 'Retur struk ' + t.order.receipt_no + ' memerlukan persetujuan supervisor.',
        reasonDefault: alasan,
        referenceType: 'order',
        referenceId: t.order.id,
      });
  if (!izin) return;

  const semua = (t.order.items || []).every(i => Number(t.qty[i.id] || 0) === Number(i.qty));
  const kirim = async (nominal) => {
    const body = {
      id: uuid(), shift_id: S.shift.id, amount: Number(nominal).toFixed(2),
      method: t.method, stock_action: t.stock, reason: alasan,
      created_at: new Date().toISOString(),
    };
    if (!semua) body.lines = baris;
    if (izin.authorization_id) body.authorization = { mode: 'online', authorization_id: izin.authorization_id };
    return pos('/pos/orders/' + t.order.id + '/refunds', { method: 'POST', body });
  };

  try {
    let hasil;
    try {
      hasil = await kirim(perkiraanRefund());
    } catch (e) {
      // Server yang berhak menentukan nominal; ulangi sekali dengan angkanya.
      const harusnya = e.details && e.details.expected;
      if (e.code !== 'REFUND_AMOUNT_MISMATCH' || !harusnya) throw e;
      hasil = await kirim(harusnya);
    }
    el('refundModal').classList.remove('on');
    try { S.orders[S.orders.findIndex(o => o.id === t.order.id)] = await pos('/pos/orders/' + t.order.id); } catch (e) {}
    renderOrders();
    alert('Retur tercatat sebesar ' + rp(hasil.amount || perkiraanRefund()) + '.');
  } catch (e) { fail('refundErr', e.message); }
};

/* ---------------- parkir bill (FR-POS-12) ----------------
   Tamu makan dulu, bayar belakangan. Tagihan disimpan di server (milik outlet, bukan
   perangkat) supaya kasir mana pun bisa membukanya, dan tidak hilang bila browser ditutup.
   Harga tidak ikut dibekukan: saat dibuka kembali, totalnya dihitung ulang oleh server. */
function isiBillDariKeranjang(){
  return S.cart.map(l => ({
    id: l.lineId, item_id: l.item_id, name: l.name, variant_id: l.variant_id || null,
    qty: Number(l.qty).toFixed(3), note: l.note || null,
    modifiers: (l.modifiers || []).map(m => ({ id: m.id, qty: m.qty || 1 })),
    bundle: l.bundle || [],
  }));
}

el('parkBtn').onclick = () => {
  if (!S.cart.length) return;
  clearFail('parkErr');
  el('parkLabel').value = S.billLabel || (S.table ? 'Meja ' + S.table : '') || (S.guest || '');
  el('parkModal').classList.add('on');
  setTimeout(() => el('parkLabel').focus(), 50);
};

el('parkGo').onclick = async () => {
  clearFail('parkErr');
  if (!S.cart.length) return fail('parkErr', 'Keranjang masih kosong.');
  const label = el('parkLabel').value.trim().slice(0, 40);
  if (!label) return fail('parkErr', 'Beri nama bill supaya mudah dicari, mis. nomor meja atau nama tamu.');
  const btn = el('parkGo'); btn.disabled = true;
  try {
    const bill = await pos('/pos/open-bills', { method: 'POST', body: {
      id: S.billId || uuid(), channel_code: S.channel, label,
      table_label: S.table || null,
      customer_name: S.guest || null, note: S.orderNote || null,
      queue_no: S.queue ? Number(S.queue) : null,
      lines: isiBillDariKeranjang(),
      totals: S.quote ? S.quote.totals : null,
    }});
    el('parkModal').classList.remove('on');
    bersihkanKeranjang();
    el('cartMeta').textContent = 'bill "' + bill.label + '" tersimpan';
    await muatBills();
  } catch (e) { fail('parkErr', e.message); }
  btn.disabled = false;
};

function bersihkanKeranjang(){
  S.cart = []; S.quote = null; S.orderId = null; S.intent = null; S.table = '';
  S.guest = ''; S.queue = ''; S.orderNote = ''; S.pays = [];
  S.billId = null; S.billLabel = '';
  renderTableBar(); renderDetailBar(); refreshQuote();
}

async function muatBills(){
  clearFail('billErr');
  try {
    S.bills = await pos('/pos/open-bills');
  } catch (e) { fail('billErr', e.message); S.bills = []; }
  renderBills();
}

function renderBills(){
  const badge = el('billBadge');
  badge.textContent = S.bills.length || '';
  badge.style.display = S.bills.length ? '' : 'none';
  el('billRows').innerHTML = S.bills.length ? S.bills.map((b, i) => `
    <tr><td><b>${esc(b.label || '(tanpa nama)')}</b></td>
      <td>${esc(b.customer_name || '-')}</td>
      <td class="n">${b.line_count}</td>
      <td class="n">${b.totals && b.totals.total ? nf(b.totals.total) : '—'}</td>
      <td>${fmtTime(b.opened_at)}${b.opened_by_name ? ' · ' + esc(b.opened_by_name.split(' ')[0]) : ''}</td>
      <td class="n" style="white-space:nowrap">
        <button class="btn" onclick="bukaBill(${i})">Buka</button>
        <button class="btn" onclick="batalkanBill(${i})">Batal</button>
      </td></tr>`).join('')
    : '<tr><td colspan="6" style="color:var(--muted)">Belum ada bill tersimpan.</td></tr>';
}

/** Buka tagihan tersimpan ke keranjang, lalu hitung ulang harganya di server. */
async function bukaBill(i){
  const ringkas = S.bills[i];
  if (!ringkas) return;
  if (S.cart.length && !confirm('Keranjang yang sedang terbuka akan diganti. Lanjutkan?')) return;
  try {
    const bill = await pos('/pos/open-bills/' + ringkas.id);
    S.billId = bill.id;
    S.billLabel = bill.label || '';
    S.channel = bill.channel_code || S.channel;
    S.guest = bill.customer_name || '';
    S.queue = bill.queue_no ? String(bill.queue_no) : '';
    S.orderNote = bill.note || '';
    S.table = bill.table_label || '';
    S.orderId = null; S.intent = null; S.pays = [];
    S.cart = (bill.lines || []).map(l => {
      const item = (S.catalog.items || []).find(x => x.id === l.item_id) || {};
      return {
        lineId: l.id, item_id: l.item_id, name: l.name || item.name || 'Item',
        variant_id: l.variant_id || null, modifiers: l.modifiers || [], bundle: l.bundle || [],
        qty: Number(l.qty), note: l.note || null,
        byWeight: !!item.sold_by_weight, unit: item.unit || 'pcs',
      };
    });
    renderChannels(); renderTableBar(); renderDetailBar(); refreshQuote();
    goView('kasir');
    el('cartMeta').textContent = 'bill "' + (bill.label || '') + '" dibuka — tinggal dibayar';
  } catch (e) { fail('billErr', e.message); }
}

async function batalkanBill(i){
  const bill = S.bills[i];
  if (!bill) return;
  const alasan = prompt('Batalkan bill "' + (bill.label || '') + '"? Tulis alasannya:');
  if (alasan === null) return;
  try {
    await pos('/pos/open-bills/' + bill.id, { method: 'DELETE', body: { reason: alasan } });
    if (S.billId === bill.id) bersihkanKeranjang();
    await muatBills();
  } catch (e) { fail('billErr', e.message); }
}

el('refreshBills').onclick = muatBills;

/* ---------------- kas masuk & keluar saat shift (FR-POS-14) ---------------- */
function askCash(type){
  S.cashType = type;
  clearFail('cashErr');
  el('cashTitle').textContent = type === 'in' ? 'Kas masuk' : 'Kas keluar';
  el('cashSub').textContent = type === 'in'
    ? 'Uang yang masuk ke laci di luar penjualan, mis. tambahan modal.'
    : 'Uang yang keluar dari laci, mis. setoran ke brankas atau belanja kecil.';
  el('cashAmount').value = '';
  el('cashReason').value = '';
  el('cashModal').classList.add('on');
}
el('cashGo').onclick = async () => {
  clearFail('cashErr');
  const nominal = Number(String(el('cashAmount').value).replace(/[^\d]/g, ''));
  const alasan = el('cashReason').value.trim();
  if (!nominal || nominal <= 0) return fail('cashErr', 'Nominal harus lebih dari nol.');
  if (!alasan) return fail('cashErr', 'Keterangan wajib diisi agar selisih kas bisa ditelusuri.');
  if (!S.shift || !S.shift.id) return fail('cashErr', 'Belum ada shift terbuka.');
  try {
    await pos('/pos/shifts/' + S.shift.id + '/cash-movements', { method: 'POST', body: {
      id: uuid(), type: S.cashType, amount: nominal.toFixed(2), reason: alasan,
      created_at: new Date().toISOString(),
    }});
    el('cashModal').classList.remove('on');
    renderShift();
  } catch (e) { fail('cashErr', e.message); }
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
  ['pesanan','bill','shift','atur'].forEach(s => el('view-' + s).classList.toggle('on', s === t));
  if (t === 'shift') renderShift();
  if (t === 'pesanan') renderOrders();
  if (t === 'bill') muatBills();
  if (t === 'atur') { renderDevice(); renderPrintPrefs(); renderSoldOut(); }
}
document.querySelectorAll('.rail button').forEach(b => b.onclick = () => goView(b.dataset.scr));
applyPaper();
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
<div id="printSlip"></div>
</body>
</html>
