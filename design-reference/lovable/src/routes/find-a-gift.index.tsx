import { createFileRoute, useNavigate } from "@tanstack/react-router";
import { useState } from "react";
import { ArrowLeft, ArrowRight, Sparkles } from "lucide-react";
import { Container } from "@/components/kit/primitives";
import { Button } from "@/components/kit/Button";
import { ChoiceTile } from "@/components/gift/tiles";
import { budgets, interests } from "@/data/gifts";

export const Route = createFileRoute("/find-a-gift/")({
  head: () => ({
    meta: [
      { title: "Gift Finder — Answer 5 questions, get gift ideas | The Gift Expert" },
      {
        name: "description",
        content:
          "Tell us who you're buying for, the occasion, what they're into and your budget. We'll narrow thousands of ideas down to a handful worth giving.",
      },
      { property: "og:title", content: "Gift Finder | The Gift Expert" },
      {
        property: "og:description",
        content: "Answer a few quick questions and we'll narrow down the best gift ideas.",
      },
    ],
  }),
  component: GiftFinderPage,
});

const stepTitles = ["Recipient", "Occasion", "Interests", "About them", "Budget"];

const recipientOptions = [
  { label: "Husband", note: "Partner" },
  { label: "Wife", note: "Partner" },
  { label: "Boyfriend", note: "Partner" },
  { label: "Girlfriend", note: "Partner" },
  { label: "Father", note: "Family" },
  { label: "Mother", note: "Family" },
  { label: "Brother", note: "Family" },
  { label: "Sister", note: "Family" },
  { label: "Friend", note: "" },
  { label: "Child", note: "" },
  { label: "Colleague", note: "Work" },
  { label: "Someone else", note: "" },
];

const occasionOptions = [
  "Birthday",
  "Anniversary",
  "Wedding",
  "Housewarming",
  "Festival",
  "Congratulations",
  "Just Because",
  "Something else",
];

const aboutOptions = [
  { label: "Practical", note: "Wants things they'll use" },
  { label: "Sentimental", note: "Loves a personal touch" },
  { label: "Loves gadgets", note: "First to try new tech" },
  { label: "Homebody", note: "Happiest at home" },
  { label: "Always out", note: "Travel, sport, outdoors" },
  { label: "Hard to please", note: "Already has everything" },
];

function ProgressIndicator({ step }: { step: number }) {
  return (
    <div>
      <div className="flex items-center justify-between text-[13px]">
        <span className="font-semibold text-primary">
          Step {step + 1} of {stepTitles.length}
        </span>
        <span className="text-muted-foreground">{stepTitles[step]}</span>
      </div>
      <div className="mt-2 flex gap-1.5" role="progressbar" aria-valuenow={step + 1} aria-valuemin={1} aria-valuemax={5}>
        {stepTitles.map((t, i) => (
          <span
            key={t}
            className={`h-1.5 flex-1 rounded-full transition-colors ${i <= step ? "bg-primary" : "bg-border"}`}
          />
        ))}
      </div>
    </div>
  );
}

