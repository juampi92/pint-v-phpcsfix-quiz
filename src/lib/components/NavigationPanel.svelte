<script lang="ts">
  import Keycap from './Keycap.svelte';

  export let autoAdvanceEnabled = true;
  export let onResetProgress: () => void = () => {};
  export let onToggleAutoAdvance: (enabled: boolean) => void = () => {};

  const bindings = [
    {
      keys: ['↑', '↓'],
      label: 'Move through the quiz',
      description: 'Jump to the previous or next rule.',
    },
    {
      keys: ['←', '→'],
      label: 'Vote on the selected rule',
      description: 'Choose the output shown on the left or right.',
    },
  ];
</script>

<aside class="settings-panel">
  <header class="settings-header">
    <div>
      <span class="panel-eyebrow">Configuration</span>
      <h2>Controls and motion</h2>
      <p class="settings-note">
        Open this panel when you need a quick reminder of how the quiz moves.
      </p>
    </div>
  </header>

  <div class="bindings-list">
    {#each bindings as binding}
      <div class="binding-row">
        <div class="binding-keys">
          {#each binding.keys as key}
            <Keycap label={key} />
          {/each}
        </div>

        <div class="binding-copy">
          <strong>{binding.label}</strong>
          <p>{binding.description}</p>
        </div>
      </div>
    {/each}
  </div>

  <label class="settings-toggle">
    <input
      type="checkbox"
      checked={autoAdvanceEnabled}
      on:change={(event) =>
        onToggleAutoAdvance((event.currentTarget as HTMLInputElement).checked)}
    />

    <div>
      <strong>Auto-scroll after voting</strong>
      <p>Wait 2 seconds, then move to the next rule automatically.</p>
    </div>
  </label>

  <section class="settings-actions">
    <div class="settings-action-copy">
      <strong>Reset quiz progress</strong>
      <p class="settings-note">
        Clear your saved answers and jump back to the first rule. Your auto-scroll
        setting stays the same.
      </p>
    </div>

    <button class="settings-reset-button" type="button" on:click={() => onResetProgress()}>
      Start over
    </button>
  </section>

  <p class="settings-note">
    Clicking either answer card still votes directly.
  </p>
</aside>
