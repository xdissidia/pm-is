export const priorityConfig = {
  1: { color: "red", label: "Very high" },
  2: { color: "orange", label: "High" },
  3: { color: "yellow", label: "Medium" },
  4: { color: "sky", label: "Low" },
  5: { color: "emerald", label: "Very low" },
};

export function getTaskPriorityConfig(priority) {
  if (!priority || !priorityConfig[priority]) {
    return null;
  }

  return priorityConfig[priority];
}

export function getTaskPriorityOptions() {
  return Object.entries(priorityConfig).map(([value, config]) => ({
    value: value.toString(),
    label: config.label,
    color: config.color,
  }));
}

