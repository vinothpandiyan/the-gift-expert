import { Link } from "@tanstack/react-router";
import { ArrowUpRight } from "lucide-react";
import { Badge } from "@/components/kit/primitives";
import { cn } from "@/lib/utils";
import type { Gift } from "@/data/gifts";

/**
 * GiftCard — the core reusable unit of the whole platform.
 * Merchant photography is inconsistent, so the image sits in a fixed-ratio,
 * neutral, padded container using object-contain. Never cropped.
 */
export function GiftImage({ src, alt, className }: { src: string; alt: string; className?: string }) {
  return (
    <div className={cn("relative aspect-4/3 overflow-hidden rounded-[10px] bg-surface-sunken p-4", className)}>
      <img
        src={src}
        alt={alt}
        loading="lazy"
        className="h-full w-full object-contain transition-transform duration-300 group-hover:scale-[1.02]"
      />
    </div>
  );
}

export function GiftCard({
  gift,
  matchReason,
  greatMatch,
}: {
  gift: Gift;
  matchReason?: string | undefined;
  greatMatch?: boolean | undefined;
}) {
  const badge = greatMatch ? "Great Match" : gift.badge;
  return (
    <article className="group relative flex h-full flex-col rounded-[14px] border border-border bg-surface p-3 transition-shadow hover:shadow-[0_8px_24px_-16px_rgba(38,35,38,0.35)]">
      <GiftImage src={gift.image} alt={gift.title} />

      <div className="flex flex-1 flex-col px-1.5 pt-4 pb-1">
        {badge ? (
          <div className="mb-2">
            <Badge tone={greatMatch ? "gold" : badge === "Personalized" ? "plum" : "neutral"}>{badge}</Badge>
          </div>
        ) : null}

        <h3 className="text-[15px] leading-snug font-semibold">
          <Link
            to="/gifts/$slug"
            params={{ slug: gift.slug }}
            className="after:absolute after:inset-0 hover:text-primary"
          >
            {gift.title}
          </Link>
        </h3>

        <p className="mt-1.5 line-clamp-2 text-[13px] leading-relaxed text-muted-foreground">
          {matchReason ?? gift.reason}
        </p>

        <div className="mt-auto flex flex-wrap items-center justify-between gap-x-3 gap-y-1 pt-4">
          <span className="text-sm font-semibold">{gift.priceLabel}</span>
          <span className="inline-flex items-center gap-1 text-[13px] font-medium text-primary">
            View gift
            <ArrowUpRight className="h-3.5 w-3.5" aria-hidden />
          </span>
        </div>
        <p className="mt-1 text-[11px] text-muted-foreground">Available at {gift.merchant}</p>
      </div>
    </article>
  );
}

export function GiftGrid({
  gifts,
  columns = 4,
  children,
}: {
  gifts?: Gift[];
  columns?: 3 | 4;
  children?: React.ReactNode;
}) {
  return (
    <div
      className={cn(
        "grid grid-cols-2 gap-3 md:gap-5",
        columns === 4 ? "md:grid-cols-3 lg:grid-cols-4" : "md:grid-cols-3",
      )}
    >
      {gifts ? gifts.map((g) => <GiftCard key={g.slug} gift={g} />) : children}
    </div>
  );
}
