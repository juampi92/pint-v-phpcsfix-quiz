import { getLabelForTool, getToolForSide } from './data';
import type { AnswerSide, QuizQuestion, ToolId } from './types';

function pluralize(count: number, singular: string): string {
  return count === 1 ? singular : `${singular}s`;
}

export interface ResultProfile {
  answeredCount: number;
  remainingCount: number;
  totalQuestions: number;
  provisional: boolean;
  pintDistance: number;
  phpDistance: number;
  recommendation: ToolId | null;
  recommendationLabel: string;
  recommendationDetail: string;
  confidenceLabel: string;
  confidenceDetail: string;
  distanceValue: string;
  distanceDetail: string;
  pathBase: ToolId | null;
  pathBaseLabel: string;
  pathSummary: string;
  pathRules: string[];
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

  const pintDistance = answeredQuestions.reduce((count, question) => {
    const side = answers[question.rule];

    return count + (side && getToolForSide(question, side) === 'pint' ? 0 : 1);
  }, 0);
  const phpDistance = answeredCount - pintDistance;
  const lead = Math.abs(pintDistance - phpDistance);
  const recommendation: ToolId | null =
    answeredCount === 0
      ? null
      : pintDistance < phpDistance
        ? 'pint'
        : phpDistance < pintDistance
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
  const leadRatio = answeredCount === 0 ? 0 : lead / answeredCount;
  const confidenceLabel =
    answeredCount === 0
      ? 'No signal'
      : leadRatio >= 0.45
        ? 'Strong'
        : leadRatio >= 0.2
          ? 'Moderate'
          : 'Tentative';
  const confidenceDetail =
    answeredCount === 0
      ? 'Finish a few rules to get a signal.'
      : lead === 0
        ? provisional
          ? `Tied on the ${answeredCount} answers so far, with ${remainingCount} ${pluralize(remainingCount, 'rule')} still open.`
          : 'Tied across every answered rule.'
        : `${lead} ${pluralize(lead, 'rule')} separate the bases${provisional ? `, with ${remainingCount} ${pluralize(remainingCount, 'rule')} still open` : ''}.`;
  const distanceValue = recommendation
    ? `${recommendation === 'pint' ? pintDistance : phpDistance} ${pluralize(
        recommendation === 'pint' ? pintDistance : phpDistance,
        'rule',
      )}`
    : answeredCount === 0
      ? 'No distance yet'
      : `${pintDistance} to Pint / ${phpDistance} to PHP-CS-Fixer`;
  const distanceDetail = recommendation
    ? `away from ${getLabelForTool(recommendation)}`
    : provisional
      ? 'provisional until the remaining rules are answered'
      : 'from either base';
  const pathBase = recommendation;
  const pathBaseLabel = recommendation
    ? getLabelForTool(recommendation)
    : answeredCount === 0
      ? 'No clear path yet'
      : 'No clear winner yet';
  const pathSummary = recommendation
    ? `Start from ${getLabelForTool(recommendation)} and flip ${recommendation === 'pint' ? pintDistance : phpDistance} ${pluralize(
        recommendation === 'pint' ? pintDistance : phpDistance,
        'rule',
      )} to match this profile.`
    : answeredCount === 0
      ? 'Keep answering to discover the closest migration path.'
      : 'Both bases are equally close right now.';
  const pathRules = recommendation
    ? questions
        .filter(
          (question) =>
            answers[question.rule] !== undefined &&
            getToolForSide(question, answers[question.rule] as AnswerSide) !== recommendation,
        )
        .map((question) => question.rule)
    : [];

  return {
    answeredCount,
    remainingCount,
    totalQuestions,
    provisional,
    pintDistance,
    phpDistance,
    recommendation,
    recommendationLabel,
    recommendationDetail,
    confidenceLabel,
    confidenceDetail,
    distanceValue,
    distanceDetail,
    pathBase,
    pathBaseLabel,
    pathSummary,
    pathRules,
  };
}
