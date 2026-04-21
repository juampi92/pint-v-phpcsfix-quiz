import rawDifferences from '../../generated/pint-php-cs-fixer-differences.json';
import { getLabelForTool, getStateForSide, getToolForSide } from './data';
import type { AnswerSide, QuizQuestion, QuizRuleState, ToolId } from './types';

interface GithubReference {
  url: string;
  path: string;
  start_line: number;
  end_line: number;
}

interface DifferenceRuleSetReference {
  definition: GithubReference | null;
  rule: GithubReference | null;
}

interface DifferenceRuleState {
  enabled: boolean;
  parameters: unknown;
}

interface DifferenceComparison {
  case: 'php_cs_fixer_default_vs_pint' | 'pint_vs_php_cs_fixer_rule_default';
  php_cs_fixer_side: 'raw_default' | 'rule_default';
}

interface DifferencePhpCsFixerStates {
  comparison: DifferenceRuleState;
  raw_default: DifferenceRuleState;
  rule_default: DifferenceRuleState;
}

interface DifferenceRuleSetMembership {
  direct: string[];
  inherited: string[];
}

interface DifferenceRuleSet {
  name: string;
  description: string;
  risky: boolean;
  declared_directly: boolean;
  state: DifferenceRuleState;
  references: DifferenceRuleSetReference;
}

interface DifferenceEntry {
  rule: string;
  category: string;
  comparison: DifferenceComparison;
  pint: DifferenceRuleState;
  php_cs_fixer: DifferencePhpCsFixerStates;
  php_cs_fixer_rule_set_membership: DifferenceRuleSetMembership;
  php_cs_fixer_rulesets: DifferenceRuleSet[];
  fixer: {
    class: string;
    configurable: boolean;
    path: string;
    github: GithubReference | null;
  };
  references: {
    php_cs_fixer: GithubReference | null;
    pint: GithubReference | null;
  };
}

interface DifferencesDocument {
  preset: string;
  differences: DifferenceEntry[];
}

interface SourceReference {
  label: string;
  detail: string;
  note: string;
  url: string | null;
}

export interface FormatterExportRule {
  rule: string;
  chosenTool: ToolId;
  targetTool: ToolId;
  samplePath: string;
  sourceLabel: string;
  sourceDetail: string;
  sourceNote: string;
  sourceUrl: string | null;
  tooltip: string;
  configState: QuizRuleState;
}

export interface FormatterExportSection {
  tool: ToolId;
  label: string;
  intro: string;
  emptyLabel: string;
  rules: FormatterExportRule[];
  snippet: string;
}

const differencesDocument = rawDifferences as unknown as DifferencesDocument;
const differencesByRule = new Map(
  differencesDocument.differences.map((difference) => [difference.rule, difference]),
);

function escapePhpString(value: string): string {
  return value.replaceAll('\\', '\\\\').replaceAll("'", "\\'");
}

function stateToValue(state: QuizRuleState): unknown {
  if (!state.enabled) {
    return false;
  }

  return state.parameters ?? true;
}

function renderPhpValue(value: unknown, indent = 0): string {
  const pad = '  '.repeat(indent);
  const nestedPad = '  '.repeat(indent + 1);

  if (value === null) {
    return 'null';
  }

  if (typeof value === 'string') {
    return `'${escapePhpString(value)}'`;
  }

  if (typeof value === 'number') {
    return Number.isFinite(value) ? String(value) : '0';
  }

  if (typeof value === 'boolean') {
    return value ? 'true' : 'false';
  }

  if (Array.isArray(value)) {
    if (value.length === 0) {
      return '[]';
    }

    const entries = value.map((item) => `${nestedPad}${renderPhpValue(item, indent + 1)}`);

    return `[\n${entries.join(',\n')}\n${pad}]`;
  }

  if (typeof value === 'object') {
    const entries = Object.entries(value as Record<string, unknown>).sort(([left], [right]) =>
      left.localeCompare(right),
    );

    if (entries.length === 0) {
      return '[]';
    }

    const rendered = entries.map(
      ([key, item]) => `${nestedPad}'${escapePhpString(key)}' => ${renderPhpValue(item, indent + 1)}`,
    );

    return `[\n${rendered.join(',\n')}\n${pad}]`;
  }

  return 'null';
}

