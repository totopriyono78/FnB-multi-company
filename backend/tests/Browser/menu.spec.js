import { test, expect } from '@playwright/test';
import { klikNavigasi } from './support/spa.js';
import AxeBuilder from '@axe-core/playwright';

const OWNER = { email: 'rina@gtgroup.test', password: 'Rahasia123' };
const CASHIER = { email: 'andi@gtgroup.test', password: 'Rahasia123' };
const SHOTS = process.env.E2E_SCREENSHOTS ?? 'test-results/screens';

async function login(page, { email, password }) {
    await page.goto('/admin/login');
    await page.getByLabel('Email atau nomor HP').fill(email);
    await page.getByLabel('Kata sandi').fill(password);
    await page.getByRole('button', { name: 'Masuk', exact: true }).click();
    await page.waitForURL(/\/admin\/(?!login)[a-z0-9-]+$/);
    return page.url();
}

async function expectAccessible(page, name) {
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(500);
    // Tabel Livewire sempat kosong saat memuat ulang. Memeriksa tabel kosong menghasilkan
    // temuan palsu ("link tanpa teks"), jadi tunggu sampai semua tautan barisnya berisi teks.
    const baris = page.locator('.fi-ta-row');
    if (await baris.count()) {
        await expect(baris.first()).toContainText(/\S/);
    }
    const results = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
        .analyze();
    const serious = results.violations.filter((v) => ['serious', 'critical'].includes(v.impact));
    if (serious.length) {
        console.log('AXE', name, JSON.stringify(serious.map((v) => ({ id: v.id, nodes: v.nodes.slice(0, 3).map((n) => n.html.slice(0, 200)) }))));
    }
    expect(serious, `pelanggaran WCAG serius di ${name}`).toEqual([]);
}

