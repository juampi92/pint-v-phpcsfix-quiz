<svelte:head>
  <title>Pint or PHP-CS-Fixer?</title>
</svelte:head>

<script lang="ts">
  import { onMount } from 'svelte';
  import Keycap from './lib/components/Keycap.svelte';
  import NavigationPanel from './lib/components/NavigationPanel.svelte';
  import QuestionCard from './lib/components/QuestionCard.svelte';
  import ScoreSummary from './lib/components/ScoreSummary.svelte';
  import {
    formatRuleName,
    questions,
    quizDocument,
    storageKey,
  } from './lib/data';
  import {
    AUTO_ADVANCE_DELAY,
    animateVerticalScroll,
    findClosestSectionIndex,
    getSectionScrollTop,
    isEditableKeyboardTarget,
  } from './lib/navigation';
  import type { AnswerSide, StoredAnswers, UiPreferences } from './lib/types';

  let answers: Record<string, AnswerSide> = {};
  let hydrated = false;
  let autoAdvanceEnabled = true;
  let settingsOpen = false;
  let scrollNode: HTMLElement | null = null;
  let navigationNode: HTMLElement | null = null;
  let navigationHeight = 88;
  let summaryNode: HTMLElement | null = null;
  let selectedSectionIndex = -1;
  let scrollFrame: number | null = null;
  let autoAdvanceTimer: number | null = null;
  let autoAdvanceFillActive = false;
  let autoAdvanceFillKey = 0;
  let activeScrollCancel: (() => void) | null = null;
  const questionNodes = new Map<number, HTMLElement>();
  const validRules = new Set(questions.map((question) => question.rule));
  const settingsKey = `${storageKey}:ui`;
  const totalSections = questions.length + 1;

  $: answeredCount = Object.keys(answers).length;
  $: completionPercent =
    questions.length > 0 ? Math.round((answeredCount / questions.length) * 100) : 0;
  $: summarySelected = selectedSectionIndex === questions.length;
  $: selectedRuleName =
    selectedSectionIndex < 0
      ? 'Choose a rule to begin'
      : summarySelected
        ? 'Next steps placeholder'
        : formatRuleName(questions[selectedSectionIndex]?.rule ?? '');
  $: selectedPositionLabel =
    selectedSectionIndex < 0
      ? 'No rule selected'
      : summarySelected
        ? 'Final stage'
        : `Rule ${selectedSectionIndex + 1} of ${questions.length}`;
  $: hasNextSection = selectedSectionIndex < totalSections - 1;

  function sanitizeAnswers(value: unknown): Record<string, AnswerSide> {
    if (!value || typeof value !== 'object') {
      return {};
    }

    const entries = Object.entries(value as Record<string, unknown>).filter(
      ([rule, side]) =>
        validRules.has(rule) && (side === 'left' || side === 'right'),
    );

    return Object.fromEntries(entries) as Record<string, AnswerSide>;
  }

  function loadStoredAnswers(): Record<string, AnswerSide> {
    const raw = window.localStorage.getItem(storageKey);

    if (!raw) {
      return {};
    }

    try {
      const parsed = JSON.parse(raw) as StoredAnswers;

      return parsed.version === quizDocument.schema_version
        ? sanitizeAnswers(parsed.answers)
        : {};
    } catch {
      return {};
    }
  }

  function loadUiPreferences(): boolean {
    const raw = window.localStorage.getItem(settingsKey);

    if (!raw) {
      return true;
    }

    try {
      const parsed = JSON.parse(raw) as Partial<UiPreferences>;

      return parsed.autoAdvanceEnabled !== false;
    } catch {
      return true;
    }
  }

  function saveAnswers(): void {
    const payload: StoredAnswers = {
      version: quizDocument.schema_version,
      answers,
    };

    window.localStorage.setItem(storageKey, JSON.stringify(payload));
  }

  function saveUiPreferences(): void {
    const payload: UiPreferences = {
      autoAdvanceEnabled,
    };

    window.localStorage.setItem(settingsKey, JSON.stringify(payload));
  }

  function cancelAutoAdvance(): void {
    if (autoAdvanceTimer !== null) {
      window.clearTimeout(autoAdvanceTimer);
      autoAdvanceTimer = null;
    }

    autoAdvanceFillActive = false;
  }

  function startAutoAdvanceFill(): void {
    autoAdvanceFillKey += 1;
    autoAdvanceFillActive = true;
  }

  function cancelActiveScroll(): void {
    activeScrollCancel?.();
    activeScrollCancel = null;
  }

  function closeSettings(): void {
    settingsOpen = false;
  }

  function toggleSettings(): void {
    settingsOpen = !settingsOpen;
  }

  function getStickyOffset(): number {
    return (navigationNode?.offsetHeight ?? navigationHeight ?? 0) + 16;
  }

  function getSections(): HTMLElement[] {
    const questionSections = Array.from({ length: questions.length }, (_, index) =>
      questionNodes.get(index),
    ).filter((node): node is HTMLElement => node !== undefined);

    return summaryNode ? [...questionSections, summaryNode] : questionSections;
  }

  function syncSelectionFromScroll(): void {
    if (!scrollNode) {
      return;
    }

    const sections = getSections();

    if (sections.length !== totalSections) {
      return;
    }

    selectedSectionIndex = findClosestSectionIndex(
      scrollNode,
      sections,
      getStickyOffset(),
    );
  }

  function scheduleSelectionSync(): void {
    if (scrollFrame !== null) {
      return;
    }

    scrollFrame = window.requestAnimationFrame(() => {
      scrollFrame = null;
      syncSelectionFromScroll();
    });
  }

  function scrollToSection(index: number, options: { instant?: boolean } = {}): void {
    if (!scrollNode) {
      return;
    }

    const sections = getSections();
    const target = sections[index];

    if (!target) {
      return;
    }

    cancelActiveScroll();

    const top = getSectionScrollTop(scrollNode, target, getStickyOffset());

    if (options.instant) {
      scrollNode.scrollTop = top;
      syncSelectionFromScroll();
      return;
    }

    const animation = animateVerticalScroll(scrollNode, top);

    activeScrollCancel = animation.cancel;

    void animation.finished.finally(() => {
      if (activeScrollCancel === animation.cancel) {
        activeScrollCancel = null;
      }

      syncSelectionFromScroll();
    });
  }

  function moveSelection(direction: -1 | 1): void {
    closeSettings();
    cancelAutoAdvance();

    const nextIndex = Math.max(
      0,
      Math.min(selectedSectionIndex + direction, totalSections - 1),
    );

    if (nextIndex === selectedSectionIndex) {
      return;
    }

    selectedSectionIndex = nextIndex;
    scrollToSection(nextIndex);
  }

  function scheduleAutoAdvance(currentIndex: number): void {
    cancelAutoAdvance();

    if (!autoAdvanceEnabled) {
      return;
    }

    startAutoAdvanceFill();

    const nextIndex = Math.min(currentIndex + 1, totalSections - 1);

    autoAdvanceTimer = window.setTimeout(() => {
      autoAdvanceTimer = null;
      autoAdvanceFillActive = false;
      selectedSectionIndex = nextIndex;
      scrollToSection(nextIndex);
    }, AUTO_ADVANCE_DELAY);
  }

  function handleChoose(index: number, side: AnswerSide): void {
    const question = questions[index];

    if (!question) {
      return;
    }

    closeSettings();

    answers = {
      ...answers,
      [question.rule]: side,
    };

    selectedSectionIndex = index;
    scheduleAutoAdvance(index);
  }

  function resetAnswers(): void {
    cancelAutoAdvance();
    answers = {};
    window.localStorage.removeItem(storageKey);
    selectedSectionIndex = 0;
    closeSettings();

    if (!scrollNode) {
      return;
    }

    cancelActiveScroll();

    const animation = animateVerticalScroll(scrollNode, 0);

    activeScrollCancel = animation.cancel;

    void animation.finished.finally(() => {
      if (activeScrollCancel === animation.cancel) {
        activeScrollCancel = null;
      }
    });
  }

  function handleAutoAdvanceToggle(enabled: boolean): void {
    autoAdvanceEnabled = enabled;

    if (!enabled) {
      cancelAutoAdvance();
    }
  }

  function handleKeydown(event: KeyboardEvent): void {
    if (event.defaultPrevented || event.metaKey || event.ctrlKey || event.altKey) {
      return;
    }

    if (isEditableKeyboardTarget(event.target)) {
      return;
    }

    if (
      event.key === 'ArrowUp' ||
      event.key === 'ArrowDown' ||
      event.key === 'ArrowLeft' ||
      event.key === 'ArrowRight'
    ) {
      closeSettings();
    }

    if (event.key === 'ArrowUp') {
      event.preventDefault();
      moveSelection(-1);
      return;
    }

    if (event.key === 'ArrowDown') {
      event.preventDefault();
      moveSelection(1);
      return;
    }

    if (selectedSectionIndex < 0 || summarySelected) {
      return;
    }

    if (event.key === 'ArrowLeft') {
      event.preventDefault();
      handleChoose(selectedSectionIndex, 'left');
      return;
    }

    if (event.key === 'ArrowRight') {
      event.preventDefault();
      handleChoose(selectedSectionIndex, 'right');
      return;
    }

    if (event.key === 'Escape') {
      closeSettings();
    }
  }

  function handleScroll(): void {
    cancelAutoAdvance();
    closeSettings();
    scheduleSelectionSync();
  }

  function handleUserScrollIntent(): void {
    cancelAutoAdvance();
    cancelActiveScroll();
    closeSettings();
  }

  function handleNextClick(): void {
    moveSelection(1);
  }

  function trackQuestion(node: HTMLElement, index: number) {
    questionNodes.set(index, node);

    return {
      destroy() {
        questionNodes.delete(index);
      },
    };
  }

  function trackSummary(node: HTMLElement) {
    summaryNode = node;

    return {
      destroy() {
        if (summaryNode === node) {
          summaryNode = null;
        }
      },
    };
  }

  onMount(() => {
    let detachWheel = () => {};
    let detachTouch = () => {};

    answers = loadStoredAnswers();
    autoAdvanceEnabled = loadUiPreferences();
    selectedSectionIndex = -1;
    hydrated = true;

    if (scrollNode) {
      const node = scrollNode;
      const onWheel = () => handleUserScrollIntent();
      const onTouchStart = () => handleUserScrollIntent();

      node.addEventListener('wheel', onWheel, { passive: true });
      node.addEventListener('touchstart', onTouchStart, { passive: true });

      detachWheel = () => node.removeEventListener('wheel', onWheel);
      detachTouch = () => node.removeEventListener('touchstart', onTouchStart);
    }

    return () => {
      detachWheel();
      detachTouch();
      cancelAutoAdvance();
      cancelActiveScroll();

      if (scrollFrame !== null) {
        window.cancelAnimationFrame(scrollFrame);
      }
    };
  });

  $: if (hydrated) {
    saveAnswers();
  }

  $: if (hydrated) {
    saveUiPreferences();
  }
