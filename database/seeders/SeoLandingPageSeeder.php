<?php

namespace Database\Seeders;

use App\Actions\SeoLandingPage\PublishSeoLandingPageAction;
use App\Enums\SeoLandingPageStatus;
use App\Models\Category;
use App\Models\Occasion;
use App\Models\Relationship;
use App\Models\SeoLandingPage;
use App\Support\SeoLandingPageCandidateCatalog;
use Illuminate\Database\Seeder;

class SeoLandingPageSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedPublishedBirthdayGiftsForHusband();
        $this->seedApprovedDrafts();
    }

    private function seedPublishedBirthdayGiftsForHusband(): void
    {
        $husband = Relationship::query()->where('slug', 'husband')->firstOrFail();
        $birthday = Occasion::query()->where('slug', 'birthday')->firstOrFail();
        $copy = $this->editorialCopy()['birthday-gifts-for-husband'];

        $page = SeoLandingPage::query()->updateOrCreate(
            ['slug' => 'birthday-gifts-for-husband'],
            [
                'name' => 'Birthday Gifts for Husband',
                'heading' => 'Birthday Gifts for Husband',
                'relationship_id' => $husband->id,
                'occasion_id' => $birthday->id,
                'recipient_type_id' => null,
                'profession_id' => null,
                'gift_type_id' => null,
                'category_id' => null,
                'budget_range_id' => null,
                'is_indexable' => true,
                'include_in_sitemap' => true,
                'sort_order' => 1,
                ...$copy,
            ],
        );

        $page->interests()->sync([]);

        if ($page->status !== SeoLandingPageStatus::Published) {
            app(PublishSeoLandingPageAction::class)->execute($page->fresh());
        }

        $parent = Category::query()
            ->where('slug', 'birthday-gifts')
            ->whereNull('parent_id')
            ->firstOrFail();

        $category = Category::query()
            ->where('slug', 'birthday-gifts-for-husband')
            ->where('parent_id', $parent->id)
            ->firstOrFail();

        $category->update([
            'canonical_seo_landing_page_id' => $page->id,
            'is_active' => true,
        ]);
    }

    private function seedApprovedDrafts(): void
    {
        $copy = $this->editorialCopy();
        $sortOrder = 2;

        foreach (SeoLandingPageCandidateCatalog::evaluate() as $candidate) {
            if ($candidate['recommendation'] !== SeoLandingPageCandidateCatalog::APPROVE) {
                continue;
            }

            if ($candidate['slug'] === 'birthday-gifts-for-husband') {
                continue;
            }

            $this->seedDraftPage($candidate, $copy[$candidate['slug']] ?? [], $sortOrder);
            $sortOrder++;
        }
    }

    /**
     * @param  array{
     *     slug: string,
     *     name: string,
     *     heading: string,
     *     filters: array{
     *         occasion_id: int|null,
     *         relationship_id: int|null,
     *         recipient_type_id: int|null,
     *         profession_id: int|null,
     *         gift_type_id: int|null,
     *         category_id: int|null,
     *         budget_range_id: int|null,
     *         interest_ids: list<int>,
     *     },
     * }  $candidate
     * @param  array<string, mixed>  $copy
     */
    private function seedDraftPage(array $candidate, array $copy, int $sortOrder): void
    {
        $filters = $candidate['filters'];
        $existing = SeoLandingPage::query()->where('slug', $candidate['slug'])->first();

        $attributes = [
            'name' => $candidate['name'],
            'heading' => $candidate['heading'],
            'occasion_id' => $filters['occasion_id'],
            'relationship_id' => $filters['relationship_id'],
            'recipient_type_id' => $filters['recipient_type_id'],
            'profession_id' => $filters['profession_id'],
            'gift_type_id' => $filters['gift_type_id'],
            'category_id' => null,
            'budget_range_id' => $filters['budget_range_id'],
            'sort_order' => $sortOrder,
            ...$copy,
        ];

        if ($existing === null) {
            $page = SeoLandingPage::query()->create([
                ...$attributes,
                'slug' => $candidate['slug'],
                'status' => SeoLandingPageStatus::Draft,
                'is_indexable' => false,
                'include_in_sitemap' => false,
            ]);
        } elseif ($existing->status === SeoLandingPageStatus::Published) {
            return;
        } else {
            $existing->fill($attributes);
            $existing->save();
            $page = $existing;
        }

        $page->interests()->sync($filters['interest_ids']);
    }

    /**
     * @return array<string, array{intro_content: string, body_content: string, faq_content: ?string, meta_title: string, meta_description: string}>
     */
    private function editorialCopy(): array
    {
        return [
            'birthday-gifts-for-husband' => [
                'intro_content' => 'A birthday gift for a husband works best when it fits how he already spends his time — commuting, hobbies, or quiet evenings at home — rather than a generic “for him” object.',
                'body_content' => "Start with something he will use in the next few weeks. Everyday carry, coffee, or a small upgrade to a hobby he already has usually lands better than a novelty item.\n\nIf you live together, notice what is wearing out or missing. If you do not, ask a sibling or close friend one practical question instead of guessing at a grand gesture.\n\nThis page is for combined Husband + Birthday browsing. Broader Husband ideas stay on the Husband gifts page, and Birthday ideas for anyone stay on the Birthday occasion page.",
                'faq_content' => "Q: Should a birthday gift for a husband be a surprise?\nA: Only if you already know the size, preference, or brand. A short conversation often produces a gift he will actually keep using.\n\nQ: Is an experience better than an object?\nA: An experience can work when you can attend it together or when he has clearly asked for time rather than another item. Otherwise a useful object is easier to get right.",
                'meta_title' => 'Birthday Gifts for Husband',
                'meta_description' => 'Practical birthday gift ideas for a husband, focused on everyday use and hobbies rather than generic novelty items.',
            ],
            'anniversary-gifts-for-husband' => [
                'intro_content' => 'Anniversary gifts for a husband can be quieter than birthday gifts. The useful ones usually mark the relationship without turning into a display piece he never touches.',
                'body_content' => "Think in terms of shared routine: something for evenings at home, travel you already do, or a replacement for an item he has used for years.\n\nAvoid duplicating last year’s birthday if it was already a big object. An anniversary is a good moment for something smaller and more personal, or for one well-chosen upgrade.\n\nHusband gifts without an anniversary filter, and anniversary gifts for other relationships, remain on their taxonomy pages.",
                'faq_content' => "Q: Does an anniversary gift need to match a traditional material or year?\nA: No. Those lists are optional. Fit and use matter more than matching a theme he does not care about.",
                'meta_title' => 'Anniversary Gifts for Husband',
                'meta_description' => 'Anniversary gift ideas for a husband that favour shared routine and lasting use over novelty.',
            ],
            'birthday-gifts-for-wife' => [
                'intro_content' => 'Birthday gifts for a wife are easier when you start from how she actually relaxes, dresses, or cooks — not from a generic “romantic gift” list.',
                'body_content' => "Pay attention to refills and replacements: fragrance she is finishing, jewellery she wears often, or kitchen and wellness items she already chose for herself.\n\nIf you are unsure between wearable and home items, wearable usually needs a clearer sense of taste. A food hamper or wellness item is safer when her style is hard to guess.\n\nThis listing is Wife + Birthday only. Wife gifts for other occasions stay on the Wife page.",
                'faq_content' => "Q: Is jewellery always the right birthday gift for a wife?\nA: Only if she already wears similar pieces. Otherwise fragrance, food, or something for a hobby she already practices is less risky.",
                'meta_title' => 'Birthday Gifts for Wife',
                'meta_description' => 'Birthday gift ideas for a wife based on everyday taste, wear, and home routines rather than generic romance tropes.',
            ],
            'anniversary-gifts-for-wife' => [
                'intro_content' => 'An anniversary gift for a wife can be smaller than a birthday gift and still feel considered, especially when it matches jewellery, fragrance, or a shared habit.',
                'body_content' => "Choose one lane: something she will wear, something you will use together, or a replacement for a favourite that is wearing out.\n\nDo not treat anniversary as a second birthday. If the birthday gift was already large, keep this one specific and easy to live with.\n\nAnniversary ideas for other relationships stay on their own pages.",
                'faq_content' => null,
                'meta_title' => 'Anniversary Gifts for Wife',
                'meta_description' => 'Anniversary gift ideas for a wife, with a focus on wear, shared habits, and one clear choice.',
            ],
            'birthday-gifts-for-boyfriend' => [
                'intro_content' => 'Birthday gifts for a boyfriend work when they match his commute, hobbies, or gadgets — not a placeholder labelled “for him.”',
                'body_content' => "Tech accessories, coffee gear, and everyday carry are usually easier to get right than clothing unless you already know fit and brands.\n\nIf the relationship is new, stay practical and easy to return in spirit: something useful, not overly sentimental.\n\nBoyfriend gifts that are not birthday-specific remain on the Boyfriend taxonomy page.",
                'faq_content' => "Q: What if we have not been together long?\nA: Choose something he can use alone, without a big public gesture. Coffee, charging, or listening gear is usually safer than jewellery.",
                'meta_title' => 'Birthday Gifts for Boyfriend',
                'meta_description' => 'Birthday gift ideas for a boyfriend, weighted toward everyday carry, coffee, and tech he will actually use.',
            ],
            'anniversary-gifts-for-boyfriend' => [
                'intro_content' => 'Anniversary gifts for a boyfriend can stay modest. Marking the date matters more than matching a married-couple gift script.',
                'body_content' => "A useful upgrade to something he already owns — wallet, earbuds, or a daily accessory — is usually clearer than a decorative object.\n\nIf you already gave a large birthday gift, keep the anniversary item smaller and tied to a shared memory or a practical gap.\n\nThis page is Boyfriend + Anniversary only.",
                'faq_content' => null,
                'meta_title' => 'Anniversary Gifts for Boyfriend',
                'meta_description' => 'Anniversary gift ideas for a boyfriend that stay practical and proportionate to the relationship.',
            ],
            'birthday-gifts-for-girlfriend' => [
                'intro_content' => 'Birthday gifts for a girlfriend are strongest when they match what she already wears, practices, or keeps on her shelf — not a last-minute stuffed toy.',
                'body_content' => "Fragrance, a necklace in a style she already chooses, or a fitness item she has mentioned will usually beat a generic hamper.\n\nIf you do not live together, avoid large home items she has to store. Wearable or compact hobby gear travels better.\n\nGirlfriend gifts for other occasions remain on the Girlfriend page.",
                'faq_content' => "Q: Should I guess a clothing size?\nA: Prefer not to. Jewellery in a simple style, fragrance she already likes, or hobby gear with a published size chart is safer.",
                'meta_title' => 'Birthday Gifts for Girlfriend',
                'meta_description' => 'Birthday gift ideas for a girlfriend, focused on wear, fitness, and compact items she can actually keep.',
            ],
            'birthday-gifts-for-dad' => [
                'intro_content' => 'Birthday gifts for dad are usually better when they replace something he already uses than when they introduce a new hobby he did not ask for.',
                'body_content' => "Wallets, coffee kit, and simple electronics tend to fit fathers who do not want more clutter. Ask what he already complains about: a worn wallet, a dead power bank, a chipped mug.\n\nSkip gag gifts unless that is genuinely how your family jokes. Most dads keep the useful object and recycle the joke item.\n\nThis page filters Father + Birthday. General Father ideas stay on the Father gifts page.",
                'faq_content' => "Q: What if he says he does not want anything?\nA: Treat that as a request for something small and useful, not as permission to buy a novelty. A replacement for a worn everyday item is usually accepted.",
                'meta_title' => 'Birthday Gifts for Dad',
                'meta_description' => 'Birthday gift ideas for dad, centred on replacements and everyday use rather than novelty hobbies.',
            ],
            'birthday-gifts-for-mom' => [
                'intro_content' => 'Birthday gifts for mom land well when they match how she already cooks, dresses, or hosts — not a generic “world’s best mom” object.',
                'body_content' => "Jewellery she can wear with clothes she already owns, a fragrance in a family she likes, or food she will actually open with guests is usually stronger than décor she did not choose.\n\nIf siblings are pooling money, agree on one complete gift instead of three small duplicates.\n\nMother gifts that are not birthday-specific stay on the Mother taxonomy page.",
                'faq_content' => "Q: Is a hamper too impersonal for a mom’s birthday?\nA: It can work if the contents match what she already buys. A random mixed hamper with items she never eats does not.",
                'meta_title' => 'Birthday Gifts for Mom',
                'meta_description' => 'Birthday gift ideas for mom, with attention to wear, food she will use, and one complete choice.',
            ],
            'birthday-gifts-for-brother' => [
                'intro_content' => 'Birthday gifts for a brother are often easiest in tech and everyday carry, especially when you do not want the gift to feel parental.',
                'body_content' => "Earbuds, a power bank, or another small upgrade he will take out of the house usually beats a decorative item for his room.\n\nIf he is still in studies or a first job, practical electronics tend to get used immediately.\n\nBrother gifts without a birthday filter remain on the Brother page.",
                'faq_content' => null,
                'meta_title' => 'Birthday Gifts for Brother',
                'meta_description' => 'Birthday gift ideas for a brother, weighted toward compact tech and everyday carry.',
            ],
            'gifts-for-husband-who-loves-coffee' => [
                'intro_content' => 'Gifts for a husband who loves coffee should improve the cup he already makes, not add a machine he has no counter space for.',
                'body_content' => "Match the gift to his current method. A pour-over kit helps someone who already talks about brewing. A travel tumbler helps someone who drinks coffee on the commute.\n\nAvoid flavoured novelty beans if he is particular about origin or roast. When in doubt, gear he has mentioned, or a tumbler that fits his bag, is clearer.\n\nCoffee gifts that are not husband-specific stay on the Coffee interest page.",
                'faq_content' => "Q: Should I buy a full espresso machine?\nA: Only if he has asked for one and you have measured space and plumbing. Most husbands who like coffee need better daily gear, not a café installation.",
                'meta_title' => 'Gifts for Husband Who Loves Coffee',
                'meta_description' => 'Gift ideas for a husband who loves coffee, matched to brewing at home or drinking on the commute.',
            ],
            'gifts-for-husband-who-loves-technology' => [
                'intro_content' => 'Gifts for a husband who likes technology work when they fill a gap in what he already carries — not when they duplicate a gadget he just bought.',
                'body_content' => "Check what is already in his bag: earbuds, a worn power bank, or a phone accessory that keeps failing. Replacing a weak link is more useful than adding a fifth device.\n\nSkip smart-home kits unless he is the person who will set them up. Personal audio and charging gear are easier to get right.\n\nTechnology gifts for any recipient remain on the Technology interest page.",
                'faq_content' => "Q: How do I avoid buying a duplicate?\nA: Look at what he already charges overnight. If earbuds or a power bank are there, upgrade that line instead of introducing a new category.",
                'meta_title' => 'Gifts for Husband Who Loves Technology',
                'meta_description' => 'Gift ideas for a husband who likes technology, focused on charging, audio, and replacing worn gadgets.',
            ],
            'birthday-gifts-for-coffee-lovers' => [
                'intro_content' => 'Birthday gifts for coffee lovers should respect how they brew. The same person may want better gear at home and a tumbler that does not leak on the way to work.',
                'body_content' => "This page is Birthday + Coffee, not tied to one relationship. That makes it useful when you know the hobby more clearly than the family label.\n\nKeep food pairings modest unless you know their taste. Brewing gear and a sound travel cup are the usual safe pair.\n\nCoffee ideas with no birthday filter stay on the Coffee page.",
                'faq_content' => null,
                'meta_title' => 'Birthday Gifts for Coffee Lovers',
                'meta_description' => 'Birthday gift ideas for coffee lovers, covering home brewing gear and travel cups without a relationship filter.',
            ],
            'birthday-gifts-for-tech-lovers' => [
                'intro_content' => 'Birthday gifts for people who like tech are usually accessories they will carry, not another large device they did not put on a list.',
                'body_content' => "Audio and charging are the two lines most people will use the week after their birthday. Confirm they do not already own a close equivalent.\n\nThis listing is Birthday + Technology for any relationship. Husband-specific tech ideas live on their own page.\n\nBroader technology browsing stays on the Technology interest page.",
                'faq_content' => null,
                'meta_title' => 'Birthday Gifts for Tech Lovers',
                'meta_description' => 'Birthday gift ideas for tech lovers, centred on audio and charging accessories rather than duplicate gadgets.',
            ],
        ];
    }
}