test('pemilik melihat dan mengubah menu, promo, dan simulasi harga', async ({ page }) => {
    const base = await login(page, OWNER);

    // Daftar menu hasil seeder
    await klikNavigasi(page, page.getByRole('link', { name: 'Daftar Menu' }));
    // Dicari lebih dulu: daftar menu berhalaman, jadi baris tertentu belum tentu ada di halaman 1
    // begitu data contoh bertambah. Tabel ditunggu terisi dulu agar isian pencarian tidak
    // tertimpa render pertama Livewire.
    await page.waitForLoadState('networkidle');
    await page.getByPlaceholder('Cari nama atau SKU').fill('kopi susu');
    await expect(page.getByRole('cell', { name: /Kopi Susu Hamzah/ })).toBeVisible({ timeout: 10_000 });
    await page.getByPlaceholder('Cari nama atau SKU').fill('paket');
    await expect(page.getByRole('cell', { name: /Paket Sarapan/ })).toBeVisible();
    await expect(page.getByRole('cell', { name: /Kopi Susu Hamzah/ })).toHaveCount(0);
    // Tombol hapus pencarian harus punya nama aksesibel.
    await expect(page.getByRole('button', { name: 'Hapus semua filter' })).toBeVisible();
    await expectAccessible(page, 'daftar menu (pencarian aktif)');
    await page.getByPlaceholder('Cari nama atau SKU').fill('');
    await expect(page.getByRole('button', { name: 'Hapus semua filter' })).toHaveCount(0);
    await expectAccessible(page, 'daftar menu');
    await page.screenshot({ path: `${SHOTS}/10-menu.png`, fullPage: true });

    // Tambah menu baru
    await klikNavigasi(page, page.getByRole('link', { name: 'Tambah Menu' }));
    // Form menu memuat aset unggah foto; menyentuh isian sebelum Livewire siap membuat
    // perubahan brand tidak terkirim, sehingga pilihan kategorinya tidak pernah muncul.
    await page.waitForLoadState('networkidle');
    await page.getByRole('combobox', { name: 'Brand' }).selectOption({ label: 'Hamzah Coffee' });
    await expect(page.locator('#data\\.category_id option', { hasText: 'Non-Kopi' })).toHaveCount(1, { timeout: 15_000 });
    await page.getByRole('combobox', { name: 'Kategori' }).selectOption({ label: 'Non-Kopi' });
    const sku = `E2E-${Date.now() % 100000}`;
    await page.getByRole('textbox', { name: /^SKU\*?$/ }).fill(sku);
    await page.getByRole('textbox', { name: /^Nama menu\*?$/ }).fill('Es Teh Lemon');
    await page.getByRole('textbox', { name: /^Harga dasar\*?$/ }).fill('15000');
    await expectAccessible(page, 'tambah menu');
    await page.screenshot({ path: `${SHOTS}/11-menu-baru.png`, fullPage: true });
    await page.getByRole('button', { name: 'Simpan Menu' }).click();
    await expect(page).toHaveURL(/\/menu\/.+\/edit/, { timeout: 15_000 });
    await expect(page.getByRole('heading', { name: /Ubah Menu: Es Teh Lemon/ })).toBeVisible();

    // Halaman ubah menu dengan harga khusus & riwayat harga
    await page.goto(`${base}/menu`);
    await page.waitForLoadState('networkidle');
    await page.getByPlaceholder('Cari nama atau SKU').fill('kopi susu');
    await expect(page.getByRole('cell', { name: /Kopi Susu Hamzah/ })).toBeVisible({ timeout: 10_000 });
    await page.getByRole('cell', { name: /Kopi Susu Hamzah/ }).click();
    await expect(page.getByRole('tab', { name: 'Varian & Modifier' })).toBeVisible();
    await page.getByRole('tab', { name: 'Varian & Modifier' }).click();
    await expect(page.getByRole('textbox', { name: /^Nama varian/ }).nth(1)).toHaveValue('Large');
    await expect(page.getByRole('tab', { name: 'Harga Khusus' })).toBeVisible();
    await expect(page.getByRole('tab', { name: 'Riwayat Harga' })).toBeVisible();
    await expectAccessible(page, 'ubah menu');
    await page.screenshot({ path: `${SHOTS}/12-menu-ubah.png`, fullPage: true });

    // Promo
    await klikNavigasi(page, page.getByRole('link', { name: 'Promo' }));
    await expect(page.getByRole('cell', { name: /Happy Hour Kopi 20%/ })).toBeVisible();
    await expectAccessible(page, 'promo');
    await page.getByRole('cell', { name: /Happy Hour Kopi 20%/ }).click();
    await expect(page.getByRole('heading', { name: 'Ubah Promo' })).toBeVisible();
    await expect(page.getByRole('checkbox', { name: 'Kopi', exact: true })).toBeChecked();
    await expectAccessible(page, 'ubah promo');
    await page.screenshot({ path: `${SHOTS}/13-promo.png`, fullPage: true });

    // Simulasi harga: Kopi Susu Regular di Kaliurang (PB1 10%, pembulatan Rp100)
    await klikNavigasi(page, page.getByRole('link', { name: 'Simulasi Harga' }));
    await page.getByRole('combobox', { name: 'Outlet' }).selectOption({ label: 'Hamzah Coffee Kaliurang (KLU)' });
    // Waktu dipatok di luar jam promo (Happy Hour Kopi 14.00-17.00), supaya angka yang diuji
    // tidak berubah tergantung jam berapa rangkaian uji ini dijalankan.
    const hariIni = new Date().toISOString().slice(0, 10);
    await page.getByLabel('Waktu pesanan').fill(`${hariIni}T11:00`);
    const menuSelect = page.getByRole('combobox', { name: /^Menu\*?$/ }).first();
    await menuSelect.selectOption({ label: 'Kopi Susu Hamzah' });
    await page.getByRole('button', { name: 'Hitung' }).click();
    // Menu bervarian wajib memilih varian → sistem menolak dengan pesan yang jelas.
    await expect(page.getByText('Pesanan tidak dapat dihitung')).toBeVisible();
    await expect(page.getByText('Pilih varian untuk Kopi Susu Hamzah.')).toBeVisible();
    await expectAccessible(page, 'simulasi harga (galat)');

    await menuSelect.selectOption({ label: 'Croissant Butter' });
    await page.getByRole('button', { name: 'Hitung' }).click();
    // 22.000 + PB1 2.200 = 24.200
    await expect(page.locator('.fnb-totals__grand')).toContainText('Rp24.200');

    // Kopi Susu Large + Tingkat Gula + Suhu + Extra Shot: 24.000 + 6.000 = 30.000; PB1 3.000 → 33.000
    await menuSelect.selectOption({ label: 'Kopi Susu Hamzah' });
    await page.getByRole('combobox', { name: 'Varian' }).first().selectOption({ label: 'Large' });
    await page.getByRole('checkbox', { name: 'Tingkat Gula: Normal' }).check();
    await page.getByRole('checkbox', { name: 'Suhu: Dingin' }).check();
    await page.getByRole('checkbox', { name: /Tambahan Kopi: Extra Shot/ }).check();
    await page.getByRole('button', { name: 'Hitung' }).click();
    await expect(page.locator('.fnb-totals__grand')).toContainText('Rp33.000');
    await expectAccessible(page, 'simulasi harga');
    await page.screenshot({ path: `${SHOTS}/14-simulasi.png`, fullPage: true });
});

test('kasir menandai menu habis di outletnya', async ({ page }) => {
    const base = await login(page, CASHIER);
    await expect(page.getByRole('link', { name: 'Daftar Menu' })).toHaveCount(0);

    await page.goto(`${base}/ketersediaan-menu`);
    await expect(page.getByRole('heading', { name: 'Ketersediaan Menu' })).toBeVisible();
    await page.getByPlaceholder('Cari').fill('pisang');
    const toggle = page.getByRole('switch', { name: 'Tandai habis: Pisang Goreng Keju' });
    await expect(toggle).toHaveAttribute('aria-checked', 'false');
    // Dapat dioperasikan dengan keyboard.
    await toggle.focus();
    await page.keyboard.press('Space');
    await expect(toggle).toHaveAttribute('aria-checked', 'true');
    await page.reload();
    await page.getByPlaceholder('Cari').fill('pisang');
    await expect(page.getByRole('switch', { name: 'Tandai habis: Pisang Goreng Keju' })).toHaveAttribute('aria-checked', 'true');
    // Kasir tidak boleh menyembunyikan menu: sakelar "Tampil di POS" nonaktif.
    await expect(page.getByRole('switch', { name: 'Tampil di POS: Pisang Goreng Keju' })).toHaveAttribute('aria-disabled', 'true');
    await expectAccessible(page, 'ketersediaan menu');
    await page.screenshot({ path: `${SHOTS}/15-ketersediaan.png`, fullPage: true });
});
