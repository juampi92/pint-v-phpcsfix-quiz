<script lang="ts">
  export let checked = true;
  export let disabled = false;
  export let label = 'Toggle code view';
  export let offLabel = 'Plain';
  export let onLabel = 'Diff';
</script>

<label class="sliding-switch">
  <input bind:checked aria-label={label} type="checkbox" {disabled} />

  <span aria-hidden="true" class="sliding-switch-track">
    <span class="sliding-switch-label off">{offLabel}</span>
    <span class="sliding-switch-label on">{onLabel}</span>
    <span class="sliding-switch-thumb"></span>
  </span>
</label>

<style>
  .sliding-switch {
    position: relative;
    display: inline-flex;
    flex: none;
    align-items: center;
    justify-content: center;
    user-select: none;
  }

  .sliding-switch input {
    position: absolute;
    inset: 0;
    margin: 0;
    opacity: 0;
    cursor: pointer;
  }

  .sliding-switch input:disabled {
    cursor: not-allowed;
  }

  .sliding-switch-track {
    position: relative;
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    align-items: center;
    width: 5.5rem;
    height: 1.75rem;
    padding: 0.12rem;
    border: 1px solid rgba(17, 36, 51, 0.14);
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.88);
    box-shadow:
      inset 0 1px 0 rgba(255, 255, 255, 0.8),
      0 10px 20px rgba(17, 36, 51, 0.08);
    transition:
      border-color 160ms ease,
      box-shadow 160ms ease,
      background-color 160ms ease;
  }

  .sliding-switch-thumb {
    position: absolute;
    inset: 0.12rem auto 0.12rem 0.12rem;
    width: calc(50% - 0.12rem);
    border-radius: 999px;
    background:
      linear-gradient(180deg, rgba(255, 255, 255, 0.95), rgba(241, 244, 247, 0.92)),
      rgba(255, 255, 255, 0.9);
    box-shadow:
      0 6px 14px rgba(17, 36, 51, 0.08),
      inset 0 1px 0 rgba(255, 255, 255, 0.95);
    transition: transform 180ms ease, background-color 180ms ease;
  }

  .sliding-switch-label {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    color: rgba(93, 103, 112, 0.85);
    font-size: 0.68rem;
    font-weight: 800;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    transition: color 160ms ease;
  }

  .sliding-switch input:checked + .sliding-switch-track {
    border-color: rgba(47, 95, 135, 0.24);
    background: rgba(255, 255, 255, 0.96);
  }

  .sliding-switch input:checked + .sliding-switch-track .sliding-switch-thumb {
    transform: translateX(100%);
  }

  .sliding-switch input:not(:checked) + .sliding-switch-track .sliding-switch-label.off,
  .sliding-switch input:checked + .sliding-switch-track .sliding-switch-label.on {
    color: var(--ink);
  }

  .sliding-switch input:focus-visible + .sliding-switch-track {
    outline: 3px solid rgba(47, 95, 135, 0.26);
    outline-offset: 2px;
  }

  .sliding-switch input:disabled + .sliding-switch-track {
    opacity: 0.55;
    cursor: not-allowed;
  }
</style>
