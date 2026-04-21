<script lang="ts">
  import CodeBlock from './CodeBlock.svelte';
  import { formatRuleName } from '../data';
  import type { FormatterExportSection } from '../formatter-export';
  import type { ResultProfile } from '../results';

  export let sections: FormatterExportSection[] = [];
  export let resultProfile: ResultProfile;

  $: totalRules = sections.reduce((count, section) => count + section.rules.length, 0);
  $: hasAnswers = resultProfile.answeredCount > 0;
</script>

<section class="formatter-export">
  <header class="formatter-export-header">
    <div class="formatter-export-copy">
      <span class="panel-eyebrow">Configuration export</span>
      <h2>Mirror the selected rules</h2>
      <p>
        {#if hasAnswers}
          {#if resultProfile.recommendation}
            Start from {resultProfile.pathBaseLabel} and copy the opposite-side choices into the
            formatter that needs them.
          {:else}
            The recommendation is still provisional, but the footer can already split your answers
            into Pint and PHP-CS-Fixer fragments.
          {/if}
        {:else}
          Answer a few rules and the footer will split the selected configuration into Pint and
          PHP-CS-Fixer fragments.
        {/if}
      </p>
    </div>

    <span class="progress-pill">{totalRules} rules</span>
  </header>

  <div class="formatter-export-grid">
    {#each sections as section}
      <article
        class:php={section.tool === 'php_cs_fixer'}
        class:pint={section.tool === 'pint'}
        class="footer-card export-panel"
      >
        <div class="export-panel-header">
          <div class="export-panel-copy">
            <span class="panel-eyebrow">Apply to {section.label}</span>
            <strong>{section.rules.length} {section.rules.length === 1 ? 'rule' : 'rules'}</strong>
            <p>{section.intro}</p>
          </div>

          <span class="export-count">{section.rules.length}</span>
        </div>

        {#if section.rules.length > 0}
          <div class="export-snippet">
            <CodeBlock code={section.snippet} original={null} tone={section.tool} />
          </div>

          <p class="export-note">
            Each chip links to the original sample file and the formatter source that produced the
            selected output.
          </p>

          <div class="path-chips export-chips">
            {#each section.rules.slice(0, 6) as rule}
              {#if rule.sourceUrl}
                <a
                  aria-label={rule.tooltip}
                  class="path-chip"
                  href={rule.sourceUrl}
                  rel="noreferrer"
                  target="_blank"
                  title={rule.tooltip}
                >
                  {formatRuleName(rule.rule)}
                </a>
              {:else}
                <span
                  class="path-chip muted"
                  title={rule.tooltip}
                >
                  {formatRuleName(rule.rule)}
                </span>
              {/if}
            {/each}

            {#if section.rules.length > 6}
              <span class="path-chip muted">+{section.rules.length - 6} more</span>
            {/if}
          </div>
        {:else}
          <p class="export-empty">{section.emptyLabel}</p>
        {/if}
      </article>
    {/each}
  </div>
</section>
