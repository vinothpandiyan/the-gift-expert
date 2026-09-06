import type { ComponentProps, ReactNode } from "react";
import { Check } from "lucide-react";
import { cn } from "@/lib/utils";

/** Shared selectable surface used by recipient / occasion / interest / budget choices. */
export function SelectTile({
  selected,
  className,
  children,
  ...props
}: ComponentProps<"button"> & { selected?: boolean | undefined }) {
  return (
    <button
      type="button"
      aria-pressed={selected}
      className={cn(
        "relative flex min-h-16 w-full flex-col justify-center rounded-[12px] border p-4 text-left transition-colors",
        selected
          ? "border-primary bg-primary-light ring-1 ring-primary"
          : "border-border bg-surface hover:border-primary/40 hover:bg-primary-light/40",
        className,
      )}
      {...props}
    >
      {selected ? (
        <span className="absolute top-3 right-3 flex h-5 w-5 items-center justify-center rounded-full bg-primary text-primary-foreground">
          <Check className="h-3 w-3" aria-hidden />
        </span>
      ) : null}
      {children}
    </button>
  );
}

export function ChoiceTile({
  label,
  note,
  selected,
  onClick,
}: {
  label: string;
  note?: string | undefined;
  selected?: boolean | undefined;
  onClick?: (() => void) | undefined;
}) {
  return (
    <SelectTile selected={selected} onClick={onClick}>
      <span className="pr-6 text-[15px] font-semibold">{label}</span>
      {note ? <span className="mt-1 text-[13px] text-muted-foreground">{note}</span> : null}
    </SelectTile>
  );
}

export function OccasionCard({ name, note, icon }: { name: string; note: string; icon?: ReactNode }) {
  return (
    <a
      href="#"
      className="group flex h-full flex-col justify-between rounded-[12px] border border-border bg-surface p-5 transition-colors hover:border-primary/40"
    >
      <div className="mb-8 text-primary">{icon}</div>
      <div>
        <h3 className="font-display text-lg group-hover:text-primary">{name}</h3>
        <p className="mt-1 text-[13px] text-muted-foreground">{note}</p>
      </div>
    </a>
  );
}

export function EditorialCard({ kicker, title, excerpt }: { kicker: string; title: string; excerpt: string }) {
  return (
    <a href="#" className="group block border-t-2 border-primary/15 pt-4 transition-colors hover:border-primary">
      <p className="text-[11px] font-semibold tracking-[0.14em] text-gold uppercase">{kicker}</p>
      <h3 className="mt-2 font-display text-xl leading-snug group-hover:text-primary">{title}</h3>
      <p className="mt-2 text-[14px] leading-relaxed text-muted-foreground">{excerpt}</p>
    </a>
  );
}
