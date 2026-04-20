<script lang="ts">
  import { onDestroy } from 'svelte';
  import Keycap from './Keycap.svelte';
  import CodeBlock from './CodeBlock.svelte';
  import {
    formatRuleName,
    getLabelForTool,
    getShortLabelForTool,
    getStateForSide,
    getToolForSide,
  } from '../data';
  import type { AnswerSide, QuizQuestion } from '../types';

  export let question: QuizQuestion;
  export let index: number;
  export let answer: AnswerSide | undefined = undefined;
  export let selected = false;
  export let onChoose: (side: AnswerSide) => void = () => {};
  let copiedRule = false;
  let copyResetTimer: number | null = null;

  const sides: AnswerSide[] = ['left', 'right'];

  $: chosenTool = answer ? getToolForSide(question, answer) : null;
  $: formattedRule = formatRuleName(question.rule);
  $: answerCopy = chosenTool
    ? `You voted for ${getLabelForTool(chosenTool)}.`
    : 'Use the arrow keys or click either output to vote on this rule.';

  function copyRuleName(): void {
    if (!navigator.clipboard) {
      return;
    }

    void navigator.clipboard.writeText(question.rule);

    copiedRule = true;

    if (copyResetTimer !== null) {
      window.clearTimeout(copyResetTimer);
    }

    copyResetTimer = window.setTimeout(() => {
      copiedRule = false;
      copyResetTimer = null;
    }, 1500);
  }

  onDestroy(() => {
    if (copyResetTimer !== null) {
      window.clearTimeout(copyResetTimer);
    }
  });
</script>

<article class:selected class="question-card" id={`question-${question.rule}`}>
  <header class="question-header">
    <div class="question-heading">
      <span class="question-index">Rule {index + 1}</span>
      <div class="question-title-row">
        <h2>{formattedRule}</h2>
        <button
          aria-label={`Copy rule name ${question.rule}`}
          class:copied={copiedRule}
          class="copy-button"
          type="button"
          on:click={copyRuleName}
        >
          {#if copiedRule}
            Copied
          {:else}
            Copy
          {/if}
        </button>
      </div>
      <p>{question.fixer.summary}</p>
    </div>

    <div class="question-badges">
      {#if question.fixer.is_risky}
        <span class="question-badge risky">Risky fixer</span>
      {/if}
    </div>
  </header>

  <section class="question-source">
    <div class="panel-copy">
      <div class="panel-header">
        <div>
          <span class="panel-eyebrow">Original</span>
          <h3>Starting code</h3>
        </div>

        <!-- <div class="panel-meta">
          <span class="panel-note"></span>
        </div> -->
      </div>

      {#if question.source.selection_reason}
        <p class="panel-description">{question.source.selection_reason}</p>
      {/if}
    </div>

    <CodeBlock code={question.source.code} tone="source" />
  </section>

  <div aria-hidden="true" class="question-divider"></div>

  <div class="option-grid">
    {#each sides as side}
      {@const tool = getToolForSide(question, side)}
      {@const state = getStateForSide(question, side)}
      <div
        class:chosen={answer === side}
        class:revealed={answer !== undefined}
        class={`option-card tool-${tool}`}
        aria-pressed={answer === side}
        role="button"
        tabindex="0"
        on:click={() => onChoose(side)}
        on:keydown={(event: KeyboardEvent) => {
          if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            onChoose(side);
          }
        }}
      >
        <div class="option-header">
          <div class="option-copy">
            <span class="option-eyebrow">
              <Keycap label={side === 'left' ? '←' : '→'} />
              {side === 'left' ? 'Left vote' : 'Right vote'}
            </span>

            <h3 class:tool-pint={tool === 'pint' && answer !== undefined} class:tool-php_cs_fixer={tool === 'php_cs_fixer' && answer !== undefined}>
              {#if answer !== undefined}
                {getShortLabelForTool(tool)}
              {:else if side === 'left'}
                Left output
              {:else}
                Right output
              {/if}
            </h3>
          </div>

          <span class:vote-pill={answer === side} class="change-pill">
            {#if answer === side}
              Your vote
            {:else}
              {state.changed ? 'Changes applied' : 'No changes'}
            {/if}
          </span>
        </div>

        <CodeBlock code={state.output} original={question.source.code} tone={tool} />

        <div class="option-footer">
          <span class="option-hint">
            {#if answer === side}
              Selected output
            {:else if answer !== undefined}
              Other output
            {:else}
              Click to choose
            {/if}
          </span>
        </div>
      </div>
    {/each}
  </div>

  <p aria-live="polite" class:answered={answer !== undefined} class="answer-reveal">
    {answerCopy}
  </p>
</article>
