"use client";

import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import {
  dismissOnboardingGoals,
  dismissSetupCard,
  saveOnboardingGoals,
  updateOnboardingTask,
  type OnboardingStatus,
  type OnboardingTaskItem,
} from "@/lib/api/onboarding";
import { cn } from "@/lib/cn";
import {
  ArrowRight,
  Check,
  CheckCircle2,
  Circle,
  SkipForward,
  Sparkles,
  Target,
  X,
} from "lucide-react";
import Link from "next/link";
import { useEffect, useState } from "react";

type SetupDashboardProps = {
  status: OnboardingStatus;
  onStatusChange: (status: OnboardingStatus) => void;
  compact?: boolean;
};

function TaskRow({
  task,
  onStatusChange,
}: {
  task: OnboardingTaskItem;
  onStatusChange: (status: OnboardingStatus) => void;
}) {
  const [busy, setBusy] = useState(false);

  const setState = async (state: "todo" | "skipped") => {
    setBusy(true);
    try {
      onStatusChange(await updateOnboardingTask(task.key, state));
    } finally {
      setBusy(false);
    }
  };

  const done = task.state === "done";

  return (
    <div
      className={cn(
        "flex flex-col gap-3 rounded-xl border p-4 sm:flex-row sm:items-center sm:justify-between",
        done
          ? "border-emerald-500/20 bg-emerald-500/5"
          : "border-roicard-border bg-roicard-bg-muted/30"
      )}
    >
      <div className="flex min-w-0 items-start gap-3">
        <span className="mt-0.5 shrink-0">
          {done ? (
            <CheckCircle2 className="h-5 w-5 text-emerald-400" />
          ) : task.required ? (
            <Circle className="h-5 w-5 text-roicard-accent" />
          ) : (
            <Circle className="h-5 w-5 text-roicard-text-muted" />
          )}
        </span>
        <div className="min-w-0">
          <p className="text-sm font-semibold text-roicard-text">
            {task.title}
            {task.required && (
              <span className="ml-2 text-[10px] font-semibold uppercase tracking-wider text-roicard-accent">
                Required
              </span>
            )}
          </p>
          <p className="mt-0.5 text-xs leading-relaxed text-roicard-text-muted">
            {task.description}
          </p>
        </div>
      </div>

      <div className="flex shrink-0 items-center gap-2 sm:pl-4">
        {!done && (
          <>
            <Link href={task.action}>
              <Button variant="secondary" size="sm" className="rounded-lg">
                {task.cta}
                <ArrowRight className="ml-1.5 h-3.5 w-3.5" />
              </Button>
            </Link>
            {!task.required && (
              <Button
                variant="ghost"
                size="sm"
                disabled={busy}
                onClick={() => setState("skipped")}
                className="rounded-lg text-roicard-text-muted"
              >
                <SkipForward className="h-3.5 w-3.5" />
                Later
              </Button>
            )}
          </>
        )}
        {done && (
          <span className="text-xs font-medium text-emerald-400">Completed</span>
        )}
      </div>
    </div>
  );
}

