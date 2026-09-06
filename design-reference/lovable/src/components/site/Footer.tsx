import { Container } from "@/components/kit/primitives";
import { Button } from "@/components/kit/Button";
import { Logo } from "./Header";

const columns = [
  {
    title: "Gift discovery",
    items: ["Gift Ideas", "Gift Finder", "Gifts by Recipient", "Gifts by Occasion", "Gifts by Interest", "Return Gifts"],
  },
  {
    title: "Information",
    items: ["About", "How It Works", "Affiliate Disclosure", "Privacy", "Terms", "Contact"],
  },
];

export function Footer() {
  return (
    <footer className="border-t border-border bg-surface">
      <Container className="py-14 md:py-20">
        <div className="grid gap-12 md:grid-cols-[1.2fr_1fr_1fr_1.4fr]">
          <div>
            <Logo />
            <p className="mt-4 max-w-xs text-[14px] leading-relaxed text-muted-foreground">
              We help you work out what to give — then send you to the merchant with the best listing.
            </p>
          </div>

          {columns.map((col) => (
            <div key={col.title}>
              <p className="mb-4 text-xs font-semibold tracking-[0.14em] text-muted-foreground uppercase">
                {col.title}
              </p>
              <ul className="space-y-2.5">
                {col.items.map((item) => (
                  <li key={item}>
                    <a href="#" className="text-[14px] hover:text-primary hover:underline">
                      {item}
                    </a>
                  </li>
                ))}
              </ul>
            </div>
          ))}

          <div>
            <p className="font-display text-lg">Get thoughtful gift ideas in your inbox</p>
            <p className="mt-2 text-[14px] text-muted-foreground">Occasional guides. No promotional noise.</p>
            <form className="mt-4 flex flex-col gap-2 sm:flex-row" onSubmit={(e) => e.preventDefault()}>
              <label htmlFor="newsletter" className="sr-only">
                Email address
              </label>
              <input
                id="newsletter"
                type="email"
                placeholder="you@example.com"
                className="h-12 flex-1 rounded-[10px] border border-border bg-background px-4 text-sm outline-none focus:border-primary"
              />
              <Button type="submit" variant="secondary">
                Subscribe
              </Button>
            </form>
          </div>
        </div>

        <div className="mt-12 border-t border-border pt-6">
          <p className="text-[13px] leading-relaxed text-muted-foreground">
            When you buy through some links on The Gift Expert, we may earn a commission at no extra cost to you. We
            don't sell any of the products listed — prices and availability are set by the merchant.
          </p>
          <p className="mt-4 text-[13px] text-muted-foreground">
            © {new Date().getFullYear()} The Gift Expert. All rights reserved.
          </p>
        </div>
      </Container>
    </footer>
  );
}

export function SiteLayout({ children }: { children: React.ReactNode }) {
  return <div className="flex min-h-screen flex-col">{children}</div>;
}
