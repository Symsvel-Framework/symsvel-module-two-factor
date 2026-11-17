<script lang="ts">
  import { goto } from '$app/navigation';
  import { resolve } from '$app/paths';
  import { onMount } from 'svelte';
  import {
    pendingBoSecondFactor,
    completeBoSecondFactor,
    type SecondFactorChallenge
  } from '#lib/api/session';

  let challenge = $state<SecondFactorChallenge | null>(null);
  let code = $state('');
  let error = $state('');
  let busy = $state(false);
  let recoveryCodes = $state<string[]>([]);

  onMount(() => {
    challenge = pendingBoSecondFactor();
    if (!challenge) void goto(resolve('/login'), { replaceState: true });
  });

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
      error = 'Code invalide ou expiré. Vérifiez votre application ou reconnectez-vous.';
    } finally {
      busy = false;
    }
  }
</script>

<svelte:head><title>Double authentification | SymSvel BO</title></svelte:head>

<main class="auth-shell">
  <section class="auth-card">
    <header><strong>SymSvel</strong><span>BO</span></header>
    {#if recoveryCodes.length}
      <h1>Codes de secours</h1>
      <p>Conservez ces codes dans un endroit sûr. Ils ne seront plus affichés.</p>
      <ul>
        {#each recoveryCodes as recovery (recovery)}
          <li><code>{recovery}</code></li>
        {/each}
      </ul>
      <button onclick={() => goto(resolve('/dashboard'), { replaceState: true })}>J'ai enregistré mes codes</button>
    {:else if challenge}
      <h1>{challenge.enrollment_required ? 'Activer la double authentification' : 'Double authentification'}</h1>
      {#if challenge.enrollment_required}
        <p>Ajoutez ce compte dans votre application TOTP (2FAS, Google Authenticator ou équivalent).</p>
        {#if challenge.totp_secret}
          <p>Clé de configuration manuelle :</p>
          <code>{challenge.totp_secret}</code>
        {/if}
        <p>Une fois le compte ajouté, saisissez son premier code à six chiffres.</p>
      {:else}
        <p>Saisissez un code TOTP à six chiffres ou un code de secours.</p>
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
