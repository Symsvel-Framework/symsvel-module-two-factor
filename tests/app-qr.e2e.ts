import { expect, test } from '@playwright/test';

const challenge = {
	second_factor_required: true,
	challenge: 'c'.repeat(43),
	challenge_expires_at: new Date(Date.now() + 4 * 60_000).toISOString(),
	enrollment_required: true,
	totp_secret: 'JBSWY3DPEHPK3PXP',
	provisioning_uri: 'otpauth://totp/SymSvel-App?secret=JBSWY3DPEHPK3PXP'
};

test('real assembled App creates a local QR PNG during 2FA enrollment', async ({ page }) => {
	await page.route('**/apiopen/auth/login?*', (route) =>
		route.fulfill({ status: 202, json: challenge })
	);
	await page.goto('/login');
	await page.locator('input[type=email]').fill('user@example.test');
	await page.locator('input[type=password]').fill('password');
	await page.locator('form button[type=submit]').click();
	await expect(page).toHaveURL(/\/two-factor$/);
	await expect(page.getByRole('heading', { name: 'Activer la double authentification' })).toBeVisible();
	const qr = page.getByRole('img', { name: 'QR code de configuration de la double authentification' });
	await expect(qr).toBeVisible();
	await expect(qr).toHaveAttribute('src', /^data:image\/png;base64,/);
	// Compare the image and card centers rather than relying on a CSS class alone.
	await expect.poll(() => qr.evaluate((image) => {
		const card = image.closest('.auth-card')!.getBoundingClientRect();
		const bounds = image.getBoundingClientRect();
		return Math.abs((card.left + card.width / 2) - (bounds.left + bounds.width / 2));
	})).toBeLessThan(1.5);
	await expect.poll(() => qr.evaluate((img) => (img as HTMLImageElement).naturalWidth)).toBeGreaterThan(100);
	const manual = page.locator('details');
	await expect(manual).not.toHaveAttribute('open', '');
	await manual.locator('summary').click();
	await expect(manual.locator('.totp-secret')).toHaveText(challenge.totp_secret);
});


test('App recovery codes reuse the native authentication button styling', async ({ page }) => {
	await page.route('**/apiopen/auth/login?*', (route) =>
		route.fulfill({ status: 202, json: challenge })
	);
	await page.route('**/apiopen/auth/second-factor?*', (route) =>
		route.fulfill({ status: 200, json: {
			access_token: 'a'.repeat(43),
			refresh_token: 'r'.repeat(43),
			expires_at: '2099-01-01T00:00:00+00:00',
			refresh_expires_at: '2099-01-01T00:00:00+00:00',
			token_type: 'Bearer',
			recovery_codes: ['TEST-CODE-ONE']
		} })
	);
	await page.route('**/api/auth/me?*', (route) =>
		route.fulfill({ status: 200, json: { id: 7, context: 'app' } })
	);
	await page.goto('/login');
	await page.locator('input[type=email]').fill('user@example.test');
	await page.locator('input[type=password]').fill('password');
	await page.locator('form button[type=submit]').click();
	await expect(page).toHaveURL(/\/two-factor$/);
	await page.getByLabel('Code de vérification').fill('123456');
	await page.getByRole('button', { name: 'Valider' }).click();
	await expect(page.getByRole('heading', { name: 'Codes de secours' })).toBeVisible();
	await expect(page.getByText('TEST-CODE-ONE', { exact: true })).toBeVisible();
	const confirmation = page.locator('.form').getByRole('button', { name: /enregistré mes codes/ });
	await expect(confirmation).toHaveAttribute('type', 'button');
	await confirmation.click();
	await expect(page).toHaveURL(/\/dashboard$/);
});
