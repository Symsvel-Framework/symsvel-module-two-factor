import { expect, test } from '@playwright/test';

const challenge = {
	second_factor_required: true,
	challenge: 'b'.repeat(43),
	challenge_expires_at: new Date(Date.now() + 4 * 60_000).toISOString(),
	enrollment_required: true,
	totp_secret: 'JBSWY3DPEHPK3PXP',
	provisioning_uri: 'otpauth://totp/SymSvel-BO?secret=JBSWY3DPEHPK3PXP'
};

const tokens = {
	access_token: 'a'.repeat(43),
	expires_at: '2099-01-01T00:00:00+00:00',
	token_type: 'Bearer'
};

test('real assembled BO follows 202 -> 2FA -> dashboard without premature refresh', async ({
	page
}) => {
	let refreshCalls = 0;
	let challengeCalls = 0;
	let verificationCalls = 0;

	await page.route('**/apibo/auth/browser/login?*', async (route) => {
		challengeCalls += 1;
		expect(route.request().method()).toBe('POST');
		await route.fulfill({ status: 202, json: challenge });
	});
	await page.route('**/apibo/auth/browser/refresh?*', async (route) => {
		refreshCalls += 1;
		await route.fulfill({ status: 401, json: { error: { code: 'invalid_session' } } });
	});
	await page.route('**/apibo/auth/browser/second-factor?*', async (route) => {
		verificationCalls += 1;
		expect(route.request().method()).toBe('POST');
		expect(route.request().postDataJSON()).toEqual({
			challenge: challenge.challenge,
			code: '123456'
		});
		await route.fulfill({ status: 200, json: tokens });
	});
	await page.route('**/apibo/auth/me?*', async (route) => {
		await route.fulfill({ status: 200, json: { id: 7, context: 'bo' } });
	});

	await page.goto('/login');
	await page.getByLabel('Adresse e-mail').fill('admin@example.test');
	await page.getByLabel('Mot de passe').fill('password');
	await page.getByRole('button', { name: 'Se connecter' }).click();

	await expect(page).toHaveURL(/\/two-factor$/);
	await expect(
		page.getByRole('heading', { name: 'Activer la double authentification' })
	).toBeVisible();
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

	expect(challengeCalls).toBe(1);
	expect(refreshCalls).toBe(0);

	// A navigation reload must not discard the short-lived challenge.
	await page.reload();
	await expect(page).toHaveURL(/\/two-factor$/);
	await expect(
		page.getByRole('heading', { name: 'Activer la double authentification' })
	).toBeVisible();
	expect(refreshCalls).toBe(0);
	await expect(page.getByRole('img', { name: 'QR code de configuration de la double authentification' })).toBeVisible();

	await page.getByLabel('Code de vérification').fill('123456');
	await page.getByRole('button', { name: 'Valider' }).click();
	await expect(page).toHaveURL(/\/dashboard$/);
	await expect(page.getByText('Administrateur #7')).toBeVisible();
	expect(verificationCalls).toBe(1);
	expect(refreshCalls).toBe(0);
	expect(
	await page.evaluate(() => sessionStorage.getItem('symsvel.bo.second-factor.challenge'))
	).toBeNull();
});

test('direct 2FA access without a challenge sends the browser back to login', async ({ page }) => {
	await page.goto('/two-factor');
	await expect(page).toHaveURL(/\/login$/);
	await expect(page.getByRole('heading', { name: 'Connexion' })).toBeVisible();
});

test('BO displays manual enrollment if the local QR cannot be generated', async ({ page }) => {
	await page.route('**/apibo/auth/browser/login?*', (route) =>
		route.fulfill({
			status: 202,
			json: { ...challenge, provisioning_uri: 'invalid' }
		})
	);
	await page.goto('/login');
	await page.getByLabel('Adresse e-mail').fill('admin@example.test');
	await page.getByLabel('Mot de passe').fill('password');
	await page.getByRole('button', { name: 'Se connecter' }).click();
	await expect(page).toHaveURL(/\/two-factor$/);
	await expect(page.getByText('QR code indisponible. Utilisez la configuration manuelle ci-dessous.')).toBeVisible();
	await expect(page.getByRole('img', { name: 'QR code de configuration de la double authentification' })).toHaveCount(0);
	const manual = page.locator('details');
	await expect(manual).toHaveAttribute('open', '');
	await expect(manual.locator('.totp-secret')).toHaveText(challenge.totp_secret);
});


test('BO recovery codes use the native confirmation button before dashboard', async ({ page }) => {
	const recoveryCodes = ['TEST-CODE-ONE', 'TEST-CODE-TWO'];
	await page.route('**/apibo/auth/browser/login?*', (route) =>
		route.fulfill({ status: 202, json: challenge })
	);
	await page.route('**/apibo/auth/browser/second-factor?*', (route) =>
		route.fulfill({ status: 200, json: { ...tokens, recovery_codes: recoveryCodes } })
	);
	await page.route('**/apibo/auth/me?*', (route) =>
		route.fulfill({ status: 200, json: { id: 7, context: 'bo' } })
	);
	await page.goto('/login');
	await page.getByLabel('Adresse e-mail').fill('admin@example.test');
	await page.getByLabel('Mot de passe').fill('password');
	await page.getByRole('button', { name: 'Se connecter' }).click();
	await expect(page).toHaveURL(/\/two-factor$/);
	await page.getByLabel('Code de vérification').fill('123456');
	await page.getByRole('button', { name: 'Valider' }).click();
	await expect(page.getByRole('heading', { name: 'Codes de secours' })).toBeVisible();
	for (const code of recoveryCodes) await expect(page.getByText(code, { exact: true })).toBeVisible();
	const confirmation = page.getByRole('button', { name: /enregistré mes codes/ });
	await expect(confirmation).toHaveClass(/\bbo-button-success\b/);
	await expect(confirmation).toHaveAttribute('type', 'button');
	await confirmation.click();
	await expect(page).toHaveURL(/\/dashboard$/);
	await expect(page.getByText('Administrateur #7')).toBeVisible();
});
