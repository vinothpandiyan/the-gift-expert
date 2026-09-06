import { createFileRoute } from "@tanstack/react-router";
import { ListingPage } from "@/components/listing/ListingPage";
import {
  budgetGroup,
  giftTypeGroup,
  interestGroup,
  occasionGroup,
  recipientGroup,
  validateListingSearch,
} from "@/lib/listing";

export const Route = createFileRoute("/gift-ideas")({
  validateSearch: validateListingSearch,
  head: () => {
    const title = "Gift Ideas — Browse by Recipient, Occasion & Budget | The Gift Expert";
    const description =
      "Browse every gift idea we recommend and narrow it down by recipient, occasion, interest, gift type or budget.";
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
  component: GiftIdeasListing,
});

function GiftIdeasListing() {
  const search = Route.useSearch();
  const navigate = Route.useNavigate();

  return (
    <ListingPage
      search={search}
      onSearchChange={(next) => navigate({ to: ".", search: next, replace: true })}
      config={{
        crumbs: [{ label: "Home", to: "/" }, { label: "Gift Ideas" }],
        title: "Gift Ideas",
        intro:
          "Every idea we recommend, in one place. Narrow it down by who it's for, the occasion, what they're into or what you'd like to spend.",
        filterGroups: [recipientGroup, occasionGroup, budgetGroup, interestGroup, giftTypeGroup],
      }}
    />
  );
}
