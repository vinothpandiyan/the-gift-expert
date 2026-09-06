import { createFileRoute } from "@tanstack/react-router";
import { ListingPage } from "@/components/listing/ListingPage";
import {
  FAQSection,
  HighlightStrip,
  RelatedLinks,
  SeoEditorialSection,
} from "@/components/listing/parts";
import { budgetGroup, giftTypeGroup, interestGroup, validateListingSearch } from "@/lib/listing";

export const Route = createFileRoute("/birthday-gifts-for-husband")({
  validateSearch: validateListingSearch,
  head: () => {
    const title = "Birthday Gifts for Husband — 25 Ideas He'll Actually Use";
    const description =
      "Birthday gift ideas for husbands, from ₹399 keepsakes to milestone gadgets — each with a short note on who it suits and where to buy it.";
    return {
      meta: [
        { title },
        { name: "description", content: description },
        { property: "og:title", content: title },
        { property: "og:description", content: description },
        { property: "og:type", content: "article" },
        { name: "twitter:card", content: "summary_large_image" },
      ],
    };
  },
  component: SeoLanding,
});

function SeoLanding() {
  const search = Route.useSearch();
  const navigate = Route.useNavigate();

  return (
    <ListingPage
      search={search}
      onSearchChange={(next) => navigate({ to: ".", search: next, replace: true })}
      config={{
        crumbs: [
          { label: "Home", to: "/" },
          { label: "Gift Ideas", to: "/gift-ideas" },
          { label: "Birthday Gifts for Husband" },
        ],
        title: "Birthday Gifts for Husband",
        intro:
          "The birthday gifts husbands actually keep tend to be personal, useful or both. These are the ideas we'd recommend first, across every budget.",
        scope: { recipient: "Husband", occasion: "Birthday" },
        filterGroups: [budgetGroup, interestGroup, giftTypeGroup],
        contextualLabel: "Also popular",
        contextualLinks: [
          { label: "Gifts for Husband", to: "/gifts-for/$relationship", params: { relationship: "husband" } },
          { label: "Anniversary Gifts for Husband" },
          { label: "Personalised Gifts for Husband" },
          { label: "Birthday Gift Ideas", to: "/occasions/$occasion", params: { occasion: "birthday" } },
        ],
        highlight: (
          <HighlightStrip title="Popular choices this year">
            <p className="max-w-3xl text-[15px] leading-relaxed text-foreground">
              Personalised leather goods and single-origin coffee kits are the two categories husbands most often keep
              using months later. If he already owns everything, an experience card usually lands better than another
              object.
            </p>
          </HighlightStrip>
        ),
        belowResults: (
          <>
            <SeoEditorialSection title="How to choose a birthday gift for your husband">
              <p>
                Start with how he spends a normal week rather than what's trending. A gift that fits an existing habit —
                his commute, his morning coffee, his weekend trips — gets used far more than something that asks him to
                pick up a new one.
              </p>
              <p>
                Personalisation is the cheapest way to make a modest gift feel considered. An engraved keychain at ₹399
                often reads as more thoughtful than an unbranded gadget at three times the price. Keep engraving short:
                initials or a single date.
              </p>
              <p>
                If you're gifting jointly with family, pool the budget into one milestone gift rather than several small
                ones. And where the gift is an experience, book a date you both already have free.
              </p>
            </SeoEditorialSection>

            <RelatedLinks
              title="Related gift ideas"
              links={[
                { label: "Gifts for Husband", to: "/gifts-for/$relationship", params: { relationship: "husband" } },
                { label: "Birthday Gift Ideas", to: "/occasions/$occasion", params: { occasion: "birthday" } },
                { label: "All Gift Ideas", to: "/gift-ideas" },
                { label: "Anniversary Gifts for Husband" },
                { label: "Personalised Gifts for Him" },
                { label: "Gifts under ₹1,000" },
              ]}
            />

            <FAQSection
              items={[
                {
                  q: "What is a good birthday gift budget for a husband?",
                  a: "Most people in India spend between ₹1,000 and ₹2,500 on a partner's birthday gift. Milestone birthdays tend to move into the ₹5,000–₹10,000 range.",
                },
                {
                  q: "Are personalised gifts worth it?",
                  a: "For a partner, usually yes — personalisation turns an ordinary object into a keepsake. Allow extra delivery time, since engraving and printing add a few days.",
                },
                {
                  q: "Do the prices shown here update automatically?",
                  a: "No. Prices are approximate and were correct when we last checked. Merchants change prices often, so always confirm the final price on the merchant's own page.",
                },
              ]}
            />
          </>
        ),
      }}
    />
  );
}
