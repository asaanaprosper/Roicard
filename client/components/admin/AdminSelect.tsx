import { cn } from "@/lib/cn";
import { SelectHTMLAttributes, forwardRef } from "react";

export type AdminSelectProps = SelectHTMLAttributes<HTMLSelectElement> & {
  label?: string;
  error?: string;
  hint?: string;
};

export const AdminSelect = forwardRef<HTMLSelectElement, AdminSelectProps>(
  ({ className, label, error, hint, id, children, ...props }, ref) => {
    const selectId = id ?? label?.toLowerCase().replace(/\s+/g, "-");

    return (
      <div className="w-full space-y-2">
        {label && (
          <label
            htmlFor={selectId}
            className="block text-sm font-medium text-roicard-text"
          >
            {label}
          </label>
        )}
        <select
          ref={ref}
          id={selectId}
          className={cn(
            "flex h-11 w-full rounded-lg border border-roicard-border bg-roicard-bg-muted px-3 text-sm text-roicard-text theme-transition",
            "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-roicard-accent/50 focus-visible:border-roicard-accent/50",
            "disabled:cursor-not-allowed disabled:opacity-50",
            error && "border-red-500 focus-visible:ring-red-500/50",
            className
          )}
          {...props}
        >
          {children}
        </select>
        {error && <p className="text-sm text-red-400">{error}</p>}
        {!error && hint && (
          <p className="text-sm text-roicard-text-muted">{hint}</p>
        )}
      </div>
    );
  }
);

AdminSelect.displayName = "AdminSelect";
