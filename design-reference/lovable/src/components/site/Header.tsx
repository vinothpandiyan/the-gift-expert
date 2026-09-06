import { Link } from "@tanstack/react-router";
import { useState } from "react";
import { Gift, Heart, Menu, Search, Sparkles, X } from "lucide-react";
import { Container } from "@/components/kit/primitives";
import { ButtonLink } from "@/components/kit/Button";
import { cn } from "@/lib/utils";

const recipientGroups = [
  { title: "For him", items: ["Husband", "Boyfriend", "Father", "Brother", "Son", "Friend"] },
  { title: "For her", items: ["Wife", "Girlfriend", "Mother", "Sister", "Daughter", "Friend"] },
  { title: "Others", items: ["Kids", "Teens", "Couples", "Colleagues", "Teachers", "Pets"] },
];

const occasionItems = [
  "Birthday",
  "Anniversary",
  "Wedding",
  "Housewarming",
  "Diwali",
  "Mother's Day",
  "Father's Day",
  "Graduation",
];

export function Logo({ className }: { className?: string }) {
  return (
    <Link to="/" className={cn("flex items-center gap-2", className)}>
      <span className="flex h-8 w-8 items-center justify-center rounded-md bg-primary text-primary-foreground">
        <Gift className="h-4 w-4" aria-hidden />
      </span>
      <span className="font-display text-lg leading-none tracking-tight">
        The Gift Expert
      </span>
    </Link>
  );
}

function MegaMenu({ label, children }: { label: string; children: React.ReactNode }) {
  const [open, setOpen] = useState(false);
  return (
    <div className="static" onMouseEnter={() => setOpen(true)} onMouseLeave={() => setOpen(false)}>
      <button
        type="button"
        aria-expanded={open}
        onClick={() => setOpen((o) => !o)}
        className={cn(
          "flex h-16 items-center px-3 text-[15px] font-medium transition-colors hover:text-primary",
          open && "text-primary",
        )}
      >
        {label}
      </button>
      {open ? (
        <div className="absolute inset-x-0 top-16 z-40 border-t border-border bg-surface shadow-[0_16px_40px_-28px_rgba(38,35,38,0.5)]">
          <Container className="py-8">{children}</Container>
        </div>
      ) : null}
    </div>
  );
}

function NavLinkItem({ label, kind }: { label: string; kind?: "recipient" | "occasion" }) {
  const className = "block py-1.5 text-[14px] text-muted-foreground hover:text-primary hover:underline";
  const slug = label.toLowerCase().replace(/[^a-z]+/g, "-");
  if (kind === "recipient") {
    return (
      <Link to="/gifts-for/$relationship" params={{ relationship: slug }} className={className}>
        {label}
      </Link>
    );
  }
  if (kind === "occasion") {
    return (
      <Link to="/occasions/$occasion" params={{ occasion: slug }} className={className}>
        {label}
      </Link>
    );
  }
  return (
    <a href="#" className={className}>
      {label}
    </a>
  );
}


export function Header() {
  const [mobileOpen, setMobileOpen] = useState(false);

  return (
    <header className="sticky top-0 z-50 border-b border-border bg-surface/95 backdrop-blur">
      <Container>
        <div className="flex h-16 items-center justify-between gap-4">
          <div className="flex min-w-0 items-center gap-6">
            <Logo />
            <nav className="hidden items-center lg:flex" aria-label="Main">
              <Link to="/gift-ideas" className="flex h-16 items-center px-3 text-[15px] font-medium hover:text-primary">
                Gift Ideas
              </Link>
              <MegaMenu label="By Recipient">
                <div className="grid grid-cols-3 gap-8">
                  {recipientGroups.map((group) => (
                    <div key={group.title}>
                      <p className="mb-3 text-xs font-semibold tracking-[0.14em] text-muted-foreground uppercase">
                        {group.title}
                      </p>
                      {group.items.map((item) => (
                        <NavLinkItem key={item} label={item} kind="recipient" />
                      ))}
                    </div>
                  ))}
                </div>
              </MegaMenu>
              <MegaMenu label="By Occasion">
                <div className="grid grid-cols-4 gap-x-8 gap-y-1">
                  {occasionItems.map((item) => (
                    <NavLinkItem key={item} label={item} kind="occasion" />
                  ))}
                </div>
              </MegaMenu>
              <a href="#" className="flex h-16 items-center px-3 text-[15px] font-medium hover:text-primary">
                Interests
              </a>
              <a href="#" className="flex h-16 items-center px-3 text-[15px] font-medium hover:text-primary">
                Return Gifts
              </a>
            </nav>
          </div>

          <div className="flex shrink-0 items-center gap-2">
            <div className="hidden items-center gap-2 rounded-[10px] border border-border bg-background px-3 py-2 md:flex">
              <Search className="h-4 w-4 text-muted-foreground" aria-hidden />
              <label htmlFor="site-search" className="sr-only">
                Search gift ideas
              </label>
              <input
                id="site-search"
                type="search"
                placeholder="Search gift ideas..."
                className="w-36 bg-transparent text-sm outline-none placeholder:text-muted-foreground lg:w-48"
              />
            </div>
            <button
              type="button"
              aria-label="Saved gifts"
              className="hidden h-11 w-11 items-center justify-center rounded-[10px] text-muted-foreground hover:bg-primary-light hover:text-primary md:inline-flex"
            >
              <Heart className="h-5 w-5" aria-hidden />
            </button>
            <ButtonLink to="/find-a-gift" size="sm" className="hidden sm:inline-flex">
              <Sparkles className="h-4 w-4" aria-hidden />
              Gift Finder
            </ButtonLink>
            <button
              type="button"
              aria-label="Search"
              className="inline-flex h-11 w-11 items-center justify-center rounded-[10px] text-foreground md:hidden"
            >
              <Search className="h-5 w-5" aria-hidden />
            </button>
            <button
              type="button"
              aria-label={mobileOpen ? "Close menu" : "Open menu"}
              aria-expanded={mobileOpen}
              onClick={() => setMobileOpen((o) => !o)}
              className="inline-flex h-11 w-11 items-center justify-center rounded-[10px] text-foreground lg:hidden"
            >
              {mobileOpen ? <X className="h-5 w-5" aria-hidden /> : <Menu className="h-5 w-5" aria-hidden />}
            </button>
          </div>
        </div>
      </Container>

      {mobileOpen ? (
        <div className="border-t border-border bg-surface lg:hidden">
          <Container className="py-5">
            <ButtonLink to="/find-a-gift" size="lg" className="w-full" onClick={() => setMobileOpen(false)}>
              <Sparkles className="h-4 w-4" aria-hidden />
              Find a Gift
            </ButtonLink>
            <div className="mt-5 grid grid-cols-2 gap-2">
              {["Husband", "Wife", "Father", "Mother", "Friend", "Kids"].map((r) => (
                <Link
                  key={r}
                  to="/gifts-for/$relationship"
                  params={{ relationship: r.toLowerCase() }}
                  onClick={() => setMobileOpen(false)}
                  className="flex min-h-12 items-center rounded-[10px] border border-border px-4 text-[15px] font-medium"
                >
                  {r}
                </Link>
              ))}
            </div>
            <nav className="mt-5 divide-y divide-border border-t border-border" aria-label="Mobile">
              {["Gift Ideas", "By Occasion", "Interests", "Return Gifts", "Gifts by Budget"].map((item) => (
                <a key={item} href="#" className="flex min-h-12 items-center text-[15px] font-medium">
                  {item}
                </a>
              ))}
            </nav>
          </Container>
        </div>
      ) : null}
    </header>
  );
}
