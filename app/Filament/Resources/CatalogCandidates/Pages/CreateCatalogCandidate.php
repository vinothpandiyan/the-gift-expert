<?php

namespace App\Filament\Resources\CatalogCandidates\Pages;

use App\Actions\CatalogCandidate\CreateCatalogCandidateAction;
use App\Filament\Resources\CatalogCandidates\CatalogCandidateResource;
use App\Models\CatalogCandidate;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateCatalogCandidate extends CreateRecord
{
    protected static string $resource = CatalogCandidateResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $allowSimilarTitle = (bool) ($data['allow_similar_title'] ?? false);
        unset($data['allow_similar_title'], $data['status']);

        $data['created_by_user_id'] = auth()->id();

        try {
            return app(CreateCatalogCandidateAction::class)->execute($data, $allowSimilarTitle);
        } catch (ValidationException $exception) {
            $this->mapActionValidationErrors($exception);
        }
    }

    private function mapActionValidationErrors(ValidationException $exception): never
    {
        foreach ($exception->errors() as $field => $messages) {
            foreach ($messages as $message) {
                $this->addError("data.{$field}", $message);
            }
        }

        $this->halt();
    }

    protected function getRedirectUrl(): string
    {
        $record = $this->getRecord();

        if (! $record instanceof CatalogCandidate) {
            return parent::getRedirectUrl();
        }

        return $this->getResource()::getUrl('edit', ['record' => $record]);
    }
}
