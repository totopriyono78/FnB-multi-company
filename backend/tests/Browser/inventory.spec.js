import { test, expect } from '@playwright/test';
import { klikNavigasi } from './support/spa.js';
import AxeBuilder from '@axe-core/playwright';

const OWNER = { email: 'rina@gtgroup.test', password: 'Rahasia123' };
const WAREHOUSE = { email: 'rudi@gtgroup.test', password: 'Rahasia123' };
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
    const results = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
        .analyze();
    const serious = results.violations.filter((v) => ['serious', 'critical'].includes(v.impact));
    if (serious.length) {
        console.log('AXE', name, JSON.stringify(serious.map((v) => ({ id: v.id, nodes: v.nodes.slice(0, 3).map((n) => n.html.slice(0, 200)) }))));
    }
    expect(serious, `pelanggaran WCAG serius di ${name}`).toEqual([]);
}

/** Select Filament yang bisa dicari (Choices.js) berdasarkan teks labelnya. */
function searchable(page, label) {
    return page.locator('.fi-fo-field-wrp', { has: page.locator('label', { hasText: new RegExp(`^\\s*${label}\\s*\\*?\\s*$`) }) }).locator('.choices').first();
}

/** Pilih opsi pada Select Filament yang bisa dicari (Choices.js). */
async function choose(page, field, option) {
    await field.click();
    const search = page.locator('.choices.is-open input[type="search"]');
    if (await search.count()) {
        await search.fill(option);
    }
    await page.locator('.choices.is-open .choices__item--choice', { hasText: option }).first().click();
}

