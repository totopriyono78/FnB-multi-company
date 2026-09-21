import { defineConfig } from '@playwright/test';

// E2E back-office. Jalankan setelah `php artisan migrate:fresh --seed` dan server aktif:
//   php artisan serve --port=8123
//   E2E_BASE_URL=http://127.0.0.1:8123 npx playwright test
export default defineConfig({
    testDir: './tests/Browser',
    timeout: 60_000,
    fullyParallel: false,
    workers: 1,
    reporter: [['list']],
    use: {
        baseURL: process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8123',
        locale: 'id-ID',
        timezoneId: 'Asia/Jakarta',
        viewport: { width: 1366, height: 800 },
        screenshot: 'only-on-failure',
        launchOptions: process.env.PW_CHROMIUM_PATH ? { executablePath: process.env.PW_CHROMIUM_PATH } : {},
    },
});
