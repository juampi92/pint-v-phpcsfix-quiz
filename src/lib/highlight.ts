import type { HighlightLine } from './types';

const highlightCache = new Map<string, Promise<HighlightLine[]>>();
let highlighterPromise: Promise<{
  codeToTokens: (
    code: string,
    options: { lang: 'php'; theme: 'github-light' },
  ) => {
    tokens: Array<
      Array<{
        content: string;
        color?: string;
        fontStyle?: number;
      }>
    >;
  };
}> | null = null;

function hasFlag(value: number | undefined, flag: number): boolean {
  return Boolean((value ?? 0) & flag);
}

async function getPhpHighlighter() {
  highlighterPromise ??= (async () => {
    const [
      { createHighlighterCore },
      { createOnigurumaEngine },
      { default: php },
      { default: githubLight },
    ] = await Promise.all([
      import('@shikijs/core'),
      import('@shikijs/engine-oniguruma'),
      import('@shikijs/langs/php'),
      import('@shikijs/themes/github-light'),
    ]);

    return createHighlighterCore({
      engine: createOnigurumaEngine(import('shiki/wasm')),
      langs: [...php],
      themes: [githubLight],
    });
  })();

  return highlighterPromise as NonNullable<typeof highlighterPromise>;
}

export function highlightPhp(code: string): Promise<HighlightLine[]> {
  const cached = highlightCache.get(code);

  if (cached) {
    return cached;
  }

  const promise = (async () => {
    const highlighter = await getPhpHighlighter();
    const result = await highlighter.codeToTokens(code, {
      lang: 'php',
      theme: 'github-light',
    });

    return result.tokens.map((line) => ({
      tokens: line.map((token) => ({
        content: token.content,
        color: token.color ?? null,
        bold: hasFlag(token.fontStyle, 2),
        italic: hasFlag(token.fontStyle, 1),
        underline: hasFlag(token.fontStyle, 4),
      })),
    }));
  })();

  highlightCache.set(code, promise);

  return promise;
}