test('pemilik memantau stok, menyetujui PO & opname, dan melihat food cost', async ({ page }) => {
    const base = await login(page, OWNER);

    await expect(page.getByText('Stok kritis')).toBeVisible();
    await expect(page.getByText('PO menunggu persetujuan')).toBeVisible();
    await expect(page.getByText('Opname menunggu persetujuan')).toBeVisible();

    // Posisi stok: bahan di bawah minimum.
    await klikNavigasi(page, page.getByRole('link', { name: 'Posisi Stok' }));
    await expect(page.getByRole('heading', { name: 'Posisi Stok' })).toBeVisible();
    await expect(page.getByRole('row', { name: /Boba Brown Sugar.*Gudang Kaliurang/ }).getByText('Di bawah minimum')).toBeVisible();
    await expectAccessible(page, 'posisi stok');
    await page.screenshot({ path: `${SHOTS}/30-posisi-stok.png`, fullPage: true });

    // Kartu stok memuat pemakaian penjualan & penerimaan.
    await klikNavigasi(page, page.getByRole('link', { name: 'Kartu Stok', exact: true }));
    await expect(page.getByRole('heading', { name: 'Kartu Stok' })).toBeVisible();
    // Disaring per jenis, bukan mengandalkan halaman pertama: kartu stok berurut waktu, jadi
    // memeriksa dua jenis mutasi sekaligus akan gagal begitu transaksi baru bertambah.
    for (const [jenis, label] of [['sale', 'Pemakaian penjualan'], ['transfer_out', 'Transfer keluar']]) {
        await page.goto(`${base}/kartu-stok?tableFilters[type][values][0]=${jenis}`);
        await expect(page.getByRole('cell', { name: label }).first()).toBeVisible({ timeout: 10_000 });
    }
    await page.goto(`${base}/kartu-stok`);
    await expectAccessible(page, 'kartu stok');
    await page.screenshot({ path: `${SHOTS}/31-kartu-stok.png`, fullPage: true });

    // Resep & HPP per porsi.
    await page.goto(`${base}/resep`);
    await choose(page, searchable(page, 'Pilih'), 'Kopi Susu Hamzah');
    await expect(page.getByRole('heading', { name: 'HPP teoritis per porsi' })).toBeVisible();
    await expect(page.getByRole('table', { name: 'Rincian HPP resep' })).toContainText('Biji Kopi Arabika Gayo');
    await expectAccessible(page, 'resep');
    await page.screenshot({ path: `${SHOTS}/32-resep.png`, fullPage: true });

    // Food cost.
    await klikNavigasi(page, page.getByRole('link', { name: 'Food Cost' }));
    await expect(page.getByRole('heading', { name: 'Food Cost', exact: true })).toBeVisible();
    // Halaman ini memilih outlet pertama secara otomatis; tunjuk Kaliurang agar skenario tidak
    // bergantung pada urutan outlet.
    await page.getByRole('combobox', { name: 'Outlet' }).selectOption({ label: 'Hamzah Coffee Kaliurang (KLU)' });
    await expect(page.getByRole('table', { name: 'Food cost per menu' })).toContainText('Kopi Susu Hamzah · Regular');
    await expect(page.getByText('Food cost aktual').first()).toBeVisible();
    await expectAccessible(page, 'food cost');
    await page.screenshot({ path: `${SHOTS}/33-food-cost.png`, fullPage: true });

    // PO menunggu persetujuan.
    await klikNavigasi(page, page.getByRole('link', { name: 'Purchase Order' }));
    const waiting = page.getByRole('row', { name: /Menunggu persetujuan/ });
    await expect(waiting).toContainText('PT Boulangerie Nusantara');
    await expectAccessible(page, 'daftar PO');
    await klikNavigasi(page, waiting.getByRole('link', { name: 'Buka' }));
    await expect(page.getByText('Croissant Butter Beku')).toBeVisible();
    await expectAccessible(page, 'rincian PO');
    await page.getByRole('button', { name: 'Setujui' }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Ya, setujui' }).click();
    await expect(page.getByText('PO disetujui.')).toBeVisible();
    await expect(page.getByText('Disetujui', { exact: true })).toBeVisible();
    await page.screenshot({ path: `${SHOTS}/34-po-disetujui.png`, fullPage: true });

    // Opname yang diajukan gudang.
    await klikNavigasi(page, page.getByRole('link', { name: 'Stock Opname' }));
    await klikNavigasi(page, page.getByRole('row', { name: /Menunggu persetujuan/ }).getByRole('link', { name: 'Buka' }));
    await expect(page.getByText('Sistem').first()).toBeVisible();
    await expect(page.getByText('4 gelas penyok')).toBeVisible();
    await expectAccessible(page, 'rincian opname');
    await page.screenshot({ path: `${SHOTS}/35-opname.png`, fullPage: true });
    await page.getByRole('button', { name: 'Setujui & Sesuaikan Stok' }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Ya, setujui' }).click();
    await expect(page.getByText('Opname disetujui. Stok sudah disesuaikan.')).toBeVisible();
});

test('gudang mencatat waste dan menerima transfer', async ({ page }) => {
    const base = await login(page, WAREHOUSE);

    await page.goto(`${base}/penyesuaian-stok/create`);
    await expect(page.getByRole('heading', { name: 'Catat Penyesuaian / Waste' })).toBeVisible();
    await page.getByRole('combobox', { name: 'Lokasi' }).selectOption({ label: 'Hamzah Coffee Kaliurang · Gudang Kaliurang' });
    await page.getByRole('combobox', { name: 'Alasan' }).selectOption({ label: 'Tumpah / jatuh' });
    await choose(page, searchable(page, 'Bahan'), 'Susu Segar Full Cream');
    await page.getByRole('textbox', { name: /^Jumlah\*?$/ }).first().fill('250');
    await page.getByRole('textbox', { name: /^Catatan$/ }).first().fill('Tumpah saat steaming');
    await expectAccessible(page, 'form waste');
    await page.getByRole('button', { name: 'Simpan Dokumen' }).click();
    await expect(page.getByRole('heading', { name: /Dokumen WST-KLU-\d{4}-\d{4}/ })).toBeVisible();
    await expect(page.getByText('Tumpah saat steaming')).toBeVisible();
    await expect(page.getByText('-250 ml')).toBeVisible();
    await page.screenshot({ path: `${SHOTS}/36-waste.png`, fullPage: true });

    // Transfer Kaliurang → Prawirotaman yang masih dalam perjalanan.
    await page.goto(`${base}/transfer-stok`);
    await klikNavigasi(page, page.getByRole('row', { name: /Dalam pengiriman/ }).getByRole('link', { name: 'Detail' }));
    await expectAccessible(page, 'rincian transfer');
    await page.getByRole('button', { name: 'Terima Barang' }).click();
    const dialog = page.getByRole('dialog');
    await dialog.getByRole('textbox', { name: /^Jumlah diterima\*?$/ }).first().fill('1900');
    await dialog.getByLabel('Catatan penerimaan').fill('Satu pak kopi sobek');
    await dialog.getByRole('button', { name: 'Simpan Penerimaan' }).click();
    await expect(page.getByText('Transfer diterima. Stok tujuan sudah bertambah.')).toBeVisible();
    await expect(page.getByText('Diterima', { exact: true }).first()).toBeVisible();
    await page.screenshot({ path: `${SHOTS}/37-transfer-diterima.png`, fullPage: true });
});

test('kasir tidak melihat menu inventory & pembelian', async ({ page }) => {
    const base = await login(page, CASHIER);
    await expect(page.getByRole('link', { name: 'Posisi Stok' })).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Purchase Order' })).toHaveCount(0);
    const res = await page.goto(`${base}/stok`);
    expect(res.status()).toBe(403);
});
