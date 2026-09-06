import { useEffect, useState, type ReactNode } from "react";
import { Container } from "@/components/kit/primitives";
import { GiftCard } from "@/components/gift/GiftCard";
import {
  ActiveFilterBar,
  ContextualLinks,
  FilterSidebar,
  GiftFinderCTA,
  ListingEmptyState,
  ListingPageHeader,
  ListingSkeletonCard,
  LoadMore,
  MobileFilterDrawer,
  ResultToolbar,
  type ContextualLink,
} from "@/components/listing/parts";
import {
  activeFilters,
  defaultSearch,
  filterGifts,
  PAGE_SIZE,
  toggleValue,
  type FilterDimension,
  type FilterGroupConfig,
  type ListingScope,
  type ListingSearch,
} from "@/lib/listing";
import { cn } from "@/lib/utils";

export type ListingConfig = {
  crumbs: { label: string; to?: string }[];
  title: string;
  intro?: string;
  scope?: ListingScope;
  filterGroups: FilterGroupConfig[];
  contextualLinks?: ContextualLink[];
  contextualLabel?: string;
  /** Optional editorial highlight rendered above the results (SEO variant). */
  highlight?: ReactNode;
  /** Everything that belongs below the results: guidance, related links, FAQ. */
  belowResults?: ReactNode;
  finderCta?: { title?: string; copy?: string };
};

/**
 * One listing system for every browse surface: relationship, occasion,
 * interest, gift type and filtered /gift-ideas states.
 * All state lives in the URL, so a Livewire implementation can mirror it 1:1.
 */
export function ListingPage({
  config,
  search,
  onSearchChange,
}: {
  config: ListingConfig;
  search: ListingSearch;
  onSearchChange: (next: ListingSearch) => void;
}) {
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [pending, setPending] = useState(false);

  const results = filterGifts(config.scope ?? {}, search);
  const visible = results.slice(0, search.show);
  const chips = activeFilters(search);
  const lastChip = chips[chips.length - 1];

  // Lightweight request-shaped loading state (mirrors a Livewire round trip).
  const filterKey = JSON.stringify({ ...search, show: 0 });
  useEffect(() => {
    setPending(true);
    const t = setTimeout(() => setPending(false), 320);
    return () => clearTimeout(t);
  }, [filterKey]);

  const update = (next: ListingSearch) => onSearchChange(next);
  const onToggle = (dimension: FilterDimension, value: string) =>
    update(toggleValue(search, dimension, value));
  const clearAll = () => update({ ...defaultSearch, sort: search.sort });

  return (
    <div className="bg-background pb-16">
      <ListingPageHeader crumbs={config.crumbs} title={config.title} {...(config.intro ? { intro: config.intro } : {})} />

      <Container className="pt-6 md:pt-8">
        {config.contextualLinks?.length ? (
          <div className="pb-6">
            <ContextualLinks
              links={config.contextualLinks}
              {...(config.contextualLabel ? { label: config.contextualLabel } : {})}
            />
          </div>
        ) : null}

        {config.highlight ? <div className="pb-6">{config.highlight}</div> : null}

        <div className="flex gap-8 lg:gap-10">
          <div className="hidden md:block">
            <FilterSidebar
              groups={config.filterGroups}
              search={search}
              onToggle={onToggle}
              onClear={clearAll}
              activeCount={chips.length}
            />
          </div>

          <div className="min-w-0 flex-1">
            <ResultToolbar
              count={results.length}
              sort={search.sort}
              onSortChange={(sort) => update({ ...search, sort, show: PAGE_SIZE })}
              activeCount={chips.length}
              onOpenFilters={() => setDrawerOpen(true)}
            />

            <ActiveFilterBar
              filters={chips}
              onRemove={(f) => update(toggleValue(search, f.dimension, f.value))}
              onClear={clearAll}
            />

            <div className="pt-6">
              {pending ? (
                <div className="grid grid-cols-2 gap-3 md:grid-cols-3 md:gap-5">
                  {Array.from({ length: Math.min(6, Math.max(visible.length, 3)) }).map((_, i) => (
                    <ListingSkeletonCard key={i} />
                  ))}
                </div>
              ) : results.length === 0 ? (
                <ListingEmptyState
                  onClear={clearAll}
                  {...(lastChip
                    ? {
                        lastLabel: lastChip.label,
                        onRemoveLast: () => update(toggleValue(search, lastChip.dimension, lastChip.value)),
                      }
                    : {})}
                />
              ) : (
                <>
                  <div
                    className={cn(
                      "grid grid-cols-2 gap-3 transition-opacity duration-200 md:grid-cols-3 md:gap-5 xl:grid-cols-4",
                    )}
                  >
                    {visible.map((gift) => (
                      <GiftCard key={gift.slug} gift={gift} />
                    ))}
                  </div>
                  <LoadMore
                    remaining={results.length - visible.length}
                    onLoadMore={() => update({ ...search, show: search.show + PAGE_SIZE })}
                  />
                </>
              )}
            </div>
          </div>
        </div>

        <GiftFinderCTA {...(config.finderCta ?? {})} />

        {config.belowResults}
      </Container>

      <MobileFilterDrawer
        open={drawerOpen}
        onClose={() => setDrawerOpen(false)}
        groups={config.filterGroups}
        search={search}
        onToggle={onToggle}
        onClear={clearAll}
        resultCount={results.length}
        activeCount={chips.length}
      />
    </div>
  );
}
