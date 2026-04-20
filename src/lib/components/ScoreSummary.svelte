<script lang="ts">
  import { getToolForSide } from '../data';
  import type { AnswerSide, QuizQuestion } from '../types';

  export let questions: QuizQuestion[] = [];
  export let answers: Record<string, AnswerSide> = {};
  export let compact = false;
  export let onReset: (() => void) | null = null;

  $: answeredQuestions = questions.filter((question) => answers[question.rule]);
  $: answeredCount = answeredQuestions.length;
  $: pintVotes = answeredQuestions.filter(
    (question) => getToolForSide(question, answers[question.rule] as AnswerSide) === 'pint',
  ).length;
  $: phpVotes = answeredCount - pintVotes;
  $: remaining = questions.length - answeredCount;
  $: pintPercent = answeredCount > 0 ? Math.round((pintVotes / answeredCount) * 100) : 0;
  $: phpPercent = answeredCount > 0 ? 100 - pintPercent : 0;
  $: summaryCopy =
    answeredCount === 0
      ? 'Pick the formatting you prefer on each card and the site will keep score for you.'
      : answeredCount === questions.length
        ? `You currently lean ${pintPercent}% Pint and ${phpPercent}% PHP-CS-Fixer.`
        : `You have answered ${answeredCount} of ${questions.length} questions.`;
</script>

{#if compact}
  <section class="score-summary compact">
    <div class="score-copy">
      <span class="panel-eyebrow">Running score</span>
      <strong>{answeredCount}/{questions.length} answered</strong>
      <p>{summaryCopy}</p>
    </div>

    <div class="score-bars">
      <div class="score-bar pint">
        <span class="score-bar-label">Pint</span>
        <div class="score-track">
          <span class="score-fill" style:width={`${pintPercent}%`}></span>
        </div>
        <strong>{pintVotes}</strong>
      </div>
      <div class="score-bar php">
        <span class="score-bar-label">PHP-CS-Fixer</span>
        <div class="score-track">
          <span class="score-fill" style:width={`${phpPercent}%`}></span>
        </div>
        <strong>{phpVotes}</strong>
      </div>
    </div>
  </section>
{:else}
  <section class="score-summary full">
    <header class="summary-header">
      <div>
        <span class="panel-eyebrow">Next</span>
        <h2>The recommendation screen comes next.</h2>
        <p>
          This last section is intentionally quiet for now. Finish the remaining
          questions, review the choices above, or restart the run and compare
          again.
        </p>
      </div>

      {#if onReset}
        <button class="reset-button" type="button" on:click={() => onReset?.()}>
          Reset answers
        </button>
      {/if}
    </header>

    <div class="summary-stats">
      <div class="summary-stat neutral">
        <span class="summary-label">Answered</span>
        <strong>{answeredCount}</strong>
        <span>of {questions.length} questions</span>
      </div>

      <div class="summary-stat neutral">
        <span class="summary-label">Still open</span>
        <strong>{remaining}</strong>
        <span>questions left to decide</span>
      </div>

      <div class="summary-stat neutral">
        <span class="summary-label">Soon</span>
        <strong>Match</strong>
        <span>Recommendation and starter config</span>
      </div>
    </div>

    <p class="summary-note">
      The answers are still stored in this browser, so the future recommendation
      step can build on the same run.
    </p>
  </section>
{/if}
