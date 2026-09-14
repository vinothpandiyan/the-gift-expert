<?php

namespace App\Actions\RecipientMerchandising;

use App\Models\RecipientGender;
use App\Models\RecipientType;
use App\Models\Relationship;
use InvalidArgumentException;

/**
 * Resolves composed recipient merchandising intents into product filter keys.
 * Does not invent gender-shaped Relationship rows.
 */
class ResolveRecipientMerchandisingCompositionAction
{
    /**
     * @return array{
     *     relationship_id?: int,
     *     recipient_type_id?: int,
     *     recipient_gender_id?: int,
     * }
     */
    public function execute(string $intent): array
    {
        $key = str($intent)->lower()->trim()->toString();

        return match ($key) {
            'female friend' => $this->compose(relationship: 'friends', gender: RecipientGender::SLUG_FEMALE),
            'male friend' => $this->compose(relationship: 'friends', gender: RecipientGender::SLUG_MALE),
            'school friend boy' => $this->compose(
                relationship: 'friends',
                recipientType: 'school-student',
                gender: RecipientGender::SLUG_MALE,
            ),
            'school friend girl' => $this->compose(
                relationship: 'friends',
                recipientType: 'school-student',
                gender: RecipientGender::SLUG_FEMALE,
            ),
            'college boy' => $this->compose(
                recipientType: 'college-student',
                gender: RecipientGender::SLUG_MALE,
            ),
            'college girl' => $this->compose(
                recipientType: 'college-student',
                gender: RecipientGender::SLUG_FEMALE,
            ),
            'male colleague' => $this->compose(relationship: 'colleagues', gender: RecipientGender::SLUG_MALE),
            'female colleague' => $this->compose(relationship: 'colleagues', gender: RecipientGender::SLUG_FEMALE),
            'baby boy' => $this->compose(recipientType: 'baby', gender: RecipientGender::SLUG_MALE),
            'baby girl' => $this->compose(recipientType: 'baby', gender: RecipientGender::SLUG_FEMALE),
            'kids' => $this->compose(recipientType: 'kids'),
            'boy kids', 'boys' => $this->compose(
                recipientType: $key === 'boys' ? null : 'kids',
                gender: RecipientGender::SLUG_MALE,
            ),
            'girl kids', 'girls' => $this->compose(
                recipientType: $key === 'girls' ? null : 'kids',
                gender: RecipientGender::SLUG_FEMALE,
            ),
            'men' => $this->compose(gender: RecipientGender::SLUG_MALE),
            'women' => $this->compose(gender: RecipientGender::SLUG_FEMALE),
            default => throw new InvalidArgumentException("Unsupported recipient merchandising intent [{$intent}]."),
        };
    }

    /**
     * @return array{
     *     relationship_id?: int,
     *     recipient_type_id?: int,
     *     recipient_gender_id?: int,
     * }
     */
    private function compose(
        ?string $relationship = null,
        ?string $recipientType = null,
        ?string $gender = null,
    ): array {
        $filters = [];

        if ($relationship !== null) {
            $filters['relationship_id'] = $this->activeId(Relationship::class, $relationship);
        }

        if ($recipientType !== null) {
            $filters['recipient_type_id'] = $this->activeId(RecipientType::class, $recipientType);
        }

        if ($gender !== null) {
            $filters['recipient_gender_id'] = $this->activeId(RecipientGender::class, $gender);
        }

        return $filters;
    }

    /**
     * @param  class-string  $model
     */
    private function activeId(string $model, string $slug): int
    {
        $id = $model::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->value('id');

        if ($id === null) {
            throw new InvalidArgumentException("Active taxonomy slug [{$slug}] was not found for {$model}.");
        }

        return (int) $id;
    }
}
