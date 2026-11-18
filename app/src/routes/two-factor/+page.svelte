<script lang="ts">
  import { goto } from '$app/navigation';
  import { resolve } from '$app/paths';
  import { onMount } from 'svelte';
  import QRCode from 'qrcode';
  import {
    pendingAppSecondFactor,
    completeAppSecondFactor,
    type SecondFactorChallenge
  } from '../../../../api/session';

  let challenge = $state<SecondFactorChallenge | null>(null);
  let code = $state('');
  let busy = $state(false);
  let error = $state('');
  let recoveryCodes = $state<string[]>([]);
  let qrCode = $state('');
  let qrLoading = $state(false);
  let qrFailed = $state(false);

  onMount(() => {
    challenge = pendingAppSecondFactor();
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
      recoveryCodes = await completeAppSecondFactor(code.trim());
      code = '';
      if (recoveryCodes.length === 0) {
        await goto(resolve('/dashboard'), { replaceState: true });
      }
    } catch {
      error = 'Code invalide ou expiré. Vérifiez votre application ou reconnectez-vous.';
    } finally {
      busy = false;
    }
  }
</script>

<svelte:head><title>Double authentification | SymSvel App</title></svelte:head>
<main class="auth-shell">
  <section class="auth-card">
    <header><strong>SymSvel</strong><span>App</span></header>
    {#if recoveryCodes.length}
      <h1>Codes de secours</h1>
      <p>Conservez ces codes dans un endroit sûr. Ils ne seront affichés qu'une seule fois.</p>
      <ul>
        {#each recoveryCodes as recovery (recovery)}
          <li><code>{recovery}</code></li>
        {/each}
      </ul>
      <button onclick={() => goto(resolve('/dashboard'), { replaceState: true })}>J'ai enregistré mes codes</button>
    {:else if challenge}
      <h1>{challenge.enrollment_required ? 'Activer la double authentification' : 'Double authentification'}</h1>
      {#if challenge.enrollment_required}
        <p>Scannez ce QR code avec votre application d'authentification TOTP.</p>
        {#if qrCode}
          <div class="totp-qr">
            <img src={qrCode} alt="QR code de configuration de la double authentification" width="240" height="240" />
          </div>
        {:else if qrLoading}
          <p role="status">Génération du QR code…</p>
        {:else if qrFailed}
          <p role="status">QR code indisponible. Utilisez la configuration manuelle ci-dessous.</p>
        {/if}
        {#if challenge.totp_secret}
          <details open={qrFailed}>
            <summary>Configuration manuelle</summary>
            <p>Si vous ne pouvez pas scanner le QR code, ajoutez cette clé dans votre application :</p>
            <code class="totp-secret">{challenge.totp_secret}</code>
          </details>
        {/if}
        <p>Une fois le compte ajouté, saisissez son premier code à six chiffres.</p>
      {:else}
        <p>Saisissez votre code TOTP ou un code de secours.</p>
      {/if}
      {#if error}<p class="alert" role="alert">{error}</p>{/if}
      <form class="form" onsubmit={submit}>
        <label for="two-factor-code">Code de vérification</label>
        <input id="two-factor-code" type="text" inputmode="text" autocomplete="one-time-code" bind:value={code} required />
        <button type="submit" disabled={busy}>{busy ? 'Vérification…' : 'Valider'}</button>
      </form>
      <p><a href={resolve('/login')}>Recommencer la connexion</a></p>
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
  }
</style>
