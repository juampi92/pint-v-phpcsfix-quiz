<script lang="ts">
  import { buildResultProfile } from '../results';
  import type { AnswerSide, QuizQuestion } from '../types';

  export let questions: QuizQuestion[] = [];
  export let answers: Record<string, AnswerSide> = {};
  export let compact = false;

  $: resultProfile = buildResultProfile(questions, answers);
  $: answeredCount = resultProfile.answeredCount;
  $: remaining = resultProfile.remainingCount;
  $: pintVotes = resultProfile.pintVotes;
  $: phpVotes = resultProfile.phpVotes;
  $: pintPercent = answeredCount > 0 ? Math.round((pintVotes / answeredCount) * 100) : 0;
  $: phpPercent = answeredCount > 0 ? 100 - pintPercent : 0;
  $: recommendationAccent =
    resultProfile.recommendation === 'pint'
      ? 'var(--pint)'
      : resultProfile.recommendation === 'php_cs_fixer'
        ? 'var(--php)'
        : 'var(--muted)';
  $: summaryCopy =
    answeredCount === 0
      ? 'Pick the formatting you prefer on each rule and the score will fill in.'
      : resultProfile.recommendation
        ? `${resultProfile.recommendationLabel} is ahead right now.`
        : 'The vote is tied right now.';
  $: splitCopy =
    answeredCount === 0
      ? 'No votes yet'
      : `${phpPercent}% PHP-CS-Fixer / ${pintPercent}% Pint`;
</script>

{#if compact}
  <section class="score-summary compact">
    <div class="score-copy">
      <span class="panel-eyebrow">Running score</span>
      <strong>{answeredCount}/{questions.length} answered</strong>
      <p>{summaryCopy}</p>
    </div>

    <div class="score-bars">
      <div class="score-bar php">
        <span class="score-bar-label">PHP-CS-Fixer</span>
        <div class="score-track">
          <span class="score-fill" style:width={`${phpPercent}%`}></span>
        </div>
        <strong>{phpVotes}</strong>
      </div>
      <div class="score-bar pint">
        <span class="score-bar-label">Pint</span>
        <div class="score-track">
          <span class="score-fill" style:width={`${pintPercent}%`}></span>
        </div>
        <strong>{pintVotes}</strong>
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
    </header>

    <div class="summary-stats">
      <div
        class="summary-stat recommendation"
        style={`--summary-accent: ${recommendationAccent};`}
      >
        <span class="summary-label">Recommendation</span>
        <strong>{resultProfile.recommendationLabel}</strong>
        <span>{resultProfile.recommendationDetail}</span>
      </div>

      <div class="summary-stat split">
        <div class="summary-stat-top">
          <div>
            <span class="summary-label">Vote split</span>
            <strong>{splitCopy}</strong>
          </div>

          <span class="split-pill">{answeredCount}/{questions.length} answered</span>
        </div>

        <div
          aria-label={`Vote split: ${phpPercent}% PHP-CS-Fixer and ${pintPercent}% Pint`}
          aria-hidden={answeredCount === 0}
          class="summary-split-track"
          style={`--php-share: ${phpPercent}%; --pint-share: ${pintPercent}%;`}
        >
          <span class="summary-split-fill php"></span>
          <span class="summary-split-fill pint"></span>
        </div>

        <div class="summary-split-meta">
          <span class="split-pill php">{phpPercent}% PHP-CS-Fixer</span>
          <span class="split-pill pint">{pintPercent}% Pint</span>
        </div>
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
