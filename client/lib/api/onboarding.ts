import apiClient from "./client";

export type OnboardingTaskState = "todo" | "done" | "skipped";

export interface OnboardingTaskItem {
  key: string;
  title: string;
  description: string;
  group: "get_live" | "get_matched" | "grow";
  group_label: string;
  required: boolean;
  action: string;
  cta: string;
  state: OnboardingTaskState;
  completed_at: string | null;
  skipped_at: string | null;
}

export interface OnboardingTaskGroup {
  key: "get_live" | "get_matched" | "grow";
  label: string;
  tasks: OnboardingTaskItem[];
}

export interface OnboardingStatus {
  tasks: OnboardingTaskItem[];
  groups: OnboardingTaskGroup[];
  completed_tasks: OnboardingTaskItem[];
  skipped_tasks: OnboardingTaskItem[];
  next_task: OnboardingTaskItem | null;
  progress: {
    required_total: number;
    required_done: number;
    required_skipped: number;
    required_remaining: number;
    optional_total: number;
    optional_done: number;
    optional_remaining: number;
    percent: number;
  };
  required_complete: boolean;
  setup_card_visible: boolean;
  setup_card_dismissed: boolean;
  goals: string[];
  goal_options: { key: string; label: string }[];
  goals_dismissed: boolean;
  goal_prefill: string[];
}

export async function getOnboardingStatus(): Promise<OnboardingStatus> {
  const { data } = await apiClient.get("/onboarding");
  return data;
}

export async function updateOnboardingTask(
  taskKey: string,
  state: OnboardingTaskState
): Promise<OnboardingStatus> {
  const { data } = await apiClient.patch(`/onboarding/tasks/${taskKey}`, { state });
  return data;
}

export async function saveOnboardingGoals(goals: string[]): Promise<OnboardingStatus> {
  const { data } = await apiClient.post("/onboarding/goals", { goals });
  return data;
}

export async function dismissOnboardingGoals(): Promise<OnboardingStatus> {
  const { data } = await apiClient.delete("/onboarding/goals");
  return data;
}

export async function dismissSetupCard(): Promise<OnboardingStatus> {
  const { data } = await apiClient.post("/onboarding/setup-card/dismiss");
  return data;
}
