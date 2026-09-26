import { test, expect } from '@playwright/test';

/*
 * Geometri struk cetak.
 *
 * Struk disusun dengan lebar kolom tetap (42 kolom di kertas 80 mm, 32 di 58 mm) dan
 * kerapiannya bergantung pada seluruh kolom itu muat dalam satu baris. Pernah tidak:
 * ukuran huruf 11pt membuat baris 42 karakter memerlukan ±103 mm di kertas 72 mm, sehingga
 * kolom harga terpotong di printer termal — tidak terlihat saat mencetak ke A4 karena
 * halamannya lebar. Uji ini mengukurnya, bukan menilai dari tampilan.
 */

for (const [lebarKertas, cssLebar] of [[80, '72mm'], [58, '50mm']]) {
    test(`ukur slip ${lebarKertas}mm`, async ({ page }) => {
        await page.goto('/pos');
        const hasil = await page.evaluate((w) => {
            savePrefs({ w });
            applyPaper();
            const P = slip();
            // Baris terpanjang yang mungkin: garis pemisah selebar kolom penuh.
            const teks = [P.rule.trimEnd(), P.row('TOTAL', '1.200').trimEnd(), P.mid('UJI CETAK').trimEnd()].join('\n');
            const el = document.getElementById('printSlip');
            el.innerHTML = '';
            const s = document.createElement('span');
            s.textContent = teks;
            el.appendChild(s);
            return { kolom: P.W, panjangBaris: P.rule.trimEnd().length };
        }, lebarKertas);

        await page.emulateMedia({ media: 'print' });
        const ukur = await page.evaluate(() => {
            const el = document.getElementById('printSlip');
            const r = el.getBoundingClientRect();
            const sr = el.querySelector('span').getBoundingClientRect();
            const mm = (px) => +(px / 96 * 25.4).toFixed(1);
            const gaya = getComputedStyle(el);
            const padKiri = parseFloat(gaya.paddingLeft), padKanan = parseFloat(gaya.paddingRight);
            return {
                slipKiri: mm(r.left), slipLebar: mm(r.width),
                areaTeks: mm(el.clientWidth - padKiri - padKanan), teksLebar: mm(sr.width),
                huruf: gaya.fontSize,
            };
        });
        console.log(`UKUR ${lebarKertas}mm`, JSON.stringify({ ...hasil, ...ukur }));

        // Teks harus muat di dalam area aman, bukan cuma di dalam kertas.
        expect(ukur.teksLebar, 'baris terpanjang harus muat di area cetak').toBeLessThanOrEqual(ukur.areaTeks + 0.2);
        expect(ukur.slipKiri, 'slip tidak boleh menempel tepi kiri kertas lebar').toBeGreaterThan(1);
    });
}

test('pratinjau struk di layar berada di tengah, barisnya tetap rata kiri', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto('/pos');
    await page.evaluate(() => {
        savePrefs({ w: 80 });
        applyPaper();
        const P = slip();
        document.getElementById('rcpt').textContent =
            [P.rule, P.row('TOTAL', '28.200'), P.row('Tunai', '50.000')].join('');
        document.getElementById('rcptModal').classList.add('on');
    });

    const ukur = await page.evaluate(() => {
        const el = document.getElementById('rcpt');
        const wadah = el.parentElement;
        const r = el.getBoundingClientRect();
        const w = wadah.getBoundingClientRect();
        return {
            kiri: r.left - w.left,
            kanan: w.right - r.right,
            menyusutKeIsi: r.width < w.width - 20,
            perataanTeks: getComputedStyle(el).textAlign,
        };
    });

    // Kotaknya menyusut seukuran isi lalu dipusatkan — jarak kiri dan kanan sama.
    expect(ukur.menyusutKeIsi, 'kotak struk harus seukuran isinya, bukan selebar modal').toBe(true);
    expect(Math.abs(ukur.kiri - ukur.kanan), 'kotak struk harus di tengah').toBeLessThan(2);
    // Yang dipusatkan kotaknya, bukan teksnya: baris struk wajib tetap rata kiri
    // supaya kolom harga yang rata kanan tidak berantakan.
    expect(ukur.perataanTeks).not.toBe('center');
});
