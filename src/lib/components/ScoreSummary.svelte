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
        <span class="panel-eyebrow">Result</span>
        <h2>Your formatter profile</h2>
        <p>{summaryCopy}</p>
      </div>

      {#if onReset}
        <button class="reset-button" type="button" on:click={() => onReset?.()}>
          Reset answers
        </button>
      {/if}
    </header>

    <div class="summary-stats">
      <div class="summary-stat pint">
        <span class="summary-label">Pint</span>
        <strong>{pintPercent}%</strong>
        <span>{pintVotes} picks</span>
      </div>
      <div class="summary-stat php">
        <span class="summary-label">PHP-CS-Fixer</span>
        <strong>{phpPercent}%</strong>
        <span>{phpVotes} picks</span>
      </div>
      <div class="summary-stat neutral">
        <span class="summary-label">Remaining</span>
        <strong>{remaining}</strong>
        <span>unanswered</span>
      </div>
    </div>

    <p class="summary-note">
      The score is stored in this browser so we can use it when we build the
      configuration generator next.
    </p>
  </section>
{/if}
