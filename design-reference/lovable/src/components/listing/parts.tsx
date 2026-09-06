import { useEffect, useRef, useState, type ReactNode } from "react";
import { Link } from "@tanstack/react-router";
import { ChevronDown, Minus, Plus, SlidersHorizontal, Sparkles, X } from "lucide-react";
import { Button, ButtonLink } from "@/components/kit/Button";
import { Breadcrumbs, Container, SkeletonCard } from "@/components/kit/primitives";
import { cn } from "@/lib/utils";
import type { ActiveFilter, FilterDimension, FilterGroupConfig, ListingSearch, SortId } from "@/lib/listing";
import { sortOptions } from "@/lib/listing";

/* 1 — Header --------------------------------------------------------------- */

export function ListingPageHeader({
  crumbs,
  title,
  intro,
}: {
  crumbs: { label: string; to?: string }[];
  title: string;
  intro?: string;
}) {
  return (
    <div className="border-b border-border bg-surface">
      <Container className="py-6 md:py-10">
        <Breadcrumbs items={crumbs} />
        <h1 className="mt-3 font-display text-[30px] leading-[1.12] tracking-tight md:text-[44px]">{title}</h1>
        {intro ? <p className="mt-3 max-w-2xl text-[15px] leading-relaxed text-muted-foreground">{intro}</p> : null}
      </Container>
    </div>
  );
}

/* 2 — Contextual navigation links (optional) ------------------------------- */

export type ContextualLink = { label: string; to?: string; params?: Record<string, string> };

export function ContextualLinks({ links, label = "Popular in this section" }: { links: ContextualLink[]; label?: string }) {
  if (!links.length) return null;
  return (
    <nav aria-label={label} className="flex flex-wrap items-center gap-x-5 gap-y-2 md:gap-x-7">
      <span className="text-[12px] font-semibold tracking-[0.12em] text-muted-foreground uppercase">{label}</span>
      {links.map((link) =>
        link.to ? (
          <Link
            key={link.label}
            to={link.to}
            {...(link.params ? { params: link.params } : {})}
            className="text-[14px] font-medium text-primary underline decoration-primary/25 underline-offset-4 hover:decoration-primary"
          >
            {link.label}
          </Link>
        ) : (
          <span key={link.label} className="text-[14px] font-medium text-muted-foreground">
            {link.label}
          </span>
        ),
      )}
    </nav>
  );
}

/* 3 — Sort ----------------------------------------------------------------- */

export function SortSelect({
  value,
  onChange,
  id = "sort",
  className,
}: {
  value: SortId;
  onChange: (v: SortId) => void;
  id?: string;
  className?: string;
}) {
  return (
    <div className={cn("relative inline-flex items-center", className)}>

      <label htmlFor={id} className="sr-only text-[13px] text-muted-foreground md:not-sr-only md:mr-2">
        Sort
      </label>
      <select
        id={id}
        value={value}
        onChange={(e) => onChange(e.target.value as SortId)}
        className="h-11 w-full appearance-none rounded-[10px] border border-border bg-surface pr-9 pl-3 text-[14px] font-medium hover:border-primary/40"
      >
        {sortOptions.map((o) => (
          <option key={o.id} value={o.id}>
            {o.label}
          </option>
        ))}
      </select>
      <ChevronDown className="pointer-events-none absolute right-3 h-4 w-4 text-muted-foreground" aria-hidden />
    </div>
  );
}

/* 4 — Toolbar -------------------------------------------------------------- */

export function ResultToolbar({
  count,
  sort,
  onSortChange,
  activeCount,
  onOpenFilters,
}: {
  count: number;
  sort: SortId;
  onSortChange: (v: SortId) => void;
  activeCount: number;
  onOpenFilters: () => void;
}) {
  return (
    <div className="flex flex-col gap-3 border-b border-border pb-4 md:flex-row md:items-center md:justify-between">
      <p aria-live="polite" className="text-[14px] font-medium">
        {count} gift {count === 1 ? "idea" : "ideas"}
      </p>

      <div className="hidden md:block">
        <SortSelect value={sort} onChange={onSortChange} />
      </div>

      <div className="flex items-center gap-2 md:hidden">
        <Button variant="secondary" size="sm" onClick={onOpenFilters} className="min-h-11 flex-1">
          <SlidersHorizontal className="h-4 w-4" aria-hidden />
          Filters{activeCount ? ` · ${activeCount}` : ""}
        </Button>
        <SortSelect value={sort} onChange={onSortChange} id="sort-mobile" className="flex-1" />
      </div>
    </div>
  );

}

