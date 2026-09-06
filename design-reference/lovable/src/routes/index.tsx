import { createFileRoute, Link, useNavigate } from "@tanstack/react-router";
import { useState } from "react";
import {
  ArrowRight,
  Cake,
  Calendar,
  Camera,
  Coffee,
  Dumbbell,
  Gamepad2,
  Gift,
  Heart,
  Home,
  Music,
  PawPrint,
  Plane,
  Sparkles,
  Utensils,
  Wallet,
  BookOpen,
  Laptop,
  GraduationCap,
  Flame,
} from "lucide-react";
import { Container, Section, SectionHeading, Badge } from "@/components/kit/primitives";
import { Button, ButtonLink } from "@/components/kit/Button";
import { GiftCard } from "@/components/gift/GiftCard";
import { EditorialCard, OccasionCard } from "@/components/gift/tiles";
import { budgets, editorial, gifts, interests, occasions, recipients } from "@/data/gifts";
import heroImage from "@/assets/hero-gifting.jpg";

export const Route = createFileRoute("/")({
  head: () => ({
    meta: [
      { title: "The Gift Expert — Find a gift they'll actually love" },
      {
        name: "description",
        content:
          "Thoughtful gift ideas for every person, occasion and budget. Tell us who you're buying for and we'll narrow it down to a handful of gifts worth giving.",
      },
      { property: "og:title", content: "The Gift Expert — Find a gift they'll actually love" },
      {
        property: "og:description",
        content: "Curated gift ideas for every person, occasion and budget in India.",
      },
    ],
  }),
  component: HomePage,
});

const occasionIcons: Record<string, React.ReactNode> = {
  Birthday: <Cake className="h-5 w-5" />,
  Anniversary: <Heart className="h-5 w-5" />,
  Wedding: <Sparkles className="h-5 w-5" />,
  Housewarming: <Home className="h-5 w-5" />,
  Diwali: <Flame className="h-5 w-5" />,
  "Mother's Day": <Heart className="h-5 w-5" />,
  "Father's Day": <Gift className="h-5 w-5" />,
  Graduation: <GraduationCap className="h-5 w-5" />,
};

const interestIcons: Record<string, React.ReactNode> = {
  Coffee: <Coffee className="h-4 w-4" />,
  Travel: <Plane className="h-4 w-4" />,
  Technology: <Laptop className="h-4 w-4" />,
  Books: <BookOpen className="h-4 w-4" />,
  Fitness: <Dumbbell className="h-4 w-4" />,
  Music: <Music className="h-4 w-4" />,
  Photography: <Camera className="h-4 w-4" />,
  Pets: <PawPrint className="h-4 w-4" />,
  Cooking: <Utensils className="h-4 w-4" />,
  Gaming: <Gamepad2 className="h-4 w-4" />,
};

function HeroSelector() {
  const navigate = useNavigate();
  const [recipient, setRecipient] = useState("Husband");
  const [occasion, setOccasion] = useState("Birthday");
  const [budget, setBudget] = useState("₹1,000 – ₹2,500");

  const field = "h-12 w-full rounded-[10px] border border-border bg-surface px-3 text-[15px] outline-none focus:border-primary";

  return (
    <form
      className="rounded-[14px] border border-border bg-surface p-4 shadow-[0_16px_40px_-32px_rgba(38,35,38,0.5)] md:p-5"
      onSubmit={(e) => {
        e.preventDefault();
        navigate({ to: "/find-a-gift/results" });
      }}
    >
      <p className="mb-4 text-[15px] font-semibold">I'm looking for a gift for…</p>
      <div className="grid gap-3 md:grid-cols-3">
        <div>
          <label htmlFor="hero-recipient" className="mb-1.5 block text-[13px] text-muted-foreground">
            Recipient
          </label>
          <select id="hero-recipient" className={field} value={recipient} onChange={(e) => setRecipient(e.target.value)}>
            {recipients.map((r) => (
              <option key={r}>{r}</option>
            ))}
          </select>
        </div>
        <div>
          <label htmlFor="hero-occasion" className="mb-1.5 block text-[13px] text-muted-foreground">
            For
          </label>
          <select id="hero-occasion" className={field} value={occasion} onChange={(e) => setOccasion(e.target.value)}>
            {occasions.map((o) => (
              <option key={o.name}>{o.name}</option>
            ))}
          </select>
        </div>
        <div>
          <label htmlFor="hero-budget" className="mb-1.5 block text-[13px] text-muted-foreground">
            My budget is
          </label>
          <select id="hero-budget" className={field} value={budget} onChange={(e) => setBudget(e.target.value)}>
            {budgets.map((b) => (
              <option key={b.label}>{b.label}</option>
            ))}
          </select>
        </div>
      </div>
      <Button type="submit" size="lg" className="mt-4 w-full md:w-auto">
        Show me gifts
        <ArrowRight className="h-4 w-4" aria-hidden />
      </Button>
    </form>
  );
}

