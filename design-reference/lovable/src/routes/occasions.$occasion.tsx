import { createFileRoute } from "@tanstack/react-router";
import { ListingPage } from "@/components/listing/ListingPage";
import { budgetGroup, giftTypeGroup, interestGroup, recipientGroup, validateListingSearch } from "@/lib/listing";

const titleCase = (s: string) => s.charAt(0).toUpperCase() + s.slice(1);

export const Route = createFileRoute("/occasions/$occasion")({
  validateSearch: validateListingSearch,
  head: ({ params }) => {
    const occ = titleCase(params.occasion);
    const title = `${occ} Gift Ideas — What to Give | The Gift Expert`;
    const description = `${occ} gift ideas for every recipient and budget, with a short note on who each gift suits and where to buy it.`;
    return {
      meta: [
        { title },
        { name: "description", content: description },
        { property: "og:title", content: title },
        { property: "og:description", content: description },
        { property: "og:type", content: "website" },
        { name: "twitter:card", content: "summary_large_image" },
      ],
    };
  },
  component: OccasionListing,
});

function OccasionListing() {
  const { occasion } = Route.useParams();
  const search = Route.useSearch();
  const navigate = Route.useNavigate();
  const occ = titleCase(occasion);

  return (
    <ListingPage
      search={search}
      onSearchChange={(next) => navigate({ to: ".", search: next, replace: true })}
      config={{
        crumbs: [{ label: "Home", to: "/" }, { label: "Gift Ideas", to: "/gift-ideas" }, { label: `${occ} Gifts` }],
        title: `${occ} Gift Ideas`,
        intro: `Ideas that suit a ${occasion.toLowerCase()} — grouped by who you're buying for, what they like and what you'd like to spend.`,
        scope: { occasion: occ },
        filterGroups: [recipientGroup, budgetGroup, interestGroup, giftTypeGroup],
        contextualLabel: "Popular",
        contextualLinks: [
          { label: `${occ} Gifts for Husband`, to: "/birthday-gifts-for-husband" },
          { label: `${occ} Gifts for Wife` },
          { label: `${occ} Gifts under ₹1,000` },
          { label: `Personalised ${occ} Gifts` },
        ],
      }}
    />
  );
}
