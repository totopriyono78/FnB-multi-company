/*
 * Back-office memakai mode SPA Filament (Livewire navigate): klik tautan tidak memuat ulang halaman,
 * tetapi mengambil isi halaman baru lalu menukarnya. Selama pengambilan berlangsung, halaman lama masih
 * tampil — sehingga isian atau tombol yang namanya sama bisa tertangkap dari halaman lama.
 * klikNavigasi() menunggu sampai penukaran selesai (event `livewire:navigated`).
 * Bila klik ternyata memicu muat ulang penuh (tab baru, halaman di luar panel), fungsi ini tidak menunggu.
 */
export async function klikNavigasi(page, locator) {
    await page.evaluate(() => {
        window.__fnbNav = { started: false };
        window.__fnbNav.done = new Promise((resolve) => {
            document.addEventListener('livewire:navigate', () => { window.__fnbNav.started = true; }, { once: true });
            document.addEventListener('livewire:navigated', () => resolve(true), { once: true });
            // Klik yang tidak memicu navigasi (mis. membuka modal) tidak ditunggu lama.
            setTimeout(() => { if (!window.__fnbNav.started) resolve(false); }, 1_500);
            setTimeout(() => resolve(false), 15_000);
        });
    });
    await locator.click();
    const navigated = await page.evaluate(() => window.__fnbNav?.done ?? true).catch(() => true);
    // Setelah halaman ditukar, Livewire bisa masih mengirim permintaan susulan (inisialisasi komponen);
    // tunggu reda agar isian yang diketik tidak tertimpa render berikutnya.
    if (navigated) await page.waitForLoadState('networkidle').catch(() => {});
}