function HomePage() {
  return (
    <>
      {/* Hero ------------------------------------------------------------- */}
      <section className="border-b border-border bg-background">
        <Container className="py-10 md:py-16">
          <div className="grid items-center gap-10 lg:grid-cols-[1.05fr_0.95fr]">
            <div>
              <Badge tone="plum">
                <Sparkles className="h-3 w-3" aria-hidden />
                Gift discovery, not a catalogue
              </Badge>
              <h1 className="mt-4 font-display text-[38px] leading-[1.08] tracking-tight md:text-[56px]">
                Find a gift they'll actually love.
              </h1>
              <p className="mt-4 max-w-lg text-[16px] leading-relaxed text-muted-foreground md:text-[18px]">
                Thoughtful gift ideas for every person, occasion and budget — with a reason why each one works.
              </p>
              <div className="mt-6 flex flex-col gap-3 sm:flex-row">
                <ButtonLink to="/find-a-gift" size="lg" className="w-full sm:w-auto">
                  <Sparkles className="h-4 w-4" aria-hidden />
                  Find a Gift
                </ButtonLink>
                <ButtonLink to="/find-a-gift/results" variant="secondary" size="lg" className="w-full sm:w-auto">
                  Browse gift ideas
                </ButtonLink>
              </div>
              <div className="mt-8">
                <HeroSelector />
              </div>
            </div>

            <div className="order-first lg:order-none">
              <div className="overflow-hidden rounded-[16px] border border-border">
                <img
                  src={heroImage}
                  alt="A wrapped gift being handed from one person to another"
                  width={1200}
                  height={1408}
                  className="h-56 w-full object-cover sm:h-72 lg:h-[520px]"
                />
              </div>
            </div>
          </div>
        </Container>
      </section>

      {/* Recipients ------------------------------------------------------- */}
      <Section tone="surface">
        <Container>
          <SectionHeading
            eyebrow="Start here"
            title="Who are you shopping for?"
            description="Pick a person and we'll show ideas chosen for that relationship."
          />
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
            {recipients.map((r) => (
              <a
                key={r}
                href="#"
                className="flex min-h-14 items-center justify-between rounded-[12px] border border-border bg-background px-4 text-[15px] font-semibold transition-colors hover:border-primary/50 hover:bg-primary-light"
              >
                {r}
                <ArrowRight className="h-4 w-4 text-primary" aria-hidden />
              </a>
            ))}
          </div>
        </Container>
      </Section>

      {/* Occasions -------------------------------------------------------- */}
      <Section>
        <Container>
          <SectionHeading
            eyebrow="By occasion"
            title="What's the occasion?"
            action={
              <a href="#" className="inline-flex items-center gap-1 text-sm font-semibold text-primary hover:underline">
                All occasions <ArrowRight className="h-4 w-4" aria-hidden />
              </a>
            }
          />
          <div className="grid grid-cols-2 gap-3 md:grid-cols-4 md:gap-5">
            {occasions.map((o) => (
              <OccasionCard key={o.name} name={o.name} note={o.note} icon={occasionIcons[o.name]} />
            ))}
          </div>
        </Container>
      </Section>

      {/* Trending --------------------------------------------------------- */}
      <Section tone="surface">
        <Container>
          <SectionHeading
            eyebrow="Curated this week"
            title="Trending gift ideas"
            description="Chosen by us, bought at Amazon, Flipkart and specialist stores."
          />
          <div className="grid grid-cols-2 gap-3 md:grid-cols-3 md:gap-5 lg:grid-cols-4">
            {gifts.slice(0, 4).map((g) => (
              <GiftCard key={g.slug} gift={g} />
            ))}
          </div>
        </Container>
      </Section>

      {/* Gift Finder promo ------------------------------------------------ */}
      <Section tone="plum">
        <Container>
          <div className="grid items-center gap-10 lg:grid-cols-2">
            <div>
              <p className="text-xs font-semibold tracking-[0.14em] text-primary-light/80 uppercase">Gift Finder</p>
              <h2 className="mt-3 font-display text-[30px] leading-tight md:text-[42px]">
                Not sure what to get them?
              </h2>
              <p className="mt-4 max-w-md text-[16px] leading-relaxed text-primary-light/90">
                Answer a few quick questions and we'll narrow down the best gift ideas — with a reason why each one
                suits them.
              </p>
              <ButtonLink to="/find-a-gift" variant="coral" size="lg" className="mt-7">
                Start Gift Finder
                <ArrowRight className="h-4 w-4" aria-hidden />
              </ButtonLink>
            </div>

            <ol className="grid gap-3 rounded-[14px] border border-primary-light/20 bg-primary-dark/40 p-5">
              {[
                { step: "Recipient", detail: "Husband", icon: <Heart className="h-4 w-4" /> },
                { step: "Occasion", detail: "Birthday", icon: <Calendar className="h-4 w-4" /> },
                { step: "Interests", detail: "Coffee, Travel", icon: <Coffee className="h-4 w-4" /> },
                { step: "Budget", detail: "₹1,000 – ₹2,500", icon: <Wallet className="h-4 w-4" /> },
              ].map((s, i) => (
                <li key={s.step} className="flex items-center gap-4 rounded-[10px] bg-primary/40 px-4 py-3">
                  <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-primary-light text-primary">
                    {s.icon}
                  </span>
                  <span className="min-w-0 flex-1">
                    <span className="block text-[12px] tracking-wide text-primary-light/70 uppercase">
                      Step {i + 1} · {s.step}
                    </span>
                    <span className="block truncate text-[15px] font-semibold">{s.detail}</span>
                  </span>
                </li>
              ))}
              <li className="mt-1 rounded-[10px] border border-gold/40 px-4 py-3 text-[15px] font-semibold text-gold">
                → 6 gift ideas, ranked
              </li>
            </ol>
          </div>
        </Container>
      </Section>

      {/* Interests -------------------------------------------------------- */}
      <Section>
        <Container>
          <SectionHeading eyebrow="By interest" title="What are they into?" />
          <div className="flex flex-wrap gap-2.5">
            {interests.map((i) => (
              <a
                key={i}
                href="#"
                className="inline-flex min-h-12 items-center gap-2 rounded-[10px] border border-border bg-surface px-4 text-[15px] font-medium transition-colors hover:border-primary/50 hover:bg-primary-light"
              >
                <span className="text-primary">{interestIcons[i]}</span>
                {i}
              </a>
            ))}
          </div>
        </Container>
      </Section>

      {/* Budget ----------------------------------------------------------- */}
      <Section tone="surface">
        <Container>
          <SectionHeading eyebrow="By budget" title="How much are you spending?" />
          <div className="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-6">
            {budgets.map((b) => (
              <a
                key={b.label}
                href="#"
                className="flex min-h-24 flex-col justify-center rounded-[12px] border border-border bg-background p-4 transition-colors hover:border-primary/50 hover:bg-primary-light"
              >
                <span className="text-[15px] font-semibold">{b.label}</span>
                <span className="mt-1 text-[13px] text-muted-foreground">{b.note}</span>
              </a>
            ))}
          </div>
        </Container>
      </Section>

      {/* Return gifts ----------------------------------------------------- */}
      <Section>
        <Container>
          <div className="flex flex-col gap-6 rounded-[14px] border border-border bg-primary-light/60 p-6 md:flex-row md:items-center md:justify-between md:p-8">
            <div className="max-w-xl">
              <h2 className="font-display text-2xl md:text-[28px]">Return gift ideas</h2>
              <p className="mt-2 text-[15px] leading-relaxed text-muted-foreground">
                Hosting a birthday, wedding or house celebration? Browse return gift ideas that are useful, easy to
                buy in bulk and kind to your budget.
              </p>
            </div>
            <ButtonLink to="/find-a-gift" variant="secondary" size="lg" className="shrink-0">
              Browse return gifts
            </ButtonLink>
          </div>
        </Container>
      </Section>

      {/* Editorial -------------------------------------------------------- */}
      <Section tone="surface">
        <Container>
          <SectionHeading eyebrow="Inspiration" title="Gifting guides worth reading" />
          <div className="grid gap-8 md:grid-cols-2 lg:grid-cols-4">
            {editorial.map((e) => (
              <EditorialCard key={e.title} {...e} />
            ))}
          </div>
        </Container>
      </Section>

      {/* Trust ------------------------------------------------------------ */}
      <Section>
        <Container>
          <div className="grid gap-8 border-t border-border pt-10 md:grid-cols-4">
            {[
              { title: "Curated, not scraped", body: "Every idea is picked and written up by a person." },
              { title: "Multiple merchants", body: "Amazon, Flipkart and specialist gifting stores." },
              { title: "Ideas for every budget", body: "From under ₹500 to milestone presents." },
              { title: "Transparent links", body: "We may earn a commission — you pay the same price." },
            ].map((t) => (
              <div key={t.title}>
                <h3 className="text-[15px] font-semibold">{t.title}</h3>
                <p className="mt-2 text-[14px] leading-relaxed text-muted-foreground">{t.body}</p>
              </div>
            ))}
          </div>
          <p className="mt-10 text-center text-[15px] text-muted-foreground">
            Still stuck?{" "}
            <Link to="/find-a-gift" className="font-semibold text-primary hover:underline">
              Try the Gift Finder
            </Link>
            .
          </p>
        </Container>
      </Section>
    </>
  );
}
