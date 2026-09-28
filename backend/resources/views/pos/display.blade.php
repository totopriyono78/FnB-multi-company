<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Layar Pelanggan — {{ config('fnb.brand.name') }}</title>
<link rel="icon" href="{{ asset('img/favicon.svg') }}" type="image/svg+xml">
{{--
  Layar pelanggan (FR-DEV, jenis perangkat `customer_display`).

  Halaman ini SENGAJA tidak mengambil apa pun dari server: tidak ada fetch, tidak ada token,
  tidak ada id tenant di URL. Seluruh isinya dikirim oleh jendela kasir di komputer yang sama
  lewat BroadcastChannel — kanal antar-jendela milik satu peramban, yang tidak pernah keluar
  dari mesin itu. Akibatnya, tiga hal yang biasanya jadi pekerjaan rumah hilang sekaligus:
  tidak ada endpoint baru yang bisa disalahgunakan, tidak ada kebocoran lintas tenant yang
  mungkin (tidak ada kueri untuk dibocorkan), dan layar ini tetap hidup walau internet outlet
  mati. Kalau kelak layar pelanggan dipasang di tablet terpisah, baru dibutuhkan endpoint
  berpasangan seperti KDS — dan saat itu uji akses lintas tenant menjadi wajib.

  Isinya hanya milik pelanggan yang sedang berdiri di depan kasir: item, total, QR, status lunas.
  Tidak ada daftar transaksi lain, tidak ada nama kasir, tidak ada tombol apa pun — layar ini
  tidak bisa dipakai untuk melakukan sesuatu, hanya untuk dilihat.
--}}
<style>
  :root{
    --bg:#0b1220; --panel:#131c2e; --line:#243149; --ink:#f4f7fb; --muted:#93a3bd;
    --brand:#2f6df6; --ok:#16a34a; --warn:#d97706;
  }
  *{box-sizing:border-box}
  html,body{height:100%}
  body{
    margin:0;background:var(--bg);color:var(--ink);
    font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
    /* Layar pelanggan sering LCD kecil 10–15" yang dilihat dari ±70 cm, dan pelanggan tidak
       boleh menyipit. Ukuran huruf ikut lebar layar, dengan batas atas supaya di monitor besar
       tidak jadi raksasa. */
    font-size:clamp(15px,1.35vw,22px);
    display:flex;flex-direction:column;overflow:hidden;
  }
  header{
    display:flex;align-items:center;justify-content:space-between;gap:16px;
    padding:14px 24px;border-bottom:1px solid var(--line);background:var(--panel);
  }
  .brand{font-weight:700;letter-spacing:.2px}
  .brand small{display:block;font-weight:500;font-size:.72em;color:var(--muted)}
  .clock{font-variant-numeric:tabular-nums;color:var(--muted)}
  main{flex:1;min-height:0;display:flex;flex-direction:column}

  /* ---- keadaan menunggu / idle ---- */
  .center{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:18px;text-align:center;padding:32px}
  .center h1{margin:0;font-size:2.6em;line-height:1.15}
  .center p{margin:0;color:var(--muted);max-width:30ch;font-size:1.05em}
  .mark{width:96px;height:96px;opacity:.85}

  /* ---- keranjang berjalan ---- */
  .rows{flex:1;min-height:0;overflow:auto;padding:10px 24px}
  .row{display:flex;gap:14px;align-items:baseline;padding:11px 0;border-bottom:1px solid var(--line)}
  .row:last-child{border-bottom:0}
  .row .qty{min-width:3.6em;color:var(--muted);font-variant-numeric:tabular-nums}
  .row .nm{flex:1}
  .row .mod{display:block;font-size:.78em;color:var(--muted);margin-top:3px}
  .row .amt{font-variant-numeric:tabular-nums;font-weight:600}
  /* Baris terakhir yang baru masuk disorot sebentar: pelanggan bisa memastikan item yang baru
     disebutkan benar-benar yang masuk, dan itulah gunanya layar ini di luar momen bayar. */
  .row.baru{animation:sorot 1.4s ease-out}
  @keyframes sorot{from{background:rgba(47,109,246,.28)}to{background:transparent}}

  .sum{border-top:1px solid var(--line);background:var(--panel);padding:16px 24px}
  .sum .l{display:flex;justify-content:space-between;color:var(--muted);padding:3px 0;font-size:.92em}
  .sum .l.big{
    color:var(--ink);font-size:1.9em;font-weight:700;padding-top:10px;margin-top:8px;
    border-top:1px solid var(--line);
  }
  .num{font-variant-numeric:tabular-nums}

  /* ---- QRIS ---- */
  .pay{flex:1;min-height:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;padding:18px}
  .pay h2{margin:0;font-size:1.5em}
  .pay .tot{font-size:2.4em;font-weight:700}
  /* Kotak putih adalah syarat, bukan hiasan: pemindai butuh kontras terang di sekitar modul,
     dan latar gelap halaman ini akan membuat sebagian kamera gagal mengunci kode. */
  .qrwrap{background:#fff;padding:12px;border-radius:12px;line-height:0}
  .qrwrap svg{display:block;width:min(46vh,42vw,420px);height:auto}
  .countdown{font-variant-numeric:tabular-nums;color:var(--muted)}
  .countdown b{color:var(--ink)}
  .countdown.habis{color:#fca5a5}
  .warn{
    background:rgba(217,119,6,.16);border:1px solid rgba(217,119,6,.5);color:#fed7aa;
    padding:9px 14px;border-radius:10px;max-width:44ch;text-align:center;font-size:.9em;
  }

  /* ---- lunas ---- */
  .ok{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:16px;text-align:center;padding:32px}
  .tick{
    width:120px;height:120px;border-radius:50%;background:var(--ok);
    display:flex;align-items:center;justify-content:center;animation:pop .35s ease-out;
  }
  .tick svg{width:64px;height:64px;stroke:#fff;stroke-width:3.4;fill:none;stroke-linecap:round;stroke-linejoin:round}
  @keyframes pop{from{transform:scale(.6);opacity:0}to{transform:scale(1);opacity:1}}
  .ok h1{margin:0;font-size:2.4em}
  .ok .amt{font-size:1.7em;font-weight:700;font-variant-numeric:tabular-nums}
  .ok .meta{color:var(--muted)}

  .hint{
    position:fixed;left:50%;bottom:14px;transform:translateX(-50%);
    background:rgba(0,0,0,.55);color:var(--muted);padding:6px 14px;border-radius:999px;font-size:.78em;
  }
  @media (prefers-reduced-motion:reduce){*{animation:none !important}}
</style>
</head>
<body>

<header>
  <div class="brand" id="brand">{{ config('fnb.brand.product') }}<small id="outlet">layar pelanggan</small></div>
  <div class="clock" id="clock"></div>
</header>

<main id="main"></main>
<div class="hint" id="hint" hidden></div>

<script>
'use strict';
const el = id => document.getElementById(id);
const nf = n => Math.round(Number(n) || 0).toLocaleString('id-ID');
const rp = n => 'Rp ' + nf(n);
const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c =>
  ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

const KANAL = 'fnb-customer-display';
/* Kasir menyiarkan keadaan tiap beberapa detik. Bila siaran berhenti lebih lama dari ini,
   jendela kasir dianggap tertutup dan layar kembali menyambut — lebih baik daripada
   membekukan total pelanggan sebelumnya di layar sepanjang hari. */
const BASI_MS = 16000;

let keadaan = null, terakhir = 0, jumlahBarisSebelum = 0, kunciBarisSebelum = '';

const ch = 'BroadcastChannel' in window ? new BroadcastChannel(KANAL) : null;

if (ch) {
  ch.onmessage = e => {
    const d = e.data;
    if (!d || d.t !== 'state') return;
    keadaan = d;
    terakhir = Date.now();
    render();
  };
  // Jendela ini bisa dibuka setelah kasir mulai bekerja, jadi ia menyapa dulu dan kasir
  // membalas dengan keadaan terkini. Tanpa jabat tangan ini layar akan kosong sampai
  // kasir menyentuh sesuatu — persis saat pelanggan sudah berdiri di depannya.
  ch.postMessage({ t: 'hello' });
  setInterval(() => { if (!segar()) ch.postMessage({ t: 'hello' }); }, 4000);
}

function segar(){ return keadaan !== null && (Date.now() - terakhir) < BASI_MS; }

function jam(){
  el('clock').textContent = new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
}
setInterval(jam, 10000); jam();

/* Mark disisipkan langsung, bukan lewat <img>: layar pelanggan harus tetap utuh tampilannya
   saat jaringan outlet mati, dan permintaan gambar kedua adalah satu hal lagi yang bisa gagal.
   Berkasnya diperiksa dulu supaya deploy tanpa favicon tidak membuat halaman ini galat. */
const IKON_MARK = @json(is_file(public_path('img/favicon.svg'))
    ? trim((string) file_get_contents(public_path('img/favicon.svg')))
    : '');

function render(){
  const s = segar() ? keadaan : null;
  el('outlet').textContent = (s && s.outlet) || 'layar pelanggan';

  if (!s) return tampilMenunggu();
  if (s.mode === 'paid') return tampilLunas(s);
  if (s.mode === 'qris') return tampilQris(s);
  if (s.mode === 'cart' && (s.lines || []).length) return tampilKeranjang(s);
  return tampilSelamatDatang(s);
}

function tampilMenunggu(){
  el('hint').hidden = false;
  el('hint').textContent = ch
    ? 'Menunggu layar kasir di komputer ini…'
    : 'Peramban ini tidak mendukung BroadcastChannel — layar pelanggan butuh peramban modern.';
  el('main').innerHTML = '<div class="center">' + IKON_MARK.replace('<svg', '<svg class="mark"')
    + '<h1>Selamat datang</h1><p>Silakan menunggu, kasir akan segera melayani.</p></div>';
  jumlahBarisSebelum = 0; kunciBarisSebelum = '';
}

function tampilSelamatDatang(s){
  el('hint').hidden = true;
  el('main').innerHTML = '<div class="center">' + IKON_MARK.replace('<svg', '<svg class="mark"')
    + '<h1>Selamat datang</h1><p>Pesanan Anda akan tampil di layar ini.</p></div>';
  jumlahBarisSebelum = 0; kunciBarisSebelum = '';
}

function tampilKeranjang(s){
  el('hint').hidden = true;
  const baris = s.lines || [];
  const t = s.totals || {};
  // Sorotan hanya untuk baris yang benar-benar baru — bukan tiap kali total diperbarui,
  // yang akan membuat layar berkedip tiap kali kuantitas diubah.
  const kunci = baris.map(l => l.key).join('|');
  const adaBaru = baris.length > jumlahBarisSebelum && kunci !== kunciBarisSebelum;
  jumlahBarisSebelum = baris.length; kunciBarisSebelum = kunci;

  const rows = baris.map((l, i) => `<div class="row${adaBaru && i === baris.length - 1 ? ' baru' : ''}">
      <span class="qty num">${esc(l.qty)}${l.unit ? ' ' + esc(l.unit) : '&times;'}</span>
      <span class="nm">${esc(l.name)}${l.mods ? `<span class="mod">${esc(l.mods)}</span>` : ''}</span>
      <span class="amt num">${l.amount === null || l.amount === undefined ? '…' : nf(l.amount)}</span>
    </div>`).join('');

  const rinci = [];
  if (Number(t.discount)) rinci.push(['Diskon', '−' + nf(t.discount)]);
  if (Number(t.service_charge)) rinci.push([s.service_label || 'Layanan', nf(t.service_charge)]);
  if (Number(t.tax)) rinci.push([s.tax_label || 'Pajak', nf(t.tax)]);
  if (Number(t.rounding)) rinci.push(['Pembulatan', (Number(t.rounding) < 0 ? '−' : '') + nf(Math.abs(Number(t.rounding)))]);

  el('main').innerHTML = `<div class="rows">${rows}</div>
    <div class="sum">
      <div class="l"><span>Subtotal</span><span class="num">${nf(t.subtotal || 0)}</span></div>
      ${rinci.map(([k, v]) => `<div class="l"><span>${esc(k)}</span><span class="num">${v}</span></div>`).join('')}
      <div class="l big"><span>Total</span><span class="num">${rp(t.total || 0)}</span></div>
    </div>`;
}

function tampilQris(s){
  el('hint').hidden = true;
  const q = s.qr || {};
  jumlahBarisSebelum = 0; kunciBarisSebelum = '';

  el('main').innerHTML = `<div class="pay">
      <h2>Bayar dengan QRIS</h2>
      <div class="tot num">${rp(q.amount || (s.totals || {}).total || 0)}</div>
      ${q.svg
        ? `<div class="qrwrap">${q.svg}</div>`
        : (q.status && q.status !== 'pending'
            /* 28 Sep 2026: tagihan tertandai gagal di tengah jalan, dan layar terus berbunyi
               "Menyiapkan kode QR…" — pelanggan menunggu sesuatu yang tidak akan datang, dan
               tidak tahu harus bicara ke kasir. Keadaan yang tidak bisa dipulihkan sendiri
               harus mengatakannya. */
            ? '<p class="warn">Kode QR ini tidak lagi berlaku. Mohon beri tahu kasir — <b>jangan mengulang pembayaran</b> sebelum kasir memeriksa.</p>'
            : '<p class="countdown">Menyiapkan kode QR…</p>')}
      ${q.payable === false ? '<p class="warn">Mode simulasi — kode ini bukan QRIS sungguhan dan tidak bisa dibayar dari aplikasi bank.</p>' : ''}
      <p class="countdown" id="cd"></p>
    </div>`;
  hitungMundur();
}

/* Hitung mundur berjalan di layar ini, bukan dikirim per detik dari kasir: siaran sekali per
   perubahan sudah cukup, dan angka yang bergerak tetap mulus walau kasir sedang sibuk. */
let cdTimer = null;
function hitungMundur(){
  clearInterval(cdTimer);
  const tulis = () => {
    const box = el('cd');
    if (!box) return clearInterval(cdTimer);
    const q = (keadaan && keadaan.qr) || {};
    if (q.status && q.status !== 'pending') { box.textContent = ''; return; }
    if (!q.expires_at) { box.textContent = 'Silakan pindai kode di atas dengan aplikasi pembayaran Anda.'; return; }
    const sisa = Math.max(0, Math.round((new Date(q.expires_at) - Date.now()) / 1000));
    box.className = 'countdown' + (sisa <= 0 ? ' habis' : '');
    box.innerHTML = sisa > 0
      ? 'Berlaku <b>' + Math.floor(sisa / 60) + ':' + String(sisa % 60).padStart(2, '0') + '</b> lagi'
      : 'Kode ini sudah kedaluwarsa — mohon minta kode baru ke kasir.';
  };
  tulis();
  cdTimer = setInterval(tulis, 1000);
}

function tampilLunas(s){
  el('hint').hidden = true;
  const p = s.paid || {};
  jumlahBarisSebelum = 0; kunciBarisSebelum = '';
  el('main').innerHTML = `<div class="ok">
      <div class="tick"><svg viewBox="0 0 24 24"><path d="M5 13l4.5 4.5L19 7.5"/></svg></div>
      <h1>Pembayaran diterima</h1>
      <div class="amt">${rp(p.total || 0)}</div>
      <div class="meta">${esc(p.method || '')}${p.change ? ' · kembalian ' + rp(p.change) : ''}</div>
      <div class="meta">Terima kasih, silakan ambil struk Anda.</div>
    </div>`;
}

// Layar bisa jadi basi tanpa pesan baru (kasir ditutup), jadi digambar ulang berkala.
setInterval(() => { if (!segar() && keadaan !== null) { keadaan = null; render(); } }, 2000);
render();
</script>
</body>
</html>