/* 5 — Filters -------------------------------------------------------------- */

export function FilterCheckbox({
  label,
  checked,
  onChange,
  type = "checkbox",
  name,
}: {
  label: string;
  checked: boolean;
  onChange: () => void;
  type?: "checkbox" | "radio";
  name?: string;
}) {
  return (
    <label className="flex min-h-11 cursor-pointer items-center gap-3 rounded-[8px] px-1 text-[14px] hover:text-primary">
      <input
        type={type}
        {...(name ? { name } : {})}
        checked={checked}
        onChange={onChange}
        className="h-[18px] w-[18px] shrink-0 accent-[var(--color-primary)]"
      />
      <span>{label}</span>
    </label>
  );
}

export function FilterGroup({
  group,
  search,
  onToggle,
  defaultOpen = true,
}: {
  group: FilterGroupConfig;
  search: ListingSearch;
  onToggle: (dimension: FilterDimension, value: string) => void;
  defaultOpen?: boolean;
}) {
  const [open, setOpen] = useState(defaultOpen);
  const isChecked = (id: string) =>
    group.dimension === "budget" ? search.budget === id : search[group.dimension].includes(id);

  return (
    <div className="border-b border-border py-4 last:border-b-0">
      <button
        type="button"
        aria-expanded={open}
        onClick={() => setOpen((o) => !o)}
        className="flex min-h-11 w-full items-center justify-between text-left text-[14px] font-semibold"
      >
        {group.label}
        {open ? <Minus className="h-4 w-4 text-muted-foreground" aria-hidden /> : <Plus className="h-4 w-4 text-muted-foreground" aria-hidden />}
      </button>
      {open ? (
        <div className="mt-1.5 flex flex-col">
          {group.options.map((option) => (
            <FilterCheckbox
              key={option.id}
              label={option.label}
              checked={isChecked(option.id)}
              type={group.single ? "radio" : "checkbox"}
              {...(group.single ? { name: `${group.dimension}-${group.label}` } : {})}
              onChange={() => onToggle(group.dimension, option.id)}
            />
          ))}
        </div>
      ) : null}
    </div>
  );
}

export function FilterSidebar({
  groups,
  search,
  onToggle,
  onClear,
  activeCount,
}: {
  groups: FilterGroupConfig[];
  search: ListingSearch;
  onToggle: (dimension: FilterDimension, value: string) => void;
  onClear: () => void;
  activeCount: number;
}) {
  return (
    <aside aria-label="Filters" className="w-[240px] shrink-0 lg:w-[264px]">
      <div className="flex items-center justify-between pb-2">
        <h2 className="text-[13px] font-semibold tracking-[0.12em] text-muted-foreground uppercase">Narrow it down</h2>
        {activeCount ? (
          <button type="button" onClick={onClear} className="text-[13px] font-medium text-primary hover:underline">
            Clear all
          </button>
        ) : null}
      </div>
      <div className="rounded-[14px] border border-border bg-surface px-4">
        {groups.map((group) => (
          <FilterGroup key={group.label} group={group} search={search} onToggle={onToggle} />
        ))}
      </div>
    </aside>
  );
}

export function ActiveFilterChip({ filter, onRemove }: { filter: ActiveFilter; onRemove: () => void }) {
  return (
    <button
      type="button"
      onClick={onRemove}
      className="inline-flex min-h-9 items-center gap-1.5 rounded-[8px] border border-primary/25 bg-primary-light px-3 text-[13px] font-medium text-primary hover:border-primary"
    >
      {filter.label}
      <X className="h-3.5 w-3.5" aria-hidden />
      <span className="sr-only">Remove filter</span>
    </button>
  );
}

