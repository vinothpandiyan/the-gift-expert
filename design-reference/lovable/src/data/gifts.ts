import travelOrganizer from "@/assets/gift-travel-organizer.jpg";
import coffeeKit from "@/assets/gift-coffee-kit.jpg";
import photoFrame from "@/assets/gift-photo-frame.jpg";
import headphones from "@/assets/gift-headphones.jpg";
import brassLamp from "@/assets/gift-brass-lamp.jpg";
import fitnessBand from "@/assets/gift-fitness-band.jpg";
import journalSet from "@/assets/gift-journal-set.jpg";

export type Badge = "Trending" | "Personalized" | "Great Match" | "Editor's Pick";

export type MerchantOffer = {
  merchant: string;
  price: string;
  note?: string;
  url: string;
};

export type Gift = {
  slug: string;
  title: string;
  reason: string;
  priceLabel: string;
  priceFrom: number;
  image: string;
  badge?: Badge;
  merchant: string;
  offers: MerchantOffer[];
  recipients: string[];
  occasions: string[];
  interests: string[];
  giftTypes: string[];
  addedAt: string;
  details: { label: string; value: string }[];
  why: string[];
};

export const gifts: Gift[] = [
  {
    slug: "personalized-leather-travel-organizer",
    title: "Personalised Leather Travel Organiser",
    reason: "Great for a husband who travels often and loses his boarding passes.",
    priceLabel: "Around ₹1,499",
    priceFrom: 1499,
    image: travelOrganizer,
    badge: "Personalized",
    merchant: "Amazon",
    offers: [
      { merchant: "Amazon", price: "₹1,499", note: "Free delivery", url: "https://www.amazon.in" },
      { merchant: "Flipkart", price: "₹1,449", url: "https://www.flipkart.com" },
    ],
    recipients: ["Husband", "Boyfriend", "Father", "Colleague"],
    occasions: ["Birthday", "Anniversary", "Farewell"],
    interests: ["Travel", "Everyday carry"],
    giftTypes: ["Personalized", "Practical"],
    addedAt: "2026-06-02",
    details: [
      { label: "Material", value: "Full-grain leather" },
      { label: "Personalisation", value: "Up to 3 initials, embossed" },
      { label: "Holds", value: "Passport, cards, boarding pass, pen" },
    ],
    why: [
      "It solves a real problem — everything for a trip lives in one place.",
      "The embossed initials make an everyday object feel considered.",
      "It ages well, so it keeps being used long after the occasion.",
    ],
  },
  {
    slug: "specialty-coffee-sampler-kit",
    title: "Single-Origin Coffee Sampler Kit",
    reason: "For the person who takes their morning cup seriously.",
    priceLabel: "Around ₹1,250",
    priceFrom: 1250,
    image: coffeeKit,
    badge: "Trending",
    merchant: "Amazon",
    offers: [
      { merchant: "Amazon", price: "₹1,250", url: "https://www.amazon.in" },
      { merchant: "Blue Tokai", price: "₹1,199", note: "Roasted to order", url: "https://bluetokaicoffee.com" },
    ],
    recipients: ["Husband", "Wife", "Friend", "Colleague"],
    occasions: ["Birthday", "Housewarming", "Just Because"],
    interests: ["Coffee", "Cooking"],
    giftTypes: ["Hampers", "Practical"],
    addedAt: "2026-07-11",
    details: [
      { label: "Contents", value: "4 × 100g single-origin beans" },
      { label: "Grind", value: "Whole bean or ground on request" },
      { label: "Origin", value: "Chikmagalur, Coorg, Araku" },
    ],
    why: [
      "A consumable gift that never becomes clutter.",
      "Four origins turn a routine into something to look forward to.",
      "Works equally well for a beginner or a seasoned drinker.",
    ],
  },
  {
    slug: "personalized-wooden-photo-frame",
    title: "Personalised Engraved Wooden Photo Frame",
    reason: "A quiet, sentimental gift that sits on a desk for years.",
    priceLabel: "Around ₹899",
    priceFrom: 899,
    image: photoFrame,
    badge: "Personalized",
    merchant: "Amazon",
    offers: [
      { merchant: "Amazon", price: "₹899", note: "Delivered in 2–4 days", url: "https://www.amazon.in" },
      { merchant: "Flipkart", price: "₹949", url: "https://www.flipkart.com" },
      { merchant: "IGP", price: "₹999", note: "Same-day in metros", url: "https://www.igp.com" },
    ],
    recipients: ["Husband", "Wife", "Parents", "Friend"],
    occasions: ["Anniversary", "Birthday", "Housewarming"],
    interests: ["Photography", "Home"],
    giftTypes: ["Personalized"],
    addedAt: "2026-05-20",
    details: [
      { label: "Material", value: "Sheesham wood, matte finish" },
      { label: "Photo size", value: "5 × 7 inches" },
      { label: "Engraving", value: "Name or short message, laser etched" },
      { label: "Placement", value: "Tabletop stand and wall hook" },
    ],
    why: [
      "Personalisation turns an everyday object into a keepsake.",
      "It suits almost any room, so it actually gets displayed.",
      "Affordable without feeling like an afterthought.",
    ],
  },
  {
    slug: "wireless-noise-cancelling-headphones",
    title: "Wireless Noise-Cancelling Headphones",
    reason: "For long commutes, noisy homes and focus-heavy work days.",
    priceLabel: "Around ₹8,999",
    priceFrom: 8999,
    image: headphones,
    badge: "Editor's Pick",
    merchant: "Amazon",
    offers: [
      { merchant: "Amazon", price: "₹8,999", url: "https://www.amazon.in" },
      { merchant: "Flipkart", price: "₹9,199", url: "https://www.flipkart.com" },
    ],
    recipients: ["Husband", "Wife", "Brother", "Sister", "Colleague"],
    occasions: ["Birthday", "Anniversary", "Congratulations"],
    interests: ["Technology", "Music", "Travel"],
    giftTypes: ["Gadgets"],
    addedAt: "2026-07-28",
    details: [
      { label: "Battery", value: "Up to 30 hours with ANC on" },
      { label: "Fit", value: "Over-ear, memory foam cushions" },
      { label: "Extras", value: "Carry case, multipoint pairing" },
    ],
    why: [
      "A genuinely daily-use gift for anyone who commutes or works from home.",
      "Feels premium without needing a special occasion to justify it.",
    ],
  },
  {
    slug: "handcrafted-brass-table-lamp",
    title: "Handcrafted Brass Table Lamp",
    reason: "A warm housewarming gift that suits most Indian homes.",
    priceLabel: "Around ₹2,400",
    priceFrom: 2400,
    image: brassLamp,
    merchant: "Amazon",
    offers: [{ merchant: "Amazon", price: "₹2,400", url: "https://www.amazon.in" }],
    recipients: ["Parents", "Couples", "Friend"],
    occasions: ["Housewarming", "Diwali", "Wedding"],
    interests: ["Home", "Design"],
    giftTypes: ["Practical"],
    addedAt: "2026-04-18",
    details: [
      { label: "Material", value: "Brushed brass, cotton shade" },
      { label: "Height", value: "42 cm" },
    ],
    why: [
      "Handmade, so it feels chosen rather than picked off a shelf.",
      "Neutral enough to fit an existing home without clashing.",
    ],
  },
  {
    slug: "fitness-tracker-band",
    title: "Everyday Fitness Tracker Band",
    reason: "Good for someone who's just started taking their steps seriously.",
    priceLabel: "Around ₹2,999",
    priceFrom: 2999,
    image: fitnessBand,
    badge: "Trending",
    merchant: "Flipkart",
    offers: [
      { merchant: "Flipkart", price: "₹2,999", url: "https://www.flipkart.com" },
      { merchant: "Amazon", price: "₹3,049", url: "https://www.amazon.in" },
    ],
    recipients: ["Husband", "Wife", "Brother", "Friend"],
    occasions: ["Birthday", "Congratulations", "Just Because"],
    interests: ["Fitness", "Technology"],
    giftTypes: ["Gadgets", "Practical"],
    addedAt: "2026-06-24",
    details: [
      { label: "Battery", value: "Up to 14 days" },
      { label: "Tracks", value: "Steps, sleep, heart rate, SpO2" },
    ],
    why: ["Encourages a habit rather than sitting in a drawer.", "Easy to use for a first-time wearable owner."],
  },
  {
    slug: "leather-journal-and-pen-set",
    title: "Leather Journal & Brass Pen Set",
    reason: "For the note-taker, planner or someone starting something new.",
    priceLabel: "Around ₹1,150",
    priceFrom: 1150,
    image: journalSet,
    merchant: "Amazon",
    offers: [{ merchant: "Amazon", price: "₹1,150", url: "https://www.amazon.in" }],
    recipients: ["Colleague", "Friend", "Teacher", "Brother"],
    occasions: ["Congratulations", "Farewell", "Birthday"],
    interests: ["Books", "Work"],
    giftTypes: ["Practical"],
    addedAt: "2026-03-30",
    details: [
      { label: "Pages", value: "192 ruled, 100 GSM" },
      { label: "Cover", value: "Vegan leather with elastic band" },
    ],
    why: ["Safe, useful and appropriate for a colleague or mentor.", "Feels thoughtful at a modest price."],
  },
  {
    slug: "personalised-travel-map-poster",
    title: "Personalised Travel Map Poster",
    reason: "Marks the places they've been — and leaves room for the next trip.",
    priceLabel: "Around ₹1,299",
    priceFrom: 1299,
    image: photoFrame,
    badge: "Personalized",
    merchant: "Amazon",
    offers: [{ merchant: "Amazon", price: "₹1,299", url: "https://www.amazon.in" }],
    recipients: ["Husband", "Wife", "Friend", "Couples"],
    occasions: ["Anniversary", "Birthday", "Housewarming"],
    interests: ["Travel", "Home"],
    giftTypes: ["Personalized"],
    addedAt: "2026-07-02",
    details: [
      { label: "Includes", value: "Gift-ready packaging" }
    ],
    why: [
      "Useful enough to be kept, thoughtful enough to feel chosen."
    ],
  },
  {
    slug: "cold-brew-coffee-maker",
    title: "Cold Brew Coffee Maker",
    reason: "For the one who drinks iced coffee through a Chennai summer.",
    priceLabel: "Around ₹1,899",
    priceFrom: 1899,
    image: coffeeKit,
    merchant: "Amazon",
    offers: [{ merchant: "Amazon", price: "₹1,899", url: "https://www.amazon.in" }],
    recipients: ["Husband", "Wife", "Friend", "Colleague"],
    occasions: ["Birthday", "Housewarming", "Just Because"],
    interests: ["Coffee", "Cooking"],
    giftTypes: ["Practical", "Gadgets"],
    addedAt: "2026-06-15",
    details: [
      { label: "Includes", value: "Gift-ready packaging" }
    ],
    why: [
      "Useful enough to be kept, thoughtful enough to feel chosen."
    ],
  },
  {
    slug: "instant-print-camera",
    title: "Instant Print Camera",
    reason: "Turns an ordinary evening into something they can stick on a fridge.",
    priceLabel: "Around ₹6,499",
    priceFrom: 6499,
    image: photoFrame,
    badge: "Trending",
    merchant: "Amazon",
    offers: [{ merchant: "Amazon", price: "₹6,499", url: "https://www.amazon.in" }],
    recipients: ["Wife", "Girlfriend", "Sister", "Kids"],
    occasions: ["Birthday", "Graduation", "Congratulations"],
    interests: ["Photography", "Travel"],
    giftTypes: ["Gadgets"],
    addedAt: "2026-07-20",
    details: [
      { label: "Includes", value: "Gift-ready packaging" }
    ],
    why: [
      "Useful enough to be kept, thoughtful enough to feel chosen."
    ],
  },
  {
    slug: "yoga-mat-and-strap-set",
    title: "Yoga Mat & Strap Set",
    reason: "A gentle nudge for someone easing into a morning routine.",
    priceLabel: "Around ₹1,799",
    priceFrom: 1799,
    image: fitnessBand,
    merchant: "Amazon",
    offers: [{ merchant: "Amazon", price: "₹1,799", url: "https://www.amazon.in" }],
    recipients: ["Wife", "Mother", "Friend", "Husband"],
    occasions: ["Birthday", "Just Because"],
    interests: ["Fitness"],
    giftTypes: ["Practical"],
    addedAt: "2026-05-08",
    details: [
      { label: "Includes", value: "Gift-ready packaging" }
    ],
    why: [
      "Useful enough to be kept, thoughtful enough to feel chosen."
    ],
  },
  {
    slug: "portable-bluetooth-speaker",
    title: "Portable Bluetooth Speaker",
    reason: "Small enough for a balcony, loud enough for a terrace party.",
    priceLabel: "Around ₹3,499",
    priceFrom: 3499,
    image: headphones,
    merchant: "Amazon",
    offers: [{ merchant: "Amazon", price: "₹3,499", url: "https://www.amazon.in" }],
    recipients: ["Husband", "Brother", "Friend", "Colleague"],
    occasions: ["Birthday", "Housewarming", "Congratulations"],
    interests: ["Music", "Technology", "Travel"],
    giftTypes: ["Gadgets"],
    addedAt: "2026-06-28",
    details: [
      { label: "Includes", value: "Gift-ready packaging" }
    ],
    why: [
      "Useful enough to be kept, thoughtful enough to feel chosen."
    ],
  },
  {
    slug: "engraved-brass-keychain",
    title: "Engraved Brass Keychain",
    reason: "A small, personal thing they'll carry every single day.",
    priceLabel: "Around ₹399",
    priceFrom: 399,
    image: journalSet,
    badge: "Personalized",
    merchant: "Amazon",
    offers: [{ merchant: "Amazon", price: "₹399", url: "https://www.amazon.in" }],
    recipients: ["Husband", "Boyfriend", "Father", "Friend"],
    occasions: ["Birthday", "Just Because", "Farewell"],
    interests: ["Everyday carry"],
    giftTypes: ["Personalized"],
    addedAt: "2026-04-02",
    details: [
      { label: "Includes", value: "Gift-ready packaging" }
    ],
    why: [
      "Useful enough to be kept, thoughtful enough to feel chosen."
    ],
  },
  {
    slug: "scented-candle-trio",
    title: "Hand-poured Scented Candle Trio",
    reason: "An easy, warm gift when you don't know the house well.",
    priceLabel: "Around ₹749",
    priceFrom: 749,
    image: brassLamp,
    merchant: "Amazon",
    offers: [{ merchant: "Amazon", price: "₹749", url: "https://www.amazon.in" }],
    recipients: ["Wife", "Mother", "Friend", "Couples"],
    occasions: ["Housewarming", "Diwali", "Just Because"],
    interests: ["Home"],
    giftTypes: ["Hampers"],
    addedAt: "2026-05-30",
    details: [
      { label: "Includes", value: "Gift-ready packaging" }
    ],
    why: [
      "Useful enough to be kept, thoughtful enough to feel chosen."
    ],
  },
  {
    slug: "weekend-duffel-bag",
    title: "Canvas Weekend Duffel Bag",
    reason: "Sized for the two-night trips they actually take.",
    priceLabel: "Around ₹3,299",
    priceFrom: 3299,
    image: travelOrganizer,
    merchant: "Amazon",
    offers: [{ merchant: "Amazon", price: "₹3,299", url: "https://www.amazon.in" }],
    recipients: ["Husband", "Boyfriend", "Brother", "Friend"],
    occasions: ["Birthday", "Anniversary", "Farewell"],
    interests: ["Travel"],
    giftTypes: ["Practical"],
    addedAt: "2026-07-14",
    details: [
      { label: "Includes", value: "Gift-ready packaging" }
    ],
    why: [
      "Useful enough to be kept, thoughtful enough to feel chosen."
    ],
  },
  {
    slug: "coffee-hamper-gift-box",
    title: "Coffee & Cookies Hamper Box",
    reason: "A safe, generous gift when you're gifting a household.",
    priceLabel: "Around ₹2,199",
    priceFrom: 2199,
    image: coffeeKit,
    merchant: "Amazon",
    offers: [{ merchant: "Amazon", price: "₹2,199", url: "https://www.amazon.in" }],
    recipients: ["Parents", "Couples", "Colleague", "Friend"],
    occasions: ["Diwali", "Housewarming", "Congratulations"],
    interests: ["Coffee", "Cooking"],
    giftTypes: ["Hampers"],
    addedAt: "2026-06-06",
    details: [
      { label: "Includes", value: "Gift-ready packaging" }
    ],
    why: [
      "Useful enough to be kept, thoughtful enough to feel chosen."
    ],
  },
  {
    slug: "desk-organiser-set",
    title: "Wooden Desk Organiser Set",
    reason: "For the person whose work desk is quietly out of control.",
    priceLabel: "Around ₹899",
    priceFrom: 899,
    image: journalSet,
    merchant: "Amazon",
    offers: [{ merchant: "Amazon", price: "₹899", url: "https://www.amazon.in" }],
    recipients: ["Husband", "Colleague", "Brother", "Teacher"],
    occasions: ["Congratulations", "Birthday", "Farewell"],
    interests: ["Work", "Books"],
    giftTypes: ["Practical"],
    addedAt: "2026-04-22",
    details: [
      { label: "Includes", value: "Gift-ready packaging" }
    ],
    why: [
      "Useful enough to be kept, thoughtful enough to feel chosen."
    ],
  },
  {
    slug: "smart-watch-amoled",
    title: "AMOLED Smart Watch",
    reason: "A milestone-birthday gift that gets used every day.",
    priceLabel: "Around ₹11,999",
    priceFrom: 11999,
    image: fitnessBand,
    badge: "Editor's Pick",
    merchant: "Amazon",
    offers: [{ merchant: "Amazon", price: "₹11,999", url: "https://www.amazon.in" }],
    recipients: ["Husband", "Wife", "Brother", "Father"],
    occasions: ["Birthday", "Anniversary", "Congratulations"],
    interests: ["Fitness", "Technology"],
    giftTypes: ["Gadgets"],
    addedAt: "2026-07-25",
    details: [
      { label: "Includes", value: "Gift-ready packaging" }
    ],
    why: [
      "Useful enough to be kept, thoughtful enough to feel chosen."
    ],
  },
  {
    slug: "personalised-cufflinks",
    title: "Personalised Initial Cufflinks",
    reason: "Quiet, formal and personal — good for a wedding season gift.",
    priceLabel: "Around ₹1,099",
    priceFrom: 1099,
    image: journalSet,
    badge: "Personalized",
    merchant: "Amazon",
    offers: [{ merchant: "Amazon", price: "₹1,099", url: "https://www.amazon.in" }],
    recipients: ["Husband", "Father", "Brother", "Colleague"],
    occasions: ["Wedding", "Anniversary", "Birthday"],
    interests: ["Work"],
    giftTypes: ["Personalized"],
    addedAt: "2026-05-14",
    details: [
      { label: "Includes", value: "Gift-ready packaging" }
    ],
    why: [
      "Useful enough to be kept, thoughtful enough to feel chosen."
    ],
  },
  {
    slug: "spa-day-experience-voucher",
    title: "Spa Day Experience Voucher",
    reason: "An afternoon off, which is often the real gift.",
    priceLabel: "Around ₹4,500",
    priceFrom: 4500,
    image: brassLamp,
    merchant: "IGP",
    offers: [{ merchant: "IGP", price: "₹4,500", url: "https://www.amazon.in" }],
    recipients: ["Wife", "Mother", "Girlfriend", "Friend"],
    occasions: ["Anniversary", "Birthday", "Mother's Day"],
    interests: ["Wellness"],
    giftTypes: ["Experiences"],
    addedAt: "2026-06-19",
    details: [
      { label: "Includes", value: "Gift-ready packaging" }
    ],
    why: [
      "Useful enough to be kept, thoughtful enough to feel chosen."
    ],
  },
  {
    slug: "gourmet-chocolate-hamper",
    title: "Gourmet Chocolate Hamper",
    reason: "Dependable, shareable and hard to get wrong.",
    priceLabel: "Around ₹1,650",
    priceFrom: 1650,
    image: coffeeKit,
    merchant: "Amazon",
    offers: [{ merchant: "Amazon", price: "₹1,650", url: "https://www.amazon.in" }],
    recipients: ["Parents", "Colleague", "Friend", "Kids"],
    occasions: ["Diwali", "Birthday", "Congratulations"],
    interests: ["Cooking"],
    giftTypes: ["Hampers"],
    addedAt: "2026-07-08",
    details: [
      { label: "Includes", value: "Gift-ready packaging" }
    ],
    why: [
      "Useful enough to be kept, thoughtful enough to feel chosen."
    ],
  },
  {
    slug: "travel-comfort-kit",
    title: "Travel Comfort Kit",
    reason: "Neck pillow, eye mask and pouch — for the overnight-bus crowd.",
    priceLabel: "Around ₹649",
    priceFrom: 649,
    image: travelOrganizer,
    merchant: "Amazon",
    offers: [{ merchant: "Amazon", price: "₹649", url: "https://www.amazon.in" }],
    recipients: ["Friend", "Colleague", "Brother", "Sister"],
    occasions: ["Farewell", "Birthday", "Just Because"],
    interests: ["Travel"],
    giftTypes: ["Practical"],
    addedAt: "2026-04-11",
    details: [
      { label: "Includes", value: "Gift-ready packaging" }
    ],
    why: [
      "Useful enough to be kept, thoughtful enough to feel chosen."
    ],
  },
  {
    slug: "wireless-earbuds",
    title: "Wireless Earbuds with Case",
    reason: "A reliable upgrade from whatever came free with their phone.",
    priceLabel: "Around ₹4,999",
    priceFrom: 4999,
    image: headphones,
    badge: "Trending",
    merchant: "Amazon",
    offers: [{ merchant: "Amazon", price: "₹4,999", url: "https://www.amazon.in" }],
    recipients: ["Husband", "Wife", "Brother", "Sister", "Kids"],
    occasions: ["Birthday", "Congratulations", "Graduation"],
    interests: ["Music", "Technology", "Fitness"],
    giftTypes: ["Gadgets"],
    addedAt: "2026-07-30",
    details: [
      { label: "Includes", value: "Gift-ready packaging" }
    ],
    why: [
      "Useful enough to be kept, thoughtful enough to feel chosen."
    ],
  },
  {
    slug: "terracotta-planter-set",
    title: "Terracotta Planter Set",
    reason: "For the balcony gardener who keeps running out of pots.",
    priceLabel: "Around ₹1,250",
    priceFrom: 1250,
    image: brassLamp,
    merchant: "Amazon",
    offers: [{ merchant: "Amazon", price: "₹1,250", url: "https://www.amazon.in" }],
    recipients: ["Mother", "Parents", "Friend", "Couples"],
    occasions: ["Housewarming", "Birthday", "Diwali"],
    interests: ["Home", "Design"],
    giftTypes: ["Practical"],
    addedAt: "2026-05-02",
    details: [
      { label: "Includes", value: "Gift-ready packaging" }
    ],
    why: [
      "Useful enough to be kept, thoughtful enough to feel chosen."
    ],
  },
  {
    slug: "hardcover-book-box-set",
    title: "Hardcover Classics Box Set",
    reason: "For a reader who still prefers paper to a screen.",
    priceLabel: "Around ₹1,450",
    priceFrom: 1450,
    image: journalSet,
    merchant: "Amazon",
    offers: [{ merchant: "Amazon", price: "₹1,450", url: "https://www.amazon.in" }],
    recipients: ["Friend", "Sister", "Teacher", "Kids"],
    occasions: ["Birthday", "Graduation", "Just Because"],
    interests: ["Books"],
    giftTypes: ["Practical"],
    addedAt: "2026-06-11",
    details: [
      { label: "Includes", value: "Gift-ready packaging" }
    ],
    why: [
      "Useful enough to be kept, thoughtful enough to feel chosen."
    ],
  },
  {
    slug: "fine-dining-experience-card",
    title: "Fine Dining Experience Card",
    reason: "Lets them pick the evening instead of unwrapping an object.",
    priceLabel: "Around ₹5,000",
    priceFrom: 5000,
    image: brassLamp,
    merchant: "IGP",
    offers: [{ merchant: "IGP", price: "₹5,000", url: "https://www.amazon.in" }],
    recipients: ["Husband", "Wife", "Couples", "Parents"],
    occasions: ["Anniversary", "Birthday", "Congratulations"],
    interests: ["Cooking"],
    giftTypes: ["Experiences"],
    addedAt: "2026-07-18",
    details: [
      { label: "Includes", value: "Gift-ready packaging" }
    ],
    why: [
      "Useful enough to be kept, thoughtful enough to feel chosen."
    ],
  },
  {
    slug: "personalised-star-map",
    title: "Personalised Star Map Frame",
    reason: "The sky on the night that mattered, printed and framed.",
    priceLabel: "Around ₹1,999",
    priceFrom: 1999,
    image: photoFrame,
    badge: "Personalized",
    merchant: "Amazon",
    offers: [{ merchant: "Amazon", price: "₹1,999", url: "https://www.amazon.in" }],
    recipients: ["Husband", "Wife", "Girlfriend", "Boyfriend"],
    occasions: ["Anniversary", "Wedding", "Birthday"],
    interests: ["Home", "Photography"],
    giftTypes: ["Personalized"],
    addedAt: "2026-06-30",
    details: [
      { label: "Includes", value: "Gift-ready packaging" }
    ],
    why: [
      "Useful enough to be kept, thoughtful enough to feel chosen."
    ],
  },
];

