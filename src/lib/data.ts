import rawDocument from '../../generated/pint-php-cs-fixer-quiz.json';
import type {
  AnswerSide,
  QuizCategory,
  QuizDocument,
  QuizQuestion,
  QuizRuleState,
  ToolId,
} from './types';

export const quizDocument = rawDocument as QuizDocument;
export const questions = quizDocument.questions;
export const storageKey = `pint-php-cs-fixer-quiz:v${quizDocument.schema_version}`;

export function getToolForSide(
  question: Pick<QuizQuestion, 'presentation'>,
  side: AnswerSide,
): ToolId {
  return question.presentation.pint_side === side ? 'pint' : 'php_cs_fixer';
}

export function getStateForTool(
  question: QuizQuestion,
  tool: ToolId,
): QuizRuleState {
  return tool === 'pint' ? question.pint : question.php_cs_fixer;
}

export function getStateForSide(
  question: QuizQuestion,
  side: AnswerSide,
): QuizRuleState {
  return getStateForTool(question, getToolForSide(question, side));
}

export function getLabelForTool(tool: ToolId): string {
  return tool === 'pint' ? 'Laravel Pint' : 'PHP-CS-Fixer';
}

export function getShortLabelForTool(tool: ToolId): string {
  return tool === 'pint' ? 'Pint' : 'PHP-CS-Fixer';
}

export function getCategoryLabel(category: QuizCategory): string {
  if (category === 'different_configuration') {
    return 'Different configuration';
  }

  if (category === 'only_php_cs_fixer') {
    return 'Only in PHP-CS-Fixer';
  }

  return 'Only in Pint';
}

export function formatRuleName(rule: string): string {
  return rule
    .split(/[_-]+/g)
    .filter(Boolean)
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join(' ');
}