export function ActiveFilterBar({
  filters,
  onRemove,
  onClear,
}: {
  filters: ActiveFilter[];
  onRemove: (f: ActiveFilter) => void;
  onClear: () => void;
}) {
  if (!filters.length) return null;
  return (
    <div className="flex flex-wrap items-center gap-2 pt-4">
      {filters.map((f) => (
        <ActiveFilterChip key={`${f.dimension}-${f.value}`} filter={f} onRemove={() => onRemove(f)} />
      ))}
      <button type="button" onClick={onClear} className="min-h-9 px-1 text-[13px] font-medium text-muted-foreground hover:text-primary hover:underline">
        Clear all
      </button>
    </div>
  );
}

/* 6 — Mobile drawer -------------------------------------------------------- */

export function MobileFilterDrawer({
  open,
  onClose,
  groups,
  search,
  onToggle,
  onClear,
  resultCount,
  activeCount,
}: {
  open: boolean;
  onClose: () => void;
  groups: FilterGroupConfig[];
  search: ListingSearch;
  onToggle: (dimension: FilterDimension, value: string) => void;
  onClear: () => void;
  resultCount: number;
  activeCount: number;
}) {
  const closeRef = useRef<HTMLButtonElement>(null);
  useEffect(() => {
    if (open) closeRef.current?.focus();
  }, [open]);

  if (!open) return null;
  return (
    <div className="fixed inset-0 z-60 md:hidden" role="dialog" aria-modal="true" aria-label="Filters">
      <button type="button" aria-label="Close filters" onClick={onClose} className="absolute inset-0 bg-foreground/40" />
      <div className="absolute inset-x-0 bottom-0 top-10 flex flex-col rounded-t-[16px] bg-background">
        <div className="flex items-center justify-between border-b border-border px-5 py-4">
          <h2 className="font-display text-xl">Filters</h2>
          <div className="flex items-center gap-3">
            {activeCount ? (
              <button type="button" onClick={onClear} className="text-[13px] font-medium text-primary">
                Clear all
              </button>
            ) : null}
            <button
              ref={closeRef}
              type="button"
              onClick={onClose}
              aria-label="Close filters"
              className="flex h-11 w-11 items-center justify-center rounded-[10px] border border-border"
            >
              <X className="h-4 w-4" aria-hidden />
            </button>
          </div>
        </div>

        <div className="flex-1 overflow-y-auto px-5 pb-4">
          {groups.map((group) => (
            <FilterGroup key={group.label} group={group} search={search} onToggle={onToggle} />
          ))}
        </div>

        <div className="flex items-center gap-3 border-t border-border bg-surface px-5 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
          <Button variant="secondary" size="md" className="flex-1" onClick={onClear}>
            Reset
          </Button>
          <Button size="md" className="flex-[1.6]" onClick={onClose}>
            Show {resultCount} {resultCount === 1 ? "gift" : "gifts"}
          </Button>
        </div>
      </div>
    </div>
  );
}

/* 7 — Loading, empty, load more ------------------------------------------- */

export function ListingSkeletonCard() {
  return <SkeletonCard />;
}

export function ListingEmptyState({
  onRemoveLast,
  onClear,
  lastLabel,
}: {
  onRemoveLast?: (() => void) | undefined;
  onClear: () => void;
  lastLabel?: string | undefined;
}) {
  return (
    <div className="rounded-[14px] border border-dashed border-border bg-surface px-6 py-14 text-center">
      <h2 className="font-display text-2xl">No gift ideas match all those filters.</h2>
      <p className="mx-auto mt-2 max-w-md text-[15px] text-muted-foreground">
        Try removing one filter or increasing the budget — most gifts sit in one or two categories only.
      </p>
      <div className="mt-6 flex flex-wrap justify-center gap-3">
        {onRemoveLast && lastLabel ? (
          <Button variant="secondary" size="sm" onClick={onRemoveLast}>
            Remove “{lastLabel}”
          </Button>
        ) : null}
        <Button variant="secondary" size="sm" onClick={onClear}>
          Clear all filters
        </Button>
        <ButtonLink variant="secondary" size="sm" to="/gift-ideas">
          Browse all gifts
        </ButtonLink>
        <ButtonLink size="sm" to="/find-a-gift">
          Try Gift Finder
        </ButtonLink>
      </div>
    </div>
  );
}

