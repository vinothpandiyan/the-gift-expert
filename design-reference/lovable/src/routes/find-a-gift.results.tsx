import { createFileRoute } from "@tanstack/react-router";
import { useState } from "react";
import { Pencil, SearchX, Sparkles } from "lucide-react";
import { Badge, Container, EmptyState, SectionHeading } from "@/components/kit/primitives";
import { Button, ButtonLink } from "@/components/kit/Button";
import { GiftCard } from "@/components/gift/GiftCard";
import { gifts } from "@/data/gifts";

export const Route = createFileRoute("/find-a-gift/results")({
  head: () => ({
    meta: [
      { title: "Your gift matches — Gift Finder | The Gift Expert" },
      {
        name: "description",
        content:
          "Ranked gift recommendations based on who you're buying for, the occasion, their interests and your budget — each with a reason why it fits.",
      },
      { property: "og:title", content: "Your gift matches | The Gift Expert" },
      { property: "og:description", content: "Ranked gift ideas, each with a reason why it fits." },
    ],
  }),
  component: ResultsPage,
});

const preferences = [
  { label: "For", value: "Husband" },
  { label: "Occasion", value: "Birthday" },
  { label: "Interests", value: "Coffee, Travel" },
  { label: "Budget", value: "₹1,000 – ₹2,500" },
];

const matches = [
  {
    slug: "personalized-leather-travel-organizer",
    reason: "Great for a husband who loves travelling — and the initials make it feel personal.",
    great: true,
  },
  {
    slug: "specialty-coffee-sampler-kit",
    reason: "Four single-origin roasts for someone who takes their morning coffee seriously.",
    great: true,
  },
  {
    slug: "personalized-wooden-photo-frame",
    reason: "A sentimental birthday gift that sits on his desk long after the day.",
  },
  {
    slug: "leather-journal-and-pen-set",
    reason: "Simple, useful and comfortably inside your budget.",
  },
  {
    slug: "fitness-tracker-band",
    reason: "Slightly above budget, but a strong pick if he's started running.",
  },
];

function ResultsPage() {
  const [empty, setEmpty] = useState(false);

  return (
    <div className="bg-background">
      <Container className="py-8 md:py-12">
        <div className="rounded-[14px] border border-border bg-surface p-5 md:p-6">
          <div className="flex flex-col items-start gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div className="min-w-0">
              <span className="inline-flex items-center gap-1.5 text-[12px] font-semibold tracking-wide text-primary uppercase">
                <Sparkles className="h-3.5 w-3.5" aria-hidden />
                Gift Finder
              </span>
              <h1 className="mt-2 font-display text-[28px] leading-tight md:text-[38px]">
                {empty ? "We couldn't find a strong match yet" : "We found some great matches"}
              </h1>
            </div>
            <ButtonLink to="/find-a-gift" variant="secondary" size="sm" className="shrink-0">
              <Pencil className="h-3.5 w-3.5" aria-hidden />
              Edit preferences
            </ButtonLink>
          </div>

          <dl className="mt-5 grid grid-cols-2 gap-3 border-t border-border pt-5 md:grid-cols-4">
            {preferences.map((p) => (
              <div key={p.label}>
                <dt className="text-[12px] tracking-wide text-muted-foreground uppercase">{p.label}</dt>
                <dd className="mt-1 text-[15px] font-semibold">{p.value}</dd>
              </div>
            ))}
          </dl>
        </div>

        {empty ? (
          <div className="mt-8">
            <EmptyState
              icon={<SearchX className="h-5 w-5" aria-hidden />}
              title="We couldn't find a strong match yet."
              description="Your filters are a little tight. Widening any one of these usually surfaces good ideas."
              actions={
                <>
                  <Button onClick={() => setEmpty(false)}>Increase budget</Button>
                  <Button variant="secondary" onClick={() => setEmpty(false)}>
                    Remove one interest
                  </Button>
                  <ButtonLink to="/" variant="secondary">
                    Browse related gifts
                  </ButtonLink>
                  <ButtonLink to="/find-a-gift" variant="ghost">
                    Start over
                  </ButtonLink>
                </>
              }
            />
          </div>
        ) : (
          <div className="mt-10">
            <SectionHeading
              eyebrow="Ranked for this person"
              title="5 ideas worth giving"
              description="Ordered by how well they fit — not by price or commission."
              action={
                <Button variant="ghost" size="sm" onClick={() => setEmpty(true)}>
                  Preview empty state
                </Button>
              }
            />
            <div className="grid grid-cols-2 gap-3 md:grid-cols-3 md:gap-5 lg:grid-cols-4">
              {matches.map((m) => {
                const gift = gifts.find((g) => g.slug === m.slug);
                if (!gift) return null;
                return <GiftCard key={m.slug} gift={gift} matchReason={m.reason} greatMatch={m.great} />;
              })}
            </div>

            <div className="mt-10 flex flex-col items-center gap-3 rounded-[14px] border border-border bg-surface p-6 text-center">
              <Badge tone="plum">Still deciding?</Badge>
              <p className="max-w-md text-[15px] text-muted-foreground">
                Change one answer — the budget or an interest — and we'll rerank everything around it.
              </p>
              <ButtonLink to="/find-a-gift" variant="secondary">
                Adjust my answers
              </ButtonLink>
            </div>
          </div>
        )}
      </Container>
    </div>
  );
}
