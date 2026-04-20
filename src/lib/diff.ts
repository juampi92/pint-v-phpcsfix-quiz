import { diffArrays, diffChars } from 'diff';
import type { ColumnRange, DiffRow } from './types';

function splitDisplayLines(text: string): string[] {
  const lines = text.split('\n');

  if (lines.at(-1) === '') {
    lines.pop();
  }

  return lines.length > 0 ? lines : [''];
}

function mergeRanges(ranges: ColumnRange[]): ColumnRange[] {
  const sorted = [...ranges].sort((left, right) => left.start - right.start);
  const merged: ColumnRange[] = [];

  for (const range of sorted) {
    const previous = merged.at(-1);

    if (!previous || range.start > previous.end) {
      merged.push({ ...range });
      continue;
    }

    previous.end = Math.max(previous.end, range.end);
  }

  return merged;
}

function buildInlineRanges(
  originalLine: string,
  outputLine: string,
): {
  original: ColumnRange[];
  output: ColumnRange[];
} {
  const originalRanges: ColumnRange[] = [];
  const outputRanges: ColumnRange[] = [];
  let originalIndex = 0;
  let outputIndex = 0;

  for (const part of diffChars(originalLine, outputLine)) {
    if (part.added) {
      if (part.value.length > 0) {
        outputRanges.push({
          start: outputIndex,
          end: outputIndex + part.value.length,
        });
      }

      outputIndex += part.value.length;
      continue;
    }

    if (part.removed) {
      if (part.value.length > 0) {
        originalRanges.push({
          start: originalIndex,
          end: originalIndex + part.value.length,
        });
      }

      originalIndex += part.value.length;
      continue;
    }

    originalIndex += part.value.length;
    outputIndex += part.value.length;
  }

  return {
    original: mergeRanges(originalRanges),
    output: mergeRanges(outputRanges),
  };
}

export function buildDiffRows(original: string, output: string): DiffRow[] {
  const originalLines = splitDisplayLines(original);
  const outputLines = splitDisplayLines(output);
  const rows: DiffRow[] = [];
  const parts = diffArrays(originalLines, outputLines);

  let originalLineIndex = 0;
  let outputLineIndex = 0;

  for (let partIndex = 0; partIndex < parts.length; partIndex += 1) {
    const part = parts[partIndex];
    const lines = part.value;

    if (!part.added && !part.removed) {
      rows.push(
        ...lines.map((_, index) => ({
          kind: 'context' as const,
          originalLineIndex: originalLineIndex + index,
          outputLineIndex: outputLineIndex + index,
          ranges: [],
        })),
      );

      originalLineIndex += lines.length;
      outputLineIndex += lines.length;
      continue;
    }

    if (part.removed && parts[partIndex + 1]?.added) {
      const addedPart = parts[partIndex + 1];
      const removedLines = lines;
      const addedLines = addedPart.value;
      const pairedLineCount = Math.min(removedLines.length, addedLines.length);

      rows.push(
        ...removedLines.map((line, index) => ({
          kind: 'removed' as const,
          originalLineIndex: originalLineIndex + index,
          outputLineIndex: null,
          ranges:
            index < pairedLineCount
              ? buildInlineRanges(line, addedLines[index]).original
              : [],
        })),
      );

      rows.push(
        ...addedLines.map((line, index) => ({
          kind: 'added' as const,
          originalLineIndex: null,
          outputLineIndex: outputLineIndex + index,
          ranges:
            index < pairedLineCount
              ? buildInlineRanges(removedLines[index], line).output
              : [],
        })),
      );

      originalLineIndex += removedLines.length;
      outputLineIndex += addedLines.length;
      partIndex += 1;
      continue;
    }

    if (part.removed) {
      rows.push(
        ...lines.map((_, index) => ({
          kind: 'removed' as const,
          originalLineIndex: originalLineIndex + index,
          outputLineIndex: null,
          ranges: [],
        })),
      );

      originalLineIndex += lines.length;
      continue;
    }

    rows.push(
      ...lines.map((_, index) => ({
        kind: 'added' as const,
        originalLineIndex: null,
        outputLineIndex: outputLineIndex + index,
        ranges: [],
      })),
    );

    outputLineIndex += lines.length;
  }

  return rows;
}
