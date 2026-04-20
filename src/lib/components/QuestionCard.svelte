<script lang="ts">
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

  const sides: AnswerSide[] = ['left', 'right'];

  $: chosenTool = answer ? getToolForSide(question, answer) : null;
  $: formattedRule = formatRuleName(question.rule);
  $: answerCopy = chosenTool
    ? `${getLabelForTool(chosenTool)} won this round.`
    : 'Choose the output you would merge for this rule.';
</script>

<article class:selected class="question-card" id={`question-${question.rule}`}>
  <header class="question-header">
    <div class="question-heading">
      <span class="question-index">Rule {index + 1}</span>
      <h2>{formattedRule}</h2>
      <p>{question.fixer.summary}</p>
    </div>
  </header>

  <section class="question-source">
    <div class="panel-copy">
      <div class="panel-header">
        <div>
          <span class="panel-eyebrow">Original sample</span>
          <h3>Code before either formatter touches it</h3>
        </div>
      </div>
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
              {answer !== undefined
                ? 'Revealed'
                : side === 'left'
                  ? 'Option A'
                  : 'Option B'}
            </span>

            <h3 class:tool-pint={tool === 'pint' && answer !== undefined} class:tool-php_cs_fixer={tool === 'php_cs_fixer' && answer !== undefined}>
              {#if answer !== undefined}
                {getShortLabelForTool(tool)}
              {:else if side === 'left'}
                First output
              {:else}
                Second output
              {/if}
            </h3>
          </div>

          <span class:vote-pill={answer === side} class="change-pill">
            {#if answer === side}
              Chosen
            {:else if answer !== undefined}
              Other option
            {:else}
              {state.changed ? 'Reformats sample' : 'Keeps sample as-is'}
            {/if}
          </span>
        </div>

        <CodeBlock code={state.output} original={question.source.code} tone={tool} />

        <div class="option-footer">
          <span class="option-hint">
            {#if answer === side}
              Selected output
            {:else if answer !== undefined}
              Not selected
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