function buildSnippet(rules: FormatterExportRule[]): string {
  if (rules.length === 0) {
    return '';
  }

  const rendered = rules.map(
    (rule) => `    '${escapePhpString(rule.rule)}' => ${renderPhpValue(stateToValue(rule.configState), 1)},`,
  );

  return `[\n${rendered.join('\n')}\n]`;
}

function buildPintReference(difference: DifferenceEntry): SourceReference {
  const reference = difference.references.pint;
  const presetPath = `resources/presets/${differencesDocument.preset}.php`;
  const fallbackUrl = `https://github.com/laravel/pint/blob/main/${presetPath}`;

  return {
    label: 'Pint preset',
    detail: reference?.path ?? presetPath,
    note: reference ? 'direct preset entry' : 'inherited through the preset',
    url: reference?.url ?? fallbackUrl,
  };
}

function buildPhpCsFixerReference(difference: DifferenceEntry): SourceReference {
  if (difference.comparison.php_cs_fixer_side === 'rule_default') {
    return {
      label: 'PHP-CS-Fixer fixer default',
      detail: difference.fixer.github?.path ?? difference.fixer.path,
      note: 'intrinsic fixer default',
      url: difference.fixer.github?.url ?? difference.references.php_cs_fixer?.url ?? null,
    };
  }

  return {
    label: 'PHP-CS-Fixer raw default',
    detail: difference.fixer.github?.path ?? difference.fixer.path,
    note: 'raw default comparison',
    url: difference.fixer.github?.url ?? difference.references.php_cs_fixer?.url ?? null,
  };
}

function buildSourceReference(tool: ToolId, difference: DifferenceEntry): SourceReference {
  return tool === 'pint'
    ? buildPintReference(difference)
    : buildPhpCsFixerReference(difference);
}

function buildTooltip(
  question: QuizQuestion,
  chosenTool: ToolId,
  targetTool: ToolId,
  source: SourceReference,
): string {
  return [
    `Sample: ${question.source.file_path}`,
    `Chosen on: ${getLabelForTool(chosenTool)}`,
    `Apply to: ${getLabelForTool(targetTool)}`,
    `Source label: ${source.label}`,
    `Source file: ${source.detail}`,
    `Source note: ${source.note}`,
  ].join('\n');
}

function buildRuleExport(
  question: QuizQuestion,
  answer: AnswerSide,
): FormatterExportRule | null {
  const chosenTool = getToolForSide(question, answer);
  const targetTool: ToolId = chosenTool === 'pint' ? 'php_cs_fixer' : 'pint';
  const difference = differencesByRule.get(question.rule);
  const source = difference ? buildSourceReference(chosenTool, difference) : {
    label: getLabelForTool(chosenTool),
    detail: question.source.file_path,
    note: 'quiz sample only',
    url: null,
  };

  return {
    rule: question.rule,
    chosenTool,
    targetTool,
    samplePath: question.source.file_path,
    sourceLabel: source.label,
    sourceDetail: source.detail,
    sourceNote: source.note,
    sourceUrl: source.url,
    tooltip: buildTooltip(question, chosenTool, targetTool, source),
    configState: getStateForSide(question, answer),
  };
}

function buildSection(
  tool: ToolId,
  questions: QuizQuestion[],
  answers: Record<string, AnswerSide>,
): FormatterExportSection {
  const sourceTool: ToolId = tool === 'pint' ? 'php_cs_fixer' : 'pint';
  const rules = questions
    .map((question) => {
      const answer = answers[question.rule];

      if (!answer) {
        return null;
      }

      const chosenTool = getToolForSide(question, answer);
      const targetTool: ToolId = chosenTool === 'pint' ? 'php_cs_fixer' : 'pint';

      if (targetTool !== tool) {
        return null;
      }

      return buildRuleExport(question, answer);
    })
    .filter((rule): rule is FormatterExportRule => rule !== null);

  return {
    tool,
    label: getLabelForTool(tool),
    intro: `Mirror the rules you picked on ${getLabelForTool(sourceTool)} into ${getLabelForTool(
      tool,
    )}.`,
    emptyLabel: `No rules need to be mirrored into ${getLabelForTool(tool)} yet.`,
    rules,
    snippet: buildSnippet(rules),
  };
}

export function buildFormatterExportSections(
  questions: QuizQuestion[],
  answers: Record<string, AnswerSide>,
): FormatterExportSection[] {
  return [buildSection('pint', questions, answers), buildSection('php_cs_fixer', questions, answers)];
}
