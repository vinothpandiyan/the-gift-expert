import { fallback, zodValidator } from "@tanstack/zod-adapter";
import { z } from "zod";
import { budgetRanges, gifts, type Gift } from "@/data/gifts";


/* Taxonomy-driven listing model ------------------------------------------- */

export type FilterDimension = "recipient" | "occasion" | "interest" | "giftType" | "budget";

export type FilterGroupConfig = {
  /** Taxonomy dimension — never a free-form product attribute. */
  dimension: FilterDimension;
  label: string;
  options: { id: string; label: string }[];
  /** Budget is single-select; taxonomy dimensions are multi-select. */
  single?: boolean;
};

export type ListingSearch = {
  recipient: string[];
  occasion: string[];
  interest: string[];
  giftType: string[];
  budget: string;
  sort: SortId;
  show: number;
};

export type SortId = "recommended" | "price-asc" | "price-desc" | "newest";

export const sortOptions: { id: SortId; label: string }[] = [
  { id: "recommended", label: "Recommended" },
  { id: "price-asc", label: "Price: Low to High" },
  { id: "price-desc", label: "Price: High to Low" },
  { id: "newest", label: "Newest" },
];

export const PAGE_SIZE = 12;

export const defaultSearch: ListingSearch = {
  recipient: [],
  occasion: [],
  interest: [],
  giftType: [],
  budget: "",
  sort: "recommended",
  show: PAGE_SIZE,
};

const strList = fallback(z.array(z.string()), []).default([]);

const listingSearchSchema = z.object({
  recipient: strList,
  occasion: strList,
  interest: strList,
  giftType: strList,
  budget: fallback(z.string(), "").default(""),
  sort: fallback(z.enum(["recommended", "price-asc", "price-desc", "newest"]), "recommended").default("recommended"),
  show: fallback(z.number(), PAGE_SIZE).default(PAGE_SIZE),
});

/** Search-param validator — every field optional in the URL, always present in code. */
export const validateListingSearch = zodValidator(listingSearchSchema);


const field = (gift: Gift, dimension: FilterDimension): string[] => {
  switch (dimension) {
    case "recipient":
      return gift.recipients;
    case "occasion":
      return gift.occasions;
    case "interest":
      return gift.interests;
    case "giftType":
      return gift.giftTypes;
    default:
      return [];
  }
};

/** Fixed taxonomy values a page pre-applies (e.g. recipient=Husband on /gifts-for/husband). */
export type ListingScope = Partial<Record<Exclude<FilterDimension, "budget">, string>>;

export function filterGifts(scope: ListingScope, search: ListingSearch): Gift[] {
  const range = budgetRanges.find((b) => b.id === search.budget);
  const result = gifts.filter((gift) => {
    for (const [dim, value] of Object.entries(scope)) {
      if (value && !field(gift, dim as FilterDimension).includes(value)) return false;
    }
    const dims: Exclude<FilterDimension, "budget">[] = ["recipient", "occasion", "interest", "giftType"];
    for (const dim of dims) {
      const selected = search[dim];
      if (selected.length && !selected.some((v) => field(gift, dim).includes(v))) return false;
    }
    if (range && (gift.priceFrom < range.min || gift.priceFrom > range.max)) return false;
    return true;
  });

  switch (search.sort) {
    case "price-asc":
      return [...result].sort((a, b) => a.priceFrom - b.priceFrom);
    case "price-desc":
      return [...result].sort((a, b) => b.priceFrom - a.priceFrom);
    case "newest":
      return [...result].sort((a, b) => b.addedAt.localeCompare(a.addedAt));
    default:
      return result;
  }
}

export type ActiveFilter = { dimension: FilterDimension; value: string; label: string };

export function activeFilters(search: ListingSearch): ActiveFilter[] {
  const list: ActiveFilter[] = [];
  (["recipient", "occasion", "interest", "giftType"] as const).forEach((dimension) => {
    search[dimension].forEach((value) => list.push({ dimension, value, label: value }));
  });
  const range = budgetRanges.find((b) => b.id === search.budget);
  if (range) list.push({ dimension: "budget", value: range.id, label: range.label });
  return list;
}

export function toggleValue(search: ListingSearch, dimension: FilterDimension, value: string): ListingSearch {
  if (dimension === "budget") {
    return { ...search, budget: search.budget === value ? "" : value, show: PAGE_SIZE };
  }
  const current = search[dimension];
  const next = current.includes(value) ? current.filter((v) => v !== value) : [...current, value];
  return { ...search, [dimension]: next, show: PAGE_SIZE };
}

export const budgetGroup: FilterGroupConfig = {
  dimension: "budget",
  label: "Budget",
  single: true,
  options: budgetRanges.map((b) => ({ id: b.id, label: b.label })),
};

const opts = (values: string[]) => values.map((v) => ({ id: v, label: v }));

export const occasionGroup: FilterGroupConfig = {
  dimension: "occasion",
  label: "Occasion",
  options: opts(["Birthday", "Anniversary", "Wedding", "Housewarming", "Congratulations", "Diwali", "Farewell"]),
};

export const interestGroup: FilterGroupConfig = {
  dimension: "interest",
  label: "Interests",
  options: opts(["Travel", "Coffee", "Technology", "Fitness", "Books", "Music", "Cooking", "Photography", "Home"]),
};

export const giftTypeGroup: FilterGroupConfig = {
  dimension: "giftType",
  label: "Gift type",
  options: opts(["Personalized", "Practical", "Experiences", "Gadgets", "Hampers"]),
};

export const recipientGroup: FilterGroupConfig = {
  dimension: "recipient",
  label: "Recipient",
  options: opts(["Husband", "Wife", "Father", "Mother", "Brother", "Sister", "Friend", "Colleague", "Parents", "Kids"]),
};
