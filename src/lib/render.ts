import type { ColumnRange, HighlightToken, RenderSegment } from './types';

function toVisibleWhitespace(text: string): string {
  return text.replaceAll('\t', '⇥').replaceAll(' ', '·');
}

function buildSegment(
  token: HighlightToken,
  text: string,
  changed: boolean,
): RenderSegment | null {
  if (text.length === 0) {
    return null;
  }

  return {
    ...token,
    content: text,
    changed,
    display: toVisibleWhitespace(text),
  };
}

export function buildSegments(
  tokens: HighlightToken[],
  ranges: ColumnRange[],
): RenderSegment[] {
  if (tokens.length === 0) {
    return [];
  }

  const segments: RenderSegment[] = [];
  let lineOffset = 0;

  for (const token of tokens) {
    const tokenStart = lineOffset;
    const tokenEnd = tokenStart + token.content.length;
    const overlapping = ranges.filter(
      (range) => range.end > tokenStart && range.start < tokenEnd,
    );

    if (overlapping.length === 0) {
      const segment = buildSegment(token, token.content, false);

      if (segment) {
        segments.push(segment);
      }

      lineOffset = tokenEnd;
      continue;
    }

    let cursor = tokenStart;

    for (const range of overlapping) {
      const sliceStart = Math.max(tokenStart, range.start);
      const sliceEnd = Math.min(tokenEnd, range.end);

      if (sliceStart > cursor) {
        const before = buildSegment(
          token,
          token.content.slice(cursor - tokenStart, sliceStart - tokenStart),
          false,
        );

        if (before) {
          segments.push(before);
        }
      }

      const changed = buildSegment(
        token,
        token.content.slice(sliceStart - tokenStart, sliceEnd - tokenStart),
        true,
      );

      if (changed) {
        segments.push(changed);
      }

      cursor = sliceEnd;
    }

    if (cursor < tokenEnd) {
      const after = buildSegment(
        token,
        token.content.slice(cursor - tokenStart),
        false,
      );

      if (after) {
        segments.push(after);
      }
    }

    lineOffset = tokenEnd;
  }

  return segments;
}
