<script lang="ts">
  import { buildResultProfile } from '../results';
  import type { AnswerSide, QuizQuestion } from '../types';

  export let questions: QuizQuestion[] = [];
  export let answers: Record<string, AnswerSide> = {};
  export let compact = false;
  export let onReset: (() => void) | null = null;

  $: resultProfile = buildResultProfile(questions, answers);
  $: answeredCount = resultProfile.answeredCount;
  $: pintVotes = answeredCount - resultProfile.pintDistance;
  $: phpVotes = resultProfile.pintDistance;
  $: remaining = resultProfile.remainingCount;
  $: pintPercent = answeredCount > 0 ? Math.round((pintVotes / answeredCount) * 100) : 0;
  $: phpPercent = answeredCount > 0 ? 100 - pintPercent : 0;
  $: summaryCopy =
    answeredCount === 0
      ? 'Pick the formatting you prefer on each card and the site will keep score for you.'
      : resultProfile.provisional
        ? `Provisional read: ${resultProfile.recommendationLabel}. ${remaining} ${remaining === 1 ? 'rule still needs' : 'rules still need'} your vote.`
        : `${resultProfile.recommendationLabel} is the current match with ${resultProfile.confidenceLabel} confidence.`;
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
        <p class:provisional={resultProfile.provisional}>{summaryCopy}</p>
      </div>

      {#if onReset}
        <button class="reset-button" type="button" on:click={() => onReset?.()}>
          Reset answers
        </button>
      {/if}
    </header>

    <div class="summary-stats">
      <div
        class:php={resultProfile.recommendation === 'php_cs_fixer'}
        class:pint={resultProfile.recommendation === 'pint'}
        class="summary-stat"
      >
        <span class="summary-label">Recommendation</span>
        <strong>{resultProfile.recommendationLabel}</strong>
        <span>{resultProfile.recommendationDetail}</span>
      </div>

      <div class="summary-stat neutral">
        <span class="summary-label">Confidence</span>
        <strong>{resultProfile.confidenceLabel}</strong>
        <span>{resultProfile.confidenceDetail}</span>
      </div>

      <div class="summary-stat neutral">
        <span class="summary-label">Distance from base</span>
        <strong>{resultProfile.distanceValue}</strong>
        <span>{resultProfile.distanceDetail}</span>
      </div>
    </div>

    <p class:provisional={resultProfile.provisional} class="summary-note">
      {#if resultProfile.provisional}
        This recommendation is provisional until the remaining {remaining}
        {remaining === 1 ? 'rule is' : 'rules are'} answered.
      {:else}
        Every rule has been answered, so this result is final.
      {/if}
    </p>
  </section>
{/if}
