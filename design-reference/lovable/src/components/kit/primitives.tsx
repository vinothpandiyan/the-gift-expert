import { Link } from "@tanstack/react-router";
import { ChevronRight } from "lucide-react";
import type { ComponentProps, ReactNode } from "react";
import { cn } from "@/lib/utils";

/* Layout ------------------------------------------------------------------ */

export function Container({ className, children }: { className?: string; children: ReactNode }) {
  return <div className={cn("mx-auto w-full max-w-[1280px] px-5 md:px-8", className)}>{children}</div>;
}

export function Section({
  className,
  tone = "default",
  children,
  ...props
}: ComponentProps<"section"> & { tone?: "default" | "surface" | "plum" }) {
  return (
    <section
      className={cn(
        "py-12 md:py-20",
        tone === "surface" && "bg-surface",
        tone === "plum" && "bg-primary text-primary-foreground",
        className,
      )}
      {...props}
    >
      {children}
    </section>
  );
}

export function SectionHeading({
  eyebrow,
  title,
  description,
  action,
}: {
  eyebrow?: string;
  title: string;
  description?: string;
  action?: ReactNode;
}) {
  return (
    <div className="mb-7 flex flex-col gap-4 md:mb-10 md:flex-row md:items-end md:justify-between">
      <div className="max-w-2xl">
        {eyebrow ? (
          <p className="mb-2 text-xs font-semibold tracking-[0.14em] text-muted-foreground uppercase">{eyebrow}</p>
        ) : null}
        <h2 className="font-display text-[27px] leading-[1.15] tracking-tight md:text-4xl">{title}</h2>
        {description ? <p className="mt-3 text-[15px] text-muted-foreground md:text-base">{description}</p> : null}
      </div>
      {action ? <div className="shrink-0">{action}</div> : null}
    </div>
  );
}

/* Badges & chips ---------------------------------------------------------- */

export function Badge({
  children,
  tone = "neutral",
  className,
}: {
  children: ReactNode;
  tone?: "neutral" | "plum" | "gold" | "coral" | "success";
  className?: string;
}) {
  const tones = {
    neutral: "bg-surface-sunken text-muted-foreground border-border",
    plum: "bg-primary-light text-primary border-primary/15",
    gold: "bg-gold/15 text-[#7a5a12] border-gold/30",
    coral: "bg-coral/12 text-[#9c4232] border-coral/25",
    success: "bg-success/12 text-success border-success/25",
  } as const;
  return (
    <span
      className={cn(
        "inline-flex items-center gap-1 rounded-md border px-2 py-1 text-[11px] font-semibold tracking-wide",
        tones[tone],
        className,
      )}
    >
      {children}
    </span>
  );
}

export function Chip({
  children,
  selected,
  onRemove,
  ...props
}: ComponentProps<"button"> & { selected?: boolean; onRemove?: () => void }) {
  return (
    <button
      type="button"
      aria-pressed={selected}
      className={cn(
        "inline-flex min-h-11 items-center gap-2 rounded-[10px] border px-4 text-sm font-medium transition-colors",
        selected
          ? "border-primary bg-primary text-primary-foreground"
          : "border-border bg-surface text-foreground hover:border-primary/40 hover:bg-primary-light/50",
      )}
      {...props}
    >
      {children}
      {onRemove ? <span aria-hidden>×</span> : null}
    </button>
  );
}

export function TagChip({ children }: { children: ReactNode }) {
  return (
    <span className="inline-flex items-center rounded-md border border-border bg-surface px-3 py-1.5 text-[13px] text-foreground">
      {children}
    </span>
  );
}

/* Breadcrumbs ------------------------------------------------------------- */

export function Breadcrumbs({ items }: { items: { label: string; to?: string }[] }) {
  return (
    <nav aria-label="Breadcrumb" className="text-[13px] text-muted-foreground">
      <ol className="flex flex-wrap items-center gap-1.5">
        {items.map((item, i) => (
          <li key={item.label} className="flex items-center gap-1.5">
            {item.to ? (
              <Link to={item.to} className="hover:text-primary hover:underline">
                {item.label}
              </Link>
            ) : (
              <span className="text-foreground">{item.label}</span>
            )}
            {i < items.length - 1 ? <ChevronRight className="h-3.5 w-3.5 opacity-60" aria-hidden /> : null}
          </li>
        ))}
      </ol>
    </nav>
  );
}

/* Loading & empty --------------------------------------------------------- */

export function SkeletonCard() {
  return (
    <div className="overflow-hidden rounded-[14px] border border-border bg-surface">
      <div className="aspect-4/3 animate-pulse bg-surface-sunken" />
      <div className="space-y-2.5 p-4">
        <div className="h-4 w-4/5 animate-pulse rounded bg-surface-sunken" />
        <div className="h-3 w-full animate-pulse rounded bg-surface-sunken" />
        <div className="h-3 w-1/3 animate-pulse rounded bg-surface-sunken" />
      </div>
    </div>
  );
}

export function EmptyState({
  icon,
  title,
  description,
  actions,
}: {
  icon?: ReactNode;
  title: string;
  description: string;
  actions?: ReactNode;
}) {
  return (
    <div className="rounded-[14px] border border-dashed border-border bg-surface px-6 py-12 text-center">
      {icon ? (
        <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-primary-light text-primary">
          {icon}
        </div>
      ) : null}
      <h3 className="font-display text-xl md:text-2xl">{title}</h3>
      <p className="mx-auto mt-2 max-w-md text-[15px] text-muted-foreground">{description}</p>
      {actions ? <div className="mt-6 flex flex-wrap justify-center gap-3">{actions}</div> : null}
    </div>
  );
}
