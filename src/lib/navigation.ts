export const AUTO_ADVANCE_DELAY = 2000;
export const FAST_SCROLL_DURATION = 280;

interface ScrollAnimation {
  cancel: () => void;
  finished: Promise<void>;
}

function easeInOutCubic(progress: number): number {
  if (progress < 0.5) {
    return 4 * progress * progress * progress;
  }

  return 1 - Math.pow(-2 * progress + 2, 3) / 2;
}

export function findFirstIncompleteIndex(
  rules: string[],
  answers: Record<string, unknown>,
): number {
  const nextIndex = rules.findIndex((rule) => answers[rule] === undefined);

  return nextIndex === -1 ? rules.length : nextIndex;
}

export function isEditableKeyboardTarget(target: EventTarget | null): boolean {
  if (!(target instanceof HTMLElement)) {
    return false;
  }

  return target.closest('input, textarea, select, [contenteditable=""], [contenteditable="true"]') !== null;
}

export function findClosestSectionIndex(
  container: HTMLElement,
  sections: HTMLElement[],
  offset = 0,
): number {
  if (sections.length === 0) {
    return -1;
  }

  const containerRect = container.getBoundingClientRect();
  const anchor = containerRect.top + offset + 24;

  let closestIndex = 0;
  let closestDistance = Number.POSITIVE_INFINITY;

  sections.forEach((section, index) => {
    const rect = section.getBoundingClientRect();
    const distance = Math.abs(rect.top - anchor);

    if (distance < closestDistance) {
      closestDistance = distance;
      closestIndex = index;
    }
  });

  return closestIndex;
}

export function getSectionScrollTop(
  container: HTMLElement,
  section: HTMLElement,
  offset = 0,
): number {
  const containerRect = container.getBoundingClientRect();
  const sectionRect = section.getBoundingClientRect();
  const rawTop =
    container.scrollTop + (sectionRect.top - containerRect.top) - offset;
  const maxTop = Math.max(container.scrollHeight - container.clientHeight, 0);

  return Math.min(Math.max(rawTop, 0), maxTop);
}

export function animateVerticalScroll(
  container: HTMLElement,
  top: number,
  duration = FAST_SCROLL_DURATION,
): ScrollAnimation {
  let frame = 0;
  let settled = false;
  let resolveFinished: () => void = () => {};

  const finished = new Promise<void>((resolve) => {
    resolveFinished = resolve;
  });

  const startTop = container.scrollTop;
  const delta = top - startTop;

  function finish(): void {
    if (settled) {
      return;
    }

    settled = true;
    resolveFinished();
  }

  if (Math.abs(delta) < 1) {
    container.scrollTop = top;
    finish();

    return {
      cancel: finish,
      finished,
    };
  }

  const startTime = performance.now();

  function step(now: number): void {
    const progress = Math.min((now - startTime) / duration, 1);
    const eased = easeInOutCubic(progress);

    container.scrollTop = startTop + delta * eased;

    if (progress < 1 && !settled) {
      frame = window.requestAnimationFrame(step);
      return;
    }

    container.scrollTop = top;
    finish();
  }

  frame = window.requestAnimationFrame(step);

  return {
    cancel: () => {
      if (settled) {
        return;
      }

      window.cancelAnimationFrame(frame);
      finish();
    },
    finished,
  };
}
