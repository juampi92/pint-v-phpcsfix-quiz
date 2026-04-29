<script lang="ts">
  import Keycap from './Keycap.svelte';

  export let questionsCount;
</script>

<section class="hero">
  <div class="hero-copy">
    <div class="hero-meta">
      <span class="hero-kicker">PHP-CS-Fixer defaults vs. Laravel Pint</span>
      <span class="hero-meta-note">Compare both configurations, rule by rule, side by side.</span>
    </div>

    <h1>Compare linting configs and find where your taste lands.</h1>

    <p class="hero-lead">
      <span class="tool-pint">Laravel Pint</span> is a <span class="tool-php_cs_fixer">PHP-CS-Fixer</span> config with curated defaults. Here you compare them against PHP-CS-Fixer's own defaults, one rule at a time. Vote on the code you prefer — maybe you'll drop Pint, keep it, or just understand it better.
    </p>

    <div class="hero-summary">
      <div class="hero-summary-item">
        <strong>{questionsCount}</strong>
        <span>real rule differences</span>
      </div>
    </div>
  </div>

  <div class="hero-side">
    <div class="hero-sketch" aria-hidden="true">
      <div class="hero-sticker hero-sticker-pint">
        <span class="hero-sticker-tag">Laravel Pint</span>
        <strong class="tool-pint">Opinionated config</strong>
        <p>Laravel's preset keeps a strong house style.</p>
      </div>

      <div class="hero-sticker hero-sticker-php">
        <span class="hero-sticker-tag">PHP-CS-Fixer</span>
        <strong class="tool-php_cs_fixer">Default config</strong>
        <p>Start from the fixer's raw baseline.</p>
      </div>

      <span class="hero-sketch-note">
        Pick your output. Discover your own lint alignment.
      </span>
    </div>

    <div class="hero-instructions">
      <div class="instruction-card">
        <div class="instruction-keys">
          <Keycap label="↑" />
          <Keycap label="↓" />
        </div>
        <div>
          <strong>Move through the rules</strong>
          <p>Use ↑ and ↓ to keep the current sample centered.</p>
        </div>
      </div>

      <div class="instruction-card">
        <div class="instruction-keys">
          <Keycap label="←" />
          <Keycap label="→" />
        </div>
        <div>
          <strong>Vote on the output</strong>
          <p>Use ← and → to choose a side, or click the answer card you want.</p>
        </div>
      </div>
    </div>
  </div>
</section>