export function SetupDashboard({
  status,
  onStatusChange,
  compact = false,
}: SetupDashboardProps) {
  const [goals, setGoals] = useState<string[]>(status.goals ?? []);
  const [goalBusy, setGoalBusy] = useState(false);
  const [dismissBusy, setDismissBusy] = useState(false);

  useEffect(() => {
    setGoals(status.goals ?? []);
  }, [status.goals]);

  const toggleGoal = (key: string) => {
    setGoals((current) =>
      current.includes(key) ? current.filter((goal) => goal !== key) : [...current, key]
    );
  };

  const handleSaveGoals = async () => {
    setGoalBusy(true);
    try {
      onStatusChange(await saveOnboardingGoals(goals));
    } finally {
      setGoalBusy(false);
    }
  };

  const handleDismissGoals = async () => {
    setGoalBusy(true);
    try {
      onStatusChange(await dismissOnboardingGoals());
    } finally {
      setGoalBusy(false);
    }
  };

  const handleDismissSetup = async () => {
    setDismissBusy(true);
    try {
      onStatusChange(await dismissSetupCard());
    } finally {
      setDismissBusy(false);
    }
  };

  const nextTask = status.next_task;
  const laterTasks = status.skipped_tasks;
  const completedTasks = status.completed_tasks;

  if (compact) {
    const next = nextTask ?? status.groups.flatMap((group) => group.tasks).find((task) => task.state === "todo");
    if (!next) return null;

    return (
      <Card className="border-roicard-primary/20 bg-roicard-primary/5">
        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div className="flex items-start gap-3">
            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-roicard-primary/15">
              <Sparkles className="h-5 w-5 text-roicard-accent" />
            </div>
            <div>
              <p className="text-xs font-semibold uppercase tracking-wider text-roicard-accent">
                Setup · {status.progress.percent}% complete
              </p>
              <p className="mt-1 text-sm font-semibold text-roicard-text">
                {next.title}
              </p>
              <p className="text-xs text-roicard-text-muted">{next.description}</p>
            </div>
          </div>
          <div className="flex shrink-0 items-center gap-2">
            <Link href={next.action}>
              <Button size="sm" className="rounded-lg">
                {next.cta}
                <ArrowRight className="ml-1.5 h-3.5 w-3.5" />
              </Button>
            </Link>
            <Button
              variant="ghost"
              size="sm"
              className="rounded-lg"
              disabled={dismissBusy}
              onClick={handleDismissSetup}
              aria-label="Dismiss setup card"
            >
              <X className="h-4 w-4" />
            </Button>
          </div>
        </div>
      </Card>
    );
  }

  return (
    <div className="space-y-8">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <span className="inline-flex items-center gap-2 rounded-full bg-roicard-primary/15 px-3 py-1 text-xs font-medium text-roicard-accent">
            <Sparkles className="h-3.5 w-3.5" />
            Setup dashboard
          </span>
          <h1 className="mt-3 text-2xl font-bold text-roicard-text sm:text-3xl">
            Get your Roicard live
          </h1>
          <p className="mt-2 max-w-2xl text-sm text-roicard-text-muted sm:text-base">
            Complete the required steps when you are ready. Everything saves as
            you go, so you can leave and continue on another device.
          </p>
        </div>
        <div className="rounded-2xl border border-roicard-border bg-roicard-bg-elevated px-5 py-3 text-right">
          <p className="text-2xl font-bold text-roicard-text">{status.progress.percent}%</p>
          <p className="text-xs text-roicard-text-muted">
            {status.progress.required_done}/{status.progress.required_total} required steps
          </p>
        </div>
      </div>

      <div className="h-2 overflow-hidden rounded-full bg-roicard-bg-muted">
        <div
          className="h-full roicard-gradient transition-all duration-500"
          style={{ width: `${status.progress.percent}%` }}
        />
      </div>

      {!status.goals_dismissed && (
        <Card className="border-roicard-primary/20 bg-roicard-primary/5">
          <div className="flex items-start justify-between gap-4">
            <div className="flex items-start gap-3">
              <Target className="mt-0.5 h-5 w-5 shrink-0 text-roicard-accent" />
              <div>
                <h2 className="text-sm font-semibold text-roicard-text">
                  What brings you to Roicard?
                </h2>
                <p className="mt-1 text-xs text-roicard-text-muted">
                  Pick any that apply. We&apos;ll use this to prefill your seeking
                  and offering prompts and prioritise relevant setup steps.
                </p>
              </div>
            </div>
            <Button
              variant="ghost"
              size="sm"
              onClick={handleDismissGoals}
              disabled={goalBusy}
              aria-label="Dismiss goals card"
              className="h-8 w-8 shrink-0 p-0"
            >
              <X className="h-4 w-4" />
            </Button>
          </div>
          <div className="mt-4 flex flex-wrap gap-2">
            {status.goal_options.map((goal) => {
              const selected = goals.includes(goal.key);
              return (
                <button
                  key={goal.key}
                  type="button"
                  aria-pressed={selected}
                  onClick={() => toggleGoal(goal.key)}
                  className={cn(
                    "inline-flex items-center gap-1.5 rounded-full border px-3.5 py-2 text-sm font-medium transition-all",
                    selected
                      ? "border-transparent roicard-gradient text-roicard-on-primary"
                      : "border-roicard-border bg-roicard-bg-muted text-roicard-text hover:border-roicard-accent/50"
                  )}
                >
                  {selected && <Check className="h-3.5 w-3.5" />}
                  {goal.label}
                </button>
              );
            })}
          </div>
          <div className="mt-4 flex justify-end">
            <Button
              size="sm"
              onClick={handleSaveGoals}
              disabled={goalBusy || goals.length === 0}
              className="rounded-lg"
            >
              Save goals
            </Button>
          </div>
        </Card>
      )}

      {nextTask && (
        <Card className="border-roicard-primary/30 bg-roicard-bg-elevated">
          <p className="text-xs font-semibold uppercase tracking-wider text-roicard-accent">
            Your next step
          </p>
          <div className="mt-2 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
              <h2 className="text-xl font-bold text-roicard-text">{nextTask.title}</h2>
              <p className="mt-1 text-sm text-roicard-text-muted">{nextTask.description}</p>
            </div>
            <Link href={nextTask.action} className="shrink-0">
              <Button className="rounded-xl">
                {nextTask.cta}
                <ArrowRight className="ml-2 h-4 w-4" />
              </Button>
            </Link>
          </div>
        </Card>
      )}

      <div className="space-y-6">
        {status.groups.map((group) => {
          const openTasks = group.tasks.filter((task) => task.state === "todo");
          return (
            <section key={group.key} className="space-y-3">
              <div className="flex items-center justify-between">
                <h2 className="text-sm font-semibold uppercase tracking-wider text-roicard-accent">
                  {group.label}
                </h2>
                <span className="text-xs text-roicard-text-muted">
                  {group.tasks.filter((task) => task.state === "done").length}/
                  {group.tasks.length} done
                </span>
              </div>
              {openTasks.length === 0 ? (
                <p className="rounded-xl border border-roicard-border bg-roicard-bg-muted/20 px-4 py-3 text-sm text-roicard-text-muted">
                  Nothing pending in this section.
                </p>
              ) : (
                <div className="space-y-3">
                  {openTasks.map((task) => (
                    <TaskRow key={task.key} task={task} onStatusChange={onStatusChange} />
                  ))}
                </div>
              )}
            </section>
          );
        })}
      </div>

      {laterTasks.length > 0 && (
        <Card>
          <h2 className="text-sm font-semibold uppercase tracking-wider text-roicard-text-muted">
            Later
          </h2>
          <p className="mt-1 text-xs text-roicard-text-muted">
            Skipped tasks stay here. They never block publishing.
          </p>
          <div className="mt-4 space-y-3">
            {laterTasks.map((task) => (
              <div
                key={task.key}
                className="flex flex-col gap-3 rounded-xl border border-roicard-border bg-roicard-bg-muted/20 p-4 sm:flex-row sm:items-center sm:justify-between"
              >
                <div>
                  <p className="text-sm font-semibold text-roicard-text">{task.title}</p>
                  <p className="text-xs text-roicard-text-muted">{task.description}</p>
                </div>
                <div className="flex gap-2">
                  <Link href={task.action}>
                    <Button variant="secondary" size="sm" className="rounded-lg">
                      Do it now
                    </Button>
                  </Link>
                  <Button
                    variant="ghost"
                    size="sm"
                    className="rounded-lg"
                    onClick={async () =>
                      onStatusChange(await updateOnboardingTask(task.key, "todo"))
                    }
                  >
                    Move back
                  </Button>
                </div>
              </div>
            ))}
          </div>
        </Card>
      )}

      {completedTasks.length > 0 && (
        <details className="rounded-2xl border border-roicard-border bg-roicard-bg-elevated p-4">
          <summary className="cursor-pointer text-sm font-semibold text-roicard-text">
            Completed tasks ({completedTasks.length})
          </summary>
          <div className="mt-4 space-y-2">
            {completedTasks.map((task) => (
              <div
                key={task.key}
                className="flex items-center gap-2 rounded-lg bg-roicard-bg-muted/30 px-3 py-2 text-sm text-roicard-text-muted"
              >
                <CheckCircle2 className="h-4 w-4 text-emerald-400" />
                <span className="line-through">{task.title}</span>
              </div>
            ))}
          </div>
        </details>
      )}
    </div>
  );
}
