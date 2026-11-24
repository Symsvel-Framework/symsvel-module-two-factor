<script lang="ts">
  import { getProjectBrand } from '../../../../branding';
  const brandName = getProjectBrand();
  import { goto } from '$app/navigation';
  import { resolve } from '$app/paths';
  import { onMount } from 'svelte';
  import QRCode from 'qrcode';
  import { uiLanguage } from '../../../../api/locales';
  import { generatedTranslation } from '../../../../i18n/generated-translations';
  import {
    pendingBoSecondFactor,
    completeBoSecondFactor,
    type SecondFactorChallenge
  } from '../../../../api/session';

  function tr(key: string, fallback: string): string {
    return generatedTranslation($uiLanguage, 'bo.two-factor.' + key) ?? fallback;
  }

  let challenge = $state<SecondFactorChallenge | null>(null);
  let code = $state('');
  let error = $state('');
  let busy = $state(false);
  let recoveryCodes = $state<string[]>([]);
  let qrCode = $state('');
  let qrLoading = $state(false);
  let qrFailed = $state(false);

  onMount(() => {
    challenge = pendingBoSecondFactor();
    if (!challenge) {
      void goto(resolve('/login'), { replaceState: true });
      return;
    }
    if (challenge.enrollment_required) {
      if (challenge.provisioning_uri?.startsWith('otpauth://totp/')) {
        void generateQrCode(challenge.provisioning_uri);
      } else {
        qrFailed = true;
      }
    }
  });

  async function generateQrCode(uri: string): Promise<void> {
    qrLoading = true;
    try {
      // A local data URL: the TOTP secret is never sent to a remote QR service.
      qrCode = await QRCode.toDataURL(uri, { errorCorrectionLevel: 'M', margin: 2, width: 240 });
    } catch {
      qrFailed = true;
    } finally {
      qrLoading = false;
    }
  }

  async function submit(event: SubmitEvent) {
    event.preventDefault();
    if (!challenge || busy) return;
    busy = true;
    error = '';
    try {
      recoveryCodes = await completeBoSecondFactor(code.trim());
      code = '';
      if (recoveryCodes.length === 0) {
        await goto(resolve('/dashboard'), { replaceState: true });
      }
    } catch {
      error = tr('invalidCode', "Code invalide ou expiré. Vérifiez votre application ou reconnectez-vous.");
    } finally {
      busy = false;
    }
  }
</script>

<svelte:head><title>{tr('title', "Double authentification")} | {$brandName} BO</title></svelte:head>

<main class="auth-shell">
  <section class="auth-card">
    <header><strong>{$brandName}</strong><span>BO</span></header>
    {#if recoveryCodes.length}
      <h1>{tr('recoveryTitle', "Codes de secours")}</h1>
      <p>{tr('recoveryHint', "Conservez ces codes dans un endroit sûr. Ils ne seront plus affichés.")}</p>
      <ul>
        {#each recoveryCodes as recovery (recovery)}
          <li><code>{recovery}</code></li>
        {/each}
      </ul>
      <button onclick={() => goto(resolve('/dashboard'), { replaceState: true })}>{tr('saved', "J'ai enregistré mes codes")}</button>
    {:else if challenge}
      <h1>{challenge.enrollment_required ? tr('enroll', "Activer la double authentification") : tr('title', "Double authentification")}</h1>
      {#if challenge.enrollment_required}
        <p>{tr('qrInstruction', "Scannez ce QR code avec votre application d'authentification TOTP.")}</p>
        {#if qrCode}
          <div class="totp-qr">
            <img src={qrCode} alt={tr('qrAlt', "QR code de configuration de la double authentification")} width="240" height="240" />
          </div>
        {:else if qrLoading}
          <p role="status">{tr('qrLoading', "Génération du QR code…")}</p>
        {:else if qrFailed}
          <p role="status">{tr('qrFailed', "QR code indisponible. Utilisez la configuration manuelle ci-dessous.")}</p>
        {/if}
        {#if challenge.totp_secret}
          <details open={qrFailed}>
            <summary>{tr('manual', "Configuration manuelle")}</summary>
            <p>{tr('manualHint', "Si vous ne pouvez pas scanner le QR code, ajoutez cette clé dans votre application :")}</p>
            <code class="totp-secret">{challenge.totp_secret}</code>
          </details>
        {/if}
        <p>{tr('firstCode', "Une fois le compte ajouté, saisissez son premier code à six chiffres.")}</p>
      {:else}
        <p>{tr('challengeHint', "Saisissez un code TOTP à six chiffres ou un code de secours.")}</p>
      {/if}
      {#if error}<p class="alert" role="alert">{error}</p>{/if}
      <form class="form" onsubmit={submit}>
        <label for="two-factor-code">{tr('codeLabel', "Code de vérification")}</label>
        <input id="two-factor-code" type="text" inputmode="text" autocomplete="one-time-code" bind:value={code} required />
        <button type="submit" disabled={busy}>{busy ? tr('busy', "Vérification…") : tr('submit', "Valider")}</button>
      </form>
      <p><a href={resolve('/login')}>{tr('restart', "Recommencer la connexion")}</a></p>
    {/if}
  </section>
</main>

<style>
  .totp-qr {
    display: inline-block;
    max-width: 100%;
    margin: 1rem 0;
    padding: 0.75rem;
    background: #fff;
    border-radius: 0.5rem;
  }

  .totp-qr img {
    display: block;
    max-width: 100%;
    height: auto;
  }

  .totp-secret {
    display: block;
    overflow-wrap: anywhere;
    margin: 0.5rem 0 1rem;
    padding: 10px 12px;
    border: 1px solid var(--bo-border);
    border-radius: 8px;
    background: var(--bo-surface-muted);
    color: var(--bo-text);
  }
  .auth-card li code {
    display: inline-block;
    overflow-wrap: anywhere;
    padding: 6px 10px;
    margin: 3px 0;
    border: 1px solid var(--bo-border);
    border-radius: 8px;
    background: var(--bo-surface-muted);
    color: var(--bo-text);
  }
</style>