export const giftBySlug = (slug: string) => gifts.find((g) => g.slug === slug);

export const recipients = [
  "Husband",
  "Wife",
  "Boyfriend",
  "Girlfriend",
  "Father",
  "Mother",
  "Brother",
  "Sister",
  "Kids",
  "Friend",
];

export const occasions = [
  { name: "Birthday", note: "The one you can't miss" },
  { name: "Anniversary", note: "Mark another year together" },
  { name: "Wedding", note: "Gifts the couple will keep" },
  { name: "Housewarming", note: "For a brand new home" },
  { name: "Diwali", note: "Festive gifting, sorted" },
  { name: "Mother's Day", note: "Something she'd never buy herself" },
  { name: "Father's Day", note: "Useful, not obligatory" },
  { name: "Graduation", note: "For the next chapter" },
];

export const interests = [
  "Coffee",
  "Travel",
  "Technology",
  "Books",
  "Fitness",
  "Music",
  "Photography",
  "Pets",
  "Cooking",
  "Gaming",
];

export const budgets = [
  { label: "Under ₹500", note: "Small, thoughtful" },
  { label: "₹500 – ₹1,000", note: "Everyday gifting" },
  { label: "₹1,000 – ₹2,500", note: "Most popular" },
  { label: "₹2,500 – ₹5,000", note: "A proper present" },
  { label: "₹5,000 – ₹10,000", note: "Milestone moments" },
  { label: "₹10,000+", note: "Once-in-a-while" },
];

