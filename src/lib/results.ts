import { getLabelForTool, getToolForSide } from './data';
import type { AnswerSide, QuizQuestion, ToolId } from './types';

export interface ResultProfile {
  answeredCount: number;
  remainingCount: number;
  totalQuestions: number;
  provisional: boolean;
  pintVotes: number;
  phpVotes: number;
  recommendation: ToolId | null;
  recommendationLabel: string;
  recommendationDetail: string;
}

export function buildResultProfile(
  questions: QuizQuestion[],
  answers: Record<string, AnswerSide>,
): ResultProfile {
  const answeredQuestions = questions.filter((question) => answers[question.rule] !== undefined);
  const answeredCount = answeredQuestions.length;
  const totalQuestions = questions.length;
  const remainingCount = Math.max(totalQuestions - answeredCount, 0);
  const provisional = remainingCount > 0;

  const pintVotes = answeredQuestions.reduce((count, question) => {
    const side = answers[question.rule];

    return count + (side && getToolForSide(question, side) === 'pint' ? 1 : 0);
  }, 0);
  const phpVotes = answeredCount - pintVotes;
  const recommendation: ToolId | null =
    answeredCount === 0
      ? null
      : pintVotes > phpVotes
        ? 'pint'
        : phpVotes > pintVotes
          ? 'php_cs_fixer'
          : null;
  const recommendationLabel = recommendation
    ? getLabelForTool(recommendation)
    : answeredCount === 0
      ? 'No signal yet'
      : 'Too close to call';
  const recommendationDetail = recommendation
    ? 'Closest match so far'
    : answeredCount === 0
      ? 'Answer a few rules to get a first read.'
      : 'The two bases are tied so far.';

  return {
    answeredCount,
    remainingCount,
    totalQuestions,
    provisional,
    pintVotes,
    phpVotes,
    recommendation,
    recommendationLabel,
    recommendationDetail,
  };
}
