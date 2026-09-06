import { createFileRoute } from "@tanstack/react-router";
import { ListingPage } from "@/components/listing/ListingPage";
import { budgetGroup, giftTypeGroup, interestGroup, occasionGroup, validateListingSearch } from "@/lib/listing";

const titleCase = (s: string) => s.charAt(0).toUpperCase() + s.slice(1);

export const Route = createFileRoute("/gifts-for/$relationship")({
  validateSearch: validateListingSearch,
  head: ({ params }) => {
    const who = titleCase(params.relationship);
    const title = `Gifts for ${who} — Thoughtful Gift Ideas | The Gift Expert`;
    const description = `Hand-picked gift ideas for your ${params.relationship}, across occasions, interests and budgets. Filter, compare and buy from trusted merchants.`;
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
  component: RelationshipListing,
});

function RelationshipListing() {
  const { relationship } = Route.useParams();
  const search = Route.useSearch();
  const navigate = Route.useNavigate();
  const who = titleCase(relationship);

  return (
    <ListingPage
      search={search}
      onSearchChange={(next) => navigate({ to: ".", search: next, replace: true })}
      config={{
        crumbs: [{ label: "Home", to: "/" }, { label: "Gift Ideas", to: "/gift-ideas" }, { label: `Gifts for ${who}` }],
        title: `Gifts for ${who}`,
        intro: `Thoughtful gift ideas for ${relationship}s across birthdays, anniversaries, everyday surprises and different budgets.`,
        scope: { recipient: who },
        filterGroups: [occasionGroup, budgetGroup, interestGroup, giftTypeGroup],
        contextualLabel: "Popular",
        contextualLinks: [
          { label: `Birthday Gifts for ${who}`, to: "/birthday-gifts-for-husband" },
          { label: `Anniversary Gifts for ${who}` },
          { label: `Personalised Gifts for ${who}` },
          { label: `Romantic Gifts for ${who}` },
        ],
        finderCta: {
          title: `Still not sure what he'd like?`,
          copy: "Answer a few questions and we'll narrow it down to a handful of ideas.",
        },
      }}
    />
  );
}