function GiftFinderPage() {
  const navigate = useNavigate();
  const [step, setStep] = useState(0);
  const [recipient, setRecipient] = useState("");
  const [occasion, setOccasion] = useState("");
  const [chosenInterests, setChosenInterests] = useState<string[]>([]);
  const [about, setAbout] = useState("");
  const [budget, setBudget] = useState("");

  const MAX_INTERESTS = 3;

  const toggleInterest = (name: string) => {
    setChosenInterests((prev) =>
      prev.includes(name)
        ? prev.filter((i) => i !== name)
        : prev.length >= MAX_INTERESTS
          ? prev
          : [...prev, name],
    );
  };

  const canContinue = [
    Boolean(recipient),
    Boolean(occasion),
    chosenInterests.length > 0,
    true,
    Boolean(budget),
  ][step];

  const next = () => (step === 4 ? navigate({ to: "/find-a-gift/results" }) : setStep((s) => s + 1));

  return (
    <div className="bg-background">
      <Container className="max-w-3xl! py-8 pb-32 md:py-14 md:pb-16">
        <div className="text-center">
          <span className="inline-flex items-center gap-2 rounded-md bg-primary-light px-3 py-1.5 text-[12px] font-semibold tracking-wide text-primary">
            <Sparkles className="h-3.5 w-3.5" aria-hidden />
            GIFT FINDER
          </span>
        </div>

        <div className="mt-6">
          <ProgressIndicator step={step} />
        </div>

        <div className="mt-8">
          {step === 0 && (
            <StepShell title="Who are you buying for?" subtitle="Pick the closest relationship.">
              <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                {recipientOptions.map((r) => (
                  <ChoiceTile
                    key={r.label}
                    label={r.label}
                    note={r.note || undefined}
                    selected={recipient === r.label}
                    onClick={() => setRecipient(r.label)}
                  />
                ))}
              </div>
            </StepShell>
          )}

          {step === 1 && (
            <StepShell title="What's the occasion?" subtitle="This shapes the tone of the gift more than anything else.">
              <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                {occasionOptions.map((o) => (
                  <ChoiceTile key={o} label={o} selected={occasion === o} onClick={() => setOccasion(o)} />
                ))}
              </div>
            </StepShell>
          )}

          {step === 2 && (
            <StepShell
              title="What are they into?"
              subtitle={`Choose up to ${MAX_INTERESTS} — ${chosenInterests.length}/${MAX_INTERESTS} selected.`}
            >
              <div className="flex flex-wrap gap-2.5">
                {interests.map((i) => {
                  const selected = chosenInterests.includes(i);
                  const disabled = !selected && chosenInterests.length >= MAX_INTERESTS;
                  return (
                    <button
                      key={i}
                      type="button"
                      aria-pressed={selected}
                      disabled={disabled}
                      onClick={() => toggleInterest(i)}
                      className={`inline-flex min-h-12 items-center rounded-[10px] border px-4 text-[15px] font-medium transition-colors ${
                        selected
                          ? "border-primary bg-primary text-primary-foreground"
                          : disabled
                            ? "border-border bg-surface text-muted-foreground opacity-50"
                            : "border-border bg-surface hover:border-primary/40 hover:bg-primary-light/50"
                      }`}
                    >
                      {i}
                    </button>
                  );
                })}
              </div>
            </StepShell>
          )}

          {step === 3 && (
            <StepShell
              title="Anything else about them?"
              subtitle="Optional — it helps us rank ideas, but you can skip it."
            >
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                {aboutOptions.map((a) => (
                  <ChoiceTile
                    key={a.label}
                    label={a.label}
                    note={a.note}
                    selected={about === a.label}
                    onClick={() => setAbout(about === a.label ? "" : a.label)}
                  />
                ))}
              </div>
            </StepShell>
          )}

          {step === 4 && (
            <StepShell title="What's your budget?" subtitle="We'll stay inside it.">
              <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                {budgets.map((b) => (
                  <ChoiceTile
                    key={b.label}
                    label={b.label}
                    note={b.note}
                    selected={budget === b.label}
                    onClick={() => setBudget(b.label)}
                  />
                ))}
              </div>
            </StepShell>
          )}
        </div>

        {/* Desktop actions */}
        <div className="mt-10 hidden items-center justify-between md:flex">
          <Button
            variant="ghost"
            onClick={() => setStep((s) => Math.max(0, s - 1))}
            disabled={step === 0}
            aria-label="Back"
          >
            <ArrowLeft className="h-4 w-4" aria-hidden />
            Back
          </Button>
          <div className="flex items-center gap-3">
            {step === 3 ? (
              <Button variant="ghost" onClick={() => setStep(4)}>
                Skip
              </Button>
            ) : null}
            <Button size="lg" onClick={next} disabled={!canContinue}>
              {step === 4 ? "Find gifts" : "Continue"}
              <ArrowRight className="h-4 w-4" aria-hidden />
            </Button>
          </div>
        </div>
      </Container>

      {/* Mobile sticky actions */}
      <div className="fixed inset-x-0 bottom-0 z-40 border-t border-border bg-surface p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] md:hidden">
        <div className="flex items-center gap-3">
          <Button
            variant="secondary"
            onClick={() => setStep((s) => Math.max(0, s - 1))}
            disabled={step === 0}
            aria-label="Back"
            className="w-14 px-0"
          >
            <ArrowLeft className="h-4 w-4" aria-hidden />
          </Button>
          {step === 3 ? (
            <Button variant="ghost" onClick={() => setStep(4)} className="px-4">
              Skip
            </Button>
          ) : null}
          <Button size="lg" onClick={next} disabled={!canContinue} className="flex-1">
            {step === 4 ? "Find gifts" : "Continue"}
            <ArrowRight className="h-4 w-4" aria-hidden />
          </Button>
        </div>
      </div>
    </div>
  );
}

function StepShell({
  title,
  subtitle,
  children,
}: {
  title: string;
  subtitle?: string;
  children: React.ReactNode;
}) {
  return (
    <div>
      <h1 className="font-display text-[28px] leading-tight md:text-[38px]">{title}</h1>
      {subtitle ? <p className="mt-2 text-[15px] text-muted-foreground">{subtitle}</p> : null}
      <div className="mt-6">{children}</div>
    </div>
  );
}
