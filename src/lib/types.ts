export type ToolId = 'pint' | 'php_cs_fixer';
export type AnswerSide = 'left' | 'right';
export type QuizCategory =
  | 'only_php_cs_fixer'
  | 'only_pint'
  | 'different_configuration';

export interface QuizRuleState {
  enabled: boolean;
  parameters: Record<string, unknown> | null;
  output: string;
  changed: boolean;
}

export interface QuizFixerMetadata {
  class: string;
  configurable: boolean;
  path: string;
  summary: string;
  description: string | null;
  is_risky: boolean;
  risky_description: string | null;
}

export interface QuizSourceSample {
  code: string;
  file_path: string;
  origin: 'code_sample' | 'override';
  sample_index: number | null;
  sample_configuration: Record<string, unknown> | null;
  selection_reason: string | null;
}

export interface QuizQuestion {
  rule: string;
  category: QuizCategory;
  presentation: {
    pint_side: AnswerSide;
  };
  fixer: QuizFixerMetadata;
  source: QuizSourceSample;
  pint: QuizRuleState;
  php_cs_fixer: QuizRuleState;
  selection: {
    score: number;
    outputs_differ: boolean;
  };
}

export interface QuizSkippedRule {
  rule: string;
  category: QuizCategory;
  reason: string;
}

export interface QuizDocument {
  schema_version: number;
  generated_from: string;
  overrides_path: string;
  package_versions: Record<string, string>;
  counts: {
    differences_total: number;
    quiz_questions: number;
    skipped: number;
    only_php_cs_fixer: number;
    only_pint: number;
    different_configuration: number;
  };
  questions: QuizQuestion[];
  skipped: QuizSkippedRule[];
}

export interface HighlightToken {
  content: string;
  color: string | null;
  bold: boolean;
  italic: boolean;
  underline: boolean;
}

export interface HighlightLine {
  tokens: HighlightToken[];
}

export interface ColumnRange {
  start: number;
  end: number;
}

export type DiffKind = 'context' | 'added' | 'removed';

export interface DiffRow {
  kind: DiffKind;
  originalLineIndex: number | null;
  outputLineIndex: number | null;
  ranges: ColumnRange[];
}

export interface RenderSegment extends HighlightToken {
  changed: boolean;
  display: string;
}

export interface StoredAnswers {
  version: number;
  answers: Record<string, AnswerSide>;
}

export interface UiPreferences {
  autoAdvanceEnabled: boolean;
}