export const editorial = [
  {
    title: "Birthday gifts for husbands who already have everything",
    kicker: "Guide",
    excerpt: "Ten ideas that lean on experience, personalisation and genuine daily use.",
  },
  {
    title: "Thoughtful gifts under ₹1,000",
    kicker: "Budget",
    excerpt: "Proof that a small budget doesn't have to mean a forgettable gift.",
  },
  {
    title: "Unique anniversary gift ideas",
    kicker: "Occasion",
    excerpt: "Beyond flowers and chocolates — ideas that reference your actual story.",
  },
  {
    title: "Useful gifts people will actually use",
    kicker: "Guide",
    excerpt: "The test we apply: would they buy it again once it wears out?",
  },
];

export const giftTypes = ["Personalized", "Practical", "Experiences", "Gadgets", "Hampers"];

export const budgetRanges = [
  { id: "under-500", label: "Under ₹500", min: 0, max: 500 },
  { id: "500-1000", label: "₹500 – ₹1,000", min: 500, max: 1000 },
  { id: "1000-2500", label: "₹1,000 – ₹2,500", min: 1000, max: 2500 },
  { id: "2500-5000", label: "₹2,500 – ₹5,000", min: 2500, max: 5000 },
  { id: "5000-10000", label: "₹5,000 – ₹10,000", min: 5000, max: 10000 },
  { id: "10000-plus", label: "₹10,000+", min: 10000, max: Number.MAX_SAFE_INTEGER },
];