</script>

<svelte:window on:keydown={handleKeydown} />

<div class="page-shell">
  <div
    bind:this={scrollNode}
    class="page-scroll"
    style={`--nav-height: ${navigationHeight}px;`}
    on:scroll={handleScroll}
  >
    <main class="page">
      <section class="hero">
        <div class="hero-copy">
          <div class="hero-meta">
            <span class="hero-kicker">Formatter Match</span>
            <span class="hero-meta-note">A blind side-by-side for PHP style defaults</span>
          </div>

          <h1>Pick the formatter output you would actually keep.</h1>
          <p class="hero-lead">
            Compare Laravel Pint and raw PHP-CS-Fixer defaults one visible rule
            at a time. Vote on the code you would merge first. We will worry
            about naming the winner and generating configuration after that.
          </p>

          <div class="hero-summary">
            <div class="hero-summary-item">
              <strong>{quizDocument.counts.quiz_questions}</strong>
              <span>real rule differences</span>
            </div>

            <div class="hero-summary-item">
              <strong>Blind</strong>
              <span>sides stay anonymous until you choose</span>
            </div>

            <div class="hero-summary-item">
              <strong>Local</strong>
              <span>answers stay in this browser for now</span>
            </div>
          </div>
        </div>

        <div class="hero-side">
          <div class="hero-sketch" aria-hidden="true">
            <div class="hero-sticker hero-sticker-pint">
              <span class="hero-sticker-tag">Option A</span>
              <strong>One default</strong>
              <p>Opinionated and ready to go.</p>
            </div>

            <div class="hero-sticker hero-sticker-php">
              <span class="hero-sticker-tag">Option B</span>
              <strong>The other default</strong>
              <p>Rawer and more choose-your-path.</p>
            </div>

            <span class="hero-sketch-note">Choose the code, not the badge.</span>
          </div>

          <div class="hero-instructions">
            <div class="instruction-card">
              <div class="instruction-keys">
                <Keycap label="↑" />
                <Keycap label="↓" />
              </div>
              <div>
                <strong>Read the sample</strong>
                <p>Move between rules and keep the current one centered.</p>
              </div>
            </div>

            <div class="instruction-card">
              <div class="instruction-keys">
                <Keycap label="←" />
                <Keycap label="→" />
              </div>
              <div>
                <strong>Pick the output you prefer</strong>
                <p>Click a side or use the arrows. The labels reveal after the vote.</p>
              </div>
            </div>
          </div>
        </div>
      </section>

      <section
        bind:this={navigationNode}
        bind:offsetHeight={navigationHeight}
        class="quiz-nav-wrap"
      >
        <div class="quiz-nav">
          <div class="quiz-nav-copy selection-copy">
            <span class="panel-eyebrow">{summarySelected ? 'Results' : 'Selected rule'}</span>
            <strong>{selectedPositionLabel}</strong>
            <p>{selectedRuleName}</p>
          </div>

          <div class="quiz-nav-progress">
            <span class="progress-pill">{completionPercent}% complete</span>
            <div
              aria-hidden="true"
              class="progress-track"
            >
              <span class="progress-fill" style:width={`${completionPercent}%`}></span>
            </div>
          </div>

          <div class="quiz-nav-actions">
            <button
              class:pendingAutoAdvance={autoAdvanceFillActive}
              class="nav-button"
              type="button"
              disabled={!hasNextSection}
              style={`--auto-advance-duration: ${AUTO_ADVANCE_DELAY}ms;`}
              on:click|stopPropagation={handleNextClick}
            >
              {#if autoAdvanceFillActive}
                {#key autoAdvanceFillKey}
                  <span aria-hidden="true" class="nav-button-fill"></span>
                {/key}
              {/if}

              <span class="nav-button-label">Next</span>
              <Keycap label="↓" />
            </button>

            <button
              aria-expanded={settingsOpen}
              aria-label="Open configuration"
              class:active={settingsOpen}
              class="icon-button"
              type="button"
              on:click|stopPropagation={toggleSettings}
            >
              ⚙
            </button>
          </div>
        </div>

        <div
          class:open={settingsOpen}
          class="settings-drawer"
        >
          <NavigationPanel
            autoAdvanceEnabled={autoAdvanceEnabled}
            onToggleAutoAdvance={handleAutoAdvanceToggle}
          />
        </div>
      </section>

      {#if settingsOpen}
        <button
          aria-label="Close configuration"
          class="settings-backdrop"
          type="button"
          on:click={closeSettings}
        ></button>
      {/if}

      <section class="questions">
        {#each questions as question, index}
          <div
            use:trackQuestion={index}
            class:selected={selectedSectionIndex === index}
            class="question-slot"
          >
            <QuestionCard
              {index}
              answer={answers[question.rule]}
              onChoose={(side) => handleChoose(index, side)}
              selected={selectedSectionIndex === index}
              {question}
            />
          </div>
        {/each}
      </section>

      <section
        use:trackSummary
        class:selected={summarySelected}
        class="summary-slot"
      >
        <footer class="summary-stage-card">
          <ScoreSummary
            {answers}
            {questions}
            onReset={resetAnswers}
          />
        </footer>
      </section>
    </main>
  </div>
</div>
