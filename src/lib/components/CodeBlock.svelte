<script lang="ts">
  import { onMount } from 'svelte';
  import { buildDiffRows } from '../diff';
  import { highlightPhp } from '../highlight';
  import { buildSegments } from '../render';
  import type { DiffKind, HighlightLine, RenderSegment } from '../types';
  import SlidingSwitch from './SlidingSwitch.svelte';

  export let code = '';
  export let original: string | null = null;
  export let tone: 'source' | 'pint' | 'php_cs_fixer' | 'neutral' = 'neutral';

  interface DisplayLine {
    kind: DiffKind;
    lineNumber: number | null;
    marker: string;
    segments: RenderSegment[];
  }

  let host: HTMLDivElement;
  let ready = false;
  let loading = false;
  let showDiff = true;
  let diffDisplayLines: DisplayLine[] = [];
  let plainDisplayLines: DisplayLine[] = [];
  let displayLines: DisplayLine[] = [];

  $: displayLines = original === null || showDiff ? diffDisplayLines : plainDisplayLines;

  function emptyHighlightLine(): HighlightLine {
    return {
      tokens: [],
    };
  }

  async function load(): Promise<void> {
    if (loading || ready) {
      return;
    }

    loading = true;

    try {
      const [highlightedOutput, highlightedOriginal] = await Promise.all([
        highlightPhp(code),
        original === null ? Promise.resolve<HighlightLine[]>([]) : highlightPhp(original),
      ]);

      plainDisplayLines = highlightedOutput.map((highlightLine, index) => ({
        kind: 'context',
        lineNumber: index + 1,
        marker: '',
        segments: buildSegments(highlightLine.tokens, []),
      }));

      const diffRows =
        original === null
          ? highlightedOutput.map((_, index) => ({
              kind: 'context' as const,
              originalLineIndex: null,
              outputLineIndex: index,
              ranges: [],
            }))
          : buildDiffRows(original, code);

      diffDisplayLines = diffRows.map((diffRow) => {
        const highlightLine =
          diffRow.kind === 'removed'
            ? highlightedOriginal[diffRow.originalLineIndex ?? -1] ?? emptyHighlightLine()
            : highlightedOutput[diffRow.outputLineIndex ?? -1] ?? emptyHighlightLine();

        return {
          kind: diffRow.kind,
          lineNumber:
            diffRow.kind === 'removed'
              ? (diffRow.originalLineIndex ?? -1) + 1
              : (diffRow.outputLineIndex ?? -1) + 1,
          marker:
            diffRow.kind === 'added'
              ? '+'
              : diffRow.kind === 'removed'
                ? '-'
                : '',
          segments: buildSegments(highlightLine.tokens, diffRow.ranges),
        };
      });

      ready = true;
    } finally {
      loading = false;
    }
  }

  onMount(() => {
    const observer = new IntersectionObserver(
      (entries) => {
        if (entries.some((entry) => entry.isIntersecting)) {
          void load();
          observer.disconnect();
        }
      },
      {
        rootMargin: '280px',
      },
    );

    observer.observe(host);

    return () => {
      observer.disconnect();
    };
  });
</script>

<div bind:this={host} class={`code-block tone-${tone}`}>
  {#if ready && original !== null}
    <div
      class="code-block-toggle"
      role="presentation"
      on:click|stopPropagation
      on:keydown|stopPropagation
      on:pointerdown|stopPropagation
    >
      <SlidingSwitch bind:checked={showDiff} label="Toggle diff view" offLabel="Plain" onLabel="Diff" />
    </div>
  {/if}

  <div class="code-block-frame">
    {#if ready}
      <div class="code-lines" role="presentation">
        {#each displayLines as line, index}
          <div class:added={line.kind === 'added'} class:removed={line.kind === 'removed'} class="code-line">
            <span aria-hidden="true" class="line-marker">
              {line.marker}
            </span>
            <span aria-hidden="true" class="line-number">
              {line.lineNumber ?? index + 1}
            </span>
            <span class="line-content">
              {#if line.segments.length > 0}
                {#each line.segments as segment}
                  <span
                    class:changed-fragment={segment.changed}
                    class="code-segment"
                    style:color={segment.color ?? undefined}
                    style:font-weight={segment.bold ? '700' : undefined}
                    style:font-style={segment.italic ? 'italic' : undefined}
                    style:text-decoration={segment.underline
                      ? 'underline'
                      : undefined}
                  >
                    {segment.display}
                  </span>
                {/each}
              {:else}
                <span class="code-segment empty-line">&nbsp;</span>
              {/if}
            </span>
          </div>
        {/each}
      </div>
    {:else}
      <div class="code-lines skeleton" role="presentation">
        {#each Array.from({ length: Math.min(Math.max(code.split('\n').length - 1, 3), 7) }) as _, index}
          <div class="code-line">
            <span aria-hidden="true" class="line-marker"></span>
            <span aria-hidden="true" class="line-number">
              {index + 1}
            </span>
            <span class="line-content skeleton-bar"></span>
          </div>
        {/each}
      </div>
    {/if}
  </div>
</div>
