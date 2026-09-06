import { createFileRoute, notFound } from "@tanstack/react-router";
import { useState } from "react";
import { ArrowUpRight, Check, ExternalLink, Heart, Info } from "lucide-react";
import { Badge, Breadcrumbs, Container, Section, SectionHeading, TagChip } from "@/components/kit/primitives";
import { buttonClass } from "@/components/kit/Button";
import { GiftCard } from "@/components/gift/GiftCard";
import { giftBySlug, gifts } from "@/data/gifts";

export const Route = createFileRoute("/gifts/$slug")({
  loader: ({ params }) => {
    const gift = giftBySlug(params.slug);
    if (!gift) throw notFound();
    return { gift };
  },
  head: ({ loaderData }) => {
    if (!loaderData) {
      return { meta: [{ title: "Gift not found | The Gift Expert" }, { name: "robots", content: "noindex" }] };
    }
    const { gift } = loaderData;
    return {
      meta: [
        { title: `${gift.title} — ${gift.priceLabel} | The Gift Expert` },
        { name: "description", content: gift.reason },
        { property: "og:title", content: `${gift.title} | The Gift Expert` },
        { property: "og:description", content: gift.reason },
      ],
    };
  },
  component: GiftDetailPage,
});

function GiftDetailPage() {
  const { gift } = Route.useLoaderData();
  const [active, setActive] = useState(0);
  const gallery = [gift.image, gift.image, gift.image];
  const related = gifts.filter((g) => g.slug !== gift.slug).slice(0, 4);
  const primaryOffer = gift.offers[0] ?? { merchant: gift.merchant, price: gift.priceLabel, url: "#" };

  return (
    <div className="bg-background pb-24 md:pb-0">
      <Container className="py-6">
        <Breadcrumbs
          items={[
            { label: "Home", to: "/" },
            { label: "Gift Ideas", to: "/find-a-gift" },
            { label: gift.title },
          ]}
        />
      </Container>

      <Container className="pb-12">
        <div className="grid gap-8 lg:grid-cols-[1.05fr_0.95fr] lg:gap-14">
          {/* Gallery */}
          <div>
            <div className="rounded-[14px] border border-border bg-surface p-3">
              <div className="aspect-square overflow-hidden rounded-[10px] bg-surface-sunken p-6">
                <img
                  src={gallery[active] ?? gift.image}
                  alt={gift.title}
                  width={1024}
                  height={1024}
                  className="h-full w-full object-contain"
                />
              </div>
            </div>
            <div className="mt-3 flex gap-3">
              {gallery.map((src, i) => (
                <button
                  key={i}
                  type="button"
                  onClick={() => setActive(i)}
                  aria-label={`View image ${i + 1}`}
                  aria-current={active === i}
                  className={`w-20 overflow-hidden rounded-[10px] border p-1 ${
                    active === i ? "border-primary" : "border-border"
                  }`}
                >
                  <img src={src} alt="" className="aspect-square w-full object-contain" loading="lazy" />
                </button>
              ))}
            </div>
          </div>

          {/* Summary */}
          <div>
            {gift.badge ? <Badge tone={gift.badge === "Personalized" ? "plum" : "gold"}>{gift.badge}</Badge> : null}
            <h1 className="mt-3 font-display text-[30px] leading-tight md:text-[42px]">{gift.title}</h1>
            <p className="mt-3 text-[16px] leading-relaxed text-muted-foreground">{gift.reason}</p>

            <div className="mt-6 flex flex-wrap items-baseline gap-x-3 gap-y-1">
              <span className="text-2xl font-semibold">{gift.priceLabel}</span>
              <span className="text-[14px] text-muted-foreground">at {gift.merchant}</span>
            </div>

            <div className="mt-6 flex flex-col gap-3 sm:flex-row">
              <a
                href={primaryOffer.url}
                target="_blank"
                rel="nofollow sponsored noopener"
                className={buttonClass("primary", "lg", "w-full sm:w-auto")}
              >
                Check price at {primaryOffer.merchant}
                <ArrowUpRight className="h-4 w-4" aria-hidden />
              </a>
              <button type="button" className={buttonClass("secondary", "lg", "w-full sm:w-auto")}>
                <Heart className="h-4 w-4" aria-hidden />
                Save this idea
              </button>
            </div>

            <p className="mt-3 flex items-start gap-2 text-[13px] leading-relaxed text-muted-foreground">
              <Info className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden />
              Price and availability may change on the merchant website. The Gift Expert doesn't sell this product — we
              may earn a commission if you buy through this link, at no extra cost to you.
            </p>

            {/* Why it's a great gift */}
            <div className="mt-8 rounded-[14px] border border-border bg-surface p-5">
              <h2 className="font-display text-xl">Why it's a great gift</h2>
              <ul className="mt-3 space-y-2.5">
                {gift.why.map((w) => (
                  <li key={w} className="flex gap-2.5 text-[15px] leading-relaxed">
                    <Check className="mt-0.5 h-4 w-4 shrink-0 text-success" aria-hidden />
                    <span>{w}</span>
                  </li>
                ))}
              </ul>
            </div>

            {/* Best for */}
            <div className="mt-6">
              <h2 className="font-display text-xl">Best for</h2>
              <div className="mt-4 space-y-4">
                {[
                  { label: "Recipients", items: gift.recipients },
                  { label: "Occasions", items: gift.occasions },
                  { label: "Interests", items: gift.interests },
                ].map((group) => (
                  <div key={group.label}>
                    <p className="mb-2 text-[12px] tracking-[0.12em] text-muted-foreground uppercase">{group.label}</p>
                    <div className="flex flex-wrap gap-2">
                      {group.items.map((i) => (
                        <TagChip key={i}>{i}</TagChip>
                      ))}
                    </div>
                  </div>
                ))}
              </div>
            </div>
          </div>
        </div>
      </Container>

      {/* Details + merchants */}
      <Section tone="surface" className="py-12 md:py-16">
        <Container>
          <div className="grid gap-10 lg:grid-cols-2">
            <div>
              <h2 className="font-display text-2xl">Gift details</h2>
              <dl className="mt-4 divide-y divide-border border-y border-border">
                {gift.details.map((d) => (
                  <div key={d.label} className="grid grid-cols-[130px_minmax(0,1fr)] gap-4 py-3 text-[15px]">
                    <dt className="text-muted-foreground">{d.label}</dt>
                    <dd>{d.value}</dd>
                  </div>
                ))}
              </dl>
            </div>

            <div>
              <h2 className="font-display text-2xl">Where to buy</h2>
              <p className="mt-2 text-[14px] text-muted-foreground">
                Listings we've found for this gift. Prices are indicative and set by the merchant.
              </p>
              <ul className="mt-4 space-y-3">
                {gift.offers.map((offer) => (
                  <li
                    key={offer.merchant}
                    className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-4 rounded-[12px] border border-border bg-background p-4"
                  >
                    <div className="min-w-0">
                      <p className="text-[15px] font-semibold">{offer.merchant}</p>
                      <p className="mt-0.5 text-[14px] text-muted-foreground">
                        {offer.price}
                        {offer.note ? ` · ${offer.note}` : ""}
                      </p>
                    </div>
                    <a
                      href={offer.url}
                      target="_blank"
                      rel="nofollow sponsored noopener"
                      className={buttonClass("secondary", "sm", "shrink-0")}
                    >
                      View deal
                      <ExternalLink className="h-3.5 w-3.5" aria-hidden />
                    </a>
                  </li>
                ))}
              </ul>
            </div>
          </div>
        </Container>
      </Section>

      {/* Related */}
      <Section>
        <Container>
          <SectionHeading eyebrow="Keep looking" title="You may also like" />
          <div className="grid grid-cols-2 gap-3 md:grid-cols-3 md:gap-5 lg:grid-cols-4">
            {related.map((g) => (
              <GiftCard key={g.slug} gift={g} />
            ))}
          </div>
        </Container>
      </Section>

      {/* Mobile sticky CTA */}
      <div className="fixed inset-x-0 bottom-0 z-40 border-t border-border bg-surface p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] md:hidden">
        <div className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-3">
          <div className="min-w-0">
            <p className="truncate text-[13px] text-muted-foreground">{gift.priceLabel}</p>
            <p className="truncate text-[13px] font-semibold">at {gift.merchant}</p>
          </div>
          <a
            href={primaryOffer.url}
            target="_blank"
            rel="nofollow sponsored noopener"
            className={buttonClass("primary", "md", "shrink-0")}
          >
            Check price
            <ArrowUpRight className="h-4 w-4" aria-hidden />
          </a>
        </div>
      </div>
    </div>
  );
}