export function LoadMore({ remaining, onLoadMore, loading }: { remaining: number; onLoadMore: () => void; loading?: boolean }) {
  if (remaining <= 0) return null;
  return (
    <div className="mt-10 flex flex-col items-center gap-2">
      <Button variant="secondary" size="md" onClick={onLoadMore} disabled={loading}>
        {loading ? "Loading…" : "Load more gift ideas"}
      </Button>
      <p className="text-[13px] text-muted-foreground">{remaining} more to see</p>
    </div>
  );
}

/* 8 — Below-results blocks ------------------------------------------------- */

export function GiftFinderCTA({
  title = "Still not sure what they'd like?",
  copy = "Answer a few questions and we'll narrow it down to a handful of ideas.",
}: {
  title?: string;
  copy?: string;
}) {
  return (
    <div className="mt-14 flex flex-col gap-5 rounded-[14px] bg-primary px-6 py-8 text-primary-foreground md:flex-row md:items-center md:justify-between md:px-10 md:py-10">
      <div className="max-w-xl">
        <p className="mb-2 inline-flex items-center gap-2 text-[12px] font-semibold tracking-[0.12em] uppercase opacity-80">
          <Sparkles className="h-3.5 w-3.5" aria-hidden /> Gift Finder
        </p>
        <h2 className="font-display text-2xl md:text-3xl">{title}</h2>
        <p className="mt-2 text-[15px] opacity-85">{copy}</p>
      </div>
      <ButtonLink to="/find-a-gift" variant="coral" size="lg" className="w-full shrink-0 md:w-auto">
        Start Gift Finder
      </ButtonLink>
    </div>
  );
}

export function RelatedLinks({ title, links }: { title: string; links: ContextualLink[] }) {
  return (
    <section className="mt-12">
      <h2 className="font-display text-2xl">{title}</h2>
      <ul className="mt-4 grid gap-x-8 gap-y-2 sm:grid-cols-2 lg:grid-cols-3">
        {links.map((l) => (
          <li key={l.label}>
            {l.to ? (
              <Link
                to={l.to}
                {...(l.params ? { params: l.params } : {})}
                className="text-[14px] text-primary hover:underline"
              >
                {l.label}
              </Link>
            ) : (
              <span className="text-[14px] text-muted-foreground">{l.label}</span>
            )}
          </li>
        ))}
      </ul>
    </section>
  );
}

export function FAQSection({ items }: { items: { q: string; a: string }[] }) {
  return (
    <section className="mt-12">
      <h2 className="font-display text-2xl">Common questions</h2>
      <dl className="mt-4 divide-y divide-border border-y border-border">
        {items.map((item) => (
          <div key={item.q} className="py-4">
            <dt className="text-[15px] font-semibold">{item.q}</dt>
            <dd className="mt-1.5 max-w-3xl text-[15px] leading-relaxed text-muted-foreground">{item.a}</dd>
          </div>
        ))}
      </dl>
    </section>
  );
}

export function SeoEditorialSection({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section className="mt-12 max-w-3xl">
      <h2 className="font-display text-2xl">{title}</h2>
      <div className="mt-3 space-y-4 text-[15px] leading-relaxed text-muted-foreground">{children}</div>
    </section>
  );
}

export function HighlightStrip({ title, children }: { title: string; children: ReactNode }) {
  return (
    <div className={cn("rounded-[14px] border border-gold/30 bg-gold/8 px-5 py-5 md:px-6")}>
      <p className="text-[12px] font-semibold tracking-[0.12em] text-[#7a5a12] uppercase">{title}</p>
      <div className="mt-3">{children}</div>
    </div>
  );
}
